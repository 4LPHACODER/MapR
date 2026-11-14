<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class BdrrmReportQueueTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createReportTables();
    }

    public function test_queue_stays_inside_the_assignment_and_filters_the_authorized_set(): void
    {
        $home = $this->barangay('Mabua');
        $other = $this->barangay('Telaje');
        $officer = $this->officer($home);
        $flood = $this->incident('Flood');
        $fire = $this->incident('Fire');
        $building = $this->building($home, 'BLD-1');
        $draft = $this->report($officer->id, $home, $flood, 'BR-DRAFT', 'draft', 'Doorway is wet.', '2026-03-01 08:00:00');
        $returned = $this->report($officer->id, $home, $fire, 'BR-RETURNED', 'returned', 'Revise the description.', '2026-03-02 08:00:00', '2026-03-04 09:00:00', 'Add the building photo.');
        $review = $this->report($officer->id, $home, $flood, 'BR-REVIEW', 'review', 'Sent for review.', '2026-03-03 08:00:00', null, null, '2026-03-03 10:00:00');
        $this->report($officer->id, $other, $flood, 'BR-FOREIGN', 'draft', 'Outside.', '2026-03-05 08:00:00');
        DB::table('barangay_report_buildings')->insert([
            'barangay_report_id' => $draft,
            'building_id' => $building,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('resident_incident_reports')->insert([
            'report_reference' => 'RIR-LINKED',
            'report_status' => 'reviewed',
            'barangay_report_id' => $draft,
            'resident_user_id' => $officer->id,
            'disaster_incident_id' => $flood,
            'barangay_id' => $home,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_signatures')->insert([
            'signature_reference' => 'sig-1',
            'document_reference' => 'BR-REVIEW',
            'document_hash' => str_repeat('a', 64),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => 'barangay_signer',
            'signed_at' => '2026-03-03 09:00:00',
            'verification_status' => 'verified',
            'signer_user_id' => $officer->id,
            'barangay_report_id' => $review,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $drafts = $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=draft')->assertOk();
        $drafts->assertJsonPath('meta.total', 1);
        $drafts->assertJsonPath('data.0.reference', 'BR-DRAFT');
        $drafts->assertJsonPath('data.0.incident_name', 'Flood');
        $drafts->assertJsonPath('data.0.barangay_name', 'Mabua');
        $drafts->assertJsonPath('data.0.building_reference', 'BLD-1');
        $drafts->assertJsonPath('data.0.resident_reference', 'RIR-LINKED');
        $drafts->assertJsonPath('data.0.signature_recorded', false);
        $drafts->assertJsonPath('data.0.description', 'Doorway is wet.');
        $this->assertNotContains('BR-FOREIGN', $drafts->json('data.*.reference'));

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=returned')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'BR-RETURNED')
            ->assertJsonPath('data.0.label', 'Returned for correction')
            ->assertJsonPath('data.0.return_reason', 'Add the building photo.')
            ->assertJsonPath('data.0.returned_at', '2026-03-04 09:00:00');

        $handed = $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off')->assertOk();
        $handed->assertJsonPath('meta.total', 1);
        $handed->assertJsonPath('data.0.reference', 'BR-REVIEW');
        $handed->assertJsonPath('data.0.label', 'Awaiting the next office');
        $handed->assertJsonPath('data.0.signature_recorded', true);
        $handed->assertJsonPath('data.0.submitted_at', '2026-03-03 10:00:00');

        $detail = $this->actingAs($officer)->getJson('/bdrrm/api/reports/'.$review)->assertOk();
        $detail->assertJsonPath('data.id', $review);
        $detail->assertJsonPath('data.reference', 'BR-REVIEW');
        $detail->assertJsonPath('data.status', 'review');
        $detail->assertJsonPath('data.can_edit', false);
        $this->actingAs($officer)->get('/bdrrm/reports/'.$review.'/preview')->assertOk();
        $this->actingAs($officer)->get('/bdrrm/reports/'.$review.'/preview?format=pdf')->assertOk();

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off&status_note=approved')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Awaiting the next office');

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=review')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=signed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?q=Fire')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'BR-RETURNED');
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?q=Mabua')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?search=BR-DRAFT')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'BR-DRAFT');

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?updated_from=2026-03-03&updated_to=2026-03-03')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'BR-REVIEW');
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?returned_from=2026-03-04&returned_to=2026-03-04')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'BR-RETURNED');
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?submitted_from=not-a-date&sort=not-a-column')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'BR-REVIEW');

        $sorted = $this->actingAs($officer)->getJson('/bdrrm/api/reports?sort=report_reference&direction=asc')->assertOk();
        $this->assertSame(['BR-DRAFT', 'BR-RETURNED', 'BR-REVIEW'], $sorted->json('data.*.reference'));

        $this->actingAs($officer)->getJson('/bdrrm/api/reports?barangay_id='.$other)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/reports/'.$this->reportId('BR-FOREIGN'))->assertForbidden();

        $this->actingAs($officer)->getJson('/bdrrm/api/navigation')
            ->assertOk()
            ->assertJsonPath('drafts', 1)
            ->assertJsonPath('returned', 1)
            ->assertJsonPath('submitted', 1);
    }

    private function createReportTables(): void
    {
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status')->default('active');
            $table->timestamps();
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference');
            $table->text('address_description')->nullable();
            $table->string('location_verification_status')->default('unverified');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('barangay_report_buildings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id');
            $table->timestamps();
        });
        Schema::create('resident_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->string('report_status');
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->string('document_hash', 64);
            $table->string('mechanism');
            $table->string('signature_role');
            $table->dateTime('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->timestamps();
        });
    }

    private function officer(int $barangayId): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'BDRRM',
            'code' => 'bdrrm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->create([
            'first_name' => 'Barangay',
            'last_name' => 'Officer',
            'email' => 'officer'.$barangayId.'@mapr.test',
            'password' => 'secret-pass',
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

        return $user->fresh('role');
    }

    private function barangay(string $name): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => '1668'.$name,
            'barangay_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function incident(string $name): int
    {
        return DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-'.$name,
            'incident_name' => $name,
            'incident_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function building(int $barangayId, string $reference): int
    {
        return DB::table('buildings')->insertGetId([
            'building_reference' => $reference,
            'address_description' => 'Purok 1',
            'location_verification_status' => 'verified',
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function report(
        int $userId,
        int $barangayId,
        int $incidentId,
        string $reference,
        string $status,
        string $description,
        string $updatedAt,
        ?string $returnedAt = null,
        ?string $reason = null,
        ?string $submittedAt = null,
    ): int {
        return DB::table('barangay_reports')->insertGetId([
            'report_reference' => $reference,
            'report_description' => $description,
            'report_status' => $status,
            'return_reason' => $reason,
            'returned_at' => $returnedAt,
            'submitted_at' => $submittedAt,
            'disaster_incident_id' => $incidentId,
            'barangay_id' => $barangayId,
            'prepared_by' => $userId,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function reportId(string $reference): int
    {
        return (int) DB::table('barangay_reports')->where('report_reference', $reference)->value('id');
    }
}
