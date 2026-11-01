<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createIncidentSchema();
    }

    public function test_incidents_index_uses_disaster_types_name_column(): void
    {
        $typeId = DB::table('disaster_types')->insertGetId([
            'name' => 'Flood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('disaster_incidents')->insert([
            'disaster_type_id' => $typeId,
            'incident_reference' => 'INC-TEST-001',
            'incident_name' => 'Test incident',
            'occurred_at' => now(),
            'incident_status' => 'active',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->makeUser('cdrrmo_admin');

        $this->actingAs($admin)
            ->getJson('/cdrrmo/api/incidents')
            ->assertOk()
            ->assertJsonPath('data.0.disaster_type', 'Flood')
            ->assertJsonPath('data.0.incident_name', 'Test incident');
    }

    public function test_create_requires_a_chosen_type_and_only_links_selected_barangays(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');
        $typeId = $this->type('Typhoon');
        $selected = $this->barangay('166819002', 'Bag-ong Lungsod');
        $other = $this->barangay('166819022', 'Telaje');

        $this->actingAs($admin)->postJson('/cdrrmo/api/incidents', [
            'incident_name' => 'River flood',
            'occurred_at' => '2026-10-29T09:30',
            'incident_status' => 'active',
            'barangay_ids' => [$selected],
        ])->assertStatus(422)->assertJsonValidationErrors(['disaster_type_id']);

        $this->assertSame(0, DB::table('disaster_incidents')->count());

        $created = $this->actingAs($admin)->postJson('/cdrrmo/api/incidents', [
            'incident_name' => 'River flood',
            'description' => 'Water reached the road.',
            'occurred_at' => '2026-10-29T09:30',
            'disaster_type_id' => $typeId,
            'incident_status' => 'active',
            'barangay_ids' => [$selected],
        ])->assertCreated()
            ->assertJsonPath('occurred_at_display', '29 Oct 2026, 9:30 AM PHT')
            ->json();

        $this->assertStringStartsWith('INC-'.now()->format('Y').'-', $created['incident_reference']);
        $this->assertSame('2026-10-29 01:30:00', DB::table('disaster_incidents')->value('occurred_at'));
        $this->assertSame([$selected], DB::table('disaster_barangays')->pluck('barangay_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains($other, DB::table('disaster_barangays')->pluck('barangay_id')->map(fn ($id) => (int) $id)->all());

        $this->actingAs($this->makeUser('resident'))->postJson('/cdrrmo/api/incidents', [])->assertForbidden();
    }

    public function test_edit_preserves_the_reference_and_blocks_a_barangay_with_reports(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');
        $typeId = $this->type('Flood');
        $kept = $this->barangay('166819008', 'Mabua');
        $linked = $this->barangay('166819005', 'Bongtud');
        $incidentId = DB::table('disaster_incidents')->insertGetId([
            'disaster_type_id' => $typeId,
            'incident_reference' => 'INC-KEEP-001',
            'incident_name' => 'Coastal flood',
            'occurred_at' => '2026-10-29 01:30:00',
            'incident_status' => 'active',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$kept, $linked] as $barangayId) {
            DB::table('disaster_barangays')->insert([
                'disaster_incident_id' => $incidentId,
                'barangay_id' => $barangayId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('barangay_reports')->insert([
            'report_reference' => 'BR-KEEP-001',
            'report_status' => 'draft',
            'disaster_incident_id' => $incidentId,
            'barangay_id' => $linked,
            'prepared_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->patchJson('/cdrrmo/api/incidents/'.$incidentId, [
            'incident_name' => 'Coastal flood revised',
            'barangay_ids' => [$kept],
            'occurred_at' => '2026-10-29T09:30',
            'disaster_type_id' => $typeId,
            'incident_status' => 'active',
        ])->assertStatus(422);

        $this->assertSame('INC-KEEP-001', DB::table('disaster_incidents')->where('id', $incidentId)->value('incident_reference'));
        $this->assertTrue(DB::table('disaster_barangays')->where('barangay_id', $linked)->exists());

        $this->actingAs($admin)->patchJson('/cdrrmo/api/incidents/'.$incidentId, [
            'incident_status' => 'closed',
        ])->assertStatus(422);

        $this->actingAs($admin)->patchJson('/cdrrmo/api/incidents/'.$incidentId, [
            'incident_status' => 'closed',
            'confirm_close' => true,
        ])->assertOk();

        $this->assertSame('closed', DB::table('disaster_incidents')->where('id', $incidentId)->value('incident_status'));
        $this->assertSame('draft', DB::table('barangay_reports')->value('report_status'));
        $this->assertStringContainsString('Reports were not approved', (string) DB::table('activity_events')->value('summary'));
    }

    public function test_summary_counts_are_not_limited_to_the_current_page(): void
    {
        $admin = $this->makeUser('cdrrmo_admin');
        $typeId = $this->type('Earthquake');
        foreach (['INC-A', 'INC-B', 'INC-C'] as $index => $reference) {
            DB::table('disaster_incidents')->insert([
                'disaster_type_id' => $typeId,
                'incident_reference' => $reference,
                'incident_name' => 'Event '.$reference,
                'occurred_at' => now()->subDays($index),
                'incident_status' => $index === 2 ? 'closed' : 'active',
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($admin)->getJson('/cdrrmo/api/incidents?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($admin)->getJson('/cdrrmo/api/incidents/summary')
            ->assertOk()
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('counts.active', 2)
            ->assertJsonPath('counts.closed', 1)
            ->assertJsonPath('filters_applied', false);

        $this->actingAs($admin)->getJson('/cdrrmo/api/incidents/summary?search=INC-C')
            ->assertOk()
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('counts.closed', 1)
            ->assertJsonPath('filters_applied', true);
    }

    private function createIncidentSchema(): void
    {
        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disaster_type_id');
            $table->string('incident_reference')->unique();
            $table->string('incident_name');
            $table->text('description')->nullable();
            $table->dateTime('occurred_at');
            $table->string('incident_status')->default('active');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
            $table->unique(['disaster_incident_id', 'barangay_id']);
        });

        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->string('report_status');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });

        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->timestamps();
        });
    }

    private function type(string $name): int
    {
        return (int) DB::table('disaster_types')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function barangay(string $code, string $name): int
    {
        return (int) DB::table('barangays')->insertGetId([
            'barangay_code' => $code,
            'barangay_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
