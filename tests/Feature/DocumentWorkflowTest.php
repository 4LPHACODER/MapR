<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Database\Seeders\MaprDocumentWorkflowSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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
        $this->seed(RoleSeeder::class);
    }

    public function test_screen_markup_uses_stat_cards_and_a_scoped_barangay_placeholder(): void
    {
        $map = file_get_contents(resource_path('js/shared/components/MapWorkspace.vue'));
        $this->assertStringContainsString('No assigned barangay', $map);
        $this->assertStringContainsString('All four barangays', $map);

        foreach ([
            resource_path('js/bdrrm/pages/DashboardPage.vue'),
            resource_path('js/assessor/pages/DashboardPage.vue'),
        ] as $dashboardPath) {
            $dashboard = file_get_contents($dashboardPath);
            $this->assertStringContainsString('cdrrmo-stat-grid', $dashboard);
            $this->assertStringContainsString('cdrrmo-stat-card', $dashboard);
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

    public function test_document_seed_is_idempotent_and_keeps_existing_passwords(): void
    {
        $markPassword = User::query()->where('email', 'mark.merillo@mapr.test')->value('password');
        $lesterPassword = User::query()->where('email', 'lestertalan@mapr_admin.com')->value('password');

        $this->seed(MaprDocumentWorkflowSeeder::class);
        $this->seed(MaprDocumentWorkflowSeeder::class);

        $this->assertTrue(Hash::check('already-set', $markPassword));
        $this->assertSame($markPassword, User::query()->where('email', 'mark.merillo@mapr.test')->value('password'));
        $this->assertSame($lesterPassword, User::query()->where('email', 'lestertalan@mapr_admin.com')->value('password'));
        $this->assertNull(User::withTrashed()->where('email', 'lester.talan@mapr.test')->first());
        $this->assertSame(1, User::query()->where('email', 'luis.maquiling@mapr.test')->count());
        $this->assertSame(4, DB::table('imported_report_documents')->count());
        $this->assertSame(1, DB::table('resident_incident_reports')->count());
        $this->assertSame(1, DB::table('field_assessments')->where('assessment_status', 'draft')->count());
        $this->assertSame(1, DB::table('field_assessments')->where('assessment_status', 'submitted')->count());
        $this->assertSame(0, DB::table('buildings')->count());

        $unresolved = json_decode(DB::table('imported_report_documents')->where('import_key', 'MAPR-20261029-BOL-001')->value('unresolved_identifiers'), true);
        $this->assertContains('resident:CBMS22-RES-632-01', $unresolved);
        $this->assertContains('building:CBMS22-BLDG-632', $unresolved);
        $this->assertNull(DB::table('imported_report_documents')->where('import_key', 'MAPR-20261029-BOL-001')->value('resolved_building_id'));

        $simulated = DB::table('report_signatures')->where('document_reference', 'MAPR-20261029-BON-004')->first();
        $this->assertSame('simulated_demo', $simulated->mechanism);
        $this->assertStringContainsString('simulated', $simulated->signature_statement);
        $this->assertSame(0, DB::table('report_signatures')->where('mechanism', 'authenticated_e_signature')->where('document_reference', 'MAPR-20261029-BON-004')->count());

        $mark = User::query()->where('email', 'mark.merillo@mapr.test')->first();
        $summary = $this->actingAs($mark)->getJson('/rdana/api/summary')->assertOk();
        $cards = collect($summary->json('cards'))->keyBy('key');
        $this->assertSame(1, $cards['drafts']['count']);
        $this->assertSame(1, $cards['submitted']['count']);
        $this->assertGreaterThanOrEqual(1, $cards['review']['count']);

        $luis = User::query()->where('email', 'luis.maquiling@mapr.test')->first();
        $this->actingAs($luis)->get('/bdrrm/api/documents/MAPR-20261029-TEL-002')->assertForbidden();
        $this->actingAs($mark)->get('/rdana/api/documents/MAPR-20261029-MAB-003')->assertOk();

        $assessment = DB::table('field_assessments')->where('assessment_status', 'draft')->value('id');
        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/approve', [
            'confirmed' => true,
            'password' => 'already-set',
        ])->assertForbidden();

        $lester = User::query()->where('email', 'lestertalan@mapr_admin.com')->first();
        $consolidated = DB::table('consolidated_reports')->value('id');
        $this->actingAs($lester)->postJson('/cdrrmo/api/consolidated/'.$consolidated.'/approve', [
            'confirmed' => true,
            'password' => 'lester-existing',
        ])->assertForbidden();

        $this->actingAs($mark)->post('/rdana/api/signature', [
            'signature' => UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('user_signature_assets')->count());

        $this->actingAs($mark)->post('/rdana/api/signature', [
            'signature' => UploadedFile::fake()->image('mark.png', 40, 40),
        ])->assertOk();
        $assetId = DB::table('user_signature_assets')->where('is_current', true)->value('id');
        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/verify', [
            'confirmed' => true,
            'password' => 'already-set',
        ])->assertStatus(422);
        DB::table('field_assessments')->where('id', $assessment)->update(['findings' => 'Waterline checked.']);
        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/verify', [
            'confirmed' => true,
            'password' => 'already-set',
        ])->assertOk();
        $this->assertSame((int) $assetId, (int) DB::table('report_signatures')->where('signature_role', 'verified_by')->value('user_signature_asset_id'));
        $this->actingAs($mark)->delete('/rdana/api/signature')->assertOk();
        $this->assertSame((int) $assetId, (int) DB::table('report_signatures')->where('signature_role', 'verified_by')->value('user_signature_asset_id'));
        $this->assertSame(0, DB::table('user_signature_assets')->where('is_current', true)->count());
    }

    public function test_production_refuses_the_document_seeder(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        (new MaprDocumentWorkflowSeeder)->run();
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

        $this->seed(RoleSeeder::class);
        User::query()->create([
            'first_name' => 'Mark',
            'middle_name' => 'L.',
            'last_name' => 'Merillo',
            'email' => 'mark.merillo@mapr.test',
            'password' => 'already-set',
            'job_title' => 'Administrative Officer III',
            'account_status' => 'active',
            'role_id' => DB::table('roles')->where('code', 'rdana')->value('id'),
        ]);
        User::query()->create([
            'first_name' => 'Lester',
            'last_name' => 'Talan',
            'email' => 'lestertalan@mapr_admin.com',
            'password' => 'lester-existing',
            'account_status' => 'active',
            'role_id' => DB::table('roles')->where('code', 'cdrrmo_admin')->value('id'),
        ]);
    }
}
