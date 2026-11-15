<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class BdrrmBuildingRegistryTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createRegistryTables();
    }

    public function test_registry_stays_inside_the_assignment_and_counts_distinct_links(): void
    {
        $home = $this->barangay('166819002', 'Mabua');
        $other = $this->barangay('166819022', 'Telaje');
        $officer = $this->officer($home);
        $incident = $this->incident();
        $linked = $this->building($home, 'BLD-LINKED', 'Purok 1', 'unverified');
        $plain = $this->building($home, 'BLD-PLAIN', 'Riverside lane', 'verified');
        $foreign = $this->building($other, 'BLD-FOREIGN', 'Other road', 'unverified');
        $this->household($home, $linked, 'HH-1');
        $this->household($home, $linked, 'HH-2');
        $this->household($home, $linked, 'HH-OLD', 'inactive');
        $this->report($officer->id, $home, $incident, $linked, 'submitted', 'RIR-1');
        $this->report($officer->id, $home, $incident, $linked, 'reviewed', 'RIR-2');
        $this->report($officer->id, $home, $incident, $linked, 'draft', 'RIR-DRAFT');
        $this->report($officer->id, $home, $incident, null, 'submitted', 'RIR-UNLINKED');
        $barangayReport = $this->barangayReport($officer->id, $home, $incident, 'BR-1');
        DB::table('barangay_report_buildings')->insert([
            'barangay_report_id' => $barangayReport,
            'building_id' => $linked,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classId = DB::table('damage_classes')->insertGetId([
            'class_code' => 'partial',
            'class_name' => 'Partially damaged',
            'display_order' => 1,
            'counts_as_damaged' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assessment = DB::table('field_assessments')->insertGetId([
            'assessment_reference' => 'FA-1',
            'assessment_status' => 'submitted',
            'barangay_report_id' => $barangayReport,
            'assigned_assessor_id' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('building_verifications')->insert([
            'location_verified' => false,
            'household_link_verified' => true,
            'verified_at' => '2026-03-01 00:00:00',
            'field_assessment_id' => $assessment,
            'building_id' => $linked,
            'verified_damage_class_id' => $classId,
            'verified_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = $this->actingAs($officer)->getJson('/bdrrm/api/buildings')->assertOk();
        $list->assertJsonPath('meta.total', 2);
        $list->assertJsonPath('meta.authorized_total', 2);
        $list->assertJsonPath('summary.total', 2);
        $list->assertJsonPath('summary.authorized_total', 2);
        $list->assertJsonPath('summary.with_households', 1);
        $list->assertJsonPath('summary.with_reports', 1);
        $list->assertJsonPath('summary.with_recorded_location', 0);
        $this->assertSame(['Mabua'], collect($list->json('barangays'))->pluck('name')->all());
        $row = collect($list->json('data'))->firstWhere('reference', 'BLD-LINKED');
        $this->assertSame(2, $row['household_count']);
        $this->assertSame(3, $row['report_count']);
        $this->assertFalse($row['map_available']);
        $this->assertSame('unverified', $row['location_status']);
        $this->assertArrayNotHasKey('damage_class', $row);
        $this->assertNotContains('BLD-FOREIGN', collect($list->json('data'))->pluck('reference')->all());

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?reports=with')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.authorized_total', 2)
            ->assertJsonPath('data.0.reference', 'BLD-LINKED');

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?households=without')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'BLD-PLAIN');

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?q=Riverside')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'BLD-PLAIN')
            ->assertJsonPath('data.0.address', 'Riverside lane');

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?barangay_id='.$other)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$foreign)->assertForbidden();

        $detail = $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$linked)->assertOk();
        $detail->assertJsonPath('reference', 'BLD-LINKED');
        $detail->assertJsonPath('map_available', false);
        $detail->assertJsonPath('location_status', 'unverified');
        $detail->assertJsonMissingPath('location_verified_by');
        $this->assertSame(['RIR-2', 'RIR-1'], collect($detail->json('resident_reports'))->pluck('reference')->all());
        $this->assertSame(['BR-1'], collect($detail->json('barangay_reports'))->pluck('reference')->all());
        $this->assertSame('Partially damaged', $detail->json('assessments.0.damage_class'));
        $this->assertSame('Flood', $detail->json('assessments.0.incident_name'));

        $households = $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$linked.'/households')->assertOk();
        $this->assertSame(['HH-1', 'HH-2'], collect($households->json('data'))->pluck('household_reference')->all());

        DB::table('user_barangay_assignments')->where('user_id', $officer->id)->update(['revoked_at' => now()]);
        $this->actingAs($officer)->getJson('/bdrrm/api/buildings')
            ->assertOk()
            ->assertJsonPath('meta.authorized_total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_search_and_sort_cover_the_full_assignment(): void
    {
        $home = $this->barangay('166819003', 'San Agustin');
        $officer = $this->officer($home);
        foreach (range(1, 11) as $number) {
            $this->building($home, sprintf('BLD-%02d', $number), 'Street '.$number, 'unverified', '2026-01-01 00:00:00');
        }
        $this->building($home, 'BLD-99', 'Hidden riverside marker', 'unverified', '2026-01-01 00:00:00');

        $page = $this->actingAs($officer)->getJson('/bdrrm/api/buildings?per_page=10&page=2')->assertOk();
        $page->assertJsonPath('meta.total', 12);
        $page->assertJsonPath('meta.last_page', 2);
        $this->assertSame(['BLD-11', 'BLD-99'], collect($page->json('data'))->pluck('reference')->all());

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?q=riverside&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'BLD-99');

        $newest = $this->actingAs($officer)->getJson('/bdrrm/api/buildings?sort=newest&per_page=10')->assertOk();
        $this->assertSame('BLD-99', $newest->json('data.0.reference'));
    }

    private function createRegistryTables(): void
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
            $table->string('building_name')->nullable();
            $table->string('building_type')->nullable();
            $table->text('address_description')->nullable();
            $table->string('location_source')->nullable();
            $table->string('location_verification_status')->default('unverified');
            $table->dateTime('location_verified_at')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('location_verified_by')->nullable();
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference');
            $table->string('household_status')->default('active');
            $table->unsignedInteger('source_household_size')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
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
        Schema::create('barangay_report_buildings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id');
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
        Schema::create('damage_classes', function (Blueprint $table) {
            $table->id();
            $table->string('class_code');
            $table->string('class_name');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('counts_as_damaged')->default(false);
            $table->timestamps();
        });
        Schema::create('building_verifications', function (Blueprint $table) {
            $table->id();
            $table->boolean('location_verified')->default(false);
            $table->boolean('household_link_verified')->default(false);
            $table->dateTime('verified_at');
            $table->unsignedBigInteger('field_assessment_id');
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('verified_damage_class_id');
            $table->unsignedBigInteger('verified_by');
            $table->timestamps();
        });
    }

    private function officer(int $barangayId): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Barangay Secretary',
            'code' => 'barangay_secretary',
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

    private function barangay(string $code, string $name): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => $code,
            'barangay_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function incident(): int
    {
        return DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-1',
            'incident_name' => 'Flood',
            'incident_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function building(int $barangayId, string $reference, string $address, string $status, string $createdAt = '2026-01-01 00:00:00'): int
    {
        return DB::table('buildings')->insertGetId([
            'building_reference' => $reference,
            'address_description' => $address,
            'location_source' => 'registry',
            'location_verification_status' => $status,
            'barangay_id' => $barangayId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function household(int $barangayId, int $buildingId, string $reference, string $status = 'active'): void
    {
        DB::table('households')->insert([
            'household_reference' => $reference,
            'household_status' => $status,
            'source_household_size' => 4,
            'building_id' => $buildingId,
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function report(int $userId, int $barangayId, int $incidentId, ?int $buildingId, string $status, string $reference): void
    {
        DB::table('resident_incident_reports')->insert([
            'report_reference' => $reference,
            'report_status' => $status,
            'submitted_at' => $status === 'draft' ? null : '2026-02-0'.(str_ends_with($reference, '2') ? '2' : '1').' 01:00:00',
            'resident_user_id' => $userId,
            'disaster_incident_id' => $incidentId,
            'barangay_id' => $barangayId,
            'building_id' => $buildingId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function barangayReport(int $userId, int $barangayId, int $incidentId, string $reference): int
    {
        return DB::table('barangay_reports')->insertGetId([
            'report_reference' => $reference,
            'report_status' => 'submitted',
            'disaster_incident_id' => $incidentId,
            'barangay_id' => $barangayId,
            'prepared_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
