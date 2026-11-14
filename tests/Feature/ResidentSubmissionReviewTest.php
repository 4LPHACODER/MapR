<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\ResidentReportDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ResidentSubmissionReviewTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createReportTables();
    }

    public function test_queue_stays_inside_the_assignment_and_can_filter_the_real_states(): void
    {
        $home = $this->barangay('Mabua');
        $other = $this->barangay('Awasian');
        $incident = $this->incident($home, 'River flood');
        $resident = $this->user('resident', 'Maricar Irizari', $home);
        $officer = $this->officer($home);
        $this->report($resident, $incident, $home, 'submitted', 'RIR-QUEUE-LIVE', 'Chapel road');
        $this->report($resident, $incident, $home, 'draft', 'RIR-QUEUE-DRAFT', 'Hidden draft');
        $this->report($resident, $incident, $other, 'submitted', 'RIR-QUEUE-FOREIGN', 'Other barangay');
        $returned = $this->report($resident, $incident, $home, 'returned', 'RIR-QUEUE-BACK', 'Returned road');
        $reviewed = $this->report($resident, $incident, $home, 'reviewed', 'RIR-QUEUE-DONE', 'Accepted road');
        DB::table('resident_incident_reports')->where('id', $reviewed)->update(['barangay_report_id' => 41]);

        $pending = $this->actingAs($officer)->getJson('/bdrrm/api/submissions?search=Irizari')->assertOk();
        $references = collect($pending->json('data'))->pluck('reference');
        $this->assertTrue($references->contains('RIR-QUEUE-LIVE'));
        $this->assertFalse($references->contains('RIR-QUEUE-DRAFT'));
        $this->assertFalse($references->contains('RIR-QUEUE-FOREIGN'));
        $this->assertFalse($references->contains('RIR-QUEUE-BACK'));
        $this->assertSame('Pending BDRRM Review', collect($pending->json('data'))->firstWhere('reference', 'RIR-QUEUE-LIVE')['label']);

        $this->actingAs($officer)->getJson('/bdrrm/api/submissions?status=returned')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'RIR-QUEUE-BACK');
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions?status=reviewed&search=River')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'RIR-QUEUE-DONE');
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions?submitted_from=2000-01-01&submitted_to=2000-01-02')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$returned)->assertOk();
    }

    public function test_document_download_does_not_open_the_review_and_stays_assigned(): void
    {
        [$report, $officer, $other] = $this->readyReport();
        $stamp = '2000-01-01 00:00:00';
        DB::table('resident_incident_reports')->where('id', $report)->update([
            'report_status' => 'submitted',
            'updated_at' => $stamp,
        ]);

        $this->actingAs($other)->get('/bdrrm/api/submissions/'.$report.'/document')->assertForbidden();
        $pdf = $this->actingAs($officer)->get('/bdrrm/api/submissions/'.$report.'/document')->assertOk();
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $stored = DB::table('resident_incident_reports')->where('id', $report)->first();
        $this->assertSame('submitted', $stored->report_status);
        $this->assertSame($stamp, (string) $stored->updated_at);
        $this->assertSame(0, DB::table('activity_events')->count());

        $payload = app(ResidentReportDocument::class)->payload($stored);
        $slot = collect($payload['endorsements'])->firstWhere('title', 'BDRRM Review');
        $this->assertSame('', $slot['name']);
        $this->assertNull($slot['image']);
        $this->assertSame('Pending endorsement', $slot['caption']);
        $this->assertSame('Pending endorsement', collect($payload['endorsements'])->firstWhere('title', 'Endorsed by')['caption']);
        $this->assertSame('Pending endorsement', collect($payload['endorsements'])->firstWhere('title', 'Checked by')['caption']);
    }

    public function test_flags_stay_on_the_report_without_changing_its_status(): void
    {
        [$report, $officer] = $this->readyReport();
        $this->actingAs($officer)->postJson('/bdrrm/api/flags', [
            'subject_type' => 'resident_incident_report',
            'subject_id' => $report,
            'category' => 'Damage',
            'reason' => 'The photograph does not show the wall.',
        ])->assertStatus(422);
        $this->actingAs($officer)->postJson('/bdrrm/api/flags', [
            'subject_type' => 'resident_incident_report',
            'subject_id' => $report,
            'category' => 'Photographs',
            'section' => 'Photo 2',
            'reason' => 'The photograph does not show the wall.',
        ])->assertCreated();

        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $report)->value('report_status'));
        $this->assertSame("Section: Photo 2\nThe photograph does not show the wall.", DB::table('report_flags')->value('reason'));
        $this->assertSame(1, DB::table('activity_events')->where('action_type', 'report_flag_opened')->count());
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$report.'/accept')->assertStatus(422);
        $this->assertSame(0, DB::table('barangay_reports')->count());

        $flagId = (int) DB::table('report_flags')->value('id');
        $this->actingAs($officer)->postJson('/bdrrm/api/flags/'.$flagId.'/resolve', [
            'resolution' => 'No',
        ])->assertStatus(422);
        $this->actingAs($officer)->postJson('/bdrrm/api/flags/'.$flagId.'/resolve', [
            'resolution' => 'A clearer photograph was checked.',
        ])->assertOk();
        $this->assertSame('resolved', DB::table('report_flags')->value('status'));
        $this->assertSame('submitted', DB::table('resident_incident_reports')->where('id', $report)->value('report_status'));
        $this->assertSame(0, DB::table('report_flags')->where('id', $flagId)->whereNull('resolution')->count());
    }

    public function test_return_keeps_the_reason_for_the_resident_and_rejects_a_short_note(): void
    {
        [$report, $officer, , $resident] = $this->readyReport();
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$report.'/return', [
            'return_reason' => 'Too short',
        ])->assertStatus(422);
        $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$report.'/return', [
            'return_reason' => '  The photo does not show the damaged wall.  ',
        ])->assertOk()->assertJsonPath('data.label', 'Needs Correction');

        $stored = DB::table('resident_incident_reports')->where('id', $report)->first();
        $this->assertSame('returned', $stored->report_status);
        $this->assertSame('The photo does not show the damaged wall.', $stored->return_reason);
        $this->assertSame('Water reached the doorway.', $stored->incident_description);
        $this->actingAs($resident)->getJson('/resident/api/reports/'.$report)
            ->assertOk()
            ->assertJsonPath('data.return_reason', 'The photo does not show the damaged wall.');
    }

    public function test_signing_requires_confirmation_a_current_revision_and_one_draft(): void
    {
        [$report, $officer, , $resident] = $this->readyReport();
        $residentAsset = $this->signatureAsset($resident, true);
        DB::table('report_signatures')->insert([
            'signature_reference' => 'resident-signature',
            'document_reference' => 'RIR-REVIEW-1',
            'document_hash' => str_repeat('a', 64),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => 'resident_signer',
            'signed_at' => now(),
            'verification_status' => 'verified',
            'signer_user_id' => $resident->id,
            'resident_incident_report_id' => $report,
            'user_signature_asset_id' => $residentAsset,
            'signature_statement' => 'The location, description, and photographs are accurate.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profileAsset = $this->signatureAsset($officer, true);

        $opened = $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$report)->assertOk();
        $this->assertSame('submitted', $opened->json('data.status'));
        $started = $this->actingAs($officer)->postJson('/bdrrm/api/submissions/'.$report.'/start-review')->assertOk();
        $revision = $started->json('data.content_revision');
        $this->assertSame('under_review', $started->json('data.status'));

        $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$report.'/accept', [
            'confirmed' => true,
            'content_revision' => $revision,
            'signature' => UploadedFile::fake()->create('note.txt', 20, 'text/plain'),
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('barangay_reports')->count());
        $this->assertSame($profileAsset, (int) DB::table('user_signature_assets')->where('user_id', $officer->id)->where('is_current', true)->value('id'));

        DB::table('resident_incident_reports')->where('id', $report)->update([
            'incident_description' => 'Water reached the doorway and the cabinet.',
        ]);
        $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$report.'/accept', [
            'confirmed' => true,
            'content_revision' => $revision,
            'use_saved_signature' => true,
        ])->assertStatus(409);
        $this->assertSame(0, DB::table('barangay_reports')->count());
        $this->assertSame(1, DB::table('report_signatures')->count());

        $fresh = $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$report)->assertOk();
        $accepted = $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$report.'/accept', [
            'confirmed' => true,
            'content_revision' => $fresh->json('data.content_revision'),
            'signature' => UploadedFile::fake()->image('signature.png', 80, 40),
        ])->assertOk();
        $handedId = $accepted->json('barangay_report_id');
        $again = $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$report.'/accept', [
            'confirmed' => true,
            'content_revision' => $fresh->json('data.content_revision'),
            'use_saved_signature' => true,
        ])->assertOk();
        $this->assertSame($handedId, $again->json('barangay_report_id'));
        $this->assertSame(1, DB::table('barangay_reports')->count());
        $this->assertSame('draft', DB::table('barangay_reports')->value('report_status'));
        $this->assertNull(DB::table('barangay_reports')->value('submitted_at'));
        $this->assertSame(0, DB::table('report_signatures')->where('signature_role', 'barangay_signer')->count());
        $this->actingAs($officer)->getJson('/bdrrm/api/reports?status=handed-off')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($officer)->getJson('/bdrrm/api/navigation')
            ->assertOk()
            ->assertJsonPath('submitted', 0)
            ->assertJsonPath('drafts', 1);

        $endorsement = DB::table('report_signatures')->where('signature_role', 'bdrrm_reviewer')->first();
        $this->assertNotNull($endorsement);
        $this->assertSame(1, DB::table('report_signatures')->where('signature_role', 'bdrrm_reviewer')->count());
        $this->assertSame($report, (int) $endorsement->resident_incident_report_id);
        $this->assertNull($endorsement->barangay_report_id);
        $this->assertSame($fresh->json('data.content_revision'), $endorsement->document_hash);
        $this->assertSame('I have reviewed this resident submission and accept it for handoff to RDANA assessor review.', $endorsement->signature_statement);
        $this->assertNotSame($profileAsset, (int) $endorsement->user_signature_asset_id);
        $this->assertSame($residentAsset, (int) DB::table('report_signatures')->where('signature_role', 'resident_signer')->value('user_signature_asset_id'));
        $this->assertSame($profileAsset, (int) DB::table('user_signature_assets')->where('user_id', $officer->id)->where('is_current', true)->value('id'));
        $this->assertSame(0, (int) DB::table('user_signature_assets')->where('id', $endorsement->user_signature_asset_id)->value('is_current'));

        $payload = app(ResidentReportDocument::class)->payload(DB::table('resident_incident_reports')->where('id', $report)->first());
        $slot = collect($payload['endorsements'])->firstWhere('title', 'BDRRM Review');
        $this->assertTrue($slot['signed']);
        $this->assertSame('Officer Home', $slot['name']);
        $this->assertNotNull($slot['image']);
        $this->assertSame('Pending endorsement', collect($payload['endorsements'])->firstWhere('title', 'Endorsed by')['caption']);
        $this->assertSame('Pending endorsement', collect($payload['endorsements'])->firstWhere('title', 'Checked by')['caption']);
        $this->assertNotNull(collect($payload['endorsements'])->firstWhere('title', 'Assessed by'));
        $this->assertSame('Noted by', collect($payload['endorsements'])->first(fn ($row) => ($row['role'] ?? null) === 'approved_by' && str_contains((string) $row['position'], 'Assessor'))['title']);
    }

    public function test_generated_pdfs_cover_long_text_photos_and_both_endorsement_states(): void
    {
        [$report, $officer] = $this->readyReport();
        $long = str_repeat('Floodwater entered the ground floor and stayed above the outlet. ', 40);
        DB::table('resident_incident_reports')->where('id', $report)->update(['incident_description' => $long]);
        foreach ([1, 2, 3] as $number) {
            $path = 'incident-photos/review-'.$report.'-'.$number.'.png';
            Storage::disk('local')->put($path, UploadedFile::fake()->image('photo-'.$number.'.png', 80, 60)->getContent());
            DB::table('incident_photos')->insert([
                'file_path' => $path,
                'original_filename' => 'photo-'.$number.'.png',
                'mime_type' => 'image/png',
                'file_size' => 100,
                'file_sha256' => hash('sha256', 'photo-'.$number),
                'image_source' => 'upload',
                'incident_report_id' => $report,
                'uploaded_by' => $officer->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $documents = app(ResidentReportDocument::class);
        $row = DB::table('resident_incident_reports')->where('id', $report)->first();
        $pending = $documents->payload($row);
        $this->assertSame('', collect($pending['endorsements'])->firstWhere('title', 'BDRRM Review')['name']);
        $this->assertCount(3, $pending['photos']);
        $pendingPdf = $documents->render($row)->output();
        $this->assertStringStartsWith('%PDF', $pendingPdf);
        $this->assertGreaterThan(1000, strlen($pendingPdf));

        $opened = $this->actingAs($officer)->getJson('/bdrrm/api/submissions/'.$report)->assertOk();
        $this->actingAs($officer)->post('/bdrrm/api/submissions/'.$report.'/accept', [
            'confirmed' => true,
            'content_revision' => $opened->json('data.content_revision'),
            'signature' => UploadedFile::fake()->image('signature.png', 90, 40),
        ])->assertOk();
        $signedRow = DB::table('resident_incident_reports')->where('id', $report)->first();
        $signed = $documents->payload($signedRow);
        $this->assertSame('Officer Home', collect($signed['endorsements'])->firstWhere('title', 'BDRRM Review')['name']);
        $signedPdf = $documents->render($signedRow)->output();
        $this->assertStringStartsWith('%PDF', $signedPdf);
        $this->assertGreaterThan(strlen($pendingPdf), strlen($signedPdf));
    }

    private function readyReport(): array
    {
        $home = $this->barangay('Home');
        $other = $this->barangay('Other');
        $incident = $this->incident($home, 'Flooding');
        $resident = $this->user('resident', 'Ana Cruz', $home);
        $officer = $this->officer($home);
        $stranger = $this->officer($other);
        $report = $this->report($resident, $incident, $home, 'submitted', 'RIR-REVIEW-1', 'Purok 1');

        return [$report, $officer, $stranger, $resident];
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
            $table->dateTime('occurred_at')->nullable();
            $table->unsignedBigInteger('disaster_type_id')->nullable();
            $table->timestamps();
        });
        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
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
        Schema::create('report_flags', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('category', 80);
            $table->text('reason');
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('opened_by');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
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

    private function report(User $resident, int $incident, int $barangay, string $status, string $reference, string $location): int
    {
        return DB::table('resident_incident_reports')->insertGetId([
            'report_reference' => $reference,
            'incident_description' => 'Water reached the doorway.',
            'reported_location' => $location,
            'reported_point' => '126.19000 9.07000',
            'report_status' => $status,
            'submitted_at' => $status === 'draft' ? null : now(),
            'resident_user_id' => $resident->id,
            'disaster_incident_id' => $incident,
            'barangay_id' => $barangay,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function signatureAsset(User $user, bool $current): int
    {
        return DB::table('user_signature_assets')->insertGetId([
            'user_id' => $user->id,
            'storage_path' => 'signature-images/test-'.$user->id.'-'.($current ? 'current' : 'old').'.png',
            'mime_type' => 'image/png',
            'byte_size' => 32,
            'width' => 80,
            'height' => 40,
            'sha256' => hash('sha256', 'signature-'.$user->id),
            'is_current' => $current,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function incident(int $barangayId, string $name): int
    {
        $type = DB::table('disaster_types')->insertGetId([
            'name' => 'Flood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-'.$barangayId.'-'.$name,
            'incident_name' => $name,
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
        $barangayName = DB::table('barangays')->where('id', $barangayId)->value('barangay_name');
        $user = $this->user('bdrrm', 'Officer '.$barangayName, $barangayId);
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
