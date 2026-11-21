<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ProvisionAdminCommandTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        Role::query()->create(['code' => 'cdrrmo_admin', 'name' => 'CDRRMO Administrator']);
    }

    public function test_provisioned_admin_can_login_and_access_cdrrmo(): void
    {
        $this->artisan('mapr:provision-admin')
            ->expectsQuestion('First name', 'Deployment')
            ->expectsQuestion('Last name', 'Administrator')
            ->expectsQuestion('Email', 'operator@example.test')
            ->expectsQuestion('Password', 'Strong-Test-Password42!')
            ->expectsQuestion('Confirm password', 'Strong-Test-Password42!')
            ->assertSuccessful();

        $user = User::where('email', 'operator@example.test')->firstOrFail();
        $this->assertSame('active', $user->account_status);
        $this->assertSame('cdrrmo_admin', $user->role->code);
        $this->assertTrue(Hash::check('Strong-Test-Password42!', $user->password));
        $this->post('/login', ['email' => $user->email, 'password' => 'Strong-Test-Password42!'])->assertRedirect('/cdrrmo');
        $this->get('/cdrrmo')->assertOk();
    }

    public function test_existing_and_soft_deleted_accounts_are_never_modified(): void
    {
        foreach ([false, true] as $deleted) {
            $user = User::factory()->create(['email' => $deleted ? 'deleted@example.test' : 'existing@example.test']);
            if ($deleted) {
                $user->delete();
            }
            $before = $user->fresh()->getAttributes();
            $this->artisan('mapr:provision-admin')
                ->expectsQuestion('First name', 'New')
                ->expectsQuestion('Last name', 'Name')
                ->expectsQuestion('Email', $user->email)
                ->assertFailed();
            $this->assertSame($before, $user->fresh()->getAttributes());
        }
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->artisan('mapr:provision-admin')
            ->expectsQuestion('First name', 'Admin')
            ->expectsQuestion('Last name', 'Operator')
            ->expectsQuestion('Email', 'operator@example.test')
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_confirmation_must_match(): void
    {
        $this->artisan('mapr:provision-admin')
            ->expectsQuestion('First name', 'Admin')
            ->expectsQuestion('Last name', 'Operator')
            ->expectsQuestion('Email', 'operator@example.test')
            ->expectsQuestion('Password', 'Strong-Test-Password42!')
            ->expectsQuestion('Confirm password', 'Different-Test-Password42!')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_noninteractive_execution_is_rejected(): void
    {
        $this->artisan('mapr:provision-admin --no-interaction')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
