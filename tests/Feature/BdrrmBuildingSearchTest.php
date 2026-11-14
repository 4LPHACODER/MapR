<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class BdrrmBuildingSearchTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createRegistryTables();
    }

    public function test_resident_search_stays_inside_the_assignment_and_saves_the_building(): void
    {
        $home = $this->barangay('166819002', 'Mabua');
        $other = $this->barangay('166819022', 'Telaje');
        $officer = $this->officer($home);
        $incident = $this->incident();
        $homeBuilding = $this->building($home, 'BLD-HOME', 'Purok 1');
        $otherHome = $this->building($home, 'BLD-OTHER', 'Riverside lane');
        $foreign = $this->building($other, 'BLD-FOREIGN', 'Other road');
        $this->resident($home, $homeBuilding, 'CBMS22-RES-100', 'Ana', 'Maria', 'Cruz');
        $this->resident($home, $homeBuilding, 'CBMS22-RES-101', 'Luis', 'Rey', 'Cruz');
        $this->resident($home, $otherHome, 'CBMS22-RES-200', 'Ana', 'Belen', 'Santos');
        $this->resident($other, $foreign, 'CBMS22-RES-900', 'Ana', 'Foreign', 'Cruz');
        $this->resident($home, null, 'CBMS22-RES-NONE', 'Nilo', 'Unhoused', 'Diaz');
        $report = $this->barangayReport($officer->id, $home, $incident, 'BR-PHOTO');
        Storage::fake('local');
        Storage::disk('local')->put('incident-photos/home.jpg', 'home-photo');
        $linkedPhoto = DB::table('incident_photos')->insertGetId([
            'file_path' => 'incident-photos/home.jpg',
            'original_filename' => 'home.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'file_sha256' => str_repeat('a', 64),
            'image_source' => 'bdrrm_upload',
            'captured_at' => '2026-03-01 01:00:00',
            'barangay_report_id' => $report,
            'building_id' => $homeBuilding,
            'uploaded_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('incident_photos')->insert([
            'file_path' => 'incident-photos/unlinked.jpg',
            'original_filename' => 'unlinked.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'file_sha256' => str_repeat('b', 64),
            'image_source' => 'bdrrm_upload',
            'barangay_report_id' => $report,
            'building_id' => null,
            'uploaded_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?q=Ana&barangay_id='.$home)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $byName = $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=Cruz%20Ana&barangay_id='.$home)->assertOk();
        $byName->assertJsonPath('meta.total', 1);
        $byName->assertJsonPath('data.0.id', $homeBuilding);
        $byName->assertJsonPath('data.0.reference', 'BLD-HOME');
        $byName->assertJsonPath('data.0.matched_resident_count', 1);
        $byName->assertJsonPath('data.0.matched_residents.0.resident_id', 'CBMS22-RES-100');
        $byName->assertJsonPath('data.0.matched_residents.0.name', 'Ana Maria Cruz');
        $byName->assertJsonPath('data.0.photo.id', $linkedPhoto);
        $byName->assertJsonPath('data.0.photo.report_reference', 'BR-PHOTO');
        $byName->assertJsonPath('data.0.photo.incident_name', 'Flood');
        $byName->assertJsonPath('data.0.photo.source', 'incident_report');
        $this->assertArrayNotHasKey('birth_date', $byName->json('data.0.matched_residents.0'));

        $shared = $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=Cruz&barangay_id='.$home)->assertOk();
        $this->assertSame(['BLD-HOME'], collect($shared->json('data'))->pluck('reference')->all());
        $this->assertSame(2, $shared->json('data.0.matched_resident_count'));

        $similar = $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=Ana&barangay_id='.$home)->assertOk();
        $this->assertEqualsCanonicalizing(['BLD-HOME', 'BLD-OTHER'], collect($similar->json('data'))->pluck('reference')->all());

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=CBMS22-RES-200&barangay_id='.$home)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'BLD-OTHER');

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=BLD-HOME&barangay_id='.$home)
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'BLD-HOME');

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=Riverside&barangay_id='.$home)
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'BLD-OTHER');

        $unlinked = $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=CBMS22-RES-NONE&barangay_id='.$home)->assertOk();
        $unlinked->assertJsonPath('meta.total', 0);
        $unlinked->assertJsonPath('resident_without_building', true);
        $unlinked->assertJsonPath('data', []);

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=Foreign&barangay_id='.$home)
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('resident_without_building', false);

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings?match=residents&q=Ana&barangay_id='.$other)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$foreign.'/photos')->assertForbidden();

        $photos = $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$homeBuilding.'/photos')->assertOk();
        $this->assertSame([$linkedPhoto], collect($photos->json('data'))->pluck('id')->all());
        $this->actingAs($officer)->get('/bdrrm/api/buildings/'.$homeBuilding.'/photos/'.$linkedPhoto)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $created = $this->actingAs($officer)->postJson('/bdrrm/api/reports', [
            'disaster_incident_id' => $incident,
            'barangay_id' => $home,
            'building_id' => $byName->json('data.0.id'),
            'report_description' => 'Doorway is wet.',
        ])->assertCreated();
        $this->assertDatabaseHas('barangay_report_buildings', [
            'barangay_report_id' => $created->json('data.id'),
            'building_id' => $homeBuilding,
        ]);
        $this->assertDatabaseMissing('incident_photos', [
            'barangay_report_id' => $created->json('data.id'),
        ]);
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
            $table->string('residency_status')->default('active');
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('barangay_report_buildings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id');
            $table->text('observations')->nullable();
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
            $table->dateTime('captured_at')->nullable();
            $table->unsignedBigInteger('incident_report_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('uploaded_by');
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

    private function building(int $barangayId, string $reference, string $address): int
    {
        return DB::table('buildings')->insertGetId([
            'building_reference' => $reference,
            'address_description' => $address,
            'location_source' => 'registry',
            'location_verification_status' => 'unverified',
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resident(int $barangayId, ?int $buildingId, string $reference, string $first, string $middle, string $last): void
    {
        $householdId = DB::table('households')->insertGetId([
            'household_reference' => 'HH-'.$reference,
            'household_status' => 'active',
            'building_id' => $buildingId,
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('residents')->insert([
            'registry_reference' => $reference,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'birth_date' => '1990-01-01',
            'residency_status' => 'active',
            'household_id' => $householdId,
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
            'submitted_at' => '2026-03-02 01:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
