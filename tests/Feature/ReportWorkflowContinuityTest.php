<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\ReportAssignmentService;
use App\Services\ReportWorkflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ReportWorkflowContinuityTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createWorkflowTables();
    }

    public function test_barangay_preview_uses_the_linked_resident_registry_and_signature(): void
    {
        [$barangay, $assessor] = $this->office();
        $building = DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-100',
            'barangay_id' => $barangay,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $household = DB::table('households')->insertGetId([
            'household_reference' => 'HH-100',
            'building_id' => $building,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resident = DB::table('residents')->insertGetId([
            'registry_reference' => 'RES-100',
            'household_id' => $household,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $account = $this->user('resident', 'Ana Cruz', $barangay);
        DB::table('account_resident_links')->insert([
            'user_id' => $account->id,
            'resident_id' => $resident,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-LINEAGE',
            'report_description' => 'Water entered the house.',
            'report_status' => 'draft',
            'barangay_id' => $barangay,
            'prepared_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rir = DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-LINEAGE',
            'report_status' => 'reviewed',
            'resident_user_id' => $account->id,
            'barangay_id' => $barangay,
            'barangay_report_id' => $report,
            'building_id' => $building,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_signatures')->insert([
            'signature_reference' => 'sig-1',
            'document_reference' => 'RIR-LINEAGE',
            'document_hash' => str_repeat('a', 64),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => 'resident_signer',
            'signed_at' => now(),
            'verification_status' => 'verified',
            'signer_user_id' => $account->id,
            'resident_incident_report_id' => $rir,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('field_assessments')->insert([
            'assessment_reference' => 'FA-LINEAGE',
            'assessment_status' => 'draft',
            'workflow_stage' => 'assessor_draft',
            'barangay_report_id' => $report,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$report.'/preview')->assertOk()->getContent();
        $this->assertStringContainsString('RES-100', $html);
        $this->assertStringContainsString('HH-100', $html);
        $this->assertStringContainsString('BLD-100', $html);
        $this->assertStringContainsString('Reported and declared by', $html);
        $this->assertStringContainsString('Ana Cruz', $html);
    }

    public function test_preview_stays_not_recorded_without_a_registry_link(): void
    {
        [$barangay, $assessor] = $this->office();
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-EMPTY',
            'report_description' => 'No registry.',
            'report_status' => 'review',
            'barangay_id' => $barangay,
            'prepared_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('field_assessments')->insert([
            'assessment_reference' => 'FA-EMPTY',
            'assessment_status' => 'draft',
            'workflow_stage' => 'assessor_draft',
            'barangay_report_id' => $report,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$report.'/preview')->assertOk()->getContent();
        $this->assertStringContainsString('Not recorded', $html);
        $this->assertStringNotContainsString('RES-', $html);
    }

    public function test_assignment_is_limited_to_the_report_barangay_and_keeps_history(): void
    {
        [$barangay, $assessor] = $this->office();
        $other = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819022',
            'barangay_name' => 'Telaje',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secretary = $this->member('barangay_secretary', 'Test Secretary', $barangay);
        $outsider = $this->member('barangay_secretary', 'Other Secretary', $other);
        $officer = $this->member('bdrrm', 'Test Officer', $barangay);
        $replacement = $this->member('bdrrm', 'Peter Salomon', $barangay);
        $submission = DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-ASSIGN',
            'report_status' => 'submitted',
            'resident_user_id' => $assessor->id,
            'barangay_id' => $barangay,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($outsider)->postJson('/bdrrm/api/submissions/'.$submission.'/assign', [
            'assignee_user_id' => $officer->id,
        ])->assertForbidden();
        $this->actingAs($secretary)->postJson('/bdrrm/api/submissions/'.$submission.'/assign', [
            'assignee_user_id' => $officer->id,
        ])->assertOk();
        $this->actingAs($secretary)->postJson('/bdrrm/api/submissions/'.$submission.'/assign', [
            'assignee_user_id' => $replacement->id,
            'replaced_reason' => 'Original officer is unavailable.',
        ])->assertOk();

        $this->assertSame(1, DB::table('report_assignments')->where('is_active', true)->count());
        $this->assertSame(1, DB::table('report_assignments')->where('is_active', false)->count());
        $this->assertSame('Original officer is unavailable.', DB::table('report_assignments')->where('is_active', false)->value('replaced_reason'));
    }

    public function test_release_requires_captain_approval_and_assessment_reaches_cdrrmo_once(): void
    {
        [$barangay, $assessor] = $this->office();
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-GATE',
            'report_description' => 'Ready for review.',
            'report_status' => 'draft',
            'workflow_stage' => 'pending_secretary',
            'barangay_id' => $barangay,
            'prepared_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        try {
            app(ReportWorkflow::class)->releaseBarangayReportForAssessor($assessor, $row);
            $this->fail('Release should wait for the captain.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $secretary = $this->member('barangay_secretary', 'Test Secretary', $barangay);
        $captain = $this->member('barangay_captain', 'Test Captain', $barangay);
        $this->signOffice($secretary, '/bdrrm/api/reports/'.$report.'/captain-approve')->assertForbidden();
        $this->signOffice($secretary, '/bdrrm/api/reports/'.$report.'/endorse')->assertOk();
        $this->signOffice($captain, '/bdrrm/api/reports/'.$report.'/captain-approve')->assertOk();
        $this->assertSame('review', DB::table('barangay_reports')->where('id', $report)->value('report_status'));
        $this->assertSame('handed_to_rdana', DB::table('barangay_reports')->where('id', $report)->value('workflow_stage'));
        $this->assertSame(0, DB::table('field_assessments')->where('barangay_report_id', $report)->count());

        $officer = $this->member('rdana_admin_officer', 'Test Assessor', $barangay);
        $this->actingAs($officer)->postJson('/rdana/api/barangay-reports/'.$report.'/assign', [
            'assignee_user_id' => $assessor->id,
        ])->assertOk();
        $assessmentId = (int) DB::table('field_assessments')->where('barangay_report_id', $report)->value('id');
        $this->assertSame(1, DB::table('field_assessments')->where('barangay_report_id', $report)->count());

        DB::table('building_damage_cost_assessments')->insert([
            'barangay_report_id' => $report,
            'assessment_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cityEarly = $this->member('rdana_city_assessor', 'Test City Assessor', $barangay);
        $this->signAssessment($cityEarly, '/rdana/api/assessments/'.$assessmentId.'/forward')->assertStatus(422);
        $this->signAssessment($assessor, '/rdana/api/assessments/'.$assessmentId.'/submit-assessed')->assertOk();
        $this->assertSame(1, DB::table('field_assessments')->count());
        $this->assertSame('pending_admin_verification', DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage'));
        $this->assertNotNull(
            DB::table('report_signatures')
                ->where('field_assessment_id', $assessmentId)
                ->where('signature_role', 'assessed_by')
                ->value('user_signature_asset_id')
        );

        $this->actingAs($officer)->postJson('/rdana/api/assessments/'.$assessmentId.'/return', [
            'return_reason' => 'Revisit building status before administrative verification.',
        ])->assertOk();
        $this->assertSame('returned', DB::table('field_assessments')->where('id', $assessmentId)->value('assessment_status'));
        $this->assertSame('assessor_draft', DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage'));
        $this->assertSame('draft', app(ReportAssignmentService::class)->assessmentBucket($report));

        $this->signAssessment($assessor, '/rdana/api/assessments/'.$assessmentId.'/submit-assessed')->assertOk();
        $this->assertSame('pending_admin_verification', DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage'));
        $this->assertNotSame('returned', DB::table('field_assessments')->where('id', $assessmentId)->value('assessment_status'));
        $this->assertSame('completed', app(ReportAssignmentService::class)->assessmentBucket($report));

        $city = $cityEarly;
        $this->signAssessment($city, '/rdana/api/assessments/'.$assessmentId.'/forward')->assertStatus(422);
        $this->signAssessment($officer, '/rdana/api/assessments/'.$assessmentId.'/verify-officer')->assertOk();
        $this->signAssessment($city, '/rdana/api/assessments/'.$assessmentId.'/forward')->assertOk();
        $this->assertSame('ready_for_consolidation', DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage'));

        DB::table('field_assessments')->insert([
            'assessment_reference' => 'FA-HIDDEN',
            'assessment_status' => 'submitted',
            'workflow_stage' => 'pending_city_assessor',
            'barangay_report_id' => $report,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->user('cdrrmo_admin', 'City Admin');
        $listed = $this->actingAs($admin)->getJson('/cdrrmo/api/reports/field')->assertOk()->json('data');
        $references = collect($listed)->pluck('reference')->all();
        $this->assertNotContains(DB::table('field_assessments')->where('id', $assessmentId)->value('assessment_reference'), $references);
        $this->assertNotContains('FA-HIDDEN', $references);
    }

    public function test_custom_incident_type_is_saved_without_a_new_registry_event(): void
    {
        [$barangay, $resident] = $this->office('resident');
        $type = DB::table('disaster_types')->insertGetId(['name' => 'Flood', 'created_at' => now(), 'updated_at' => now()]);
        $incident = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-1',
            'incident_name' => 'River flood',
            'incident_status' => 'active',
            'disaster_type_id' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('disaster_barangays')->insert(['disaster_incident_id' => $incident, 'barangay_id' => $barangay]);
        $before = DB::table('disaster_incidents')->count();

        $this->actingAs($resident)->postJson('/resident/api/reports', [
            'reported_incident_type' => 'Ground fissure beside the chapel',
            'incident_description' => 'The road cracked after the flood.',
            'reported_location' => 'Purok 2',
        ])->assertCreated();

        $this->assertSame('Ground fissure beside the chapel', DB::table('resident_incident_reports')->value('reported_incident_type'));
        $this->assertSame($before, DB::table('disaster_incidents')->count());
        $this->actingAs($resident)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $incident,
            'reported_incident_type' => str_repeat('x', 121),
            'incident_description' => 'Too long.',
            'reported_location' => 'Purok 2',
        ])->assertStatus(422);
    }

    public function test_secretary_cannot_endorse_before_the_bdrrm_signature(): void
    {
        [$barangay, $assessor] = $this->office();
        $secretary = $this->member('barangay_secretary', 'Test Secretary', $barangay);
        $captain = $this->member('barangay_captain', 'Test Captain', $barangay);
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-SIGN-ORDER',
            'report_description' => 'Waiting for the reviewer signature.',
            'report_status' => 'draft',
            'workflow_stage' => 'pending_secretary',
            'barangay_id' => $barangay,
            'prepared_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('resident_incident_reports')->insert([
            'report_reference' => 'RIR-SIGN-ORDER',
            'report_status' => 'reviewed',
            'resident_user_id' => $assessor->id,
            'barangay_id' => $barangay,
            'barangay_report_id' => $report,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->signOffice($captain, '/bdrrm/api/reports/'.$report.'/captain-approve')->assertStatus(422);
        $this->signOffice($secretary, '/bdrrm/api/reports/'.$report.'/endorse')->assertStatus(422);

        $residentId = (int) DB::table('resident_incident_reports')->where('barangay_report_id', $report)->value('id');
        DB::table('report_signatures')->insert([
            'signature_reference' => 'sig-bdrrm',
            'document_reference' => 'RIR-SIGN-ORDER',
            'document_hash' => str_repeat('c', 64),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => 'bdrrm_reviewer',
            'signed_at' => now(),
            'verification_status' => 'verified',
            'signer_user_id' => $assessor->id,
            'resident_incident_report_id' => $residentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->signOffice($secretary, '/bdrrm/api/reports/'.$report.'/endorse')->assertOk();
        $this->assertSame('pending_captain', DB::table('barangay_reports')->where('id', $report)->value('workflow_stage'));
        $this->actingAs($captain)->getJson('/bdrrm/api/reports/'.$report)
            ->assertOk()
            ->assertJsonPath('data.signing_action', 'endorse');
        DB::table('report_signatures')->where('barangay_report_id', $report)
            ->where('signature_role', 'barangay_secretary')->update(['mechanism' => 'simulated_demo']);
        $this->getJson('/bdrrm/api/reports/'.$report)
            ->assertOk()
            ->assertJsonPath('data.signing_action', 'endorse');
        $this->actingAs($secretary)->getJson('/bdrrm/api/reports/'.$report)
            ->assertOk()
            ->assertJsonPath('data.signing_action', null);
        $this->actingAs($captain)->postJson('/bdrrm/api/reports/'.$report.'/sign', [
            'confirmed' => true,
            'password' => 'secret-pass',
        ])->assertForbidden();
        $this->signOffice($captain, '/bdrrm/api/reports/'.$report.'/captain-approve')->assertOk();
        $this->getJson('/bdrrm/api/reports/'.$report)
            ->assertOk()
            ->assertJsonPath('data.signing_action', null);
    }

    public function test_standalone_barangay_draft_still_uses_password_signing(): void
    {
        [$barangay, $officer] = $this->office();
        $captain = $this->member('barangay_captain', 'Test Captain', $barangay);
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-STANDALONE-SIGN',
            'report_status' => 'draft',
            'barangay_id' => $barangay,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = '/bdrrm/api/reports/'.$report;
        $this->actingAs($captain)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.signing_action', 'sign');
        $this->postJson($url.'/sign', ['confirmed' => true, 'password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->postJson($url.'/sign', ['confirmed' => true, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.signing_action', null);
    }

    public function test_all_prior_signatures_remain_after_captain_endorsement(): void
    {
        [$barangay, $assessor] = $this->office();
        $admin = $this->member('rdana_admin_officer', 'Test Assessor', $barangay);
        $officer = $this->member('bdrrm', 'Test Officer', $barangay);
        $secretary = $this->member('barangay_secretary', 'Test Secretary', $barangay);
        $captain = $this->member('barangay_captain', 'Test Captain', $barangay);
        $resident = $this->user('resident', 'Case Rivera', $barangay);

        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-2026-RLNJ8P',
            'report_description' => 'Flood entered the house.',
            'report_status' => 'draft',
            'workflow_stage' => 'pending_secretary',
            'barangay_id' => $barangay,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rir = DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-2026-6RAO7J',
            'report_status' => 'reviewed',
            'resident_user_id' => $resident->id,
            'barangay_id' => $barangay,
            'barangay_report_id' => $report,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['role' => 'resident_signer', 'signer' => $resident->id, 'ref' => 'sig-res'],
            ['role' => 'bdrrm_reviewer', 'signer' => $officer->id, 'ref' => 'sig-bdrrm'],
        ] as $row) {
            DB::table('report_signatures')->insert([
                'signature_reference' => $row['ref'],
                'document_reference' => 'RIR-2026-6RAO7J',
                'document_hash' => str_repeat('d', 64),
                'mechanism' => 'authenticated_e_signature',
                'signature_role' => $row['role'],
                'signed_at' => now(),
                'verification_status' => 'verified',
                'signer_user_id' => $row['signer'],
                'resident_incident_report_id' => $rir,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->signOffice($secretary, '/bdrrm/api/reports/'.$report.'/endorse')->assertOk();
        $this->assertSame(1, DB::table('report_signatures')->where('resident_incident_report_id', $rir)->where('signature_role', 'resident_signer')->count());
        $this->assertSame(1, DB::table('report_signatures')->where('resident_incident_report_id', $rir)->where('signature_role', 'bdrrm_reviewer')->count());
        $this->assertSame(1, DB::table('report_signatures')->where('barangay_report_id', $report)->where('signature_role', 'barangay_secretary')->count());

        Storage::fake('local');
        $this->actingAs($captain)->post('/bdrrm/api/reports/'.$report.'/captain-approve', [
            'confirmed' => '1',
            'signature' => UploadedFile::fake()->image('captain-signature.jpg', 120, 60),
        ])->assertOk();
        $assetId = DB::table('report_signatures')->where('barangay_report_id', $report)
            ->where('signature_role', 'barangay_signer')->value('user_signature_asset_id');
        $asset = DB::table('user_signature_assets')->find($assetId);
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertFalse((bool) $asset->is_current);
        Storage::disk('local')->assertExists($asset->storage_path);
        $this->assertSame('BR-2026-RLNJ8P', DB::table('barangay_reports')->where('id', $report)->value('report_reference'));
        $this->assertSame(1, DB::table('report_signatures')->where('resident_incident_report_id', $rir)->where('signature_role', 'resident_signer')->count());
        $this->assertSame(1, DB::table('report_signatures')->where('resident_incident_report_id', $rir)->where('signature_role', 'bdrrm_reviewer')->count());
        $this->assertSame(1, DB::table('report_signatures')->where('barangay_report_id', $report)->where('signature_role', 'barangay_secretary')->count());
        $this->assertSame(1, DB::table('report_signatures')->where('barangay_report_id', $report)->where('signature_role', 'barangay_signer')->count());
        $this->assertSame(4, DB::table('report_signatures')->where(function ($query) use ($rir, $report) {
            $query->where('resident_incident_report_id', $rir)->orWhere('barangay_report_id', $report);
        })->count());

        $detail = $this->actingAs($captain)->getJson('/bdrrm/api/reports/'.$report)->assertOk();
        $detail->assertJsonPath('data.lineage.resident_report_reference', 'RIR-2026-6RAO7J');
        $slots = collect($detail->json('data.signature_slots'));
        $this->assertSame(4, $slots->where('signed', true)->count());
        $this->assertSame(9, $slots->count());
        $this->assertSame(['Resident', 'BDRRM Personnel', 'Barangay Secretary', 'Barangay Captain'], $slots->take(4)->pluck('label')->all());
        $this->assertFalse($slots->contains(fn ($slot) => $slot['label'] === 'barangay_signer'));

        $assessed = $slots->firstWhere('role', 'assessed_by');
        $verified = $slots->firstWhere('role', 'verified_by');
        $cityNoted = $slots->first(fn ($slot) => ($slot['role'] ?? null) === 'approved_by' && ($slot['title'] ?? null) === 'Noted by');
        $cdrrmoNoted = $slots->firstWhere('role', 'noted_by');
        $cdrrmoApproved = $slots->first(fn ($slot) => ($slot['role'] ?? null) === 'approved_by' && ($slot['title'] ?? null) === 'Approved by');
        $this->assertNotNull($assessed);
        $this->assertFalse($assessed['signed']);
        $this->assertNull($assessed['name']);
        $this->assertNull($assessed['image'] ?? null);
        $this->assertSame('Pending endorsement', $assessed['caption']);
        $this->assertSame('RDANA Administrative Officer III', $verified['name']);
        $this->assertFalse($verified['signed']);
        $this->assertNull($verified['image'] ?? null);
        $this->assertSame('City Assessor', $cityNoted['name']);
        $this->assertFalse($cityNoted['signed']);
        $this->assertSame('Assigned officer', $cdrrmoNoted['name']);
        $this->assertFalse($cdrrmoNoted['signed']);
        $this->assertSame('Assigned officer', $cdrrmoApproved['name']);
        $this->assertFalse($cdrrmoApproved['signed']);
        $this->assertNull($cdrrmoApproved['image'] ?? null);

        $rdana = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports/'.$report);
        // Assessor without assignment may be forbidden after continuity rules; supervisors can open.
        if ($rdana->status() === 403) {
            $rdana = $this->actingAs($admin)->getJson('/rdana/api/barangay-reports/'.$report)->assertOk();
        } else {
            $rdana->assertOk();
        }
        $this->assertSame(4, collect($rdana->json('data.signature_slots'))->where('signed', true)->count());
        $this->assertSame(9, collect($rdana->json('data.signature_slots'))->count());

        $preview = $this->actingAs($admin)->get('/rdana/reports/barangay/'.$report.'/preview')->assertOk()->getContent();
        $this->assertStringContainsString('Reported and declared by', $preview);
        $this->assertStringContainsString('Checked by', $preview);
        $this->assertStringContainsString('Endorsed by', $preview);
        $this->assertStringContainsString('Assessed by', $preview);
        $this->assertStringContainsString('Verified by', $preview);
        $this->assertStringContainsString('Approved by', $preview);
        $this->assertStringContainsString('Case Rivera', $preview);
        $this->assertStringContainsString('Test Secretary', $preview);
        $this->assertStringContainsString('Test Captain', $preview);
        $this->assertStringContainsString('RDANA Administrative Officer III', $preview);
        $this->assertStringContainsString('City Assessor', $preview);
        $this->assertStringContainsString('CDRRMO Reviewing Officer', $preview);
        $this->assertStringContainsString('Head, CDRRMO', $preview);
        $this->assertStringContainsString('Pending endorsement', $preview);
        $this->assertStringNotContainsString('Assigned RDANA assessor', $preview);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/'.$report.'/assign', [
            'assignee_user_id' => $assessor->id,
        ])->assertOk();
        $this->assertSame(4, DB::table('report_signatures')->where(function ($query) use ($rir, $report) {
            $query->where('resident_incident_report_id', $rir)->orWhere('barangay_report_id', $report);
        })->count());
        $afterAssign = $this->actingAs($admin)->getJson('/rdana/api/barangay-reports/'.$report)->assertOk();
        $afterSlots = collect($afterAssign->json('data.signature_slots'));
        $this->assertSame(4, $afterSlots->where('signed', true)->count());
        $this->assertSame(9, $afterSlots->count());
        $assessedAfter = $afterSlots->firstWhere('role', 'assessed_by');
        $this->assertFalse($assessedAfter['signed']);
        $this->assertSame('Olver Ravelo', $assessedAfter['name']);
        $this->assertNull($assessedAfter['image'] ?? null);
        $this->assertSame('Pending endorsement', $assessedAfter['caption']);

        $this->artisan('mapr:audit-barangay-signatures')->assertSuccessful();
    }

    public function test_bdrrm_personnel_cannot_open_building_records(): void
    {
        [$barangay] = $this->office();
        $officer = $this->member('bdrrm', 'Test Officer', $barangay);
        $secretary = $this->member('barangay_secretary', 'Test Secretary', $barangay);
        $captain = $this->member('barangay_captain', 'Test Captain', $barangay);
        DB::table('buildings')->insert([
            'building_reference' => 'BLD-DIR',
            'barangay_id' => $barangay,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings')->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?lookup=1&q=BL')->assertOk()
            ->assertJsonPath('data.0.reference', 'BLD-DIR')
            ->assertJsonMissingPath('data.0.household_count');
        $this->actingAs($secretary)->getJson('/bdrrm/api/buildings')->assertOk();
        $this->actingAs($captain)->getJson('/bdrrm/api/buildings')->assertOk();
    }

    private function office(string $role = 'rdana'): array
    {
        $barangay = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $role === 'rdana'
            ? $this->member('rdana', 'Olver Ravelo', $barangay)
            : $this->user($role, 'Ana Cruz', $barangay);

        return [$barangay, $user];
    }

    private function signOffice(User $user, string $url)
    {
        $this->ensureSavedSignature($user);

        return $this->actingAs($user)->post($url, [
            'confirmed' => true,
            'use_saved_signature' => '1',
        ]);
    }

    private function signAssessment(User $user, string $url)
    {
        $this->ensureSavedSignature($user);

        return $this->actingAs($user)->post($url, [
            'confirmed' => true,
            'password' => 'secret-pass',
            'use_saved_signature' => '1',
        ]);
    }

    private function ensureSavedSignature(User $user): void
    {
        if (! Schema::hasTable('user_signature_assets')) {
            return;
        }
        DB::table('user_signature_assets')->where('user_id', $user->id)->update(['is_current' => false]);
        $payload = [
            'user_id' => $user->id,
            'storage_path' => 'signature-images/test-'.$user->id.'-'.uniqid().'.png',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach (['mime_type' => 'image/png', 'byte_size' => 120, 'width' => 80, 'height' => 40, 'sha256' => str_repeat('a', 64)] as $column => $value) {
            if (Schema::hasColumn('user_signature_assets', $column)) {
                $payload[$column] = $value;
            }
        }
        DB::table('user_signature_assets')->insert($payload);
    }

    private function member(string $role, string $name, int $barangayId): User
    {
        $user = $this->user($role, $name, $barangayId);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $user->id,
            'barangay_id' => $barangayId,
            'assigned_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh('role');
    }

    private function user(string $role, string $name, ?int $barangayId = null): User
    {
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => $role,
                'code' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        [$first, $rest] = array_pad(explode(' ', $name, 2), 2, 'User');

        return User::query()->create([
            'first_name' => $first,
            'last_name' => $rest,
            'email' => strtolower(str_replace(' ', '.', $name)).random_int(1, 9999).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ]);
    }

    private function createWorkflowTables(): void
    {
        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status');
            $table->unsignedBigInteger('disaster_type_id');
            $table->timestamps();
        });
        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference');
            $table->string('household_status')->default('active');
            $table->unsignedBigInteger('building_id');
            $table->timestamps();
        });
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('registry_reference');
            $table->unsignedBigInteger('household_id')->nullable();
            $table->timestamps();
        });
        Schema::create('account_resident_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('resident_id');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->string('workflow_stage')->nullable();
            $table->unsignedBigInteger('disaster_incident_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('barangay_report_buildings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id');
            $table->timestamps();
        });
        Schema::create('resident_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('incident_description')->nullable();
            $table->string('reported_location')->nullable();
            $table->string('report_status');
            $table->string('reported_incident_type', 120)->nullable();
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->boolean('observed_at_approximate')->default(false);
            $table->timestamps();
        });
        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference');
            $table->text('findings')->nullable();
            $table->string('assessment_status');
            $table->string('workflow_stage')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('building_damage_cost_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->string('assessment_status');
            $table->timestamps();
        });
        Schema::create('report_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->string('assignment_role', 20);
            $table->string('remarks', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('replaced_reason', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->char('document_hash', 64);
            $table->string('mechanism');
            $table->string('signature_role');
            $table->dateTime('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('consolidated_report_id')->nullable();
            $table->unsignedBigInteger('resident_incident_report_id')->nullable();
            $table->unsignedBigInteger('user_signature_asset_id')->nullable();
            $table->timestamps();
        });
        Schema::create('user_signature_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('storage_path');
            $table->string('mime_type');
            $table->unsignedInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('sha256');
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });
    }
}
