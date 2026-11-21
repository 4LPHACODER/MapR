<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class AssessorFieldAssessmentSignatureTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createTables();
        Storage::fake('local');
        config(['mapr.assessor_approver_email' => '']);
    }

    public function test_submit_assessed_rejects_unsigned_requests(): void
    {
        [$assessor, $assessmentId] = $this->draftAssessment();

        $this->actingAs($assessor)->postJson('/rdana/api/assessments/'.$assessmentId.'/submit-assessed', [
            'confirmed' => true,
            'password' => 'secret-pass',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('report_signatures')->where('field_assessment_id', $assessmentId)->count());
    }

    public function test_submit_assessed_persists_uploaded_signature_asset(): void
    {
        [$assessor, $assessmentId] = $this->draftAssessment();

        $this->actingAs($assessor)->post('/rdana/api/assessments/'.$assessmentId.'/submit-assessed', [
            'confirmed' => true,
            'password' => 'secret-pass',
            'signature' => UploadedFile::fake()->image('assessed.png', 80, 40),
        ])->assertOk();

        $signature = DB::table('report_signatures')
            ->where('field_assessment_id', $assessmentId)
            ->where('signature_role', 'assessed_by')
            ->first();
        $this->assertNotNull($signature);
        $this->assertNotNull($signature->user_signature_asset_id);
        $this->assertSame(
            'pending_admin_verification',
            DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage')
        );
        $asset = DB::table('user_signature_assets')->where('id', $signature->user_signature_asset_id)->first();
        $this->assertNotNull($asset);
        $this->assertTrue(Storage::disk('local')->exists($asset->storage_path));
    }

    public function test_verify_and_forward_accept_saved_signature(): void
    {
        [$assessor, $assessmentId, $officer, $city] = $this->pendingVerification();

        $this->attachSavedSignature($officer);
        $this->actingAs($officer)->post('/rdana/api/assessments/'.$assessmentId.'/verify-officer', [
            'confirmed' => true,
            'password' => 'secret-pass',
            'use_saved_signature' => '1',
        ])->assertOk();

        $verified = DB::table('report_signatures')
            ->where('field_assessment_id', $assessmentId)
            ->where('signature_role', 'verified_by')
            ->first();
        $this->assertNotNull($verified?->user_signature_asset_id);

        $this->attachSavedSignature($city);
        $this->actingAs($city)->post('/rdana/api/assessments/'.$assessmentId.'/forward', [
            'confirmed' => true,
            'password' => 'secret-pass',
            'use_saved_signature' => '1',
        ])->assertOk();

        $approved = DB::table('report_signatures')
            ->where('field_assessment_id', $assessmentId)
            ->where('signature_role', 'approved_by')
            ->first();
        $this->assertNotNull($approved?->user_signature_asset_id);
        $this->assertSame(
            'ready_for_consolidation',
            DB::table('field_assessments')->where('id', $assessmentId)->value('workflow_stage')
        );
    }

    /**
     * @return array{0:User,1:int}
     */
    private function draftAssessment(): array
    {
        $barangay = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assessor = $this->member('rdana', 'Field Assessor', $barangay);
        $incident = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'DI-SIG',
            'incident_name' => 'Flood',
            'incident_status' => 'active',
            'disaster_type_id' => DB::table('disaster_types')->insertGetId(['name' => 'Flood', 'created_at' => now(), 'updated_at' => now()]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $report = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-SIG',
            'report_description' => 'Flood damage',
            'report_status' => 'review',
            'barangay_id' => $barangay,
            'disaster_incident_id' => $incident,
            'prepared_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assessmentId = DB::table('field_assessments')->insertGetId([
            'assessment_reference' => 'FA-SIG',
            'assessment_status' => 'draft',
            'workflow_stage' => 'assessor_draft',
            'barangay_report_id' => $report,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('building_damage_cost_assessments')->insert([
            'barangay_report_id' => $report,
            'assessment_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_assignments')->insert([
            'subject_type' => 'barangay_report',
            'subject_id' => $report,
            'assignee_user_id' => $assessor->id,
            'assigned_by' => $assessor->id,
            'assignment_role' => 'rdana',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$assessor, $assessmentId];
    }

    /**
     * @return array{0:User,1:int,2:User,3:User}
     */
    private function pendingVerification(): array
    {
        [$assessor, $assessmentId] = $this->draftAssessment();
        $barangay = (int) DB::table('field_assessments')
            ->join('barangay_reports', 'barangay_reports.id', '=', 'field_assessments.barangay_report_id')
            ->where('field_assessments.id', $assessmentId)
            ->value('barangay_reports.barangay_id');
        $officer = $this->member('rdana_admin_officer', 'Admin Officer', $barangay);
        $city = $this->member('rdana_city_assessor', 'City Assessor', $barangay);

        $this->actingAs($assessor)->post('/rdana/api/assessments/'.$assessmentId.'/submit-assessed', [
            'confirmed' => true,
            'password' => 'secret-pass',
            'signature' => UploadedFile::fake()->image('assessed.png', 80, 40),
        ])->assertOk();

        return [$assessor, $assessmentId, $officer, $city];
    }

    private function attachSavedSignature(User $user): void
    {
        DB::table('user_signature_assets')->where('user_id', $user->id)->update(['is_current' => false]);
        $path = 'signature-images/saved-'.$user->id.'.png';
        Storage::disk('local')->put($path, UploadedFile::fake()->image('saved.png', 80, 40)->getContent());
        DB::table('user_signature_assets')->insert([
            'user_id' => $user->id,
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'byte_size' => 120,
            'width' => 80,
            'height' => 40,
            'sha256' => hash('sha256', 'saved'),
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function member(string $role, string $name, int $barangayId): User
    {
        $user = $this->user($role, $name, $barangayId);
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

    private function user(string $role, string $name, ?int $barangayId = null): User
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
            'email' => strtolower(str_replace(' ', '.', $name)).random_int(1, 9999).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ]);
    }

    private function createTables(): void
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
            $table->string('incident_status');
            $table->unsignedBigInteger('disaster_type_id');
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status');
            $table->string('workflow_stage')->nullable();
            $table->unsignedBigInteger('disaster_incident_id')->nullable();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference');
            $table->string('assessment_status');
            $table->string('workflow_stage')->nullable();
            $table->text('findings')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('building_damage_cost_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->string('assessment_status');
            $table->timestamps();
        });
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
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference');
            $table->string('document_reference');
            $table->char('document_hash', 64);
            $table->string('mechanism');
            $table->string('signature_role');
            $table->dateTime('signed_at');
            $table->string('verification_status');
            $table->unsignedBigInteger('signer_user_id');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('user_signature_asset_id')->nullable();
            $table->timestamps();
        });
        Schema::create('user_signature_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('storage_path');
            $table->string('mime_type', 64)->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->boolean('is_current')->default(false);
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
        if (! Schema::hasTable('user_barangay_assignments')) {
            Schema::create('user_barangay_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('barangay_id');
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
