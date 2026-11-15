<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Database\Seeders\MapRWorkflowPersonnelSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class WorkflowPersonnelSeederTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->seed(RoleSeeder::class);
        DB::table('barangays')->insert([
            'barangay_code' => '166819008', 'barangay_name' => 'Mabua',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['mapr.demo_password' => 'test-workflow-password']);
    }

    public function test_unrelated_people_with_matching_surnames_are_not_promoted_or_assigned(): void
    {
        $users = [];
        foreach (['Pimentel', 'Merillo', 'Talan', 'Geli'] as $surname) {
            $users[] = $this->resident(strtolower($surname).'@example.test', $surname);
        }
        $this->seed(MapRWorkflowPersonnelSeeder::class);
        foreach ($users as $user) {
            $this->assertSame($user->role_id, $user->fresh()->role_id);
            $this->assertDatabaseMissing('user_barangay_assignments', ['user_id' => $user->id]);
        }
        $this->assertDatabaseHas('users', ['email' => 'mark.merillo@mapr.test']);
        $this->assertDatabaseHas('users', ['email' => 'lester.talan@mapr.test']);
        $this->assertDatabaseHas('users', ['email' => 'reyveen.geli@mapr.test']);
    }

    public function test_new_accounts_use_configured_password_and_reseeding_preserves_passwords_and_assignments(): void
    {
        $this->seed(MapRWorkflowPersonnelSeeder::class);
        $user = User::where('email', 'jesnard.plaza@mapr.test')->firstOrFail();
        $this->assertTrue(Hash::check('test-workflow-password', $user->password));
        $user->forceFill(['password' => 'changed-password'])->save();
        $counts = [User::count(), DB::table('user_barangay_assignments')->count()];
        $this->seed(MapRWorkflowPersonnelSeeder::class);
        $this->assertSame($counts, [User::count(), DB::table('user_barangay_assignments')->count()]);
        $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
    }

    public function test_disabled_and_deleted_seed_accounts_are_left_unchanged(): void
    {
        foreach (['jesnard.plaza@mapr.test', 'mark.merillo@mapr.test', 'lester.talan@mapr.test'] as $email) {
            $user = $this->resident($email, 'Existing');
            $user->delete();
        }
        $disabled = $this->resident('peter.salomon@mapr.test', 'Existing');
        $disabled->forceFill(['account_status' => 'inactive'])->save();
        $this->seed(MapRWorkflowPersonnelSeeder::class);
        $this->assertDatabaseCount('user_barangay_assignments', 6);
        $residentRole = DB::table('roles')->where('code', 'resident')->value('id');
        foreach (User::withTrashed()->where('last_name', 'Existing')->get() as $user) {
            $this->assertSame((int) $residentRole, (int) $user->role_id);
            $this->assertDatabaseMissing('user_barangay_assignments', ['user_id' => $user->id]);
        }
    }

    public function test_existing_canonical_lester_is_reused_without_a_duplicate(): void
    {
        $lester = $this->resident('lestertalan@mapr_admin.com', 'Talan');
        $mark = $this->resident('mark.merillo@mapr.test', 'Merillo');
        $captain = $this->resident('rocky.pimentel@mapr.test', 'Pimentel');
        $this->seed(MapRWorkflowPersonnelSeeder::class);
        foreach ([[$lester, 'cdrrmo_admin'], [$mark, 'rdana_admin_officer'], [$captain, 'barangay_captain']] as [$user, $role]) {
            $this->assertSame((int) DB::table('roles')->where('code', $role)->value('id'), (int) $user->fresh()->role_id);
            $this->assertTrue(Hash::check('preserved-password', $user->fresh()->password));
        }
        $this->assertDatabaseMissing('users', ['email' => 'lester.talan@mapr.test']);
    }

    public function test_missing_password_fails_before_creating_accounts(): void
    {
        config(['mapr.demo_password' => null]);
        try {
            $this->seed(MapRWorkflowPersonnelSeeder::class);
            $this->fail('Expected missing demo password to be rejected');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('MAPR_DEMO_PASSWORD', $error->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_barangay_assignments', 0);
    }

    public function test_production_is_rejected_without_writing_users(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');
        $seeder = new MapRWorkflowPersonnelSeeder;
        $seeder->setContainer($this->app);
        $seeder->run();
    }

    public function test_assignment_failure_rolls_back_new_accounts(): void
    {
        DB::unprepared("CREATE TRIGGER reject_seed_assignment BEFORE INSERT ON user_barangay_assignments BEGIN SELECT RAISE(ABORT, 'test assignment failure'); END");
        try {
            $this->seed(MapRWorkflowPersonnelSeeder::class);
            $this->fail('Expected assignment failure');
        } catch (QueryException $error) {
            $this->assertStringContainsString('test assignment failure', $error->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_barangay_assignments', 0);
    }

    private function resident(string $email, string $surname): User
    {
        return User::create([
            'first_name' => 'Existing', 'last_name' => $surname, 'email' => $email,
            'password' => 'preserved-password', 'account_status' => 'active',
            'role_id' => DB::table('roles')->where('code', 'resident')->value('id'),
        ]);
    }
}
