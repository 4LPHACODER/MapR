<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class MapHouseholdEligibilityTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
    }

    public function test_household_map_api_returns_empty_when_no_eligible_locations(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');

        $this->actingAs($admin)
            ->getJson('/cdrrmo/api/map/households')
            ->assertOk()
            ->assertJsonPath('meta.eligible_count', 0)
            ->assertJsonPath('meta.message_if_empty', 'No source-recorded household locations are available.');
    }

    public function test_residents_list_returns_empty_payload_without_registry_tables(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');

        $this->actingAs($admin)
            ->getJson('/cdrrmo/api/residents')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_resident_cannot_access_map_households(): void
    {
        $resident = $this->makeUser('resident');

        $this->actingAs($resident)
            ->getJson('/cdrrmo/api/map/households')
            ->assertForbidden();
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
