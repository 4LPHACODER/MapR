<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\ResidentReportDocument;
use App\Services\ResidentReportWorkflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ResidentReportTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createReportTables();
    }

    public function test_residents_manage_only_their_own_reports_and_cannot_set_official_fields(): void
    {
        [$home, $other] = [$this->barangay('Home'), $this->barangay('Other')];
        $incident = $this->incident($home);
        $owner = $this->user('resident', 'Ana Cruz', $home);
        $neighbor = $this->user('resident', 'Ben Cruz', $home);
        $guest = $this->user('bdrrm', 'Barangay Officer', $home);

        $this->actingAs($owner)->postJson('/resident/api/reports', [
            'building_id' => 1,
        ])->assertStatus(422);
        $this->actingAs($owner)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $incident,
            'estimated_repair_cost' => 100,
            'report_status' => 'reviewed',
            'verified_damage_class_id' => 1,
        ])->assertStatus(422);

        $created = $this->actingAs($owner)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $incident,
            'incident_description' => 'Water entered the ground floor.',
            'reported_location' => 'Purok 2, near the chapel',
            'latitude' => 9.07,
            'longitude' => 126.19,
        ])->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($neighbor)->getJson('/resident/api/reports/'.$id)->assertForbidden();
        $this->actingAs($guest)->getJson('/resident/api/reports/'.$id)->assertForbidden();
        $this->getJson('/resident/api/reports/'.$id)->assertForbidden();

        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [
            'confirmed' => true,
        ])->assertStatus(422);

        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/photos', [
            'photo' => UploadedFile::fake()->create('note.txt', 10, 'text/plain'),
        ])->assertStatus(422);
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/photos', [
            'photo' => UploadedFile::fake()->image('wall.jpg'),
            'source' => 'capture',
        ])->assertCreated();

        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [])->assertStatus(422);
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'Please attach at least 2 photos before submitting your report.');
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/photos', [
            'photo' => UploadedFile::fake()->image('roof.jpg'),
            'source' => 'upload',
        ])->assertCreated();
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'Draw your signature before submitting your report.');
        $this->storeSignature($neighbor, 90, 40);
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [
            'confirmed' => true,
            'user_signature_asset_id' => DB::table('user_signature_assets')->where('user_id', $neighbor->id)->value('id'),
        ])->assertStatus(422)->assertJsonPath('message', 'Draw your signature before submitting your report.');
        $this->assertSame(0, DB::table('report_signatures')->where('resident_incident_report_id', $id)->count());
        $recordedAsset = $this->storeSignature($owner, 80, 40);
        $submitted = $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [
            'confirmed' => true,
        ])->assertOk();
        $this->assertSame('Pending BDRRM Review', $submitted->json('data.label'));
        $signature = DB::table('report_signatures')->where('resident_incident_report_id', $id)->get();
        $this->assertCount(1, $signature);
        $this->assertSame('resident_signer', $signature[0]->signature_role);
        $this->assertSame('authenticated_e_signature', $signature[0]->mechanism);
        $this->assertSame('verified', $signature[0]->verification_status);
        $this->assertSame($owner->id, (int) $signature[0]->signer_user_id);
        $this->assertSame($recordedAsset, (int) $signature[0]->user_signature_asset_id);
        $this->assertSame('The location, description, and photographs are accurate.', $signature[0]->signature_statement);
        $this->assertNull($signature[0]->barangay_report_id);
        $this->assertNull($signature[0]->field_assessment_id);
        $this->assertNull($signature[0]->consolidated_report_id);
        $storedReport = DB::table('resident_incident_reports')->where('id', $id)->first();
        $this->assertSame($storedReport->report_reference, $signature[0]->document_reference);
        $this->assertSame(hash('sha256', 'resident_incident_report|'.$id.'|resident_signer|'.$storedReport->updated_at), $signature[0]->document_hash);
        $replacement = $this->storeSignature($owner, 120, 48);
        $this->assertNotSame($recordedAsset, $replacement);
        $this->assertSame($recordedAsset, (int) DB::table('report_signatures')->where('resident_incident_report_id', $id)->value('user_signature_asset_id'));
        $preview = $this->actingAs($owner)->get('/resident/api/reports/'.$id.'/signature')->assertOk();
        $this->assertSame(Storage::disk('local')->get(DB::table('user_signature_assets')->where('id', $recordedAsset)->value('storage_path')), $preview->streamedContent());
        $this->actingAs($neighbor)->get('/resident/api/reports/'.$id.'/signature')->assertForbidden();
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [
            'confirmed' => true,
        ])->assertStatus(409)->assertJsonPath('reference', $submitted->json('data.reference'));
        $this->actingAs($owner)->deleteJson('/resident/api/reports/'.$id)->assertStatus(422);

        $officer = $this->officer($other);
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$id.'/return', [
            'return_reason' => 'The photo does not show the damaged wall.',
        ])->assertForbidden();
        $homeOfficer = $this->officer($home);
        $this->actingAs($homeOfficer)->postJson('/bdrrm/api/submissions/'.$id.'/return', [
            'return_reason' => 'The photo does not show the damaged wall.',
            'estimated_repair_cost' => 500,
        ])->assertStatus(422);
        $this->actingAs($homeOfficer)->postJson('/bdrrm/api/submissions/'.$id.'/return', [
            'return_reason' => 'The photo does not show the damaged wall.',
        ])->assertOk()->assertJsonPath('data.label', 'Needs Correction');

        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', [
            'confirmed' => true,
        ])->assertOk();
        $this->assertSame(1, DB::table('report_signatures')->where('resident_incident_report_id', $id)->count());
        $this->assertSame($recordedAsset, (int) DB::table('report_signatures')->where('resident_incident_report_id', $id)->value('user_signature_asset_id'));
        $accepted = $this->acceptSubmission($homeOfficer, $id);
        $this->assertNotNull($accepted->json('barangay_report_id'));
        $again = $this->acceptSubmission($homeOfficer, $id);
        $this->assertSame($accepted->json('barangay_report_id'), $again->json('barangay_report_id'));
        $this->assertSame(1, DB::table('barangay_reports')->count());
        $this->assertSame('draft', DB::table('barangay_reports')->value('report_status'));
        $this->assertNull(DB::table('barangay_reports')->value('submitted_at'));

        $dashboard = $this->actingAs($owner)->getJson('/resident/api/dashboard')->assertOk();
        $this->assertSame(1, $dashboard->json('counts.submitted'));
        $this->assertSame(1, $dashboard->json('counts.under_review'));
        $this->assertSame(0, $dashboard->json('counts.needs_correction'));
        $this->assertSame(0, $dashboard->json('counts.finalized'));
        $this->assertSame('Ana', $dashboard->json('first_name'));
        $activity = $dashboard->json('activity');
        $this->assertCount(6, $activity);
        $current = collect($activity)->firstWhere('label', now()->format('M Y'));
        $this->assertSame(1, $current['count']);
    }

    public function test_resident_can_confirm_verified_building_and_forged_ids_are_rejected(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->timestamps();
        });
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('registry_reference')->nullable();
            $table->unsignedBigInteger('household_id')->nullable();
            $table->timestamps();
        });
        Schema::create('account_resident_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('resident_id');
            $table->timestamps();
        });

        $home = $this->barangay('Mabua Confirm');
        $incident = $this->incident($home);
        $resident = $this->user('resident', 'Liza Confirm', $home);
        $buildingId = DB::table('buildings')->insertGetId([
            'building_reference' => 'CBMS22-BLDG-6268',
            'barangay_id' => $home,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBuilding = DB::table('buildings')->insertGetId([
            'building_reference' => 'CBMS22-BLDG-OTHER',
            'barangay_id' => $this->barangay('Other Confirm'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $householdId = DB::table('households')->insertGetId([
            'household_reference' => 'CBMS22-HH-6268',
            'barangay_id' => $home,
            'building_id' => $buildingId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $personId = DB::table('residents')->insertGetId([
            'registry_reference' => 'CBMS22-RES-6268',
            'household_id' => $householdId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('account_resident_links')->insert([
            'user_id' => $resident->id,
            'resident_id' => $personId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profile = $this->actingAs($resident)->getJson('/resident/api/profile')->assertOk();
        $profile->assertJsonPath('data.linked_building.building_id', $buildingId);
        $profile->assertJsonPath('data.linked_building.building_reference', 'CBMS22-BLDG-6268');
        $profile->assertJsonPath('data.linked_building.household_reference', 'CBMS22-HH-6268');

        $this->actingAs($resident)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $incident,
            'incident_description' => 'Flood entered the house.',
            'reported_location' => 'Purok 1',
            'latitude' => 9.07,
            'longitude' => 126.19,
            'confirm_affected_building' => true,
            'affected_building_id' => $foreignBuilding,
        ])->assertStatus(422);

        $created = $this->actingAs($resident)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $incident,
            'incident_description' => 'Flood entered the house.',
            'reported_location' => 'Purok 1',
            'latitude' => 9.07,
            'longitude' => 126.19,
            'confirm_affected_building' => true,
        ])->assertCreated();
        $this->assertSame($buildingId, (int) $created->json('data.building_id'));
        $this->assertTrue($created->json('data.confirm_affected_building'));

        $cleared = $this->actingAs($resident)->patchJson('/resident/api/reports/'.$created->json('data.id'), [
            'confirm_affected_building' => false,
        ])->assertOk();
        $this->assertNull($cleared->json('data.building_id'));
    }

    public function test_only_the_assigned_assessor_can_record_cost_after_bdrrm_submission(): void
    {
        $home = $this->barangay('Home');
        $incident = $this->incident($home);
        $owner = $this->user('resident', 'Ana Cruz', $home);
        $assessor = $this->user('rdana', 'Test Assessor', null);
        $other = $this->user('rdana', 'Other Assessor', null);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $assessor->id,
            'barangay_id' => $home,
            'assigned_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $building = DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-1',
            'barangay_id' => $home,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $class = DB::table('damage_classes')->insertGetId([
            'class_code' => 'partial',
            'class_name' => 'Partially damaged',
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-COST',
            'report_description' => 'Checked on site.',
            'report_status' => 'draft',
            'disaster_incident_id' => $incident,
            'barangay_id' => $home,
            'prepared_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assessment = DB::table('field_assessments')->insertGetId([
            'assessment_reference' => 'FA-COST',
            'assessment_status' => 'draft',
            'barangay_report_id' => $report,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'building_id' => $building,
            'location_verified' => true,
            'household_link_verified' => false,
            'verified_damage_class_id' => $class,
            'estimated_repair_cost' => 15000,
        ];

        $this->actingAs($owner)->postJson('/rdana/api/assessments/'.$assessment.'/verifications', $payload)->assertForbidden();
        $this->actingAs($assessor)->postJson('/rdana/api/assessments/'.$assessment.'/verifications', $payload)->assertStatus(422);
        DB::table('barangay_reports')->where('id', $report)->update(['submitted_at' => now(), 'report_status' => 'review']);
        $this->actingAs($other)->postJson('/rdana/api/assessments/'.$assessment.'/verifications', $payload)->assertForbidden();
        $this->actingAs($assessor)->postJson('/rdana/api/assessments/'.$assessment.'/verifications', $payload)->assertOk();
        $this->assertEquals(15000, DB::table('building_verifications')->value('estimated_repair_cost'));
    }

    public function test_a_resident_can_delete_only_a_draft(): void
    {
        $home = $this->barangay('Home');
        $owner = $this->user('resident', 'Ana Cruz', $home);
        $id = $this->actingAs($owner)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $this->incident($home),
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->deleteJson('/resident/api/reports/'.$id)->assertOk();
        $this->assertSame(0, DB::table('resident_incident_reports')->count());
    }

    public function test_incident_updates_show_only_published_coverage_for_the_resident(): void
    {
        $home = $this->barangay('Mabua');
        $other = $this->barangay('San Agustin');
        $resident = $this->user('resident', 'Ana Cruz', $home);
        $officer = $this->user('bdrrm', 'Barangay Officer', $home);
        $occurred = '2026-10-30 08:00:00';
        $published = '2026-10-30 09:00:00';

        $hidden = $this->incident($home);
        DB::table('disaster_incidents')->where('id', $hidden)->update([
            'description' => 'Internal note that residents must not see.',
        ]);
        $local = $this->incident($home);
        DB::table('disaster_incidents')->where('id', $local)->update([
            'incident_name' => 'Mabua flood',
            'description' => 'Water is rising along the river road.',
            'occurred_at' => $occurred,
            'published_for_residents' => true,
            'resident_coverage' => 'barangays',
            'published_at' => $published,
            'updated_at' => '2026-10-30 11:00:00',
        ]);
        $elsewhere = $this->incident($other);
        DB::table('disaster_incidents')->where('id', $elsewhere)->update([
            'incident_name' => 'San Agustin flood',
            'published_for_residents' => true,
            'resident_coverage' => 'barangays',
            'published_at' => $published,
        ]);
        $unmarked = $this->incident($home);
        DB::table('disaster_incidents')->where('id', $unmarked)->update([
            'incident_name' => 'Unscoped flood',
            'published_for_residents' => true,
            'resident_coverage' => null,
            'published_at' => $published,
        ]);
        DB::table('disaster_barangays')->where('disaster_incident_id', $unmarked)->delete();
        $citywide = $this->incident($other);
        DB::table('disaster_incidents')->where('id', $citywide)->update([
            'incident_name' => 'City flood advisory',
            'description' => '<script>alert(1)</script> Stay indoors.',
            'published_for_residents' => true,
            'resident_coverage' => 'citywide',
            'published_at' => '2026-10-30 08:30:00',
        ]);

        $this->actingAs($officer)->getJson('/resident/api/incident-updates')->assertForbidden();
        $response = $this->actingAs($resident)->getJson('/resident/api/incident-updates')->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Mabua flood', 'City flood advisory'], $names);
        $localPayload = $response->json('data.0');
        $this->assertSame('Flood '.$home, $localPayload['disaster_type']);
        $this->assertSame('Active', $localPayload['status_label']);
        $this->assertSame('Mabua', $localPayload['coverage_label']);
        $this->assertSame($occurred, $localPayload['occurred_at']);
        $this->assertSame($published, $localPayload['published_at']);
        $this->assertSame('2026-10-30 11:00:00', $localPayload['updated_at']);
        $this->assertSame('Water is rising along the river road.', $localPayload['description']);
        $this->assertArrayNotHasKey('created_by', $localPayload);
        $this->assertSame('City-wide', $response->json('data.1.coverage_label'));
        $this->assertStringContainsString('<script>', $response->json('data.1.description'));
    }

    private function createReportTables(): void
    {
        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status')->default('active');
            $table->text('description')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->unsignedBigInteger('disaster_type_id')->nullable();
            $table->boolean('published_for_residents')->default(false);
            $table->string('resident_coverage', 16)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
        });
        Schema::create('damage_classes', function (Blueprint $table) {
            $table->id();
            $table->string('class_code');
            $table->string('class_name');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });
        Schema::create('resident_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('incident_description')->nullable();
            $table->text('reported_location')->nullable();
            $table->string('reported_point')->nullable();
            $table->string('report_status')->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->boolean('observed_at_approximate')->default(false);
            $table->unsignedBigInteger('resident_user_id');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->timestamps();
        });
        Schema::create('incident_photos', function (Blueprint $table) {
            $table->id();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('file_sha256', 64);
            $table->string('image_source');
            $table->unsignedBigInteger('incident_report_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->string('workflow_stage')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('barangay_report_buildings', function (Blueprint $table) {
            $table->id();
            $table->text('observations')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id');
            $table->timestamps();
        });
        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference');
            $table->text('findings')->nullable();
            $table->string('assessment_status')->default('draft');
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamps();
        });
        Schema::create('building_verifications', function (Blueprint $table) {
            $table->id();
            $table->boolean('location_verified')->default(false);
            $table->boolean('household_link_verified')->default(false);
            $table->text('verification_notes')->nullable();
            $table->dateTime('verified_at');
            $table->unsignedBigInteger('field_assessment_id');
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('verified_damage_class_id');
            $table->unsignedBigInteger('verified_by');
            $table->decimal('estimated_repair_cost', 12, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('user_signature_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('storage_path');
            $table->string('mime_type', 64);
            $table->unsignedInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('sha256', 64);
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->char('document_hash', 64);
            $table->string('mechanism')->default('authenticated_e_signature');
            $table->string('signature_role');
            $table->dateTime('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('consolidated_report_id')->nullable();
            $table->unsignedBigInteger('resident_incident_report_id')->nullable();
            $table->unsignedBigInteger('user_signature_asset_id')->nullable();
            $table->text('signature_statement')->nullable();
            $table->timestamps();
            $table->unique(['resident_incident_report_id', 'signature_role']);
        });
    }

    public function test_document_download_is_limited_to_the_owner_and_matches_the_saved_record(): void
    {
        $home = $this->barangay('Mabua');
        DB::table('barangays')->where('id', $home)->update(['barangay_code' => '166819008']);
        $incident = $this->incident($home);
        $owner = $this->user('resident', 'Case Rivera', $home);
        $neighbor = $this->user('resident', 'Other Resident', $home);
        $created = $this->actingAs($owner)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $incident,
            'incident_description' => 'Water reached the doorway.',
            'reported_location' => 'Purok 1',
            'latitude' => 9.07123,
            'longitude' => 126.19111,
        ])->assertCreated();
        $id = $created->json('data.id');
        $reference = $created->json('data.reference');

        $this->actingAs($owner)->patchJson('/resident/api/reports/'.$id, [
            'incident_description' => 'Water reached the doorway and the wall.',
        ])->assertOk();
        $this->assertSame(1, DB::table('resident_incident_reports')->count());
        $this->assertSame('Water reached the doorway and the wall.', DB::table('resident_incident_reports')->where('id', $id)->value('incident_description'));

        $draft = app(ResidentReportDocument::class)->payload(DB::table('resident_incident_reports')->where('id', $id)->first());
        $draftHtml = view('resident.incident-report', $draft)->render();
        $this->assertStringContainsString('DRAFT', $draftHtml);
        $this->assertStringContainsString('Resident Disaster Incident Report', $draftHtml);
        $this->assertStringContainsString('Signature not recorded.', $draftHtml);
        $this->assertStringContainsString('Pending endorsement', $draftHtml);
        $this->assertStringContainsString('Awaiting assessment.', $draftHtml);
        $this->assertStringContainsString('Barangay Mabua', $draftHtml);
        $this->assertStringNotContainsString('9.07123', $draftHtml);
        $this->assertSame('Draft', $draft['status']);
        $this->assertStringNotContainsString('AWASIAN', $draftHtml);
        $this->assertTrue(collect($draft['logos'])->contains(fn ($logo) => str_contains($logo['path'], 'BRGY_MABUA_LOGO.jpg')));

        $lungsod = $this->barangay('Bag-ong Lungsod');
        DB::table('barangays')->where('id', $lungsod)->update(['barangay_code' => '166819002']);
        DB::table('resident_incident_reports')->where('id', $id)->update(['barangay_id' => $lungsod]);
        $plain = app(ResidentReportDocument::class)->payload(DB::table('resident_incident_reports')->where('id', $id)->first());
        $this->assertFalse(collect($plain['logos'])->contains(fn ($logo) => str_contains($logo['path'], 'AWASIAN')));
        $this->assertSame('Bag-ong Lungsod', $plain['barangay_name']);
        DB::table('resident_incident_reports')->where('id', $id)->update(['barangay_id' => $home]);

        $this->actingAs($neighbor)->get('/resident/api/reports/'.$id.'/document')->assertForbidden();
        $pdf = $this->actingAs($owner)->get('/resident/api/reports/'.$id.'/document?download=1')->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringContainsString('MapR_Resident_Report_'.$reference.'.pdf', (string) $pdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $row = DB::table('resident_incident_reports')->where('id', $id)->first();
        DB::table('resident_incident_reports')->where('id', $id)->update(['report_status' => 'submitted']);
        try {
            app(ResidentReportWorkflow::class)->update($owner, $row, ['incident_description' => 'Changed after submission.']);
            $this->fail('A submitted report was edited.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
        }
        $this->assertSame('Water reached the doorway and the wall.', DB::table('resident_incident_reports')->where('id', $id)->value('incident_description'));
        $this->actingAs($owner)->patchJson('/resident/api/reports/'.$id, [
            'incident_description' => 'Should stay locked.',
        ])->assertForbidden();
    }

    public function test_a_returned_report_keeps_the_original_signature_and_can_be_revised(): void
    {
        $home = $this->barangay('Telaje');
        DB::table('barangays')->where('id', $home)->update(['barangay_code' => '166819022']);
        $incident = $this->incident($home);
        $owner = $this->user('resident', 'Ana Cruz', $home);
        $id = $this->actingAs($owner)->postJson('/resident/api/reports', [
            'disaster_incident_id' => $incident,
            'incident_description' => 'The roof leaked.',
            'reported_location' => 'Purok 3',
            'latitude' => 9.08,
            'longitude' => 126.2,
        ])->json('data.id');
        $this->storeSignature($owner, 80, 40);
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/photos', [
            'photo' => UploadedFile::fake()->image('one.jpg'),
            'source' => 'upload',
        ]);
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/photos', [
            'photo' => UploadedFile::fake()->image('two.jpg'),
            'source' => 'upload',
        ]);
        $this->actingAs($owner)->postJson('/resident/api/reports/'.$id.'/submit', ['confirmed' => true])->assertOk();
        $officer = $this->officer($home);
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$id.'/return', [
            'return_reason' => 'Add a closer photograph.',
        ])->assertOk();

        $shown = $this->actingAs($owner)->getJson('/resident/api/reports/'.$id)->assertOk();
        $this->assertTrue($shown->json('data.editable'));
        $this->assertNull($shown->json('data.edit_message'));
        $this->assertTrue($shown->json('data.signature.original_submission'));
        $this->assertStringContainsString('original submission', $shown->json('data.signature.caption'));

        $this->actingAs($owner)->patchJson('/resident/api/reports/'.$id, [
            'incident_description' => 'The roof leaked into the kitchen.',
        ])->assertOk();
        $this->assertSame(1, DB::table('resident_incident_reports')->count());
        $this->assertSame(1, DB::table('report_signatures')->where('signature_role', 'resident_signer')->count());

        $barangayReport = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-TEL-1',
            'report_description' => 'Barangay draft',
            'report_status' => 'draft',
            'disaster_incident_id' => $incident,
            'barangay_id' => $home,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_signatures')->insert([
            'signature_reference' => 'sig-brgy',
            'document_reference' => 'BR-TEL-1',
            'document_hash' => str_repeat('a', 64),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => 'barangay_signer',
            'signed_at' => now(),
            'verification_status' => 'verified',
            'signer_user_id' => $officer->id,
            'barangay_report_id' => $barangayReport,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('resident_incident_reports')->where('id', $id)->update(['barangay_report_id' => $barangayReport]);
        $payload = app(ResidentReportDocument::class)->payload(DB::table('resident_incident_reports')->where('id', $id)->first());
        $barangayLine = collect($payload['endorsements'])->firstWhere('title', 'Endorsed by');
        $this->assertTrue($barangayLine['signed']);
        $this->assertSame('Officer '.$home, $barangayLine['name']);
        $this->assertSame('Barangay report BR-TEL-1', $barangayLine['document']);
        $this->assertSame('Pending endorsement', collect($payload['endorsements'])->firstWhere('title', 'Verified by')['caption']);
        $this->assertSame('Pending endorsement', collect($payload['endorsements'])->firstWhere('title', 'Checked by')['caption']);
        $this->assertSame('Noted by', collect($payload['endorsements'])->firstWhere('role', 'approved_by')['title']);
        $html = view('resident.incident-report', $payload)->render();
        $this->assertStringContainsString('Photo 1', $html);
        $this->assertStringContainsString('Photo 2', $html);
        $this->assertStringContainsString('original submission', $html);
        $this->assertStringContainsString('BRGY_TELAJE_LOGO.jpg', $html);
    }

    public function test_bdrrm_reviews_only_pending_reports_inside_the_assignment(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->timestamps();
        });
        $mabua = $this->barangay('Mabua');
        $second = $this->barangay('Second');
        $other = $this->barangay('Other');
        $incident = $this->incident($mabua);
        DB::table('disaster_barangays')->insert([
            'disaster_incident_id' => $incident,
            'barangay_id' => $second,
        ]);
        $resident = $this->user('resident', 'Case Rivera', $mabua);
        $officer = $this->officer($mabua);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $officer->id,
            'barangay_id' => $second,
            'assigned_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherOfficer = $this->officer($other);
        $revoked = $this->user('bdrrm', 'Revoked Officer', $mabua);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'revoked_at' => now(),
            'user_id' => $revoked->id,
            'barangay_id' => $mabua,
            'assigned_by' => $revoked->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bare = $this->user('bdrrm', 'Bare Officer', null);
        $insert = function (string $reference, int $barangayId, string $status, ?string $point = null, ?int $handoff = null) use ($resident, $incident) {
            return DB::table('resident_incident_reports')->insertGetId([
                'report_reference' => $reference,
                'incident_description' => 'Water reached the doorway.',
                'reported_location' => 'Purok 2',
                'reported_point' => $point,
                'report_status' => $status,
                'submitted_at' => $status === 'draft' ? null : now(),
                'resident_user_id' => $resident->id,
                'disaster_incident_id' => $incident,
                'barangay_id' => $barangayId,
                'barangay_report_id' => $handoff,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        $point = $insert('RIR-QUEUE-POINT', $mabua, 'submitted', '126.203 9.069');
        $plain = $insert('RIR-QUEUE-PLAIN', $mabua, 'submitted');
        $bad = $insert('RIR-QUEUE-BAD', $mabua, 'submitted', '999 999');
        $draft = $insert('RIR-QUEUE-DRAFT', $mabua, 'draft', '126.203 9.069');
        $foreign = $insert('RIR-QUEUE-FOREIGN', $other, 'submitted', '126.100 9.100');
        $handoff = $insert('RIR-QUEUE-HANDOFF', $mabua, 'submitted', null, 1);
        $secondReport = $insert('RIR-QUEUE-SECOND', $second, 'submitted', '126.210 9.070');
        $returned = $insert('RIR-QUEUE-RETURN', $mabua, 'submitted');

        $summary = $this->actingAs($officer)->getJson('/bdrrm/api/summary')->assertOk();
        $card = collect($summary->json('cards'))->firstWhere('key', 'submissions');
        $this->assertSame(5, $card['count']);

        $list = $this->actingAs($officer)->getJson('/bdrrm/api/submissions')->assertOk();
        $references = collect($list->json('data'))->pluck('reference')->all();
        $this->assertEqualsCanonicalizing([
            'RIR-QUEUE-POINT',
            'RIR-QUEUE-PLAIN',
            'RIR-QUEUE-BAD',
            'RIR-QUEUE-SECOND',
            'RIR-QUEUE-RETURN',
        ], $references);
        $listed = collect($list->json('data'))->firstWhere('reference', 'RIR-QUEUE-POINT');
        $this->assertSame('Pending BDRRM Review', $listed['label']);
        $this->assertSame('submitted', $listed['status']);
        $this->assertSame('Case Rivera', $listed['resident_name']);
        $this->assertTrue($listed['has_point']);
        $this->assertArrayNotHasKey('email', $listed);
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $point)->value('report_status'));

        $map = $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-submissions')->assertOk();
        $features = collect($map->json('features'));
        $this->assertEqualsCanonicalizing([$point, $secondReport], $features->pluck('id')->all());
        $feature = $features->firstWhere('id', $point);
        $this->assertEqualsWithDelta(126.203, $feature['geometry']['coordinates'][0], 0.000001);
        $this->assertEqualsWithDelta(9.069, $feature['geometry']['coordinates'][1], 0.000001);
        $this->assertSame('submitted', $feature['properties']['status']);
        $this->assertSame('Pending BDRRM Review', $feature['properties']['label']);
        $this->assertArrayNotHasKey('resident_name', $feature['properties']);
        $this->assertArrayNotHasKey('incident_description', $feature['properties']);
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $point)->value('report_status'));
        $this->assertSame(0, DB::table('activity_events')->count());

        $opened = $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$point)->assertOk();
        $this->assertSame('submitted', $opened->json('data.status'));
        $this->assertSame('Pending BDRRM Review', $opened->json('data.label'));
        $this->assertTrue($opened->json('data.reviewable'));
        $this->assertTrue($opened->json('data.has_point'));
        $this->assertSame('Case Rivera', $opened->json('data.resident_name'));
        $this->assertNull($opened->json('data.barangay_report_id'));
        $this->assertSame(0, DB::table('activity_events')->count());
        DB::table('resident_incident_reports')->where('id', $point)->update(['updated_at' => '2000-01-01 00:00:00']);
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$point)->assertOk();
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $point)->value('report_status'));
        $this->assertSame('2000-01-01 00:00:00', DB::table('resident_incident_reports')->where('id', $point)->value('updated_at'));
        $this->assertSame(0, DB::table('activity_events')->count());

        $started = $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$point.'/start-review')->assertOk();
        $this->assertSame('under_review', $started->json('data.status'));
        $this->assertSame('under_review', DB::table('resident_incident_reports')->where('id', $point)->value('report_status'));
        $this->assertSame(1, DB::table('activity_events')->where('action_type', 'resident_report_review_started')->count());

        $reviewing = $this->actingAs($officer)->getJson('/bdrrm/api/submissions?status=under_review')->assertOk();
        $this->assertSame(['RIR-QUEUE-POINT'], collect($reviewing->json('data'))->pluck('reference')->all());
        $found = $this->actingAs($officer)->getJson('/bdrrm/api/submissions?search=QUEUE-POINT')->assertOk();
        $this->assertSame(['RIR-QUEUE-POINT'], collect($found->json('data'))->pluck('reference')->all());

        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$foreign)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$draft)->assertForbidden();
        $this->actingAs($otherOfficer)->getJson('/bdrrm/api/submissions/'.$point)->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions?barangay_id='.$other)->assertForbidden();
        $otherMap = $this->actingAs($otherOfficer)->getJson('/bdrrm/api/map/resident-submissions')->assertOk();
        $this->assertSame([$foreign], collect($otherMap->json('features'))->pluck('id')->all());
        $this->actingAs($revoked)->getJson('/bdrrm/api/submissions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($revoked)->getJson('/bdrrm/api/submissions/'.$point)->assertForbidden();
        $this->actingAs($bare)->getJson('/bdrrm/api/submissions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($bare)->getJson('/bdrrm/api/map/resident-submissions')->assertOk()->assertJsonCount(0, 'features');

        Storage::fake('local');
        Storage::disk('local')->put('incident-photos/wall.jpg', 'image-bytes');
        $photo = DB::table('incident_photos')->insertGetId([
            'file_path' => 'incident-photos/wall.jpg',
            'original_filename' => 'wall.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'file_sha256' => str_repeat('b', 64),
            'image_source' => 'resident',
            'incident_report_id' => $plain,
            'uploaded_by' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($officer)->get('/bdrrm/api/submissions/'.$plain.'/photos/'.$photo)->assertOk();
        $this->actingAs($otherOfficer)->get('/bdrrm/api/submissions/'.$plain.'/photos/'.$photo)->assertForbidden();
        $draftPhoto = DB::table('incident_photos')->insertGetId([
            'file_path' => 'incident-photos/wall.jpg',
            'original_filename' => 'draft.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'file_sha256' => str_repeat('c', 64),
            'image_source' => 'resident',
            'incident_report_id' => $draft,
            'uploaded_by' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($officer)->get('/bdrrm/api/submissions/'.$draft.'/photos/'.$draftPhoto)->assertForbidden();

        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$returned.'/return', [])->assertStatus(422);
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$returned.'/return', [
            'return_reason' => 'too short',
        ])->assertStatus(422);
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$returned.'/return', [
            'return_reason' => '   Please add a clearer photo of the doorway.   ',
        ])->assertOk();
        $this->assertSame('returned', DB::table('resident_incident_reports')->where('id', $returned)->value('report_status'));
        $this->assertSame('Please add a clearer photo of the doorway.', DB::table('resident_incident_reports')->where('id', $returned)->value('return_reason'));

        $accepted = $this->acceptSubmission($officer, $point);
        $linked = $accepted->json('barangay_report_id');
        $this->assertNotNull($linked);
        $this->assertSame(1, DB::table('barangay_reports')->count());
        $this->assertSame('reviewed', DB::table('resident_incident_reports')->where('id', $point)->value('report_status'));
        $this->assertSame('draft', DB::table('barangay_reports')->where('id', $linked)->value('report_status'));
        $again = $this->acceptSubmission($officer, $point);
        $this->assertSame($linked, $again->json('barangay_report_id'));
        $this->assertSame(1, DB::table('barangay_reports')->count());
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$point.'/return', [
            'return_reason' => 'This should no longer be returnable.',
        ])->assertStatus(409);
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions')->assertOk()->assertJsonMissing(['id' => $point]);
        $this->assertNotContains($handoff, collect($this->actingAs($officer)->getJson('/bdrrm/api/submissions')->json('data'))->pluck('id'));
    }

    public function test_bdrrm_dashboard_counts_match_the_queues_without_opening_a_submission(): void
    {
        $home = $this->barangay('Mabua');
        $second = $this->barangay('Second Barangay');
        $outside = $this->barangay('Outsideville');
        $incident = $this->incident($home);
        $resident = $this->user('resident', 'Resident Ana', $home);
        $officer = $this->user('bdrrm', 'Mabua Officer', $home);
        $revoked = $this->user('bdrrm', 'Former Officer', $home);
        foreach ([$home, $second] as $barangayId) {
            DB::table('user_barangay_assignments')->insert([
                'assigned_at' => now(),
                'user_id' => $officer->id,
                'barangay_id' => $barangayId,
                'assigned_by' => $officer->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now()->subDay(),
            'revoked_at' => now(),
            'user_id' => $revoked->id,
            'barangay_id' => $home,
            'assigned_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $timezone = (string) config('app.timezone', 'UTC');
        $current = now($timezone)->startOfMonth()->addDays(2)->utc();
        $previous = now($timezone)->startOfMonth()->subMonth()->addDays(2)->utc();
        $older = now($timezone)->startOfMonth()->subMonths(7)->utc();
        $insertResident = function (string $reference, int $barangayId, string $status, $submittedAt, ?int $barangayReportId = null) use ($resident, $incident) {
            return DB::table('resident_incident_reports')->insertGetId([
                'report_reference' => $reference,
                'incident_description' => 'Water entered the room.',
                'reported_location' => 'Purok 1',
                'reported_point' => '126.20313 9.069381',
                'report_status' => $status,
                'submitted_at' => $submittedAt,
                'resident_user_id' => $resident->id,
                'disaster_incident_id' => $incident,
                'barangay_id' => $barangayId,
                'barangay_report_id' => $barangayReportId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        $insertBarangay = function (string $reference, int $barangayId, string $status, $updatedAt) use ($incident, $officer) {
            return DB::table('barangay_reports')->insertGetId([
                'report_reference' => $reference,
                'report_description' => 'Prepared for the barangay.',
                'report_status' => $status,
                'disaster_incident_id' => $incident,
                'barangay_id' => $barangayId,
                'prepared_by' => $officer->id,
                'created_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ]);
        };

        $handed = $insertBarangay('BR-HAND', $home, 'draft', now());
        $pending = $insertResident('SUB-NEW', $home, 'submitted', $current->copy()->addDays(1));
        $insertResident('UND-NEW', $home, 'under_review', $previous);
        $insertResident('SUB-HAND', $home, 'submitted', $current, $handed);
        $insertResident('ACC-NEW', $home, 'reviewed', $current, $handed);
        $insertResident('RET-NEW', $home, 'returned', $current->copy()->addDays(2));
        $insertResident('OLD-NEW', $home, 'submitted', $older);
        $insertResident('DRAFT-SKIP', $home, 'draft', $current);
        $insertResident('OUT-NEW', $outside, 'submitted', $current);
        $insertResident('SEC-NEW', $second, 'submitted', $current->copy()->addDays(3));

        $insertBarangay('BR-DRAFT-A', $home, 'draft', now());
        $insertBarangay('BR-DRAFT-B', $home, 'draft', now()->subDay());
        $insertBarangay('BR-DRAFT-SEC', $second, 'draft', now()->subHours(2));
        $insertBarangay('BR-RET-1', $home, 'returned', now());
        $insertBarangay('BR-RET-2', $home, 'returned', now()->subHours(2));
        $insertBarangay('BR-RET-SEC', $second, 'returned', now()->subHour());
        $insertBarangay('BR-REV', $home, 'review', now());
        $insertBarangay('BR-SIG', $home, 'signed', now());
        $insertBarangay('BR-SUB', $home, 'submitted', now());
        $insertBarangay('BR-OUT', $outside, 'submitted', now());

        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action_type', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->timestamps();
        });
        $event = function (string $action, string $subjectType, int $subjectId, string $summary, $at) use ($officer) {
            DB::table('activity_events')->insert([
                'user_id' => $officer->id,
                'action_type' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'summary' => $summary,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        };
        $event('resident_report_submitted', 'resident_incident_report', $pending, 'Submitted SUB-NEW to BDRRM.', now());
        $event('resident_report_under_review', 'resident_incident_report', $pending, 'Review started.', now()->subMinute());
        $event('resident_report_returned', 'resident_incident_report', $pending, 'Returned SUB-NEW.', now()->subMinutes(2));
        $outsideId = (int) DB::table('resident_incident_reports')->where('report_reference', 'OUT-NEW')->value('id');
        $event('resident_report_accepted', 'resident_incident_report', $outsideId, 'Accepted OUT-NEW.', now()->subMinutes(3));
        $barangaySubmitted = (int) DB::table('barangay_reports')->where('report_reference', 'BR-SUB')->value('id');
        $event('barangay_report_submitted', 'barangay_report', $barangaySubmitted, 'Submitted BR-SUB.', now()->subMinutes(4));
        $event('barangay_report_returned', 'barangay_report', (int) DB::table('barangay_reports')->where('report_reference', 'BR-RET-1')->value('id'), 'Returned BR-RET-1.', now()->subMinutes(5));

        $before = DB::table('resident_incident_reports')->where('id', $pending)->first();
        $dashboard = $this->actingAs($officer)->getJson('/bdrrm/api/dashboard')->assertOk();
        $after = DB::table('resident_incident_reports')->where('id', $pending)->first();
        $this->assertSame('submitted', $after->report_status);
        $this->assertSame($before->reported_point, $after->reported_point);
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at);

        $this->assertSame('Mabua Officer', $dashboard->json('officer_name'));
        $this->assertEqualsCanonicalizing([$home, $second], collect($dashboard->json('barangays'))->pluck('id')->all());
        $cards = collect($dashboard->json('cards'))->pluck('count', 'key');
        $this->assertSame($this->actingAs($officer)->getJson('/bdrrm/api/submissions')->json('meta.total'), $cards['submissions']);
        $this->assertSame($this->actingAs($officer)->getJson('/bdrrm/api/reports?status=draft')->json('meta.total'), $cards['drafts']);
        $this->assertSame($this->actingAs($officer)->getJson('/bdrrm/api/reports?status=returned')->json('meta.total'), $cards['returned']);
        $this->assertSame($this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off')->json('meta.total'), $cards['submitted']);
        $this->assertSame(4, $cards['submissions']);
        $this->assertSame(4, $cards['drafts']);
        $this->assertSame(3, $cards['returned']);
        $this->assertSame(3, $cards['submitted']);

        $status = collect($dashboard->json('resident_status'))->pluck('count', 'key');
        $this->assertSame(['submitted', 'under_review', 'reviewed', 'returned'], $status->keys()->all());
        $this->assertSame(4, $status['submitted']);
        $this->assertSame(1, $status['under_review']);
        $this->assertSame(1, $status['reviewed']);
        $this->assertSame(1, $status['returned']);
        $this->assertSame(7, $status->sum());
        $this->assertNotSame($status['submitted'] + $status['under_review'], $cards['submissions']);

        $monthPayload = $dashboard->json('resident_months');
        $this->assertTrue($monthPayload['available']);
        $this->assertTrue($monthPayload['has_history']);
        $this->assertCount(6, $monthPayload['months']);
        $monthCounts = collect($monthPayload['months'])->pluck('count', 'key');
        $this->assertSame(5, $monthCounts[now($timezone)->format('Y-m')]);
        $this->assertSame(1, $monthCounts[now($timezone)->startOfMonth()->subMonth()->format('Y-m')]);
        $this->assertSame(0, $monthCounts[now($timezone)->startOfMonth()->subMonths(2)->format('Y-m')]);
        $this->assertArrayNotHasKey($older->timezone($timezone)->format('Y-m'), $monthCounts->all());

        $encoded = json_encode($dashboard->json());
        $this->assertStringNotContainsString('OUT-NEW', $encoded);
        $this->assertStringNotContainsString('BR-OUT', $encoded);
        $this->assertStringNotContainsString('Outsideville', $encoded);
        $this->assertStringNotContainsString('DRAFT-SKIP', $encoded);
        $this->assertStringNotContainsString('Review started', $encoded);

        $recent = collect($dashboard->json('recent_submissions'));
        $this->assertCount(5, $recent);
        $this->assertSame(['SEC-NEW', 'RET-NEW', 'SUB-NEW', 'ACC-NEW', 'SUB-HAND'], $recent->pluck('reference')->all());
        $this->assertSame('Review', $recent->firstWhere('reference', 'SUB-NEW')['action']);
        $this->assertSame('View', $recent->firstWhere('reference', 'SUB-HAND')['action']);
        $this->assertSame('Pending BDRRM Review', $recent->firstWhere('reference', 'SUB-NEW')['label']);
        $this->assertSame('Resident Ana', $recent->firstWhere('reference', 'SUB-NEW')['resident_name']);

        $attention = collect($dashboard->json('attention'));
        $this->assertSame(['SEC-NEW', 'SUB-NEW', 'UND-NEW'], $attention->where('kind', 'resident')->pluck('reference')->all());
        $this->assertSame(['BR-RET-1', 'BR-RET-SEC'], $attention->where('kind', 'barangay')->pluck('reference')->all());
        $this->assertSame(['BR-DRAFT-A'], $attention->where('kind', 'barangay-draft')->pluck('reference')->all());
        $this->assertSame(['Review', 'Revise', 'Continue'], $attention->pluck('action')->unique()->values()->all());

        $activity = collect($dashboard->json('activity'));
        $this->assertCount(4, $activity);
        $this->assertSame(['resident_report_submitted', 'resident_report_returned', 'barangay_report_submitted', 'barangay_report_returned'], $activity->pluck('action')->all());

        $homeOnly = $this->actingAs($officer)->getJson('/bdrrm/api/dashboard?barangay_id='.$home)->assertOk();
        $homeCards = collect($homeOnly->json('cards'))->pluck('count', 'key');
        $this->assertSame(3, $homeCards['submissions']);
        $this->assertSame(3, $homeCards['drafts']);
        $this->assertSame(2, $homeCards['returned']);
        $this->assertSame(3, $homeCards['submitted']);
        $this->assertSame($this->actingAs($officer)->getJson('/bdrrm/api/reports?status=draft&barangay_id='.$home)->json('meta.total'), $homeCards['drafts']);
        $this->assertSame($this->actingAs($officer)->getJson('/bdrrm/api/reports?status=returned&barangay_id='.$home)->json('meta.total'), $homeCards['returned']);
        $this->assertSame($this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off&barangay_id='.$home)->json('meta.total'), $homeCards['submitted']);
        $this->assertStringNotContainsString('SEC-NEW', json_encode($homeOnly->json()));
        $this->assertEqualsCanonicalizing([$home, $second], collect($homeOnly->json('barangays'))->pluck('id')->all());

        $this->actingAs($officer)->getJson('/bdrrm/api/dashboard?barangay_id='.$outside)->assertForbidden();
        $empty = $this->actingAs($revoked)->getJson('/bdrrm/api/dashboard')->assertOk();
        $this->assertSame([0, 0, 0, 0], collect($empty->json('cards'))->pluck('count')->all());
        $this->assertSame([], $empty->json('barangays'));
        $this->assertSame([], $empty->json('recent_submissions'));
        $this->assertSame([], $empty->json('attention'));
        $this->assertSame([], $empty->json('activity'));
        $this->assertFalse($empty->json('resident_months.has_history'));
        $this->actingAs($revoked)->getJson('/bdrrm/api/dashboard?barangay_id='.$home)->assertForbidden();

        Schema::drop('activity_events');
        $this->actingAs($officer)->getJson('/bdrrm/api/dashboard')->assertOk()->assertJsonPath('activity', []);

        Schema::table('resident_incident_reports', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
        $unavailable = $this->actingAs($officer)->getJson('/bdrrm/api/dashboard')->assertOk();
        $this->assertFalse($unavailable->json('resident_months.available'));
        $this->assertSame([], $unavailable->json('resident_months.months'));
    }

    public function test_summary_and_linked_reports_do_not_open_or_guess_a_submission(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->timestamps();
        });
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->nullable();
            $table->timestamps();
        });
        Schema::create('account_resident_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('resident_id');
            $table->timestamps();
        });
        if (! Schema::hasColumn('resident_incident_reports', 'building_id')) {
            Schema::table('resident_incident_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('building_id')->nullable();
            });
        }

        $home = $this->barangay('Mabua Link');
        $other = $this->barangay('Other Link');
        $incident = $this->incident($home);
        $officer = $this->officer($home);
        $resident = $this->user('resident', 'Case Rivera', $home);
        $resident->contact_number = '09170001111';
        $resident->save();
        $stranger = $this->user('resident', 'Stranger Nearby', $home);
        $buildingId = DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-LINK',
            'barangay_id' => $home,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $householdId = DB::table('households')->insertGetId([
            'barangay_id' => $home,
            'building_id' => $buildingId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $personId = DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('account_resident_links')->insert([
            'user_id' => $resident->id,
            'resident_id' => $personId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $insert = function (string $reference, int $userId, int $barangayId, string $status, ?string $point, ?int $building = null) use ($incident) {
            return DB::table('resident_incident_reports')->insertGetId([
                'report_reference' => $reference,
                'incident_description' => 'Private description that must stay off the summary.',
                'reported_location' => 'Purok 2',
                'reported_point' => $point,
                'report_status' => $status,
                'submitted_at' => $status === 'draft' ? null : now(),
                'resident_user_id' => $userId,
                'disaster_incident_id' => $incident,
                'barangay_id' => $barangayId,
                'building_id' => $building,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        $linked = $insert('RIR-LINKED', $resident->id, $home, 'submitted', '126.100 9.100');
        $nearby = $insert('RIR-NEARBY', $stranger->id, $home, 'submitted', '126.203 9.069');
        $draft = $insert('RIR-LINK-DRAFT', $resident->id, $home, 'draft', '126.203 9.069');
        $foreign = $insert('RIR-LINK-FOREIGN', $resident->id, $other, 'submitted', '126.203 9.069');
        $byBuilding = $insert('RIR-BY-BUILDING', $stranger->id, $home, 'submitted', '125.000 8.000', $buildingId);

        $summary = $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$linked.'/summary')->assertOk();
        $summary->assertJsonPath('data.resident_name', 'Case Rivera');
        $summary->assertJsonPath('data.reference', 'RIR-LINKED');
        $summary->assertJsonPath('data.incident_name', 'Flooding');
        $summary->assertJsonPath('data.incident_type', 'Flood '.$home);
        $summary->assertJsonPath('data.barangay_name', 'Mabua Link');
        $summary->assertJsonPath('data.reported_location', 'Purok 2');
        $summary->assertJsonPath('data.status', 'submitted');
        $summary->assertJsonPath('data.label', 'Pending BDRRM Review');
        $this->assertArrayNotHasKey('incident_description', $summary->json('data'));
        $this->assertArrayNotHasKey('contact_number', $summary->json('data'));
        $this->assertStringNotContainsString('Private description', $summary->getContent());
        $this->assertStringNotContainsString('09170001111', $summary->getContent());
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $linked)->value('report_status'));

        $map = $this->actingAs($officer)->getJson('/bdrrm/api/map/resident-submissions')->assertOk();
        $feature = collect($map->json('features'))->firstWhere('id', $linked);
        $this->assertArrayNotHasKey('resident_name', $feature['properties']);
        $this->assertFalse(collect($map->json('features'))->pluck('id')->contains($draft));
        $this->assertFalse(collect($map->json('features'))->pluck('id')->contains($foreign));

        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$foreign.'/summary')->assertForbidden();
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$draft.'/summary')->assertForbidden();

        $household = $this->actingAs($officer)->getJson('/bdrrm/api/households/'.$householdId.'/resident-reports')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['RIR-LINKED', 'RIR-BY-BUILDING'],
            collect($household->json('data'))->pluck('reference')->all(),
        );
        $this->assertFalse(collect($household->json('data'))->pluck('reference')->contains('RIR-NEARBY'));

        $this->actingAs($officer)->getJson('/bdrrm/api/buildings/'.$buildingId.'/resident-reports')->assertForbidden();
        $secretary = $this->user('barangay_secretary', 'Test Secretary', $home);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $secretary->id,
            'barangay_id' => $home,
            'assigned_by' => $secretary->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $building = $this->actingAs($secretary)->getJson('/bdrrm/api/buildings/'.$buildingId.'/resident-reports')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['RIR-LINKED', 'RIR-BY-BUILDING'],
            collect($building->json('data'))->pluck('reference')->all(),
        );
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $nearby)->value('report_status'));
    }

    public function test_bdrrm_sees_only_active_assignments_and_accept_requires_one(): void
    {
        Schema::create('report_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->string('assignment_role', 20);
            $table->string('remarks', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('replaced_reason', 500)->nullable();
            $table->timestamps();
        });
        $mabua = $this->barangay('Mabua');
        $other = $this->barangay('Telaje');
        $incident = $this->incident($mabua);
        $resident = $this->user('resident', 'Case Santos', $mabua);
        $assignedOfficer = $this->user('bdrrm', 'Test Officer', $mabua);
        $peter = $this->user('bdrrm', 'Peter Salomon', $mabua);
        $secretary = $this->user('barangay_secretary', 'Test Secretary', $mabua);
        $captain = $this->user('barangay_captain', 'Test Captain', $mabua);
        $outsider = $this->user('barangay_secretary', 'Other Secretary', $other);
        foreach ([$assignedOfficer, $peter, $secretary, $captain] as $supervisor) {
            DB::table('user_barangay_assignments')->insert([
                'assigned_at' => now(),
                'user_id' => $supervisor->id,
                'barangay_id' => $mabua,
                'assigned_by' => $supervisor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $outsider->id,
            'barangay_id' => $other,
            'assigned_by' => $outsider->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $insert = function (string $reference) use ($resident, $incident, $mabua) {
            return DB::table('resident_incident_reports')->insertGetId([
                'report_reference' => $reference,
                'incident_description' => 'Water reached the doorway.',
                'reported_location' => 'Purok 2',
                'report_status' => 'submitted',
                'submitted_at' => now(),
                'resident_user_id' => $resident->id,
                'disaster_incident_id' => $incident,
                'barangay_id' => $mabua,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        $assigned = $insert('RIR-MINE');
        $otherReport = $insert('RIR-OTHER');
        $open = $insert('RIR-OPEN');
        DB::table('report_assignments')->insert([
            'subject_type' => 'resident_incident_report',
            'subject_id' => $assigned,
            'assignee_user_id' => $assignedOfficer->id,
            'assigned_by' => $secretary->id,
            'assignment_role' => 'bdrrm',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_assignments')->insert([
            'subject_type' => 'resident_incident_report',
            'subject_id' => $otherReport,
            'assignee_user_id' => $peter->id,
            'assigned_by' => $secretary->id,
            'assignment_role' => 'bdrrm',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mine = $this->actingAs($assignedOfficer)->getJson('/bdrrm/api/submissions')->assertOk();
        $this->assertSame(['RIR-MINE'], collect($mine->json('data'))->pluck('reference')->all());
        $this->actingAs($assignedOfficer)->getJson('/bdrrm/api/submissions/'.$otherReport)->assertForbidden();
        $this->actingAs($assignedOfficer)->getJson('/bdrrm/api/submissions/'.$open)->assertForbidden();
        $this->actingAs($assignedOfficer)->post('/bdrrm/api/submissions/'.$open.'/accept', [
            'confirmed' => true,
            'content_revision' => str_repeat('a', 64),
            'signature' => UploadedFile::fake()->image('signature.png', 80, 40),
        ])->assertForbidden();

        $queue = $this->actingAs($secretary)->getJson('/bdrrm/api/submissions?queue=pending')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['RIR-MINE', 'RIR-OTHER'],
            collect($queue->json('data'))->pluck('reference')->all(),
        );
        $this->assertSame(2, $queue->json('counts.pending'));
        $this->assertSame(1, $queue->json('counts.unassigned'));
        $this->assertSame(3, $queue->json('counts.total'));
        $this->assertEmpty(array_intersect(
            collect($queue->json('data'))->pluck('reference')->all(),
            ['RIR-OPEN'],
        ));
        $unassigned = $this->actingAs($secretary)->getJson('/bdrrm/api/submissions?queue=unassigned')->assertOk();
        $this->assertSame(['RIR-OPEN'], collect($unassigned->json('data'))->pluck('reference')->all());
        $this->assertSame('not_opened', collect($queue->json('data'))->firstWhere('reference', 'RIR-MINE')['review_state']);
        $this->assertSame('Unassigned', collect($unassigned->json('data'))->first()['assignment_label']);
        $this->actingAs($assignedOfficer)->getJson('/bdrrm/api/submissions')->assertOk()
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('counts.reviewed', 0);
        $this->actingAs($captain)->getJson('/bdrrm/api/submissions?queue=unassigned')->assertOk()
            ->assertJsonFragment(['reference' => 'RIR-OPEN']);
        $this->actingAs($outsider)->getJson('/bdrrm/api/submissions')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($secretary)->getJson('/bdrrm/api/submissions/'.$assigned)->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $assigned)->value('report_status'));

        $this->actingAs($outsider)->postJson('/bdrrm/api/submissions/assign', [
            'submission_ids' => [$open],
            'assignee_user_id' => $assignedOfficer->id,
        ])->assertForbidden();
        $this->actingAs($secretary)->postJson('/bdrrm/api/submissions/assign', [
            'submission_ids' => [$open, $assigned],
            'assignee_user_id' => $peter->id,
            'replaced_reason' => 'Peter is covering this set.',
        ])->assertOk();
        $this->assertSame(2, DB::table('report_assignments')->where('subject_id', $assigned)->count());
        $this->assertSame(1, DB::table('report_assignments')->where('subject_id', $assigned)->where('is_active', true)->count());
        $this->assertSame(1, DB::table('report_assignments')->where('subject_id', $open)->where('is_active', true)->count());
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $open)->value('report_status'));

        $personnel = $this->actingAs($secretary)->getJson('/bdrrm/api/personnel?role=bdrrm&barangay_id='.$mabua)->assertOk();
        $peterRow = collect($personnel->json('data'))->firstWhere('id', $peter->id);
        $this->assertSame(3, $peterRow['pending_count']);
        $this->assertSame(0, $peterRow['reviewed_count']);

        $this->actingAs($peter)->getJson('/bdrrm/api/submissions/'.$open)->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.review_state', 'not_opened');
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $open)->value('report_status'));
        $this->actingAs($peter)->postJson('/bdrrm/api/submissions/'.$open.'/start-review')->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.review_state', 'reviewing');
        $this->actingAs($assignedOfficer)->postJson('/bdrrm/api/submissions/'.$open.'/start-review')->assertForbidden();

        $this->acceptSubmission($peter, $open);
        $this->assertSame(1, DB::table('report_signatures')->where('resident_incident_report_id', $open)->where('signature_role', 'bdrrm_reviewer')->count());
        $barangayReportId = (int) DB::table('resident_incident_reports')->where('id', $open)->value('barangay_report_id');
        $this->assertSame('pending_secretary', DB::table('barangay_reports')->where('id', $barangayReportId)->value('workflow_stage'));
        $secretaryReviewed = $this->actingAs($secretary)->getJson('/bdrrm/api/submissions?queue=reviewed')->assertOk();
        $this->assertSame(['RIR-OPEN'], collect($secretaryReviewed->json('data'))->pluck('reference')->all());
        $this->assertSame('next_office', collect($secretaryReviewed->json('data'))->first()['review_state']);
        $this->assertSame('Next Office — Barangay Secretary', collect($secretaryReviewed->json('data'))->first()['assignment_label']);
        $this->actingAs($captain)->getJson('/bdrrm/api/submissions?queue=checked')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($secretary)->post('/bdrrm/api/reports/'.$barangayReportId.'/endorse', [
            'confirmed' => true,
            'signature' => UploadedFile::fake()->image('secretary.png', 80, 40),
        ])->assertOk();
        $this->assertSame('pending_captain', DB::table('barangay_reports')->where('id', $barangayReportId)->value('workflow_stage'));
        $this->actingAs($secretary)->getJson('/bdrrm/api/submissions?queue=reviewed')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $checked = $this->actingAs($captain)->getJson('/bdrrm/api/submissions?queue=checked')->assertOk();
        $this->assertSame(['RIR-OPEN'], collect($checked->json('data'))->pluck('reference')->all());
        $this->assertSame('secretary_checked', collect($checked->json('data'))->first()['review_state']);
        $this->assertSame('Test Secretary', collect($checked->json('data'))->first()['secretary_name']);
        $this->actingAs($peter)->getJson('/bdrrm/api/submissions?status=reviewed')->assertOk()
            ->assertJsonPath('counts.reviewed', 1)
            ->assertJsonPath('data.0.reference', 'RIR-OPEN');

        $this->actingAs($assignedOfficer)->getJson('/bdrrm/api/buildings')->assertForbidden();
        $this->actingAs($secretary)->getJson('/bdrrm/api/buildings')->assertOk();
        $this->actingAs($captain)->getJson('/bdrrm/api/buildings')->assertOk();
    }

    private function storeSignature(User $user, int $width, int $height): int
    {
        $this->actingAs($user)->post('/resident/api/signature', [
            'signature' => UploadedFile::fake()->image('signature.png', $width, $height),
        ])->assertOk();

        return (int) DB::table('user_signature_assets')->where('user_id', $user->id)->where('is_current', true)->value('id');
    }

    public function test_captain_sees_pending_submissions_only_for_assigned_barangays(): void
    {
        $mabua = $this->barangay('Mabua');
        $other = $this->barangay('Telaje');
        $incident = $this->incident($mabua);
        $resident = $this->user('resident', 'Case Santos', $mabua);
        $captain = $this->user('barangay_captain', 'Test Captain', $mabua);
        $outsider = $this->user('barangay_captain', 'Other Captain', $other);
        foreach ([[$captain, $mabua], [$outsider, $other]] as [$person, $barangayId]) {
            DB::table('user_barangay_assignments')->insert([
                'assigned_at' => now(),
                'user_id' => $person->id,
                'barangay_id' => $barangayId,
                'assigned_by' => $person->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $submission = DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => 'RIR-2026-WPX4MM',
            'incident_description' => 'Water reached the doorway.',
            'reported_location' => 'Purok 2',
            'report_status' => 'submitted',
            'submitted_at' => now(),
            'resident_user_id' => $resident->id,
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($captain)->getJson('/bdrrm/api/submissions')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'RIR-2026-WPX4MM')
            ->assertJsonPath('meta.total', 1);
        $this->actingAs($outsider)->getJson('/bdrrm/api/submissions')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($captain)->postJson('/bdrrm/api/submissions/'.$submission.'/accept', [
            'confirmed' => true,
            'content_revision' => str_repeat('a', 64),
        ])->assertForbidden();
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $submission)->value('report_status'));
    }

    private function barangay(string $name): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => '1668190'.random_int(10, 99),
            'barangay_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function incident(int $barangayId): int
    {
        $type = DB::table('disaster_types')->insertGetId([
            'name' => 'Flood '.$barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-'.$barangayId,
            'incident_name' => 'Flooding',
            'incident_status' => 'active',
            'occurred_at' => now(),
            'disaster_type_id' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('disaster_barangays')->insert([
            'disaster_incident_id' => $id,
            'barangay_id' => $barangayId,
        ]);

        return $id;
    }

    private function user(string $role, string $name, ?int $barangayId): User
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
        [$first, $rest] = array_pad(explode(' ', $name, 2), 2, 'User');

        return User::query()->create([
            'first_name' => $first,
            'last_name' => $rest,
            'email' => strtolower(str_replace(' ', '.', $name)).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ]);
    }

    private function officer(int $barangayId): User
    {
        $user = $this->user('bdrrm', 'Officer '.$barangayId, $barangayId);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $user->id,
            'barangay_id' => $barangayId,
            'assigned_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function acceptSubmission(User $officer, int $submissionId)
    {
        $opened = $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$submissionId)->assertOk();

        return $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$submissionId.'/accept', [
            'confirmed' => true,
            'content_revision' => $opened->json('data.content_revision'),
            'signature' => UploadedFile::fake()->image('signature.png', 80, 40),
        ])->assertOk();
    }
}
