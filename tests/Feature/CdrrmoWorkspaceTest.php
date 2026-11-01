<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Services\ImpactEstimator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class CdrrmoWorkspaceTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
    }

    public function test_landing_and_login_do_not_load_instrument_sans(): void
    {
        $this->get('/')->assertOk()->assertDontSee('instrument-sans', false);
        $this->get('/login')->assertOk()->assertDontSee('instrument-sans', false)->assertDontSee('Coverage')->assertDontSee('hero-1.webp', false);
    }

    public function test_guests_and_other_roles_cannot_open_cdrrmo(): void
    {
        $this->get('/cdrrmo')->assertRedirect('/login');

        $resident = $this->makeUser('resident');
        $this->actingAs($resident)->get('/cdrrmo')->assertForbidden();
        $this->actingAs($resident)->get('/cdrrmo/api/residents')->assertForbidden();
    }

    public function test_administrator_can_open_the_workspace_shell(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');

        $this->actingAs($admin)
            ->get('/cdrrmo')
            ->assertOk()
            ->assertSee('cdrrmo-app', false);
    }

    public function test_administrator_can_load_workspace_dashboard_api(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');

        $this->actingAs($admin)
            ->getJson('/cdrrmo/api/workspace/dashboard')
            ->assertOk()
            ->assertJsonStructure(['generated_at', 'summary', 'recent_activity', 'prepare_consolidated']);
    }

    public function test_dashboard_omits_the_local_demo_incident(): void
    {
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status');
            $table->dateTime('occurred_at')->nullable();
            $table->timestamps();
        });

        DB::table('disaster_incidents')->insert([
            [
                'incident_reference' => 'DEMO-INC-LOCAL-001',
                'incident_name' => 'Demo flood event (local)',
                'incident_status' => 'active',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'incident_reference' => 'INC-2026-REAL',
                'incident_name' => 'Typhoon watch',
                'incident_status' => 'active',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $admin = $this->makeUser('cdrrmo_admin');

        $this->actingAs($admin)
            ->getJson('/cdrrmo/api/workspace/dashboard?incident_id=1')
            ->assertOk()
            ->assertJsonMissing(['incident_name' => 'Demo flood event (local)'])
            ->assertJsonPath('selected_incident', null)
            ->assertJsonPath('incidents.0.incident_name', 'Typhoon watch')
            ->assertJsonPath('prepare_consolidated.enabled', true);
    }

    public function test_estimate_preview_rejects_invalid_polygons(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');

        $this->actingAs($admin)
            ->postJson('/cdrrmo/api/estimates/preview', [
                'coordinates' => [[1, 1], [2, 2]],
            ])
            ->assertStatus(422);
    }

    public function test_estimator_rejects_coordinates_outside_the_philippines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ImpactEstimator::class)->polygonWkt([[0, 0], [1, 0], [1, 1], [0, 0]]);
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
