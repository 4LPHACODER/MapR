<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Services\ReportSignatoryCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createDocumentTables();
    }

    public function test_screen_markup_uses_dashboard_metrics_and_a_scoped_barangay_placeholder(): void
    {
        $map = file_get_contents(resource_path('js/shared/components/MapWorkspace.vue'));
        $this->assertStringContainsString('No assigned barangay', $map);
        $this->assertStringContainsString('All four barangays', $map);

        foreach ([
            'bdrrm' => ['bdrrm-dash-cards', 'bdrrm-dash-card'],
            'assessor' => ['assessor-dashboard-metrics', 'assessor-dashboard-metric'],
        ] as $workspace => [$groupClass, $metricClass]) {
            $dashboard = file_get_contents(resource_path("js/{$workspace}/pages/DashboardPage.vue"));
            $this->assertMatchesRegularExpression('/class="'.$groupClass.'(?:\s|")/', $dashboard);
            $this->assertMatchesRegularExpression('/class="'.$metricClass.'(?:\s|")/', $dashboard);
            $this->assertStringNotContainsString('cdrrmo-summary-grid', $dashboard);
        }

        foreach ([
            resource_path('js/bdrrm/pages/RegistryPage.vue'),
            resource_path('js/assessor/pages/RegistryPage.vue'),
        ] as $registryPath) {
            $incidents = file_get_contents($registryPath);
            $this->assertStringContainsString('No incident is assigned to this account.', $incidents);
        }
    }

    public function test_configured_cdrrmo_signers_are_authorized_for_their_slot_only(): void
    {
        $notingOfficer = User::query()->where('email', 'reviewer@example.test')->firstOrFail();
        $approvingOfficer = User::factory()->create([
            'role_id' => $notingOfficer->role_id,
            'email' => 'approver@example.test',
            'password' => 'review-password',
            'account_status' => 'active',
        ]);
        $report = DB::table('consolidated_reports')->insertGetId([
            'report_reference' => 'TEST-CONSOLIDATED',
            'report_summary' => 'Test report.',
            'report_status' => 'draft',
            'disaster_incident_id' => 1,
            'prepared_by' => $notingOfficer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $confirmation = ['confirmed' => true, 'password' => 'review-password'];
        $endpoint = '/cdrrmo/api/consolidated/'.$report;
        $this->actingAs($notingOfficer)->postJson($endpoint.'/note', $confirmation)->assertForbidden();

        config(['mapr.consolidated_signer_emails' => [
            'noted_by' => strtoupper($notingOfficer->email),
            'approved_by' => $approvingOfficer->email,
        ]]);
        $this->actingAs($notingOfficer)->getJson('/cdrrmo/api/reports/consolidated')
            ->assertOk()->assertJsonPath('signing_capacity', 'noted_by');
        $this->assertSame($notingOfficer->display_name, app(ReportSignatoryCatalog::class)->consolidatedSlots()[0]['name']);
        $this->postJson($endpoint.'/approve', $confirmation)->assertForbidden();
        $this->postJson($endpoint.'/note', $confirmation)->assertOk();
        $this->actingAs($approvingOfficer)->postJson($endpoint.'/approve', $confirmation)->assertOk();
        $this->assertDatabaseHas('report_signatures', ['signature_role' => 'noted_by', 'signer_user_id' => $notingOfficer->id]);
        $this->assertDatabaseHas('report_signatures', ['signature_role' => 'approved_by', 'signer_user_id' => $approvingOfficer->id]);
    }

    public function test_removed_signature_asset_remains_linked_to_the_signed_report(): void
    {
        $verifier = User::query()->where('email', 'verifier@example.test')->firstOrFail();
        config(['mapr.assessor_verifier_email' => $verifier->email]);
        $assessment = DB::table('field_assessments')->insertGetId([
            'assessment_reference' => 'TEST-ASSESSMENT',
            'assessment_status' => 'draft',
            'barangay_report_id' => 1,
            'assigned_assessor_id' => $verifier->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($verifier)->post('/rdana/api/signature', [
            'signature' => UploadedFile::fake()->createWithContent('signature.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('user_signature_assets')->count());

        $this->actingAs($verifier)->post('/rdana/api/signature', [
            'signature' => UploadedFile::fake()->image('signature.png', 40, 40),
        ])->assertOk();
        $assetId = DB::table('user_signature_assets')->where('is_current', true)->value('id');
        $this->actingAs($verifier)->postJson('/rdana/api/assessments/'.$assessment.'/verify', [
            'confirmed' => true,
            'password' => 'already-set',
        ])->assertStatus(422);
        DB::table('field_assessments')->where('id', $assessment)->update(['findings' => 'Waterline checked.']);
        $this->actingAs($verifier)->postJson('/rdana/api/assessments/'.$assessment.'/verify', [
            'confirmed' => true,
            'password' => 'already-set',
        ])->assertOk();
        $this->assertSame((int) $assetId, (int) DB::table('report_signatures')->where('signature_role', 'verified_by')->value('user_signature_asset_id'));
        $this->actingAs($verifier)->delete('/rdana/api/signature')->assertOk();
        $this->assertSame((int) $assetId, (int) DB::table('report_signatures')->where('signature_role', 'verified_by')->value('user_signature_asset_id'));
        $this->assertSame(0, DB::table('user_signature_assets')->where('is_current', true)->count());
    }

    private function createDocumentTables(): void
    {
        foreach ([
            ['166819002', 'Bag-ong Lungsod'],
            ['166819022', 'Telaje'],
            ['166819008', 'Mabua'],
            ['166819005', 'Bongtud'],
        ] as [$code, $name]) {
            DB::table('barangays')->insert([
                'barangay_code' => $code,
                'barangay_name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('disaster_types')->insert(['name' => 'Flood', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference')->unique();
            $table->string('incident_name');
            $table->text('description')->nullable();
            $table->dateTime('occurred_at');
            $table->string('incident_status');
            $table->unsignedBigInteger('disaster_type_id');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
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
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('registry_reference');
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Schema::create('resident_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference')->unique();
            $table->text('incident_description')->nullable();
            $table->text('reported_location')->nullable();
            $table->string('report_status');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference')->unique();
            $table->text('report_description')->nullable();
            $table->string('report_status');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference')->unique();
            $table->text('findings')->nullable();
            $table->string('assessment_status');
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamps();
        });
        Schema::create('consolidated_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference')->unique();
            $table->text('report_summary')->nullable();
            $table->string('report_status');
            $table->dateTime('finalized_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->string('document_hash', 64);
            $table->string('mechanism');
            $table->string('signature_role');
            $table->text('signature_statement')->nullable();
            $table->dateTime('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('user_signature_asset_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('consolidated_report_id')->nullable();
            $table->timestamps();
        });
        Schema::create('imported_report_documents', function (Blueprint $table) {
            $table->id();
            $table->string('import_key')->unique();
            $table->string('filename');
            $table->string('storage_path');
            $table->char('sha256', 64);
            $table->string('workflow_status', 32);
            $table->string('reviewing_office', 64);
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->unsignedBigInteger('resident_user_id')->nullable();
            $table->unsignedBigInteger('resident_incident_report_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('consolidated_report_id')->nullable();
            $table->string('source_resident_reference')->nullable();
            $table->string('source_building_reference')->nullable();
            $table->string('source_household_reference')->nullable();
            $table->unsignedBigInteger('resolved_resident_id')->nullable();
            $table->unsignedBigInteger('resolved_building_id')->nullable();
            $table->unsignedBigInteger('resolved_household_id')->nullable();
            $table->json('unresolved_identifiers')->nullable();
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
            $table->char('sha256', 64);
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->timestamps();
        });

        $assessor = Role::query()->create(['name' => 'RDANA Assessor', 'code' => 'rdana']);
        $administrator = Role::query()->create(['name' => 'CDRRMO Administrator', 'code' => 'cdrrmo_admin']);
        User::factory()->create([
            'role_id' => $assessor->id,
            'email' => 'verifier@example.test',
            'password' => 'already-set',
            'account_status' => 'active',
        ]);
        User::factory()->create([
            'role_id' => $administrator->id,
            'email' => 'reviewer@example.test',
            'password' => 'review-password',
            'account_status' => 'active',
        ]);
    }
}
