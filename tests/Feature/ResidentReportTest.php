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
        $accepted = $this->actingAs($homeOfficer)->postJson('/bdrrm/api/submissions/'.$id.'/accept')->assertOk();
        $this->assertNotNull($accepted->json('barangay_report_id'));
        $again = $this->actingAs($homeOfficer)->postJson('/bdrrm/api/submissions/'.$id.'/accept')->assertOk();
        $this->assertSame($accepted->json('barangay_report_id'), $again->json('barangay_report_id'));
        $this->assertSame(1, DB::table('barangay_reports')->count());
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

    public function test_only_the_assigned_assessor_can_record_cost_after_bdrrm_submission(): void
    {
        $home = $this->barangay('Home');
        $incident = $this->incident($home);
        $owner = $this->user('resident', 'Ana Cruz', $home);
        $assessor = $this->user('rdana', 'Mark Merillo', null);
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
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
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
        $owner = $this->user('resident', 'Maricar Irizari', $home);
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
        $barangayLine = collect($payload['endorsements'])->firstWhere('title', 'Barangay endorsement');
        $this->assertTrue($barangayLine['signed']);
        $this->assertSame('Officer '.$home, $barangayLine['name']);
        $this->assertSame('Barangay report BR-TEL-1', $barangayLine['document']);
        $this->assertSame('Pending endorsement', collect($payload['endorsements'])->firstWhere('title', 'Verified by')['caption']);
        $html = view('resident.incident-report', $payload)->render();
        $this->assertStringContainsString('Photo 1', $html);
        $this->assertStringContainsString('Photo 2', $html);
        $this->assertStringContainsString('original submission', $html);
        $this->assertStringContainsString('BRGY_TELAJE_LOGO.jpg', $html);
    }

    private function storeSignature(User $user, int $width, int $height): int
    {
        $this->actingAs($user)->post('/resident/api/signature', [
            'signature' => UploadedFile::fake()->image('signature.png', $width, $height),
        ])->assertOk();

        return (int) DB::table('user_signature_assets')->where('user_id', $user->id)->where('is_current', true)->value('id');
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
}
