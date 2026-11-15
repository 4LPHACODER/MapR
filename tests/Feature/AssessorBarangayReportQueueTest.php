<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\ReportWorkflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class AssessorBarangayReportQueueTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createReportTables();
    }

    public function test_accepted_submission_is_visible_to_assigned_assessor_and_filters_securely(): void
    {
        $mabua = $this->barangay('166819008', 'Mabua');
        $telaje = $this->barangay('166819022', 'Telaje');
        $outside = $this->barangay('166819999', 'Outsideville');
        $incident = $this->incident($mabua, 'River flood');
        $this->incident($telaje, 'Coastal surge');
        $officer = $this->officer($mabua);
        $assessor = $this->assessor([$mabua, $telaje]);
        $otherAssessor = $this->assessor([$outside], 'Other Assessor');
        $resident = $this->user('resident', 'Ana Cruz', $mabua);
        $submission = $this->residentSubmission($resident, $incident, $mabua);
        DB::table('report_assignments')->insert([
            'subject_type' => 'resident_incident_report',
            'subject_id' => $submission,
            'assignee_user_id' => $officer->id,
            'assigned_by' => $officer->id,
            'assignment_role' => 'bdrrm',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $opened = $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$submission)->assertOk();
        $accepted = $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$submission.'/accept', [
            'confirmed' => true,
            'content_revision' => $opened->json('data.content_revision'),
            'signature' => UploadedFile::fake()->image('signature.png', 80, 40),
        ])->assertOk();
        $reportId = (int) $accepted->json('barangay_report_id');
        $reference = DB::table('barangay_reports')->where('id', $reportId)->value('report_reference');
        $this->assertSame('draft', DB::table('barangay_reports')->where('id', $reportId)->value('report_status'));

        $secretary = $this->user('barangay_secretary', 'Leonida Lagunda', $mabua);
        $captain = $this->user('barangay_captain', 'Rocky Pimentel', $mabua);
        foreach ([$secretary, $captain] as $person) {
            DB::table('user_barangay_assignments')->insert([
                'assigned_at' => now(),
                'user_id' => $person->id,
                'barangay_id' => $mabua,
                'assigned_by' => $person->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->actingAs($secretary)->post('/bdrrm/api/reports/'.$reportId.'/endorse', [
            'confirmed' => true,
            'signature' => UploadedFile::fake()->image('secretary.png', 80, 40),
        ])->assertOk();
        $this->actingAs($captain)->post('/bdrrm/api/reports/'.$reportId.'/captain-approve', [
            'confirmed' => true,
            'signature' => UploadedFile::fake()->image('captain.png', 80, 40),
        ])->assertOk();

        $this->assertSame('review', DB::table('barangay_reports')->where('id', $reportId)->value('report_status'));
        $this->assertNotNull(DB::table('barangay_reports')->where('id', $reportId)->value('submitted_at'));
        $this->assertSame(0, DB::table('field_assessments')->where('barangay_report_id', $reportId)->count());

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $reportId)
            ->assertJsonPath('data.0.reference', $reference);

        $admin = $this->user('rdana_admin_officer', 'Mark Merillo', $mabua);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $admin->id,
            'barangay_id' => $mabua,
            'assigned_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $admin->id,
            'barangay_id' => $telaje,
            'assigned_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unassigned = $this->actingAs($admin)->getJson('/rdana/api/barangay-reports?queue=unassigned')->assertOk();
        $unassigned->assertJsonPath('meta.total', 1);
        $unassigned->assertJsonPath('data.0.id', $reportId);
        $unassigned->assertJsonPath('counts.unassigned', 1);
        $unassigned->assertJsonPath('data.0.label', 'Unassigned');

        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/'.$reportId.'/assign', [
            'assignee_user_id' => $assessor->id,
        ])->assertOk();
        $this->assertSame(1, DB::table('field_assessments')->where('barangay_report_id', $reportId)->where('assigned_assessor_id', $assessor->id)->count());

        $list = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=pending')->assertOk();
        $list->assertJsonPath('meta.total', 1);
        $list->assertJsonPath('data.0.id', $reportId);
        $list->assertJsonPath('data.0.reference', $reference);
        $list->assertJsonPath('data.0.barangay_name', 'Mabua');
        $list->assertJsonPath('data.0.incident_name', 'River flood');
        $list->assertJsonPath('counts.pending', 1);
        $this->assertCount(2, $list->json('barangays'));
        $this->assertSame(['Mabua', 'Telaje'], collect($list->json('barangays'))->pluck('name')->all());

        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=pending&barangay_id='.$mabua)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=pending&barangay_id='.$telaje)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?barangay_id='.$outside)
            ->assertForbidden();
        $this->actingAs($otherAssessor)->getJson('/rdana/api/barangay-reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($otherAssessor)->get('/rdana/reports/barangay/'.$reportId.'/preview')
            ->assertForbidden();
        $this->actingAs($otherAssessor)->get('/rdana/reports/barangay/'.$reportId.'/preview?format=pdf')
            ->assertForbidden();

        $show = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports/'.$reportId)->assertOk();
        $show->assertJsonPath('data.id', $reportId);
        $show->assertJsonPath('data.reference', $reference);
        $show->assertJsonPath('data.status', 'review');

        $preview = $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$reportId.'/preview')->assertOk();
        $this->assertStringContainsString('html', strtolower((string) $preview->headers->get('content-type')));
        $this->assertStringContainsString('Back to Barangay Reports', $preview->getContent());
        $this->assertStringContainsString('Barangay Report', $preview->getContent());
        $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$reportId.'/preview?format=pdf')
            ->assertOk();
        $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$reportId.'/preview?format=pdf&inline=1')
            ->assertOk();

        $this->actingAs($otherAssessor)->getJson('/rdana/api/barangay-reports/'.$reportId)
            ->assertForbidden();

        auth()->logout();
        $this->get('/rdana/reports/barangay/'.$reportId.'/preview')
            ->assertRedirect();
        $this->getJson('/rdana/api/barangay-reports/'.$reportId)
            ->assertUnauthorized();

        $again = $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$submission.'/accept', [
            'confirmed' => true,
            'content_revision' => $opened->json('data.content_revision'),
            'use_saved_signature' => true,
        ])->assertOk();
        $this->assertSame($reportId, (int) $again->json('barangay_report_id'));
        $this->assertSame(1, DB::table('barangay_reports')->count());
    }

    public function test_list_and_preview_agree_when_assessor_only_has_a_specific_report_assignment(): void
    {
        $mabua = $this->barangay('166819008', 'Mabua');
        $officer = $this->officer($mabua);
        $incident = $this->incident($mabua, 'River flood');
        $limited = $this->user('rdana', 'Limited Assessor');
        $reportA = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-ASSIGNED-A',
            'report_description' => 'Assigned report.',
            'report_status' => 'review',
            'submitted_at' => now(),
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportB = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-OTHER-B',
            'report_description' => 'Sibling report in same barangay.',
            'report_status' => 'review',
            'submitted_at' => now(),
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('field_assessments')->insert([
            'assessment_reference' => 'FA-LIMITED-A',
            'findings' => null,
            'assessment_status' => 'draft',
            'barangay_report_id' => $reportA,
            'assigned_assessor_id' => $limited->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = $this->actingAs($limited)->getJson('/rdana/api/barangay-reports')->assertOk();
        $list->assertJsonPath('meta.total', 1);
        $list->assertJsonPath('data.0.id', $reportA);
        $this->assertSame([], $list->json('barangays'));
        $this->assertNotContains($reportB, collect($list->json('data'))->pluck('id')->all());

        $this->actingAs($limited)->getJson('/rdana/api/barangay-reports/'.$reportA)
            ->assertOk()
            ->assertJsonPath('data.id', $reportA)
            ->assertJsonPath('data.reference', 'BR-ASSIGNED-A')
            ->assertJsonPath('data.status', 'review');
        $this->actingAs($limited)->getJson('/rdana/api/barangay-reports/'.$reportB)
            ->assertForbidden();
        $this->actingAs($limited)->get('/rdana/reports/barangay/'.$reportA.'/preview')->assertOk();
        $this->actingAs($limited)->get('/rdana/reports/barangay/'.$reportA.'/preview?format=pdf')->assertOk();
        $this->actingAs($limited)->get('/rdana/reports/barangay/'.$reportB.'/preview')->assertForbidden();
        $this->actingAs($limited)->get('/rdana/reports/barangay/'.$reportB.'/preview?format=pdf')->assertForbidden();
        $this->actingAs($limited)->getJson('/rdana/api/barangay-reports?barangay_id='.$mabua)->assertForbidden();
    }

    public function test_manual_draft_stays_out_of_assessor_handoff_queue(): void
    {
        $mabua = $this->barangay('166819008', 'Mabua');
        $assessor = $this->assessor([$mabua]);
        $officer = $this->officer($mabua);
        $incident = $this->incident($mabua, 'Flood');
        DB::table('barangay_reports')->insert([
            'report_reference' => 'BR-MANUAL-DRAFT',
            'report_description' => 'Manual draft only.',
            'report_status' => 'draft',
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=draft')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_handed_off_mabua_report_appears_after_assessor_assignment_and_backfill(): void
    {
        $mabua = $this->barangay('166819008', 'Mabua');
        $telaje = $this->barangay('166819022', 'Telaje');
        $officer = $this->officer($mabua);
        $assessor = $this->user('rdana', 'Mark Merillo');
        $incident = $this->incident($mabua, 'Demo flood event');
        $reportId = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-2026-L1BZ22',
            'report_description' => 'Handed off Mabua report.',
            'report_status' => 'review',
            'submitted_at' => now(),
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $reportId)
            ->assertJsonPath('data.0.reference', 'BR-2026-L1BZ22');

        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($assessor)->getJson('/rdana/api/navigation')
            ->assertOk()
            ->assertJsonPath('barangay_reports', 0);

        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $assessor->id,
            'barangay_id' => $mabua,
            'assigned_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $assessor->id,
            'barangay_id' => $telaje,
            'assigned_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $withoutAssignment = app(ReportWorkflow::class)->backfillAssessorAssignmentsForReviewQueue();
        $this->assertSame(0, $withoutAssignment['linked']);
        $this->assertSame(1, $withoutAssignment['missing_assessor']);

        DB::table('report_assignments')->insert([
            'subject_type' => 'barangay_report',
            'subject_id' => $reportId,
            'assignee_user_id' => $assessor->id,
            'assigned_by' => $assessor->id,
            'assignment_role' => 'rdana',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $backfill = app(ReportWorkflow::class)->backfillAssessorAssignmentsForReviewQueue();
        $this->assertSame(1, $backfill['linked']);
        $this->assertSame(1, DB::table('field_assessments')->where('barangay_report_id', $reportId)->where('assigned_assessor_id', $assessor->id)->count());

        $list = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=pending')->assertOk();
        $list->assertJsonPath('meta.total', 1);
        $list->assertJsonPath('data.0.id', $reportId);
        $list->assertJsonPath('data.0.reference', 'BR-2026-L1BZ22');
        $list->assertJsonPath('counts.pending', 1);
        $list->assertJsonPath('counts.total', 1);

        $this->actingAs($assessor)->getJson('/rdana/api/navigation')
            ->assertOk()
            ->assertJsonPath('barangay_reports', 1);

        $bdrrm = $this->actingAs($officer)->getJson('/bdrrm/api/reports/'.$reportId)->assertOk();
        $rdana = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports/'.$reportId)->assertOk();
        $this->assertSame($bdrrm->json('data.id'), $rdana->json('data.id'));
        $this->assertSame($bdrrm->json('data.reference'), $rdana->json('data.reference'));

        $outsider = $this->assessor([$this->barangay('166819999', 'Outsideville')], 'Outside Assessor');
        $this->actingAs($outsider)->getJson('/rdana/api/barangay-reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($outsider)->getJson('/rdana/api/barangay-reports/'.$reportId)
            ->assertForbidden();
        $this->actingAs($outsider)->get('/rdana/reports/barangay/'.$reportId.'/preview')
            ->assertForbidden();
    }

    public function test_barangay_preview_includes_photographs_linked_by_barangay_report_id(): void
    {
        $mabua = $this->barangay('166819008', 'Mabua');
        $officer = $this->officer($mabua);
        $assessor = $this->assessor([$mabua]);
        $incident = $this->incident($mabua, 'River flood');
        $reportId = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-2026-PHOTO1',
            'report_description' => 'Water reached the doorway.',
            'report_status' => 'review',
            'submitted_at' => now(),
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('field_assessments')->insert([
            'assessment_reference' => 'FA-PHOTO-1',
            'findings' => null,
            'assessment_status' => 'draft',
            'barangay_report_id' => $reportId,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = 'incident-photos/barangay-'.$reportId.'.png';
        Storage::disk('local')->put($path, UploadedFile::fake()->image('evidence.png', 80, 60)->getContent());
        DB::table('incident_photos')->insert([
            'file_path' => $path,
            'original_filename' => 'evidence.png',
            'mime_type' => 'image/png',
            'file_size' => 120,
            'file_sha256' => hash('sha256', 'evidence-'.$reportId),
            'image_source' => 'upload',
            'incident_report_id' => null,
            'barangay_report_id' => $reportId,
            'field_assessment_id' => null,
            'building_id' => null,
            'uploaded_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$reportId.'/preview')->assertOk();
        $html = $preview->getContent();
        $this->assertStringContainsString('Photographic evidence', $html);
        $this->assertStringContainsString('Photo 1', $html);
        $this->assertStringContainsString(Storage::disk('local')->path($path), $html);
        $this->assertStringNotContainsString('No photographs were saved with this report.', $html);

        $pdf = $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$reportId.'/preview?format=pdf&inline=1')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertGreaterThan(1000, strlen($pdf->getContent()));
    }

    private function createReportTables(): void
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
            $table->string('incident_status')->default('active');
            $table->dateTime('occurred_at')->nullable();
            $table->unsignedBigInteger('disaster_type_id')->nullable();
            $table->timestamps();
        });
        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
        });
        Schema::create('resident_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('incident_description')->nullable();
            $table->text('reported_location')->nullable();
            $table->string('reported_point')->nullable();
            $table->string('report_status')->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->timestamps();
        });
        Schema::create('incident_photos', function (Blueprint $table) {
            $table->id();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('file_sha256', 64);
            $table->string('image_source');
            $table->unsignedBigInteger('incident_report_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->string('workflow_stage')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('report_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->string('assignment_role');
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('replaced_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('barangay_report_buildings', function (Blueprint $table) {
            $table->id();
            $table->text('observations')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id');
            $table->timestamps();
        });
        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference');
            $table->text('findings')->nullable();
            $table->string('assessment_status')->default('draft');
            $table->string('workflow_stage')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamps();
        });
        Schema::create('user_signature_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('storage_path');
            $table->string('mime_type', 64);
            $table->unsignedInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('sha256', 64);
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->char('document_hash', 64);
            $table->string('mechanism')->default('authenticated_e_signature');
            $table->string('signature_role');
            $table->dateTime('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('consolidated_report_id')->nullable();
            $table->unsignedBigInteger('resident_incident_report_id')->nullable();
            $table->unsignedBigInteger('user_signature_asset_id')->nullable();
            $table->text('signature_statement')->nullable();
            $table->timestamps();
            $table->unique(['resident_incident_report_id', 'signature_role']);
        });
        Schema::create('report_flags', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('category', 80);
            $table->text('reason');
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('opened_by');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->timestamps();
        });
        Schema::create('imported_report_documents', function (Blueprint $table) {
            $table->id();
            $table->string('import_key')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('resolved_building_id')->nullable();
            $table->string('source_building_reference')->nullable();
            $table->unsignedBigInteger('resolved_resident_id')->nullable();
            $table->string('source_resident_reference')->nullable();
            $table->timestamps();
        });
    }

    private function barangay(string $code, string $name): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => $code,
            'barangay_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function incident(int $barangayId, string $name): int
    {
        $type = DB::table('disaster_types')->insertGetId([
            'name' => 'Flood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-'.$barangayId.'-'.random_int(10, 99),
            'incident_name' => $name,
            'incident_status' => 'active',
            'occurred_at' => now(),
            'disaster_type_id' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('disaster_barangays')->insert([
            'disaster_incident_id' => $id,
            'barangay_id' => $barangayId,
        ]);

        return $id;
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
            'email' => strtolower(str_replace(' ', '.', $name)).random_int(1, 999).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ]);
    }

    private function officer(int $barangayId): User
    {
        $user = $this->user('bdrrm', 'Officer '.$barangayId, $barangayId);
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

    private function assessor(array $barangayIds, string $name = 'Mark Merillo'): User
    {
        $user = $this->user('rdana', $name);
        foreach ($barangayIds as $barangayId) {
            DB::table('user_barangay_assignments')->insert([
                'assigned_at' => now(),
                'user_id' => $user->id,
                'barangay_id' => $barangayId,
                'assigned_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user->fresh('role');
    }

    private function residentSubmission(User $resident, int $incident, int $barangay): int
    {
        return DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-HAND-'.random_int(100, 999),
            'incident_description' => 'Water reached the doorway.',
            'reported_location' => 'Purok 1',
            'reported_point' => '126.19000 9.07000',
            'report_status' => 'submitted',
            'submitted_at' => now(),
            'resident_user_id' => $resident->id,
            'disaster_incident_id' => $incident,
            'barangay_id' => $barangay,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
