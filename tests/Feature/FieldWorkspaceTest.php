<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\AssessorStudyBarangayAssignment;
use App\Services\PolygonSpatial;
use App\Support\DamageClassColor;
use Database\Seeders\AssessorDemoUserSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class FieldWorkspaceTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createReportTables();
    }

    public function test_mark_is_seeded_once_and_opens_only_the_assessor_workspace(): void
    {
        $this->seed(AssessorDemoUserSeeder::class);
        $mark = User::query()->where('email', 'mark.merillo@mapr.test')->firstOrFail();
        $this->assertSame('Administrative Officer III', $mark->job_title);
        $this->assertSame('rdana', $mark->role->code);
        $this->assertNull($mark->barangay_id);
        $this->assertSame(0, DB::table('user_barangay_assignments')->where('user_id', $mark->id)->count());

        $mark->forceFill(['password' => Hash::make('already-set')])->save();
        $this->seed(AssessorDemoUserSeeder::class);
        $this->assertTrue(Hash::check('already-set', $mark->fresh()->password));
        $this->assertSame(1, User::withTrashed()->where('email', 'mark.merillo@mapr.test')->count());

        $this->actingAs($mark)->get('/rdana')->assertOk()->assertSee('mark.merillo@mapr.test');
        $this->actingAs($mark)->get('/cdrrmo')->assertForbidden();
        $this->actingAs($mark)->get('/bdrrm')->assertForbidden();
    }

    public function test_bdrrm_cannot_open_another_barangay_or_a_resident_draft(): void
    {
        [$home, $other] = $this->twoBarangays();
        $officer = $this->officer($home);
        $resident = $this->user('resident', 'Resident One');
        $draft = $this->residentReport($resident->id, $home, 'draft');
        $shared = $this->residentReport($resident->id, $home, 'submitted');
        $foreign = $this->residentReport($resident->id, $other, 'submitted');

        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$draft)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$shared)->assertOk();
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$foreign)->assertForbidden();
        $this->actingAs($officer)->postJson('/bdrrm/api/reports', [
            'disaster_incident_id' => $this->incident($other),
            'barangay_id' => $other,
            'report_description' => 'Outside the assignment',
        ])->assertForbidden();
    }

    public function test_validation_keeps_the_draft_and_a_second_submit_conflicts(): void
    {
        $barangay = $this->barangay('166819002', 'Bag-ong Lungsod');
        $officer = $this->officer($barangay, 'Hon. Luis', 'F.', 'Maquiling');
        $incident = $this->incident($barangay);
        $building = $this->building($barangay);

        $created = $this->actingAs($officer)->postJson('/bdrrm/api/reports', [
            'disaster_incident_id' => $incident,
            'barangay_id' => $barangay,
            'building_id' => $building,
            'report_description' => 'Water reached the doorway.',
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame(1, DB::table('barangay_reports')->count());

        $this->actingAs($officer)->postJson('/bdrrm/api/reports', [
            'barangay_id' => $barangay,
        ])->assertStatus(422);
        $this->assertSame(1, DB::table('barangay_reports')->count());
        $this->assertSame('Water reached the doorway.', DB::table('barangay_reports')->where('id', $id)->value('report_description'));

        $this->actingAs($officer)->postJson('/bdrrm/api/reports/'.$id.'/photos', [
            'photo' => UploadedFile::fake()->create('notes.txt', 12, 'text/plain'),
        ])->assertStatus(422);

        $this->actingAs($officer)->postJson('/bdrrm/api/reports/'.$id.'/photos', [
            'photo' => UploadedFile::fake()->image('door.jpg'),
        ])->assertCreated();

        $this->actingAs($officer)->postJson('/bdrrm/api/reports/'.$id.'/classify', [
            'incident_photo_id' => DB::table('incident_photos')->value('id'),
            'building_id' => $building,
        ])->assertStatus(422)->assertJsonPath('classification', 'unavailable');
        $this->assertSame(0, DB::table('building_damage_predictions')->count());

        $this->actingAs($officer)->postJson('/bdrrm/api/reports/'.$id.'/submit')->assertStatus(422);
        $this->assertSame('draft', DB::table('barangay_reports')->where('id', $id)->value('report_status'));

        $this->actingAs($officer)->postJson('/bdrrm/api/reports/'.$id.'/sign', [
            'confirmed' => true,
            'password' => 'secret-pass',
        ])->assertOk();
        $this->actingAs($officer)->postJson('/bdrrm/api/reports/'.$id.'/submit')->assertOk();
        $this->actingAs($officer)->postJson('/bdrrm/api/reports/'.$id.'/submit')->assertStatus(409);
        $this->assertSame(1, DB::table('barangay_reports')->count());
    }

    public function test_mark_can_verify_but_cannot_approve_or_open_another_assessment(): void
    {
        $this->seed(AssessorDemoUserSeeder::class);
        $mark = User::query()->where('email', 'mark.merillo@mapr.test')->firstOrFail();
        $other = $this->user('rdana', 'Other Assessor');
        $barangay = $this->barangay('166819002', 'Bag-ong Lungsod');
        $report = $this->barangayReport($barangay, 'review');
        $assessment = $this->assessment($report, $mark->id, 'draft', 'Waterline is above the outlet.');
        $foreign = $this->assessment($report, $other->id, 'draft', 'Different assignment.');

        $this->actingAs($mark)->getJson('/rdana/api/assessments/'.$foreign)->assertForbidden();
        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/verify', [
            'confirmed' => true,
            'password' => 'testing-only-assessor-password',
        ])->assertOk();
        $this->assertDatabaseHas('report_signatures', [
            'field_assessment_id' => $assessment,
            'signature_role' => 'verified_by',
            'signer_user_id' => $mark->id,
        ]);
        $this->assertDatabaseMissing('report_signatures', [
            'field_assessment_id' => $assessment,
            'signature_role' => 'approved_by',
        ]);

        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/submit')->assertStatus(422);
        $this->assertSame('signed', DB::table('field_assessments')->where('id', $assessment)->value('assessment_status'));

        DB::table('field_assessments')->where('id', $assessment)->update(['assessment_status' => 'submitted']);
        $admin = $this->user('cdrrmo_admin', 'CDRRMO Admin');
        $this->actingAs($admin)->getJson('/cdrrmo/api/reports/field')
            ->assertOk()
            ->assertJsonFragment(['assessment_reference' => DB::table('field_assessments')->where('id', $assessment)->value('assessment_reference')]);
    }

    public function test_assessor_registry_covers_the_four_study_barangays_without_widening_other_roles(): void
    {
        $codes = [
            '166819002' => 'Bag-ong Lungsod',
            '166819022' => 'Telaje',
            '166819008' => 'Mabua',
            '166819005' => 'Bongtud',
        ];
        $barangays = [];
        foreach ($codes as $code => $name) {
            $barangays[$code] = $this->barangay($code, $name);
        }
        $outside = $this->barangay('166819099', 'Outside');
        $this->seed(AssessorDemoUserSeeder::class);
        $mark = User::query()->where('email', 'mark.merillo@mapr.test')->firstOrFail();
        $inactive = $this->user('rdana', 'Inactive Assessor');
        $inactive->forceFill(['account_status' => 'inactive'])->save();
        $assignment = app(AssessorStudyBarangayAssignment::class);
        $assignment->assignActiveAssessors();
        $again = $assignment->assignActiveAssessors();

        $this->assertSame(4, DB::table('user_barangay_assignments')->where('user_id', $mark->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, $again['assigned']);
        $this->assertSame(0, DB::table('user_barangay_assignments')->where('user_id', $inactive->id)->count());
        $mark->forceFill(['password' => Hash::make('already-set')])->save();
        $assignment->assignActiveAssessors();
        $this->assertTrue(Hash::check('already-set', $mark->fresh()->password));
        $this->assertSame(4, DB::table('user_barangay_assignments')->where('user_id', $mark->id)->count());

        $references = [];
        foreach ($barangays as $code => $barangayId) {
            $references[$code] = $this->registryResident($barangayId, (string) $code === '166819002');
        }
        $hidden = $this->registryResident($outside, false);

        $listed = $this->actingAs($mark)->getJson('/rdana/api/residents?per_page=50')->assertOk();
        $found = collect($listed->json('data'))->pluck('registry_reference');
        foreach ($references as $reference) {
            $this->assertTrue($found->contains($reference));
        }
        $this->assertFalse($found->contains($hidden));
        $unmapped = collect($listed->json('data'))->firstWhere('registry_reference', $references['166819022']);
        $this->assertFalse($unmapped['map_available']);
        $this->assertNotSame('', (string) $unmapped['map_limitation']);
        $this->assertSame(0, $unmapped['report_count']);

        $this->actingAs($mark)->getJson('/rdana/api/residents/summary')
            ->assertOk()
            ->assertJsonPath('registered_residents', 4)
            ->assertJsonPath('total_household_size', 4)
            ->assertJsonPath('household_size_partial', true)
            ->assertJsonPath('incident_required', true);
        $this->actingAs($mark)->getJson('/rdana/api/residents/'.$this->residentId($hidden))->assertForbidden();
        $this->actingAs($mark)->getJson('/rdana/api/dashboard')
            ->assertOk()
            ->assertJsonCount(4, 'overview');

        $home = $barangays['166819002'];
        $officer = $this->officer($home);
        $assignment->assignActiveAssessors();
        $this->assertSame(1, DB::table('user_barangay_assignments')->where('user_id', $officer->id)->whereNull('revoked_at')->count());
        $this->actingAs($officer)->getJson('/bdrrm/api/summary')
            ->assertOk()
            ->assertJsonCount(1, 'barangays')
            ->assertJsonPath('barangays.0.name', 'Bag-ong Lungsod');

        $report = $this->barangayReport($home, 'review');
        $assessment = $this->assessment($report, $mark->id, 'draft', 'Field note.');
        $before = DB::table('field_assessments')->count();
        $assignment->assignActiveAssessors();
        $this->assertSame($before, DB::table('field_assessments')->count());
        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/approve', [
            'confirmed' => true,
            'password' => 'already-set',
        ])->assertForbidden();
        $this->assertDatabaseMissing('report_signatures', [
            'field_assessment_id' => $assessment,
            'signature_role' => 'approved_by',
        ]);
    }


    public function test_open_flag_blocks_verification_until_a_reviewer_resolves_it(): void
    {
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
        $this->seed(AssessorDemoUserSeeder::class);
        $mark = User::query()->where('email', 'mark.merillo@mapr.test')->firstOrFail();
        $barangay = $this->barangay('166819002', 'Bag-ong Lungsod');
        $report = $this->barangayReport($barangay, 'review');
        $assessment = $this->assessment($report, $mark->id, 'draft', 'Waterline is above the outlet.');

        $this->actingAs($mark)->postJson('/rdana/api/flags', [
            'subject_type' => 'field_assessment',
            'subject_id' => $assessment,
            'category' => 'Evidence',
            'reason' => 'The source photograph does not show the wall.',
        ])->assertCreated();
        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/verify', [
            'confirmed' => true,
            'password' => 'testing-only-assessor-password',
        ])->assertStatus(422);
        $flagId = DB::table('report_flags')->value('id');
        $this->actingAs($mark)->postJson('/rdana/api/flags/'.$flagId.'/resolve', [
            'resolution' => 'A replacement photograph was checked.',
        ])->assertOk();
        $this->actingAs($mark)->postJson('/rdana/api/assessments/'.$assessment.'/verify', [
            'confirmed' => true,
            'password' => 'testing-only-assessor-password',
        ])->assertOk();
        $this->assertSame('resolved', DB::table('report_flags')->value('status'));
    }

    public function test_cdrrmo_report_list_keeps_submitted_rows_out_of_the_open_badge(): void
    {
        $admin = $this->user('cdrrmo_admin', 'CDRRMO Admin');
        [$home, $other] = $this->twoBarangays();
        $open = $this->barangayReport($home, 'review');
        $closed = $this->barangayReport($other, 'submitted');

        $this->actingAs($admin)->getJson('/cdrrmo/api/nav-badges')
            ->assertOk()
            ->assertJsonPath('barangay_reports', 1)
            ->assertJsonPath('badge_meaning', 'Drafts, returned reports, and reports awaiting review. Submitted, signed, and finalized reports stay in the list and are not included.');

        $list = $this->actingAs($admin)->getJson('/cdrrmo/api/reports/barangay')->assertOk();
        $list->assertJsonPath('summary.total', 2);
        $list->assertJsonPath('summary.finalized', 1);
        $list->assertJsonPath('meta.total', 2);
        $submitted = collect($list->json('data'))->firstWhere('id', $closed);
        $this->assertSame('Awaiting signature', $submitted['signature_label']);
        $this->assertNotSame('Signed', $submitted['signature_label']);

        $this->actingAs($admin)->getJson('/cdrrmo/api/reports/barangay?workflow_status=submitted')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/cdrrmo/api/reports/barangay/'.$open)
            ->assertOk()
            ->assertJsonPath('data.can_edit', false);
    }

    public function test_map_selection_keeps_household_size_separate_from_the_roster_and_colors_damage(): void
    {
        [$home, $other] = $this->twoBarangays();
        $officer = $this->officer($home);
        $building = $this->building($home);
        $foreign = $this->building($other);
        $household = DB::table('households')->insertGetId([
            'household_reference' => 'HH-1',
            'household_status' => 'active',
            'barangay_id' => $home,
            'building_id' => $building,
            'source_household_size' => 5,
            'source_nuclear_families' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('residents')->insert([
            'registry_reference' => 'R-1',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'residency_status' => 'active',
            'household_id' => $household,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$foreign)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$building)
            ->assertOk()
            ->assertJsonPath('reference', DB::table('buildings')->where('id', $building)->value('building_reference'))
            ->assertJsonPath('resident_count', 1);

        $this->actingAs($officer)->getJson('/bdrrm/api/households/'.$household)
            ->assertOk()
            ->assertJsonPath('source_household_size', 5)
            ->assertJsonPath('linked_resident_count', 1)
            ->assertJsonPath('family_count_label', '2');

        $this->assertSame('#dc2626', DamageClassColor::hex('Totally damaged'));
        $this->assertSame('#eab308', DamageClassColor::hex('Partially damaged'));
        $this->assertSame('#2563eb', DamageClassColor::hex('Not affected'));
        $this->assertSame('#64748b', DamageClassColor::hex(null));

        $spatial = app(PolygonSpatial::class);
        $this->assertSame('ST_Within', $spatial->spatialPredicate('strict_interior'));
        $this->assertSame('ST_Intersects', $spatial->spatialPredicate('includes_boundary'));
    }

    private function createReportTables(): void
    {
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status')->default('active');
            $table->dateTime('occurred_at')->nullable();
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
            $table->string('location_source')->nullable();
            $table->string('location_verification_status')->nullable();
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference');
            $table->string('household_status')->default('active');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedInteger('source_household_size')->nullable();
            $table->unsignedInteger('source_nuclear_families')->nullable();
            $table->timestamps();
        });
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('registry_reference');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('residency_status')->default('active');
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Schema::create('resident_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->string('report_status');
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
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
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
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
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('architecture');
            $table->string('optimizer');
            $table->string('deployment_status');
            $table->timestamps();
        });
        Schema::create('building_damage_predictions', function (Blueprint $table) {
            $table->id();
            $table->string('prediction_status');
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('incident_photo_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('ai_model_id');
            $table->unsignedBigInteger('predicted_damage_class_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->string('document_hash', 64);
            $table->string('mechanism');
            $table->string('signature_role');
            $table->dateTime('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('consolidated_report_id')->nullable();
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
    }

    private function user(string $role, string $name): User
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
            'email' => strtolower(str_replace(' ', '.', $name)).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
        ]);
    }

    private function officer(int $barangayId, string $first = 'Barangay', string $middle = 'Officer', string $last = 'Demo'): User
    {
        $roleId = DB::table('roles')->where('code', 'bdrrm')->value('id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'BDRRM',
                'code' => 'bdrrm',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $user = User::query()->create([
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'email' => strtolower($first.$last.$barangayId).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
        ]);
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

    private function twoBarangays(): array
    {
        return [
            $this->barangay('166819002', 'Bag-ong Lungsod'),
            $this->barangay('166819022', 'Telaje'),
        ];
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

    private function incident(int $barangayId): int
    {
        $id = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-'.$barangayId,
            'incident_name' => 'Flood '.$barangayId,
            'incident_status' => 'active',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('disaster_barangays')->insert([
            'disaster_incident_id' => $id,
            'barangay_id' => $barangayId,
        ]);

        return $id;
    }

    private function building(int $barangayId): int
    {
        return DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-'.$barangayId.'-'.random_int(10, 99),
            'barangay_id' => $barangayId,
            'location_source' => 'source',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function registryResident(int $barangayId, bool $withSize): string
    {
        $reference = 'RES-'.$barangayId.'-'.random_int(100, 999);
        $householdId = DB::table('households')->insertGetId([
            'household_reference' => 'HH-'.$reference,
            'household_status' => 'active',
            'barangay_id' => $barangayId,
            'building_id' => null,
            'source_household_size' => $withSize ? 4 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('residents')->insert([
            'registry_reference' => $reference,
            'first_name' => 'Resident',
            'last_name' => (string) $barangayId,
            'residency_status' => 'active',
            'household_id' => $householdId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $reference;
    }

    private function residentId(string $reference): int
    {
        return (int) DB::table('residents')->where('registry_reference', $reference)->value('id');
    }

    private function residentReport(int $userId, int $barangayId, string $status): int
    {
        return DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-'.$status.'-'.$barangayId.'-'.random_int(100, 999),
            'report_status' => $status,
            'resident_user_id' => $userId,
            'disaster_incident_id' => $this->incident($barangayId),
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function barangayReport(int $barangayId, string $status): int
    {
        return DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-'.$barangayId,
            'report_description' => 'Initial report',
            'report_status' => $status,
            'disaster_incident_id' => $this->incident($barangayId),
            'barangay_id' => $barangayId,
            'prepared_by' => $this->user('bdrrm', 'Preparer '.$barangayId)->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assessment(int $reportId, int $assessorId, string $status, string $findings): int
    {
        return DB::table('field_assessments')->insertGetId([
            'assessment_reference' => 'FA-'.$assessorId.'-'.$reportId,
            'findings' => $findings,
            'assessment_status' => $status,
            'barangay_report_id' => $reportId,
            'assigned_assessor_id' => $assessorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
