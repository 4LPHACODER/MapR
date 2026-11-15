<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class FieldAssessmentPreviewTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createTables();
    }

    public function test_start_is_idempotent_and_preview_preserves_source_references(): void
    {
        $barangay = $this->barangay();
        $officer = $this->member('rdana_admin_officer', 'Mark Merillo', $barangay);
        $assessor = $this->member('rdana', 'Orland Falcon', $barangay);
        $bdrrm = $this->member('bdrrm', 'Jesnard Plaza', $barangay);
        $type = DB::table('disaster_types')->insertGetId([
            'name' => 'Flood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $incident = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-FA-1',
            'incident_name' => 'Coastal flood',
            'incident_status' => 'active',
            'occurred_at' => now(),
            'disaster_type_id' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-2026-FJ1OWW',
            'report_description' => 'Barangay observed flooding near the shoreline.',
            'report_status' => 'review',
            'workflow_stage' => 'handed_to_rdana',
            'disaster_incident_id' => $incident,
            'barangay_id' => $barangay,
            'prepared_by' => $bdrrm->id,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $residentReport = DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-2026-SOURCE',
            'incident_description' => 'Resident reported rising floodwaters overnight.',
            'report_status' => 'accepted',
            'resident_user_id' => $this->user('resident', 'Maricar Irizari', $barangay)->id,
            'barangay_id' => $barangay,
            'barangay_report_id' => $report,
            'disaster_incident_id' => $incident,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $building = DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-FA-1',
            'barangay_id' => $barangay,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('barangay_report_buildings')->insert([
            'barangay_report_id' => $report,
            'building_id' => $building,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classPartial = DB::table('damage_classes')->insertGetId([
            'class_code' => 'partially_damaged',
            'class_name' => 'Partially damaged',
            'display_order' => 1,
            'counts_as_damaged' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)->postJson('/rdana/api/barangay-reports/'.$report.'/assign', [
            'assignee_user_id' => $assessor->id,
        ])->assertOk();

        $first = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$report.'/start')->assertCreated();
        $second = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$report.'/start')->assertCreated();
        $assessmentId = (int) $first->json('data.id');
        $this->assertSame($assessmentId, (int) $second->json('data.id'));
        $this->assertSame(1, DB::table('field_assessments')->where('barangay_report_id', $report)->count());
        // Prefer the FA created by assignment when present; start must still reuse that single row.
        $this->assertSame(1, DB::table('field_assessments')->where('assigned_assessor_id', $assessor->id)->count());

        DB::table('field_assessments')->where('id', $assessmentId)->update([
            'assessment_reference' => 'FA-2026-CRI5MW',
            'findings' => 'Assessor confirmed partial wall damage after field inspection.',
            'updated_at' => now(),
        ]);
        DB::table('building_damage_cost_assessments')->insert([
            'barangay_report_id' => $report,
            'field_assessment_id' => $assessmentId,
            'building_id' => $building,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 20,
            'unit_cost_php' => 10560,
            'estimated_cost_php' => 211200,
            'cost_status' => 'calculated',
            'assessment_status' => 'confirmed',
            'assessed_by' => $assessor->id,
            'confirmed_by' => $assessor->id,
            'confirmed_at' => now(),
            'remarks' => 'Schedule cost applied.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('building_verifications')->insert([
            'location_verified' => true,
            'household_link_verified' => false,
            'verification_notes' => 'Coordinates matched the registered building.',
            'verified_at' => now(),
            'field_assessment_id' => $assessmentId,
            'building_id' => $building,
            'verified_damage_class_id' => $classPartial,
            'verified_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('incident_photos')->insert([
            'file_path' => 'photos/missing-fa.jpg',
            'original_filename' => 'damage.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 20,
            'file_sha256' => str_repeat('1', 64),
            'image_source' => 'bdrrm_upload',
            'barangay_report_id' => $report,
            'building_id' => $building,
            'uploaded_by' => $bdrrm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = $this->actingAs($assessor)->get('/rdana/assessments/'.$assessmentId.'/preview')->assertOk();
        $html = $preview->getContent();
        $this->assertStringContainsString('Field Assessment Report', $html);
        $this->assertStringContainsString('FA-2026-CRI5MW', $html);
        $this->assertStringContainsString('BR-2026-FJ1OWW', $html);
        $this->assertStringContainsString('RIR-2026-SOURCE', $html);
        $this->assertStringContainsString('Resident reported rising floodwaters overnight.', $html);
        $this->assertStringContainsString('Barangay observed flooding near the shoreline.', $html);
        $this->assertStringContainsString('Assessor confirmed partial wall damage after field inspection.', $html);
        $this->assertStringContainsString('Photographic evidence', $html);
        $this->assertStringContainsString('Building Damage Assessment', $html);
        $this->assertStringContainsString('Partially Damage', $html);
        $this->assertStringContainsString('211,200.00', $html);
        $this->assertStringContainsString('Damage assessment criteria', $html);
        $this->assertStringContainsString('Building and location verification', $html);
        $this->assertStringContainsString('Location Checked', $html);
        $this->assertStringContainsString('Yes', $html);
        $this->assertStringContainsString('Household Link Checked', $html);
        $this->assertStringContainsString('No', $html);
        $this->assertStringContainsString('Report declaration and endorsements', $html);
        $this->assertSame($residentReport, (int) DB::table('resident_incident_reports')->where('barangay_report_id', $report)->value('id'));

        $detail = $this->actingAs($assessor)->getJson('/rdana/api/assessments/'.$assessmentId)->assertOk();
        $detail->assertJsonPath('data.reference', 'FA-2026-CRI5MW');
        $detail->assertJsonPath('data.barangay_report.reference', 'BR-2026-FJ1OWW');
        $detail->assertJsonPath('data.resident_report.reference', 'RIR-2026-SOURCE');
        $this->assertNotEmpty($detail->json('data.signature_slots'));
        $this->assertGreaterThanOrEqual(7, count($detail->json('data.signature_slots')));
        $this->assertSame('Partially Damage', $detail->json('data.damage_summary.building_status'));
        $roles = collect($detail->json('data.signature_slots'))->pluck('role')->all();
        $this->assertContains('assessed_by', $roles);
        $this->assertContains('verified_by', $roles);
        $this->assertContains('approved_by', $roles);
        $unsigned = collect($detail->json('data.signature_slots'))->firstWhere('role', 'assessed_by');
        $this->assertFalse((bool) ($unsigned['signed'] ?? true));
    }

    private function createTables(): void
    {
        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference')->nullable();
            $table->string('incident_name');
            $table->string('incident_status')->default('active');
            $table->dateTime('occurred_at')->nullable();
            $table->unsignedBigInteger('disaster_type_id')->nullable();
            $table->timestamps();
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
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
            $table->string('report_status');
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('disaster_incident_id')->nullable();
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
        Schema::create('report_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->string('assignment_role', 20);
            $table->string('remarks', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference')->nullable();
            $table->string('document_reference')->nullable();
            $table->char('document_hash', 64)->nullable();
            $table->string('mechanism')->nullable();
            $table->string('signature_role');
            $table->dateTime('signed_at')->nullable();
            $table->string('verification_status')->nullable();
            $table->unsignedBigInteger('signer_user_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('resident_incident_report_id')->nullable();
            $table->unsignedBigInteger('consolidated_report_id')->nullable();
            $table->unsignedBigInteger('user_signature_asset_id')->nullable();
            $table->timestamps();
        });
        Schema::create('damage_classes', function (Blueprint $table) {
            $table->id();
            $table->string('class_code')->unique();
            $table->string('class_name');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('counts_as_damaged')->default(false);
            $table->timestamps();
        });
        Schema::create('building_damage_cost_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('confirmed_damage_class_id')->nullable();
            $table->string('classification_source', 32)->default('manual');
            $table->string('occupancy_key', 64)->nullable();
            $table->string('construction_type', 8)->nullable();
            $table->string('construction_grade', 8)->nullable();
            $table->decimal('total_floor_area_m2', 12, 4)->nullable();
            $table->decimal('assessed_floor_area_m2', 12, 4)->nullable();
            $table->decimal('unit_cost_php', 12, 2)->nullable();
            $table->decimal('estimated_cost_php', 14, 2)->nullable();
            $table->string('cost_status', 32)->default('pending_inputs');
            $table->string('assessment_status', 32)->default('draft');
            $table->unsignedBigInteger('assessed_by');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        Schema::create('building_verifications', function (Blueprint $table) {
            $table->id();
            $table->boolean('location_verified')->default(false);
            $table->boolean('household_link_verified')->default(false);
            $table->text('verification_notes')->nullable();
            $table->dateTime('verified_at');
            $table->unsignedBigInteger('field_assessment_id');
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('verified_damage_class_id');
            $table->unsignedBigInteger('verified_by');
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
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('uploaded_by');
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
        Schema::create('report_flags', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('status')->default('open');
            $table->string('category')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    private function barangay(): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'email' => strtolower(str_replace(' ', '.', $name)).random_int(1, 999).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ])->fresh('role');
    }
}
