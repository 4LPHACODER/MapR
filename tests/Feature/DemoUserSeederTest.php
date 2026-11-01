<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Database\Seeders\MaprDemoUserSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class DemoUserSeederTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
    }

    public function test_seeder_is_idempotent_and_does_not_reset_an_existing_password(): void
    {
        $this->seed(MaprDemoUserSeeder::class);
        $this->assertSame(5, User::query()->count());
        $this->assertDatabaseHas('users', [
            'email' => 'lestertalan@mapr_admin.com',
            'first_name' => 'Lester Lito',
            'account_status' => 'active',
        ]);

        $resident = User::query()->where('email', 'resident.demo@mapr.test')->firstOrFail();
        $resident->forceFill([
            'password' => Hash::make('existing-password'),
        ])->save();

        $this->seed(MaprDemoUserSeeder::class);

        $this->assertSame(5, User::query()->count());
        $this->assertTrue(Hash::check('existing-password', $resident->fresh()->password));
        $this->assertSame('active', $resident->fresh()->account_status);
    }

    public function test_seeder_assigns_an_existing_barangay_without_inventing_one(): void
    {
        $barangayId = DB::table('barangays')->insertGetId([
            'barangay_code' => 'TEST-ONLY',
            'barangay_name' => 'Test Barangay',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(MaprDemoUserSeeder::class);

        $admin = User::query()->where('email', 'admin.demo@mapr.test')->firstOrFail();
        $resident = User::query()->where('email', 'resident.demo@mapr.test')->firstOrFail();
        $personnel = User::query()->where('email', 'bdrrm.demo@mapr.test')->firstOrFail();

        $this->assertNull($admin->barangay_id);
        $this->assertSame($barangayId, (int) $resident->barangay_id);
        $this->assertDatabaseHas('user_barangay_assignments', [
            'user_id' => $personnel->id,
            'barangay_id' => $barangayId,
            'assigned_by' => $admin->id,
        ]);
    }

    public function test_seeder_migrates_legacy_lester_email_and_preserves_user_id(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $roles = DB::table('roles')->pluck('id', 'code');

        $legacyId = User::query()->create([
            'first_name' => 'Lester Lito',
            'middle_name' => 'C',
            'last_name' => 'Talan',
            'email' => 'lester.talan.demo@mapr.test',
            'password' => Hash::make('legacy-password'),
            'account_status' => 'active',
            'email_verified_at' => now(),
            'role_id' => $roles['cdrrmo_admin'],
        ])->id;

        config(['mapr.demo_password' => 'migrated-demo-password']);
        $this->seed(MaprDemoUserSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'lester.talan.demo@mapr.test']);
        $this->assertDatabaseHas('users', ['id' => $legacyId, 'email' => 'lestertalan@mapr_admin.com']);
        $this->assertTrue(Hash::check('migrated-demo-password', User::query()->findOrFail($legacyId)->password));
    }

    public function test_seeder_rejects_production(): void
    {
        $this->app['env'] = 'production';
        $seeder = new MaprDemoUserSeeder();
        $seeder->setContainer($this->app);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local or testing');
        $seeder->run();
    }
}
