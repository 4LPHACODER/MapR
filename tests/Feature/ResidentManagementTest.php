<?php

namespace Tests\Feature;

use App\Models\Registry\Barangay;
use App\Models\Registry\Building;
use App\Models\Registry\Household;
use App\Models\Registry\Resident;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ResidentManagementTest extends TestCase
{
    use CreatesMaprAuthSchema;

    private User $admin;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createResidentTables();
        $this->barangay = Barangay::query()->create([
            'barangay_code' => 'BL',
            'barangay_name' => 'Bag-ong Lungsod',
        ]);
        $this->admin = $this->makeUser('cdrrmo_admin');
    }

    public function test_resident_can_be_created_shown_corrected_archived_and_restored(): void
    {
        $household = $this->household('HH-1');

        $created = $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents', [
            'first_name' => 'Quincya',
            'last_name' => 'Holdfast',
            'household_id' => $household->id,
            'record_source' => 'Authorized roster',
        ])->assertCreated()
            ->assertJsonPath('record_source', 'Authorized roster')
            ->json();

        $this->assertStringStartsWith('MAPR-RES-', $created['registry_reference']);

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents', [
            'first_name' => 'Quincya',
            'last_name' => 'Holdfast',
            'household_id' => $household->id,
            'record_source' => 'Authorized roster',
        ])->assertStatus(422)->assertJsonStructure(['matches']);

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents', [
            'first_name' => 'Quincya',
            'last_name' => 'Holdfast',
            'household_id' => $household->id,
            'record_source' => 'Authorized roster',
            'confirm_duplicate' => true,
        ])->assertCreated();

        $shown = $this->actingAs($this->admin)
            ->getJson('/cdrrmo/api/residents/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('display_name', 'Quincya Holdfast')
            ->assertJsonPath('household.members.0.current', true)
            ->json();

        $this->travel(2)->seconds();
        $updated = $this->actingAs($this->admin)->patchJson('/cdrrmo/api/residents/'.$created['id'], [
            'updated_at' => $shown['updated_at'],
            'reason' => 'Spelling correction',
            'first_name' => 'Renamed',
            'last_name' => 'Holdfast',
        ])->assertOk()->json();

        $summary = DB::table('activity_events')->where('subject_id', $created['id'])->where('action_type', 'resident_updated')->value('summary');
        $this->assertStringNotContainsString('Quincya', (string) $summary);
        $this->assertStringNotContainsString('Renamed', (string) $summary);
        $this->assertStringContainsString('first_name', (string) $summary);
        $this->assertSame('Authorized roster', $updated['record_source']);

        $this->actingAs($this->admin)->patchJson('/cdrrmo/api/residents/'.$created['id'], [
            'updated_at' => $shown['updated_at'],
            'reason' => 'Stale correction',
            'last_name' => 'Other',
        ])->assertStatus(409);

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents/'.$created['id'].'/archive', [
            'confirm_reference' => 'WRONG',
        ])->assertStatus(422);

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents/'.$created['id'].'/archive', [
            'confirm_reference' => $created['registry_reference'],
        ])->assertOk();

        $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents')
            ->assertOk()
            ->assertJsonMissing(['registry_reference' => $created['registry_reference']]);

        $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents?status=inactive')
            ->assertOk()
            ->assertJsonFragment(['registry_reference' => $created['registry_reference']]);

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents/'.$created['id'].'/restore')->assertOk();

        $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents')
            ->assertOk()
            ->assertJsonFragment(['registry_reference' => $created['registry_reference'], 'residency_status' => 'active']);
    }

    public function test_inclusion_does_not_duplicate_an_active_row_or_affect_another_incident(): void
    {
        $resident = Resident::query()->create([
            'registry_reference' => 'CBMS22-RES-1-01',
            'first_name' => 'Rosa',
            'last_name' => 'Diaz',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $this->household('HH-2')->id,
        ]);
        $first = $this->incident('INC-1', 'Flood');
        $second = $this->incident('INC-2', 'Landslide');

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents/inclusions', [
            'incident_id' => $first,
            'resident_ids' => [$resident->id],
            'inclusion_basis' => 'manual_review',
        ])->assertOk()->assertJsonPath('added', 1);

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents/inclusions', [
            'incident_id' => $first,
            'resident_ids' => [$resident->id],
            'inclusion_basis' => 'manual_review',
        ])->assertOk()->assertJsonPath('already', 1);

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents/inclusions', [
            'incident_id' => $second,
            'resident_ids' => [$resident->id],
            'inclusion_basis' => 'manual_review',
        ])->assertOk();

        $this->assertSame(1, DB::table('incident_resident_inclusions')->where('disaster_incident_id', $first)->count());

        $this->actingAs($this->admin)->postJson('/cdrrmo/api/residents/inclusions/remove', [
            'incident_id' => $second,
            'resident_ids' => [$resident->id],
        ])->assertOk()->assertJsonPath('removed', 1);

        $this->assertSame('active', DB::table('incident_resident_inclusions')->where('disaster_incident_id', $first)->value('status'));
        $this->assertSame('removed', DB::table('incident_resident_inclusions')->where('disaster_incident_id', $second)->value('status'));
        $this->assertFalse(Schema::hasTable('impact_estimate_residents') && DB::table('impact_estimate_residents')->exists());
    }

    public function test_summary_ignores_the_current_page_and_does_not_invent_an_affected_count(): void
    {
        $complete = $this->household('HH-3', 8);
        $review = $this->household('HH-4');
        Resident::query()->create([
            'registry_reference' => 'MAPR-RES-TEST-1',
            'first_name' => 'Lina',
            'last_name' => 'Cruz',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $complete->id,
        ]);
        Resident::query()->create([
            'registry_reference' => 'MAPR-RES-TEST-2',
            'first_name' => 'Marco',
            'last_name' => 'Cruz',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $complete->id,
        ]);
        Resident::query()->create([
            'registry_reference' => 'MAPR-RES-TEST-3',
            'first_name' => '#NAME?',
            'last_name' => 'Cruz',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $review->id,
        ]);

        $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents/summary')
            ->assertOk()
            ->assertJsonPath('registered_residents', 3)
            ->assertJsonPath('households_represented', 2)
            ->assertJsonPath('potentially_affected', null)
            ->assertJsonPath('incident_required', true);

        $listed = $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents?per_page=10');
        $listed->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->assertArrayNotHasKey('needs_review', $listed->json('data.0'));
        $this->assertArrayNotHasKey('record_quality', $listed->json('data.0'));
        $listed->assertJsonMissing(['record_quality' => 'Needs review']);

        $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_related_reports_do_not_match_by_barangay_or_name_alone(): void
    {
        $buildingA = Building::query()->create([
            'building_reference' => 'BLDG-A',
            'barangay_id' => $this->barangay->id,
        ]);
        $buildingB = Building::query()->create([
            'building_reference' => 'BLDG-B',
            'barangay_id' => $this->barangay->id,
        ]);
        $homeA = $this->household('HH-A', null, $buildingA->id);
        $homeB = $this->household('HH-B', null, $buildingB->id);
        $residentA = Resident::query()->create([
            'registry_reference' => 'CBMS22-RES-A',
            'first_name' => 'Same',
            'last_name' => 'Person',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $homeA->id,
        ]);
        $residentB = Resident::query()->create([
            'registry_reference' => 'CBMS22-RES-B',
            'first_name' => 'Same',
            'last_name' => 'Person',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $homeB->id,
        ]);
        $incident = $this->incident('INC-R', 'Storm');
        $unlinked = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BRGY-UNLINKED',
            'report_status' => 'draft',
            'disaster_incident_id' => $incident,
            'barangay_id' => $this->barangay->id,
            'prepared_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $linked = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BRGY-LINKED',
            'report_status' => 'draft',
            'disaster_incident_id' => $incident,
            'barangay_id' => $this->barangay->id,
            'prepared_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('barangay_report_buildings')->insert([
            'barangay_report_id' => $linked,
            'building_id' => $buildingA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('resident_incident_reports')->insert([
            'report_reference' => 'RES-OTHER',
            'report_status' => 'submitted',
            'submitted_at' => now(),
            'resident_user_id' => $this->admin->id,
            'disaster_incident_id' => $incident,
            'barangay_id' => $this->barangay->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotSame(0, $unlinked);

        $forA = $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents/'.$residentA->id.'/reports')->assertOk()->json();
        $forB = $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents/'.$residentB->id.'/reports')->assertOk()->json();

        $this->assertSame([], $forA['submitted_by_account']);
        $this->assertSame(['BRGY-LINKED'], array_column($forA['household_or_building'], 'report_reference'));
        $this->assertSame([], $forB['household_or_building']);
        $this->assertSame([], $forB['submitted_by_account']);
        $this->assertSame([], $forA['estimate_or_consolidated']);
    }

    public function test_report_availability_and_household_size_use_explicit_links(): void
    {
        $shared = $this->household('HH-SHARED', 5);
        $other = $this->household('HH-OTHER', 4);
        $missing = $this->household('HH-MISSING', null);
        $inSharedA = Resident::query()->create([
            'registry_reference' => 'RES-SIZE-A',
            'first_name' => 'Ana',
            'last_name' => 'Size',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $shared->id,
        ]);
        Resident::query()->create([
            'registry_reference' => 'RES-SIZE-B',
            'first_name' => 'Ben',
            'last_name' => 'Size',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $shared->id,
        ]);
        $reported = Resident::query()->create([
            'registry_reference' => 'RES-SIZE-C',
            'first_name' => 'Cara',
            'last_name' => 'Size',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $other->id,
        ]);
        Resident::query()->create([
            'registry_reference' => 'RES-SIZE-D',
            'first_name' => 'Dina',
            'last_name' => 'Size',
            'residency_status' => 'active',
            'record_source' => 'CBMS',
            'household_id' => $missing->id,
        ]);
        $incident = $this->incident('INC-SIZE', 'Flood');
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BRGY-SIZE',
            'report_status' => 'draft',
            'disaster_incident_id' => $incident,
            'barangay_id' => $this->barangay->id,
            'prepared_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('barangay_report_buildings')->insert([
            'barangay_report_id' => $report,
            'building_id' => $other->building_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents/summary')
            ->assertOk()
            ->assertJsonPath('total_household_size', 9)
            ->assertJsonPath('household_size_partial', true);

        $withReports = $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents?report_availability=with&per_page=50')->assertOk();
        $this->assertSame(['RES-SIZE-C'], array_column($withReports->json('data'), 'registry_reference'));
        $this->assertSame(1, $withReports->json('data.0.report_count'));

        $without = $this->actingAs($this->admin)->getJson('/cdrrmo/api/residents?report_availability=without&per_page=50')->assertOk();
        $references = array_column($without->json('data'), 'registry_reference');
        $this->assertContains('RES-SIZE-A', $references);
        $this->assertNotContains('RES-SIZE-C', $references);
        $this->assertNotSame(0, $inSharedA->id + $reported->id);
    }

    public function test_non_cdrrmo_roles_cannot_manage_residents(): void
    {
        $resident = $this->makeUser('resident');

        $this->actingAs($resident)->getJson('/cdrrmo/api/residents/summary')->assertForbidden();
        $this->actingAs($resident)->postJson('/cdrrmo/api/residents', [])->assertForbidden();
    }

    private function household(string $reference, ?int $sourceSize = null, ?int $buildingId = null): Household
    {
        $buildingId ??= Building::query()->create([
            'building_reference' => 'BLDG-'.$reference,
            'barangay_id' => $this->barangay->id,
        ])->id;

        return Household::query()->create([
            'household_reference' => $reference,
            'household_status' => 'active',
            'record_source' => 'CBMS',
            'building_id' => $buildingId,
            'barangay_id' => $this->barangay->id,
            'source_household_size' => $sourceSize,
        ]);
    }

    private function incident(string $reference, string $name): int
    {
        return DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => $reference,
            'incident_name' => $name,
            'occurred_at' => now(),
            'incident_status' => 'active',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createResidentTables(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference')->unique();
            $table->string('household_status')->default('active');
            $table->string('record_source');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedInteger('source_household_size')->nullable();
            $table->unsignedInteger('source_nuclear_families')->nullable();
            $table->timestamps();
        });
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('registry_reference')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('birth_date')->nullable();
            $table->string('sex')->nullable();
            $table->string('residency_status')->default('active');
            $table->string('record_source');
            $table->timestamp('record_verified_at')->nullable();
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->dateTime('occurred_at');
            $table->string('incident_status')->default('active');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('incident_resident_inclusions', function (Blueprint $table) {
            $table->id();
            $table->string('inclusion_basis');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->dateTime('removed_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('added_by');
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->timestamps();
            $table->unique(['disaster_incident_id', 'resident_id']);
        });
        Schema::create('incident_resident_inclusion_events', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('inclusion_id');
            $table->unsignedBigInteger('user_id');
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
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->string('report_status');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
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
            $table->string('report_status');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('account_resident_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('resident_id')->unique();
            $table->timestamps();
        });
    }

    private function makeUser(string $code): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'description' => $code],
        );

        return User::query()->create([
            'first_name' => 'Test',
            'last_name' => ucfirst($code),
            'email' => $code.'.'.uniqid().'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $role->id,
        ]);
    }
}
