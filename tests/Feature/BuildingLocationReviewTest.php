<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class BuildingLocationReviewTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
    }

    public function test_guests_cannot_preview_map_selection(): void
    {
        $this->postJson('/cdrrmo/api/map/selection/preview', [])->assertUnauthorized();
    }

    public function test_residents_cannot_verify_building_locations(): void
    {
        $this->actingAs($this->makeUser('resident'))
            ->postJson('/cdrrmo/api/buildings/verify-bulk', [
                'selection_id' => 'test',
                'building_ids' => [1],
                'action' => 'verify',
                'basis' => 'Example basis text for review.',
            ])
            ->assertForbidden();
    }

    public function test_verify_bulk_requires_active_selection_session(): void
    {
        $this->actingAs($this->makeUser('cdrrmo_admin'))
            ->postJson('/cdrrmo/api/buildings/verify-bulk', [
                'selection_id' => 'missing-selection',
                'building_ids' => [1],
                'action' => 'verify',
                'basis' => 'Example basis text for review.',
            ])
            ->assertStatus(422);
    }

    public function test_selection_preview_rejects_invalid_geometry(): void
    {
        $this->actingAs($this->makeUser('cdrrmo_admin'))
            ->postJson('/cdrrmo/api/map/selection/preview', [
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[126.1, 9.1], [126.2, 9.1], [126.2, 9.2]]],
                ],
            ])
            ->assertStatus(422);
    }

    private function makeUser(string $code): User
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $code, 'description' => $code]);

        return User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $code.'.'.uniqid().'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $role->id,
        ]);
    }
}
