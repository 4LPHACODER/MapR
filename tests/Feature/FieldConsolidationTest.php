<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class FieldConsolidationTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createTables();
        config([
            'mapr.field_consolidation_barangays' => ['Mabua', 'Telaje', 'Bungtod', 'Bag-ong Lungsod'],
            'mapr.assessor_approver_email' => '',
        ]);
    }

    public function test_city_review_marks_assessment_ready_for_consolidation_not_cdrrmo(): void
    {
        [$barangay, $assessor, $officer, $city, $assessmentId] = $this->reviewedAssessment();

        $this->signForward($city, $assessmentId)->assertOk();

        $this->assertSame('ready_for_consolidation', DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage'));
        $listed = $this->actingAs($this->user('cdrrmo_admin', 'City Admin'))->getJson('/cdrrmo/api/reports/field')->assertOk()->json('data');
        $this->assertNotContains(
            DB::table('field_assessments')->where('id', $assessmentId)->value('assessment_reference'),
            collect($listed)->pluck('reference')->all(),
        );
    }

    public function test_dashboard_and_package_submit_forward_to_cdrrmo(): void
    {
        [$barangay, $assessor, $officer, $city, $assessmentId] = $this->reviewedAssessment();
        $this->signForward($city, $assessmentId)->assertOk();

        $dash = $this->actingAs($city)->getJson('/rdana/api/field-consolidation')->assertOk()->json('data');
        $this->assertSame(1, $dash['kpis']['reviewed_reports']);
        $this->assertTrue($dash['can_submit']);
        $mabua = collect($dash['barangays'])->firstWhere('barangay_name', 'Mabua');
        $this->assertSame(1, $mabua['report_count']);

        $preview = $this->actingAs($city)->postJson('/rdana/api/field-consolidation/submit/preview', [
            'scope' => 'barangay',
            'barangay_id' => $barangay,
        ])->assertOk()->json('data');
        $this->assertSame(1, $preview['assessment_count']);

        $submit = $this->actingAs($city)->postJson('/rdana/api/field-consolidation/submit', [
            'scope' => 'barangay',
            'barangay_id' => $barangay,
            'confirmed' => 1,
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($submit['package_reference']);
        $this->assertSame('forwarded_cdrrmo', DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage'));
        $this->assertDatabaseHas('field_report_packages', [
            'package_reference' => $submit['package_reference'],
            'package_status' => 'submitted',
        ]);

        $packages = $this->actingAs($this->user('cdrrmo_admin', 'Package Admin'))
            ->getJson('/cdrrmo/api/reports/field-packages')
            ->assertOk()
            ->json('data');
        $this->assertContains($submit['package_reference'], collect($packages)->pluck('reference')->all());

        $listed = $this->actingAs($this->user('cdrrmo_admin', 'Field Admin'))->getJson('/cdrrmo/api/reports/field')->assertOk()->json('data');
        $this->assertContains(
            DB::table('field_assessments')->where('id', $assessmentId)->value('assessment_reference'),
            collect($listed)->pluck('reference')->all(),
        );
    }

    public function test_excel_export_downloads_workbook(): void
    {
        [$barangay, $assessor, $officer, $city, $assessmentId] = $this->reviewedAssessment();
        $this->signForward($city, $assessmentId)->assertOk();

        $response = $this->actingAs($city)->get('/rdana/api/field-consolidation/export?scope=consolidated');
        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            'MapR_Consolidated_Field_Assessment_Reports.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertDatabaseCount('field_report_exports', 1);
        $diskPath = (string) DB::table('field_report_exports')->value('disk_path');
        $this->assertNotSame('', $diskPath);
        $absolute = Storage::disk('local')->path($diskPath);
        $this->assertFileExists($absolute);
        $this->assertGreaterThan(200, filesize($absolute));
    }

    public function test_bungtod_alias_resolves_bongtud_registry_name(): void
    {
        DB::table('barangays')->where('barangay_name', 'Bungtod')->delete();
        $bongtudId = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819005',
            'barangay_name' => 'Bongtud',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config([
            'mapr.field_consolidation_barangays' => ['Bungtod'],
            'mapr.field_consolidation_barangay_aliases' => [
                'Bungtod' => ['Bongtud', 'Bongtod', '166819005'],
            ],
        ]);

        $city = $this->user('rdana_city_assessor', 'City Assessor');
        $dash = $this->actingAs($city)->getJson('/rdana/api/field-consolidation')->assertOk()->json('data');
        $bungtod = collect($dash['barangays'])->firstWhere('barangay_name', 'Bungtod');

        $this->assertNotNull($bungtod);
        $this->assertSame($bongtudId, $bungtod['barangay_id']);
        $this->assertSame('No Reviewed Reports', $bungtod['consolidation_status_label']);
    }

    private function signForward(User $user, int $assessmentId)
    {
        return $this->actingAs($user)->post('/rdana/api/assessments/'.$assessmentId.'/forward', [
            'confirmed' => true,
            'password' => 'secret-pass',
            'signature' => UploadedFile::fake()->image('signature.png', 80, 40),
        ]);
    }

    /**
     * @return array{0:int,1:User,2:User,3:User,4:int}
     */
    private function reviewedAssessment(): array
    {
        $barangay = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([['166819001', 'Bag-ong Lungsod'], ['166819002', 'Bungtod'], ['166819003', 'Telaje']] as [$code, $name]) {
            DB::table('barangays')->insert([
                'barangay_code' => $code,
                'barangay_name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $assessor = $this->member('rdana', 'Olver Ravelo', $barangay);
        $officer = $this->member('rdana_admin_officer', 'Mark Merillo', $barangay);
        $city = $this->member('rdana_city_assessor', 'Lovy Dedicatoria', $barangay);

        $incident = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'DI-1',
            'incident_name' => 'Flood',
            'incident_status' => 'active',
            'disaster_type_id' => DB::table('disaster_types')->insertGetId(['name' => 'Flood', 'created_at' => now(), 'updated_at' => now()]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-CONS',
            'report_description' => 'Flood damage',
            'report_status' => 'review',
            'barangay_id' => $barangay,
            'disaster_incident_id' => $incident,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assessmentId = DB::table('field_assessments')->insertGetId([
            'assessment_reference' => 'FA-CONS',
            'assessment_status' => 'draft',
            'workflow_stage' => 'pending_city_assessor',
            'barangay_report_id' => $report,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('building_damage_cost_assessments')->insert([
            'barangay_report_id' => $report,
            'assessment_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([['assessed_by', $assessor], ['verified_by', $officer]] as [$role, $user]) {
            DB::table('report_signatures')->insert([
                'signature_reference' => 'sig-'.$role,
                'document_reference' => 'FA-CONS',
                'document_hash' => hash('sha256', $role),
                'mechanism' => 'authenticated_e_signature',
                'signature_role' => $role,
                'signed_at' => now(),
                'verification_status' => 'verified',
                'signer_user_id' => $user->id,
                'field_assessment_id' => $assessmentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$barangay, $assessor, $officer, $city, $assessmentId];
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

    private function createTables(): void
    {
        $create = function (string $table, callable $callback): void {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $callback);
            }
        };

        $create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status');
            $table->date('incident_date')->nullable();
            $table->unsignedBigInteger('disaster_type_id');
            $table->timestamps();
        });
        $create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status');
            $table->string('workflow_stage')->nullable();
            $table->unsignedBigInteger('disaster_incident_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
        $create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference');
            $table->string('assessment_status');
            $table->string('workflow_stage')->nullable();
            $table->text('findings')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
        $create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->string('document_hash', 64);
            $table->string('mechanism');
            $table->string('signature_role');
            $table->timestamp('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('user_signature_asset_id')->nullable();
            $table->timestamps();
        });
        $create('user_signature_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('storage_path');
            $table->string('mime_type', 64)->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });
        $create('building_damage_cost_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('confirmed_damage_class_id')->nullable();
            $table->string('assessment_status');
            $table->decimal('estimated_cost_php', 14, 2)->nullable();
            $table->timestamps();
        });
        $create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->timestamps();
        });
        $create('field_report_packages', function (Blueprint $table) {
            $table->id();
            $table->string('package_reference')->unique();
            $table->string('scope');
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->string('package_status')->default('generated');
            $table->string('destination')->default('cdrrmo');
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->json('meta')->nullable();
            $table->string('snapshot_hash')->nullable();
            $table->timestamps();
        });
        $create('field_report_package_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_report_package_id');
            $table->unsignedBigInteger('field_assessment_id');
            $table->string('assessment_reference')->nullable();
            $table->timestamps();
        });
        $create('field_report_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_report_package_id')->nullable();
            $table->string('scope');
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->string('filename');
            $table->string('disk_path');
            $table->string('snapshot_hash')->nullable();
            $table->json('assessment_ids')->nullable();
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }
}
