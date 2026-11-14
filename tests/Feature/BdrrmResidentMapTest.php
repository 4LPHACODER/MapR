<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class BdrrmResidentMapTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createResidentMapTables();
    }

    public function test_markers_count_distinct_residents_and_ignore_unlinked_reports(): void
    {
        $home = $this->barangay('Mabua');
        $other = $this->barangay('Telaje');
        $officer = $this->officer($home);
        $flood = $this->incident('Flood');
        $fire = $this->incident('Fire');
        $building = $this->building($home, 'BLD-1');
        $plain = $this->building($home, 'BLD-2');
        $foreignBuilding = $this->building($other, 'BLD-OUT');
        $homeHousehold = $this->household($home, $building, 'HH-1');
        $otherHousehold = $this->household($home, $plain, 'HH-2');
        $inactiveHousehold = $this->household($home, $building, 'HH-OLD', 'inactive');
        $foreignHousehold = $this->household($other, $foreignBuilding, 'HH-OUT');
        $reporter = $this->person('Ana', 'Cruz');
        $siblingUser = $this->person('Ben', 'Cruz');
        $outsider = $this->person('Cara', 'Diaz');
        $ana = $this->resident($homeHousehold, 'Ana', 'Cruz', 'RES-ANA');
        $ben = $this->resident($homeHousehold, 'Ben', 'Cruz', 'RES-BEN');
        $noHome = $this->resident($otherHousehold, 'Dina', 'Rios', 'RES-DINA');
        $inactive = $this->resident($inactiveHousehold, 'Old', 'Cruz', 'RES-OLD', 'inactive');
        $foreign = $this->resident($foreignHousehold, 'Eva', 'Santos', 'RES-EVA');
        $this->link($reporter, $ana, $officer->id);
        $this->link($siblingUser, $ben, $officer->id);
        $this->link($outsider, $foreign, $officer->id);
        $this->report($reporter, $home, $flood, 'submitted', 'RIR-1');
        $this->report($reporter, $home, $fire, 'reviewed', 'RIR-2');
        $this->report($reporter, $home, $flood, 'draft', 'RIR-DRAFT');
        $this->report($reporter, $other, $flood, 'submitted', 'RIR-HIDDEN');
        $this->report($siblingUser, $home, $flood, 'returned', 'RIR-BEN');
        $this->report($outsider, $other, $flood, 'submitted', 'RIR-OUT');

        $list = $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-markers')->assertOk();
        $list->assertJsonPath('summary.authorized_total', 3);
        $list->assertJsonPath('summary.total', 3);
        $list->assertJsonPath('summary.with_reports', 2);
        $list->assertJsonPath('summary.without_reports', 1);
        $list->assertJsonPath('summary.without_location', 3);
        $list->assertJsonPath('summary.mapped_locations', 0);
        $list->assertJsonPath('summary.mapped_residents', 0);
        $list->assertJsonPath('features', []);
        $list->assertJsonPath('meta.returned', 0);
        $this->assertStringNotContainsString('Ana', $list->getContent());
        $this->assertStringNotContainsString('RES-ANA', $list->getContent());

        $withReports = $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-markers?reports=with')->assertOk();
        $withReports->assertJsonPath('summary.total', 2);
        $withReports->assertJsonPath('summary.with_reports', 2);
        $withReports->assertJsonPath('summary.without_reports', 0);
        $withReports->assertJsonPath('summary.authorized_total', 3);
        $withReports->assertJsonPath('summary.without_location', 2);

        $fireOnly = $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-markers?incident_id='.$fire)->assertOk();
        $fireOnly->assertJsonPath('summary.total', 3);
        $fireOnly->assertJsonPath('summary.with_reports', 1);
        $fireOnly->assertJsonPath('summary.without_reports', 2);

        $search = $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-markers?q=Rios')->assertOk();
        $search->assertJsonPath('summary.total', 1);
        $search->assertJsonPath('summary.with_reports', 0);
        $search->assertJsonPath('matches.0.registry_reference', 'RES-DINA');
        $search->assertJsonPath('matches.0.has_report', false);
        $search->assertJsonMissingPath('matches.0.sex');
        $search->assertJsonMissingPath('matches.0.birth_date');

        $group = $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-groups/b'.$building)->assertOk();
        $group->assertJsonPath('map_available', false);
        $group->assertJsonPath('location_status', 'unverified');
        $badges = collect($group->json('residents'))->keyBy('registry_reference');
        $this->assertTrue($badges['RES-ANA']['has_report']);
        $this->assertSame(2, $badges['RES-ANA']['report_count']);
        $this->assertSame(['RIR-2', 'RIR-1'], collect($badges['RES-ANA']['reports'])->pluck('reference')->all());
        $this->assertSame('Accepted', $badges['RES-ANA']['reports'][0]['label']);
        $this->assertTrue($badges['RES-BEN']['has_report']);
        $this->assertSame('Needs Correction', $badges['RES-BEN']['reports'][0]['label']);
        $this->assertArrayNotHasKey('RES-OLD', $badges->all());
        $this->assertArrayNotHasKey('RES-EVA', $badges->all());

        $anaDetail = $this->actingAs($officer)->getJson('/bdrrm/api/map/residents/'.$ana)->assertOk();
        $anaDetail->assertJsonPath('registry_reference', 'RES-ANA');
        $anaDetail->assertJsonPath('has_report', true);
        $anaDetail->assertJsonPath('report_count', 2);
        $anaDetail->assertJsonPath('map_available', false);
        $anaDetail->assertJsonMissingPath('sex');
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('report_reference', 'RIR-1')->value('report_status'));

        $dina = $this->actingAs($officer)->getJson('/bdrrm/api/map/residents/'.$noHome)->assertOk();
        $dina->assertJsonPath('has_report', false);
        $dina->assertJsonPath('account_linked', false);
        $dina->assertJsonPath('report_count', 0);

        $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-markers?barangay_id='.$other)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-groups/b'.$foreignBuilding)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/map/residents/'.$foreign)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-markers?reports=nearby')->assertOk()
            ->assertJsonPath('summary.total', 3);
        $this->assertNotContains($inactive, collect($list->json('matches'))->pluck('id')->all());

        DB::table('user_barangay_assignments')->where('user_id', $officer->id)->update(['revoked_at' => now()]);
        $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-markers')
            ->assertOk()
            ->assertJsonPath('summary.authorized_total', 0)
            ->assertJsonPath('features', []);
        $this->actingAs($officer)->getJson('/bdrrm/api/map/residents/'.$ana)->assertForbidden();
    }

    public function test_reviewed_report_stays_visible_when_the_consolidated_record_is_finalized(): void
    {
        $home = $this->barangay('Bag-ong Lungsod');
        $officer = $this->officer($home);
        $incident = $this->incident('Flood');
        $building = $this->building($home, 'BLD-9');
        $household = $this->household($home, $building, 'HH-9');
        $reporter = $this->person('Liza', 'Ong');
        $resident = $this->resident($household, 'Liza', 'Ong', 'RES-LIZA');
        $this->link($reporter, $resident, $officer->id);
        $barangayReport = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-9',
            'report_status' => 'submitted',
            'disaster_incident_id' => $incident,
            'barangay_id' => $home,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportId = DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-FINAL',
            'report_status' => 'reviewed',
            'submitted_at' => '2026-03-01 01:00:00',
            'resident_user_id' => $reporter,
            'disaster_incident_id' => $incident,
            'barangay_id' => $home,
            'building_id' => $building,
            'barangay_report_id' => $barangayReport,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assessment = DB::table('field_assessments')->insertGetId([
            'assessment_reference' => 'FA-9',
            'assessment_status' => 'submitted',
            'barangay_report_id' => $barangayReport,
            'assigned_assessor_id' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $consolidated = DB::table('consolidated_reports')->insertGetId([
            'report_reference' => 'CR-9',
            'report_status' => 'finalized',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('consolidated_report_assessments')->insert([
            'field_assessment_id' => $assessment,
            'consolidated_report_id' => $consolidated,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detail = $this->actingAs($officer)->getJson('/bdrrm/api/map/residents/'.$resident)->assertOk();
        $detail->assertJsonPath('has_report', true);
        $detail->assertJsonPath('reports.0.id', $reportId);
        $detail->assertJsonPath('reports.0.label', 'Finalized');
        $detail->assertJsonPath('reports.0.status', 'reviewed');
    }

    private function createResidentMapTables(): void
    {
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status')->default('active');
            $table->timestamps();
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference');
            $table->text('address_description')->nullable();
            $table->string('location_source')->nullable();
            $table->string('location_verification_status')->default('unverified');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference');
            $table->string('household_status')->default('active');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('registry_reference');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('birth_date')->nullable();
            $table->string('sex')->nullable();
            $table->string('residency_status')->default('active');
            $table->string('record_source');
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Schema::create('account_resident_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('resident_id')->unique();
            $table->dateTime('verified_at');
            $table->string('authorization_reference');
            $table->unsignedBigInteger('verified_by');
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
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->string('report_status')->default('draft');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference');
            $table->string('assessment_status')->default('draft');
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamps();
        });
        Schema::create('consolidated_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->string('report_status');
            $table->timestamps();
        });
        Schema::create('consolidated_report_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_assessment_id');
            $table->unsignedBigInteger('consolidated_report_id');
            $table->timestamps();
        });
    }

    private function officer(int $barangayId): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'BDRRM',
            'code' => 'bdrrm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create([
            'first_name' => 'Barangay',
            'last_name' => 'Officer',
            'email' => 'officer'.$barangayId.'@mapr.test',
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

    private function person(string $first, string $last): int
    {
        $roleId = DB::table('roles')->where('code', 'bdrrm')->value('id');

        return DB::table('users')->insertGetId([
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first.$last).uniqid().'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function barangay(string $name): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => $name,
            'barangay_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function incident(string $name): int
    {
        return DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => $name,
            'incident_name' => $name,
            'incident_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function building(int $barangayId, string $reference): int
    {
        return DB::table('buildings')->insertGetId([
            'building_reference' => $reference,
            'address_description' => 'Purok 1',
            'location_source' => 'registry',
            'location_verification_status' => 'unverified',
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function household(int $barangayId, int $buildingId, string $reference, string $status = 'active'): int
    {
        return DB::table('households')->insertGetId([
            'household_reference' => $reference,
            'household_status' => $status,
            'building_id' => $buildingId,
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resident(int $householdId, string $first, string $last, string $reference, string $status = 'active'): int
    {
        return DB::table('residents')->insertGetId([
            'registry_reference' => $reference,
            'first_name' => $first,
            'last_name' => $last,
            'residency_status' => $status,
            'record_source' => 'registry',
            'household_id' => $householdId,
            'sex' => 'female',
            'birth_date' => '1990-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function link(int $userId, int $residentId, int $verifierId): void
    {
        DB::table('account_resident_links')->insert([
            'user_id' => $userId,
            'resident_id' => $residentId,
            'verified_at' => now(),
            'authorization_reference' => 'LINK-'.$residentId,
            'verified_by' => $verifierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function report(int $userId, int $barangayId, int $incidentId, string $status, string $reference): void
    {
        DB::table('resident_incident_reports')->insert([
            'report_reference' => $reference,
            'report_status' => $status,
            'submitted_at' => $status === 'draft' ? null : '2026-02-02 01:00:00',
            'resident_user_id' => $userId,
            'disaster_incident_id' => $incidentId,
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
