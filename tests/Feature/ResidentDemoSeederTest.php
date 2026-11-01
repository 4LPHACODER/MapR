<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Database\Seeders\ResidentDemoSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ResidentDemoSeederTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        DB::table('barangays')->insert([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::create('resident_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->string('report_status')->default('draft');
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
    }

    public function test_the_demo_resident_signs_in_to_the_resident_workspace_only(): void
    {
        $this->seed(ResidentDemoSeeder::class);
        $hash = User::query()->where('email', ResidentDemoSeeder::EMAIL)->value('password');

        $this->seed(ResidentDemoSeeder::class);
        $this->assertSame($hash, User::query()->where('email', ResidentDemoSeeder::EMAIL)->value('password'));
        $this->assertTrue(Hash::check('MapRResident!2026', $hash));

        $user = User::query()->where('email', ResidentDemoSeeder::EMAIL)->first();
        $this->assertSame('Maricar', $user->first_name);
        $this->assertSame('Dumpa', $user->middle_name);
        $this->assertSame('Irizari', $user->last_name);
        $this->assertSame('Mabua', DB::table('barangays')->where('id', $user->barangay_id)->value('barangay_name'));
        $this->assertSame('resident', $user->role->code);

        $this->post('/login', [
            'email' => ResidentDemoSeeder::EMAIL,
            'password' => 'MapRResident!2026',
        ])->assertRedirect('/resident');

        $this->get('/resident')->assertOk();
        $this->getJson('/resident/api/dashboard')->assertOk()->assertJsonPath('barangay_name', 'Mabua');
        $this->getJson('/resident/api/reports')->assertOk();
        $this->getJson('/resident/api/profile')->assertOk()->assertJsonPath('data.email', ResidentDemoSeeder::EMAIL);
        $this->get('/bdrrm')->assertForbidden();
        $this->get('/rdana')->assertForbidden();
        $this->get('/cdrrmo')->assertForbidden();
    }

    public function test_an_existing_demo_resident_keeps_its_password(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Resident',
            'code' => 'resident',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        User::query()->create([
            'first_name' => 'Already',
            'last_name' => 'Here',
            'email' => ResidentDemoSeeder::EMAIL,
            'password' => 'existing-secret',
            'account_status' => 'active',
            'role_id' => $roleId,
        ]);

        $this->seed(ResidentDemoSeeder::class);

        $user = User::query()->where('email', ResidentDemoSeeder::EMAIL)->first();
        $this->assertTrue(Hash::check('existing-secret', $user->password));
        $this->assertFalse(Hash::check('MapRResident!2026', $user->password));
        $this->assertSame('Already', $user->first_name);
        $this->assertSame('Here', $user->last_name);
    }

    public function test_the_legacy_demo_name_is_replaced_without_resetting_the_password(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Resident',
            'code' => 'resident',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        User::query()->create([
            'first_name' => 'MapR',
            'middle_name' => 'Test',
            'last_name' => 'Resident',
            'email' => ResidentDemoSeeder::EMAIL,
            'password' => 'existing-secret',
            'account_status' => 'active',
            'role_id' => $roleId,
        ]);

        $this->seed(ResidentDemoSeeder::class);

        $user = User::query()->where('email', ResidentDemoSeeder::EMAIL)->first();
        $this->assertSame('Maricar', $user->first_name);
        $this->assertSame('Dumpa', $user->middle_name);
        $this->assertSame('Irizari', $user->last_name);
        $this->assertTrue(Hash::check('existing-secret', $user->password));
        $this->assertFalse(Hash::check('MapRResident!2026', $user->password));
    }

    public function test_the_demo_resident_links_the_cbms_household_once(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference')->unique();
            $table->text('address_description')->nullable();
            $table->string('geographic_point')->nullable();
            $table->text('location_source')->nullable();
            $table->string('location_verification_status')->default('unverified');
            $table->unsignedBigInteger('location_verified_by')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference')->unique();
            $table->string('household_status')->default('active');
            $table->string('record_source');
            $table->unsignedInteger('source_household_size')->nullable();
            $table->unsignedInteger('source_nuclear_families')->nullable();
            $table->unsignedInteger('source_no_males')->nullable();
            $table->unsignedInteger('source_no_females')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
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
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Schema::create('account_resident_links', function (Blueprint $table) {
            $table->id();
            $table->dateTime('verified_at');
            $table->string('authorization_reference');
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('resident_id')->unique();
            $table->unsignedBigInteger('verified_by');
            $table->timestamps();
        });

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'CDRRMO Administrator',
            'code' => 'cdrrmo_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $verifier = User::query()->create([
            'first_name' => 'Staff',
            'last_name' => 'Verifier',
            'email' => 'staff@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
        ]);

        $this->seed(ResidentDemoSeeder::class);
        $this->seed(ResidentDemoSeeder::class);

        $this->assertSame(1, DB::table('buildings')->where('building_reference', ResidentDemoSeeder::BUILDING_REFERENCE)->count());
        $this->assertSame(1, DB::table('households')->where('household_reference', ResidentDemoSeeder::HOUSEHOLD_REFERENCE)->count());
        $this->assertSame(1, DB::table('residents')->where('registry_reference', ResidentDemoSeeder::RESIDENT_REFERENCE)->count());
        $this->assertSame(0, DB::table('residents')->where('registry_reference', 'CBMS22-RES-4910-01')->count());
        $this->assertSame(1, DB::table('account_resident_links')->count());

        $building = DB::table('buildings')->where('building_reference', ResidentDemoSeeder::BUILDING_REFERENCE)->first();
        $this->assertSame('32', $building->address_description);
        $this->assertSame('unverified', $building->location_verification_status);
        $this->assertNull($building->location_verified_by);
        $this->assertSame('126.20313 9.069381354', $building->geographic_point);

        $household = DB::table('households')->where('household_reference', ResidentDemoSeeder::HOUSEHOLD_REFERENCE)->first();
        $this->assertSame(2, (int) $household->source_household_size);
        $this->assertSame(1, (int) $household->source_nuclear_families);
        $this->assertSame(0, (int) $household->source_no_males);
        $this->assertSame(2, (int) $household->source_no_females);
        $this->assertSame((int) $building->id, (int) $household->building_id);

        $resident = DB::table('residents')->where('registry_reference', ResidentDemoSeeder::RESIDENT_REFERENCE)->first();
        $this->assertSame('Maricar', $resident->first_name);
        $this->assertSame('Dumpa', $resident->middle_name);
        $this->assertSame('Irizari', $resident->last_name);
        $this->assertSame('female', $resident->sex);
        $this->assertSame('1985-02-13', substr((string) $resident->birth_date, 0, 10));
        $this->assertSame((int) $household->id, (int) $resident->household_id);

        $user = User::query()->where('email', ResidentDemoSeeder::EMAIL)->first();
        $link = DB::table('account_resident_links')->first();
        $this->assertSame((int) $user->id, (int) $link->user_id);
        $this->assertSame((int) $resident->id, (int) $link->resident_id);
        $this->assertSame((int) $verifier->id, (int) $link->verified_by);
        $this->assertSame(ResidentDemoSeeder::RESIDENT_REFERENCE, $link->authorization_reference);
    }
}
