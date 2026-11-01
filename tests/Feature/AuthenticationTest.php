<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
    }

    public function test_login_page_is_available_without_a_role_selector(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome back to MapR')
            ->assertSee('Sign in to access your assessment workspace.')
            ->assertDontSee('Forgot password')
            ->assertDontSee('Login as')
            ->assertDontSee('name="role"', false)
            ->assertDontSee('instrument-sans', false);
    }

    public function test_each_role_signs_in_to_its_workspace(): void
    {
        foreach ([
            'resident' => '/resident',
            'bdrrm' => '/bdrrm',
            'rdana' => '/rdana',
            'cdrrmo_admin' => '/cdrrmo',
        ] as $code => $path) {
            $user = $this->makeUser($code);

            $this->post('/login', [
                'email' => $user->email,
                'password' => 'secret-pass',
            ])->assertRedirect($path);

            $this->get('/login')->assertRedirect($path);

            $this->get($path)
                ->assertOk()
                ->assertSee($user->email);

            $this->post('/logout')->assertRedirect('/');
            $this->assertGuest();
        }
    }

    public function test_invalid_password_is_rejected(): void
    {
        $user = $this->makeUser('resident');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors([
            'email' => 'These credentials do not match our records.',
        ]);

        $this->assertGuest();
    }

    public function test_inactive_and_deleted_accounts_are_rejected(): void
    {
        $inactive = $this->makeUser('resident', ['account_status' => 'inactive']);

        $this->post('/login', [
            'email' => $inactive->email,
            'password' => 'secret-pass',
        ])->assertSessionHasErrors([
            'email' => 'This account is not active.',
        ]);

        $deleted = $this->makeUser('bdrrm');
        $deleted->delete();

        $this->from('/login')->post('/login', [
            'email' => $deleted->email,
            'password' => 'secret-pass',
        ])->assertSessionHasErrors([
            'email' => 'This account is not active.',
        ]);

        $this->assertGuest();
    }

    public function test_guests_and_other_roles_cannot_open_a_workspace(): void
    {
        $this->get('/resident')->assertRedirect('/login');

        $resident = $this->makeUser('resident');
        $this->actingAs($resident)->get('/bdrrm')->assertForbidden();
        $this->actingAs($resident)->get('/rdana')->assertForbidden();
        $this->actingAs($resident)->get('/cdrrmo')->assertForbidden();
    }

    public function test_login_ignores_a_submitted_role_and_external_redirect(): void
    {
        $user = $this->makeUser('bdrrm');

        $this->withSession(['url.intended' => 'https://evil.example/bdrrm'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'secret-pass',
                'role' => 'resident',
            ])->assertRedirect('/bdrrm');
    }

    public function test_intended_workspace_is_used_only_when_authorized(): void
    {
        $resident = $this->makeUser('resident');
        $assessor = $this->makeUser('rdana');

        $this->get('/resident');
        $this->post('/login', [
            'email' => $resident->email,
            'password' => 'secret-pass',
        ])->assertRedirect('/resident');

        $this->post('/logout');

        $this->get('/resident');
        $this->post('/login', [
            'email' => $assessor->email,
            'password' => 'secret-pass',
        ])->assertRedirect('/rdana');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->makeUser('cdrrmo_admin');
        $this->actingAs($user);
        $token = session()->token();

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertNotSame($token, session()->token());
    }

    public function test_login_is_throttled(): void
    {
        $user = $this->makeUser('resident');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ])->assertSessionHasErrors([
            'email' => 'Too many sign-in attempts. Please try again shortly.',
        ]);

        $this->assertGuest();
    }

    public function test_missing_role_fails_closed(): void
    {
        $role = Role::query()->create([
            'name' => 'Unassigned',
            'code' => 'resident',
        ]);
        $user = User::query()->create([
            'first_name' => 'No',
            'last_name' => 'Role',
            'email' => 'missing-role@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $role->id,
        ]);
        $user->role()->dissociate();
        $user->role_id = 0;
        $user->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ])->assertSessionHasErrors([
            'email' => 'This account cannot access MapR.',
        ]);
    }

    private function makeUser(string $code, array $overrides = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'description' => $code],
        );

        return User::query()->create(array_merge([
            'first_name' => 'Test',
            'last_name' => ucfirst($code),
            'email' => $code.'.'.uniqid().'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $role->id,
        ], $overrides));
    }
}
