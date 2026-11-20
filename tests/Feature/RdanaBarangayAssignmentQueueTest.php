<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class RdanaBarangayAssignmentQueueTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createTables();
    }

    public function test_supervisor_assigns_and_queues_move_correctly(): void
    {
        $barangay = $this->barangay();
        $admin = $this->member('rdana_admin_officer', 'Mark Merillo', $barangay);
        $city = $this->member('rdana_city_assessor', 'Lovy Dedicatoria', $barangay);
        $assessor = $this->member('rdana', 'James Tan', $barangay);
        $other = $this->member('rdana', 'Orland Falcon', $barangay);
        $officer = $this->member('bdrrm', 'Bdrrm Officer', $barangay);
        $incident = DB::table('disaster_incidents')->insertGetId([
            'incident_name' => 'Demo flood',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportA = $this->handedOffReport('BR-A', $barangay, $incident, $officer->id);
        $reportB = $this->handedOffReport('BR-B', $barangay, $incident, $officer->id);

        $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportA.'/assign', [
            'assignee_user_id' => $assessor->id,
        ])->assertForbidden();

        $unassigned = $this->actingAs($admin)->getJson('/rdana/api/barangay-reports?queue=unassigned')->assertOk();
        $unassigned->assertJsonPath('counts.unassigned', 2);
        $unassigned->assertJsonPath('meta.total', 2);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/assign', [
            'report_ids' => [$reportA, $reportB],
            'distribution' => [
                ['assignee_user_id' => $assessor->id, 'count' => 2],
            ],
            'remarks' => 'Legacy distribution payload.',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/assign', [
            'assignee_user_id' => $assessor->id,
            'report_ids' => [$reportA, $reportB],
            'remarks' => 'Assign both to James Tan.',
        ])->assertOk();

        $this->assertSame(1, DB::table('report_assignments')->where('subject_id', $reportA)->where('assignee_user_id', $assessor->id)->where('is_active', true)->count());
        $this->assertSame(1, DB::table('report_assignments')->where('subject_id', $reportB)->where('assignee_user_id', $assessor->id)->where('is_active', true)->count());
        $this->assertSame(2, DB::table('field_assessments')->count());

        $this->actingAs($admin)->getJson('/rdana/api/barangay-reports?queue=unassigned')
            ->assertOk()
            ->assertJsonPath('counts.unassigned', 0)
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson('/rdana/api/barangay-reports?queue=pending')
            ->assertOk()
            ->assertJsonPath('counts.pending', 2);

        $mine = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=pending')->assertOk();
        $mine->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$reportA, $reportB], collect($mine->json('data'))->pluck('id')->all());

        $reportC = $this->handedOffReport('BR-C', $barangay, $incident, $officer->id);
        $this->actingAs($city)->postJson('/rdana/api/barangay-reports/assign', [
            'assignee_user_id' => $other->id,
            'report_ids' => [$reportC],
        ])->assertOk();
        $this->assertSame(1, DB::table('report_assignments')->where('subject_id', $reportC)->where('assignee_user_id', $other->id)->where('is_active', true)->count());

        $assessmentId = (int) DB::table('field_assessments')->where('barangay_report_id', $reportA)->value('id');
        DB::table('field_assessments')->where('id', $assessmentId)->update([
            'assessment_status' => 'submitted',
            'workflow_stage' => 'pending_admin_verification',
            'updated_at' => now(),
        ]);

        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $reportB);
        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports?queue=completed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $reportA);

        $personnel = $this->actingAs($city)->getJson('/rdana/api/personnel?barangay_id='.$barangay.'&role=rdana')->assertOk();
        $this->assertNotEmpty($personnel->json('data'));
        $names = collect($personnel->json('data'))->pluck('name')->all();
        $this->assertContains('James Tan', $names);
    }

    public function test_supervisor_reassigns_pending_report_and_transfers_field_assessment(): void
    {
        $barangay = $this->barangay();
        $admin = $this->member('rdana_admin_officer', 'Admin Officer', $barangay);
        $first = $this->member('rdana', 'First Assessor', $barangay);
        $second = $this->member('rdana', 'Second Assessor', $barangay);
        $officer = $this->member('bdrrm', 'Bdrrm Officer', $barangay);
        $incident = DB::table('disaster_incidents')->insertGetId([
            'incident_name' => 'Reassign flood',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportId = $this->handedOffReport('BR-REASSIGN', $barangay, $incident, $officer->id);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/'.$reportId.'/assign', [
            'assignee_user_id' => $first->id,
            'remarks' => 'Initial assignment.',
        ])->assertOk();

        $assessmentId = (int) DB::table('field_assessments')->where('barangay_report_id', $reportId)->value('id');
        $this->assertGreaterThan(0, $assessmentId);
        DB::table('field_assessments')->where('id', $assessmentId)->update([
            'findings' => 'Draft roof damage notes',
            'assessment_status' => 'draft',
            'workflow_stage' => 'assessor_draft',
            'updated_at' => now(),
        ]);

        $this->actingAs($first)->postJson('/rdana/api/barangay-reports/'.$reportId.'/assign', [
            'assignee_user_id' => $second->id,
            'replaced_reason' => 'Workload balancing',
        ])->assertForbidden();

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/'.$reportId.'/assign', [
            'assignee_user_id' => $second->id,
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/'.$reportId.'/assign', [
            'assignee_user_id' => $second->id,
            'replaced_reason' => 'Original assessor unavailable for field work.',
        ])->assertOk();

        $this->assertSame(1, DB::table('report_assignments')
            ->where('subject_id', $reportId)
            ->where('assignee_user_id', $first->id)
            ->where('is_active', false)
            ->where('replaced_reason', 'Original assessor unavailable for field work.')
            ->count());
        $this->assertSame(1, DB::table('report_assignments')
            ->where('subject_id', $reportId)
            ->where('assignee_user_id', $second->id)
            ->where('is_active', true)
            ->count());
        $this->assertSame(1, DB::table('field_assessments')->where('barangay_report_id', $reportId)->count());
        $this->assertSame($second->id, (int) DB::table('field_assessments')->where('id', $assessmentId)->value('assigned_assessor_id'));
        $this->assertSame('Draft roof damage notes', DB::table('field_assessments')->where('id', $assessmentId)->value('findings'));

        $pendingSecond = $this->actingAs($second)->getJson('/rdana/api/barangay-reports?queue=pending')->assertOk();
        $this->assertContains($reportId, collect($pendingSecond->json('data'))->pluck('id')->all());
        $pendingFirst = $this->actingAs($first)->getJson('/rdana/api/barangay-reports?queue=pending')->assertOk();
        $this->assertNotContains($reportId, collect($pendingFirst->json('data'))->pluck('id')->all());

        $row = collect($this->actingAs($admin)->getJson('/rdana/api/barangay-reports?queue=pending')->json('data'))
            ->firstWhere('id', $reportId);
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row['can_reassign'] ?? false));
        $this->assertNotEmpty($row['assigned_at'] ?? null);
    }

    public function test_bulk_reassign_requires_same_barangay_and_blocks_submitted_assessments(): void
    {
        $barangayA = $this->barangay();
        $barangayB = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819023',
            'barangay_name' => 'Quezon',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->member('rdana_admin_officer', 'Bulk Admin', $barangayA);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $admin->id,
            'barangay_id' => $barangayB,
            'assigned_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $first = $this->member('rdana', 'Bulk First', $barangayA);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $first->id,
            'barangay_id' => $barangayB,
            'assigned_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second = $this->member('rdana', 'Bulk Second', $barangayA);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $second->id,
            'barangay_id' => $barangayB,
            'assigned_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $officer = $this->member('bdrrm', 'Bulk Officer', $barangayA);
        $incident = DB::table('disaster_incidents')->insertGetId([
            'incident_name' => 'Bulk reassign',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportA = $this->handedOffReport('BR-BULK-A', $barangayA, $incident, $officer->id);
        $reportB = $this->handedOffReport('BR-BULK-B', $barangayA, $incident, $officer->id);
        $reportOther = $this->handedOffReport('BR-BULK-C', $barangayB, $incident, $officer->id);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/assign', [
            'assignee_user_id' => $first->id,
            'report_ids' => [$reportA, $reportB],
        ])->assertOk();
        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/'.$reportOther.'/assign', [
            'assignee_user_id' => $first->id,
        ])->assertOk();

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/assign', [
            'assignee_user_id' => $second->id,
            'report_ids' => [$reportA, $reportB],
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/assign', [
            'assignee_user_id' => $second->id,
            'report_ids' => [$reportA, $reportOther],
            'replaced_reason' => 'Trying mixed barangays.',
        ])->assertStatus(422);

        DB::table('field_assessments')->where('barangay_report_id', $reportB)->update([
            'assessment_status' => 'submitted',
            'workflow_stage' => 'pending_admin_verification',
            'updated_at' => now(),
        ]);
        $activeBefore = DB::table('report_assignments')
            ->where('subject_id', $reportA)
            ->where('assignee_user_id', $first->id)
            ->where('is_active', true)
            ->count();

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/assign', [
            'assignee_user_id' => $second->id,
            'report_ids' => [$reportA, $reportB],
            'replaced_reason' => 'Should fail because one is submitted.',
        ])->assertStatus(422);

        $this->assertSame($activeBefore, DB::table('report_assignments')
            ->where('subject_id', $reportA)
            ->where('assignee_user_id', $first->id)
            ->where('is_active', true)
            ->count());

        DB::table('field_assessments')->where('barangay_report_id', $reportB)->update([
            'assessment_status' => 'draft',
            'workflow_stage' => 'assessor_draft',
            'findings' => 'Keep me',
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/rdana/api/barangay-reports/assign', [
            'assignee_user_id' => $second->id,
            'report_ids' => [$reportA, $reportB],
            'replaced_reason' => 'Rebalance pending assessments.',
        ])->assertOk();

        $this->assertSame(1, DB::table('report_assignments')
            ->where('subject_id', $reportA)
            ->where('assignee_user_id', $second->id)
            ->where('is_active', true)
            ->count());
        $this->assertSame(1, DB::table('report_assignments')
            ->where('subject_id', $reportB)
            ->where('assignee_user_id', $second->id)
            ->where('is_active', true)
            ->count());
        $this->assertSame($second->id, (int) DB::table('field_assessments')->where('barangay_report_id', $reportB)->value('assigned_assessor_id'));
        $this->assertSame('Keep me', DB::table('field_assessments')->where('barangay_report_id', $reportB)->value('findings'));
        $this->assertSame(1, DB::table('field_assessments')->where('barangay_report_id', $reportB)->count());
    }

    public function test_bdrrm_personnel_cannot_access_residents_registry(): void
    {
        $barangay = $this->barangay();
        $personnel = $this->member('bdrrm', 'Field Officer', $barangay);
        $secretary = $this->member('barangay_secretary', 'Leonida Lagunda', $barangay);

        $this->actingAs($personnel)->getJson('/bdrrm/api/residents')->assertForbidden();
        $this->actingAs($secretary)->getJson('/bdrrm/api/residents')->assertOk();
    }

    public function test_rdana_buildings_list_uses_assignment_scope(): void
    {
        $barangay = $this->barangay();
        $other = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819022',
            'barangay_name' => 'Telaje',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assessor = $this->member('rdana', 'Olver Ravelo', $barangay);
        DB::table('buildings')->insert([
            [
                'building_reference' => 'BLD-MABUA',
                'barangay_id' => $barangay,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'building_reference' => 'BLD-TELAJE',
                'barangay_id' => $other,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $list = $this->actingAs($assessor)->getJson('/rdana/api/buildings')->assertOk();
        $refs = collect($list->json('data'))->pluck('reference')->all();
        $this->assertContains('BLD-MABUA', $refs);
        $this->assertNotContains('BLD-TELAJE', $refs);
    }

    private function handedOffReport(string $reference, int $barangay, int $incident, int $preparedBy): int
    {
        return DB::table('barangay_reports')->insertGetId([
            'report_reference' => $reference,
            'report_description' => 'Handed off.',
            'report_status' => 'review',
            'workflow_stage' => 'handed_to_rdana',
            'submitted_at' => now(),
            'disaster_incident_id' => $incident,
            'barangay_id' => $barangay,
            'prepared_by' => $preparedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function barangay(): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function member(string $role, string $name, int $barangayId): User
    {
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => $role,
                'code' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $parts = explode(' ', $name, 2);
        $user = User::query()->create([
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? 'User',
            'email' => strtolower(str_replace(' ', '.', $name)).random_int(1, 999).'@mapr.test',
            'password' => bcrypt('H4ckm3!_'),
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

        return $user->fresh(['role']);
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('disaster_incidents')) {
            Schema::create('disaster_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('incident_name');
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('barangay_reports')) {
            Schema::create('barangay_reports', function (Blueprint $table) {
                $table->id();
                $table->string('report_reference');
                $table->text('report_description')->nullable();
                $table->string('report_status')->default('draft');
                $table->string('workflow_stage')->nullable();
                $table->dateTime('submitted_at')->nullable();
                $table->unsignedBigInteger('disaster_incident_id')->nullable();
                $table->unsignedBigInteger('barangay_id');
                $table->unsignedBigInteger('prepared_by');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('report_assignments')) {
            Schema::create('report_assignments', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type');
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('assignee_user_id');
                $table->unsignedBigInteger('assigned_by');
                $table->string('assignment_role');
                $table->text('remarks')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('replaced_reason')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('field_assessments')) {
            Schema::create('field_assessments', function (Blueprint $table) {
                $table->id();
                $table->string('assessment_reference');
                $table->text('findings')->nullable();
                $table->string('assessment_status')->default('draft');
                $table->string('workflow_stage')->nullable();
                $table->unsignedBigInteger('barangay_report_id');
                $table->unsignedBigInteger('assigned_assessor_id');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('buildings')) {
            Schema::create('buildings', function (Blueprint $table) {
                $table->id();
                $table->string('building_reference');
                $table->string('building_name')->nullable();
                $table->string('building_type')->nullable();
                $table->text('address_description')->nullable();
                $table->string('location_source')->nullable();
                $table->string('location_verification_status')->default('unverified');
                $table->unsignedBigInteger('barangay_id');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('households')) {
            Schema::create('households', function (Blueprint $table) {
                $table->id();
                $table->string('household_reference')->nullable();
                $table->string('household_status')->default('active');
                $table->unsignedBigInteger('building_id')->nullable();
                $table->unsignedBigInteger('barangay_id')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('barangay_report_buildings')) {
            Schema::create('barangay_report_buildings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('barangay_report_id');
                $table->unsignedBigInteger('building_id');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('residents')) {
            Schema::create('residents', function (Blueprint $table) {
                $table->id();
                $table->string('registry_reference')->nullable();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('residency_status')->default('active');
                $table->unsignedBigInteger('household_id')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('activity_events')) {
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
    }
}
