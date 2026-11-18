<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportWorkflow
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function label(?string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'review' => 'Awaiting the next office',
            'signed' => 'Signed',
            'submitted' => 'Submitted',
            'returned' => 'Returned for correction',
            'finalized' => 'Finalized',
            'assessor_draft' => 'Assessor draft',
            'pending_admin_verification' => 'Awaiting administrative verification',
            'pending_city_assessor' => 'Awaiting city assessor notation',
            'forwarded_cdrrmo' => 'Forwarded to CDRRMO',
            default => $status ?: 'Unknown',
        };
    }

    public function createBarangayReport(User $user, array $data, int $barangayId): int
    {
        $now = now();
        $id = DB::table('barangay_reports')->insertGetId([
            'report_reference' => $this->reference('BR'),
            'report_description' => $data['report_description'] ?? null,
            'report_status' => 'draft',
            'disaster_incident_id' => $data['disaster_incident_id'],
            'barangay_id' => $barangayId,
            'prepared_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! empty($data['building_id'])) {
            $this->linkBuilding($id, (int) $data['building_id'], $data['observations'] ?? null);
        }

        $this->logger->log($user, 'barangay_report_created', 'Created barangay report draft.', 'barangay_report', $id);

        return $id;
    }

    public function updateDraft(User $user, object $report, array $data): void
    {
        $this->guardVersion($report, $data['updated_at'] ?? null);
        if (! in_array($report->report_status, ['draft', 'returned'], true)) {
            abort(422, 'Only a draft or returned report can be edited.');
        }

        DB::table('barangay_reports')->where('id', $report->id)->update([
            'report_description' => $data['report_description'] ?? $report->report_description,
            'updated_at' => now(),
        ]);

        if (! empty($data['building_id'])) {
            $this->linkBuilding((int) $report->id, (int) $data['building_id'], $data['observations'] ?? null);
        }

        $this->logger->log($user, 'barangay_report_updated', 'Updated barangay report '.$report->report_reference.'.', 'barangay_report', (int) $report->id);
    }

    public function submitBarangayReport(User $user, object $report, ?string $updatedAt): void
    {
        $this->guardVersion($report, $updatedAt);
        if (! in_array($report->report_status, ['draft', 'returned'], true)) {
            abort(409, 'This report was already submitted.');
        }
        if (trim((string) $report->report_description) === '') {
            abort(422, 'A report description is required before submission.');
        }
        if (! DB::table('barangay_report_buildings')->where('barangay_report_id', $report->id)->exists()) {
            abort(422, 'Link a building before submission.');
        }
        if (Schema::hasTable('incident_photos') && ! DB::table('incident_photos')->where('barangay_report_id', $report->id)->exists()) {
            abort(422, 'At least one photograph is required before submission.');
        }
        if (! $this->hasSignature('barangay_report_id', (int) $report->id, 'barangay_signer')) {
            abort(422, 'The authorized barangay signer must sign this report before submission.');
        }

        DB::table('barangay_reports')->where('id', $report->id)->update([
            'report_status' => 'review',
            'submitted_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assignReviewingAssessor($report, $user);
        $this->logger->log($user, 'barangay_report_submitted', 'Submitted '.$report->report_reference.' for assessor review.', 'barangay_report', (int) $report->id);
    }

    public function releaseBarangayReportForAssessor(User $user, object $report): void
    {
        if (! $this->hasSignature('barangay_report_id', (int) $report->id, 'barangay_signer')) {
            abort(422, 'The barangay captain must approve this report before it is released to RDANA.');
        }
        if (! in_array($report->report_status, ['draft', 'returned'], true)) {
            return;
        }
        if (trim((string) $report->report_description) === '') {
            abort(422, 'A report description is required before handoff.');
        }

        $update = [
            'report_status' => 'review',
            'submitted_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('barangay_reports', 'workflow_stage')) {
            $update['workflow_stage'] = 'handed_to_rdana';
        }
        DB::table('barangay_reports')->where('id', $report->id)->update($update);
        $this->assignReviewingAssessor($report, $user);
        $this->logger->log($user, 'barangay_report_handed_off', 'Handed off '.$report->report_reference.' to assessor review.', 'barangay_report', (int) $report->id);
    }

    /**
     * Start a field assessment only when an explicit RDANA report assignment already exists.
     * Captain handoff leaves reports Unassigned until AO III / City Assessor assigns personnel.
     */
    private function assignReviewingAssessor(object $report, ?User $actor = null): void
    {
        if (! Schema::hasTable('report_assignments')) {
            if ($actor) {
                $this->logger->log(
                    $actor,
                    'assessor_assignment_missing',
                    'Report '.$report->report_reference.' was handed to RDANA without an assessor assignment.',
                    'barangay_report',
                    (int) $report->id,
                );
            }

            return;
        }

        $assessorId = DB::table('report_assignments')
            ->where('subject_type', 'barangay_report')
            ->where('subject_id', $report->id)
            ->where('assignment_role', 'rdana')
            ->where('is_active', true)
            ->value('assignee_user_id');

        $assessor = $assessorId ? User::query()->find($assessorId) : null;
        if ($assessor) {
            $this->startAssessment($assessor, $report);

            return;
        }

        if ($actor) {
            $this->logger->log(
                $actor,
                'assessor_assignment_missing',
                'Report '.$report->report_reference.' is awaiting RDANA assessor assignment.',
                'barangay_report',
                (int) $report->id,
            );
        }
    }

    /**
     * Link field assessments for handed-off reports that already have an active RDANA assignment
     * but still lack a field assessment draft.
     *
     * @return array{scanned: int, linked: int, skipped: int, missing_assessor: int}
     */
    public function backfillAssessorAssignmentsForReviewQueue(): array
    {
        $result = ['scanned' => 0, 'linked' => 0, 'skipped' => 0, 'missing_assessor' => 0];
        if (! Schema::hasTable('barangay_reports') || ! Schema::hasTable('field_assessments') || ! Schema::hasTable('report_assignments')) {
            return $result;
        }

        $reports = DB::table('barangay_reports')
            ->whereIn('report_status', ['review', 'signed', 'submitted'])
            ->orderBy('id')
            ->get(['id', 'report_reference', 'barangay_id', 'report_status']);

        foreach ($reports as $report) {
            $result['scanned']++;
            $exists = DB::table('field_assessments')->where('barangay_report_id', $report->id)->exists();
            if ($exists) {
                $result['skipped']++;

                continue;
            }

            $assessorId = DB::table('report_assignments')
                ->where('subject_type', 'barangay_report')
                ->where('subject_id', $report->id)
                ->where('assignment_role', 'rdana')
                ->where('is_active', true)
                ->value('assignee_user_id');

            $assessor = $assessorId ? User::query()->find($assessorId) : null;
            if (! $assessor) {
                $result['missing_assessor']++;

                continue;
            }

            $this->startAssessment($assessor, $report);
            $result['linked']++;
        }

        return $result;
    }

    public function endorseBySecretary(User $user, object $report, ?array $endorsement = null): void
    {
        $user->loadMissing('role');
        if ($user->role?->code !== 'barangay_secretary') {
            abort(403, 'Only the barangay secretary can check and sign this report.');
        }
        $this->assertBarangayMember($user, (int) $report->barangay_id);
        if (! in_array($report->report_status, ['draft', 'returned'], true)) {
            abort(422, 'This report is no longer awaiting barangay checking.');
        }
        if (Schema::hasColumn('barangay_reports', 'workflow_stage') && ($report->workflow_stage ?? null) !== 'pending_secretary') {
            abort(422, 'This report is not awaiting Secretary checking.');
        }
        $this->assertBdrrmReviewed($report);
        $storedPath = null;
        try {
            $assetId = $this->resolveEndorsementAsset($user, $endorsement, $storedPath);
            $this->storeSignature($user, 'barangay_report_id', (int) $report->id, 'barangay_secretary', $report, $assetId);
            if (Schema::hasColumn('barangay_reports', 'workflow_stage')) {
                DB::table('barangay_reports')->where('id', $report->id)->update([
                    'workflow_stage' => 'pending_captain',
                    'updated_at' => now(),
                ]);
            }
            $this->logger->log($user, 'barangay_report_endorsed', 'Checked and signed '.$report->report_reference.' for the punong barangay.', 'barangay_report', (int) $report->id);
        } catch (\Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function assertBdrrmReviewed(object $report): void
    {
        if (! Schema::hasTable('resident_incident_reports') || ! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return;
        }
        $resident = DB::table('resident_incident_reports')->where('barangay_report_id', $report->id)->first();
        if ($resident === null) {
            return;
        }
        $signed = DB::table('report_signatures')
            ->where('resident_incident_report_id', $resident->id)
            ->where('signature_role', 'bdrrm_reviewer')
            ->exists();
        if (! $signed) {
            abort(422, 'The assigned BDRRM officer must sign this report before it can be checked.');
        }
    }

    public function approveByCaptain(User $user, object $report, ?array $endorsement = null): void
    {
        $user->loadMissing('role');
        if ($user->role?->code !== 'barangay_captain') {
            abort(403, 'Only the punong barangay can endorse this report.');
        }
        $this->assertBarangayMember($user, (int) $report->barangay_id);
        if (Schema::hasColumn('barangay_reports', 'workflow_stage') && ($report->workflow_stage ?? null) !== 'pending_captain') {
            abort(422, 'This report is not awaiting Captain endorsement.');
        }
        if (! $this->hasSignature('barangay_report_id', (int) $report->id, 'barangay_secretary')) {
            abort(422, 'The barangay secretary must check and sign this report before captain endorsement.');
        }
        $this->assertBdrrmReviewed($report);
        $storedPath = null;
        try {
            $assetId = $this->resolveEndorsementAsset($user, $endorsement, $storedPath);
            $this->storeSignature($user, 'barangay_report_id', (int) $report->id, 'barangay_signer', $report, $assetId);
            $fresh = DB::table('barangay_reports')->where('id', $report->id)->first();
            $this->releaseBarangayReportForAssessor($user, $fresh);
            $this->logger->log($user, 'barangay_report_captain_approved', 'Endorsed '.$report->report_reference.' for RDANA.', 'barangay_report', (int) $report->id);
        } catch (\Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function submitAssessedDamage(User $user, object $assessment): void
    {
        $user->loadMissing('role');
        if ($user->role?->code !== 'rdana') {
            abort(403, 'Only the assigned assessor can submit this assessment.');
        }
        app(ReportAssignmentService::class)->assertAssignee($user, 'barangay_report', (int) $assessment->barangay_report_id, 'rdana');
        if (Schema::hasTable('building_damage_cost_assessments')) {
            $saved = DB::table('building_damage_cost_assessments')
                ->where('barangay_report_id', $assessment->barangay_report_id)
                ->whereIn('assessment_status', ['draft', 'confirmed'])
                ->exists();
            abort_unless($saved, 422, 'Save the damage assessment before submitting.');
        }
        $this->storeSignature($user, 'field_assessment_id', (int) $assessment->id, 'assessed_by', $assessment);
        $update = [
            // Clear prior return so assessmentBucket can treat this as completed / awaiting verification.
            'assessment_status' => Schema::hasColumn('field_assessments', 'assessment_status') ? 'draft' : null,
            'updated_at' => now(),
        ];
        if ($update['assessment_status'] === null) {
            unset($update['assessment_status']);
        }
        if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
            $update['workflow_stage'] = 'pending_admin_verification';
        }
        if (Schema::hasColumn('field_assessments', 'return_reason')) {
            $update['return_reason'] = null;
        }
        if (Schema::hasColumn('field_assessments', 'returned_at')) {
            $update['returned_at'] = null;
        }
        DB::table('field_assessments')->where('id', $assessment->id)->update($update);
        $this->logger->log($user, 'field_assessment_assessed', 'Submitted '.$assessment->assessment_reference.' for administrative verification.', 'field_assessment', (int) $assessment->id);
    }

    public function verifyByAdminOfficer(User $user, object $assessment): void
    {
        $user->loadMissing('role');
        if ($user->role?->code !== 'rdana_admin_officer') {
            abort(403, 'Only the RDANA administrative officer can verify this assessment.');
        }
        if (! $this->hasSignature('field_assessment_id', (int) $assessment->id, 'assessed_by')) {
            abort(422, 'The assigned assessor must sign before verification.');
        }
        $this->storeSignature($user, 'field_assessment_id', (int) $assessment->id, 'verified_by', $assessment);
        $update = ['updated_at' => now()];
        if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
            $update['workflow_stage'] = 'pending_city_assessor';
        }
        DB::table('field_assessments')->where('id', $assessment->id)->update($update);
        $this->logger->log($user, 'field_assessment_verified_by_officer', 'Verified '.$assessment->assessment_reference.'.', 'field_assessment', (int) $assessment->id);
    }

    public function approveAndForward(User $user, object $assessment): void
    {
        $user->loadMissing('role');
        if (! $this->matchesApprover($user)) {
            abort(403, 'Only the city assessor can approve this assessment.');
        }
        if (! $this->hasSignature('field_assessment_id', (int) $assessment->id, 'verified_by')) {
            abort(422, 'Administrative verification is required before city approval.');
        }
        $this->storeSignature($user, 'field_assessment_id', (int) $assessment->id, 'approved_by', $assessment);
        $update = [
            'assessment_status' => 'submitted',
            'submitted_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
            $update['workflow_stage'] = 'forwarded_cdrrmo';
        }
        DB::table('field_assessments')->where('id', $assessment->id)->update($update);
        $this->logger->log($user, 'field_assessment_forwarded', 'Forwarded '.$assessment->assessment_reference.' to CDRRMO.', 'field_assessment', (int) $assessment->id);
    }

    public function returnFieldAssessment(User $user, object $assessment, string $reason): void
    {
        $user->loadMissing('role');
        $role = $user->role?->code;
        if (! in_array($role, ['rdana_admin_officer', 'rdana_city_assessor'], true)) {
            abort(403, 'Only RDANA supervisors can return an assessment for correction.');
        }
        $reason = trim($reason);
        if (strlen($reason) < 10) {
            abort(422, 'Provide a clear correction reason.');
        }
        $stage = (string) ($assessment->workflow_stage ?? '');
        if (! in_array($stage, ['pending_admin_verification', 'pending_city_assessor'], true)
            && ! $this->hasSignature('field_assessment_id', (int) $assessment->id, 'assessed_by')) {
            abort(422, 'Only a submitted assessment awaiting verification can be returned.');
        }

        $update = [
            'assessment_status' => 'returned',
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('field_assessments', 'return_reason')) {
            $update['return_reason'] = $reason;
        }
        if (Schema::hasColumn('field_assessments', 'returned_at')) {
            $update['returned_at'] = now();
        }
        if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
            $update['workflow_stage'] = 'assessor_draft';
        }
        DB::table('field_assessments')->where('id', $assessment->id)->update($update);

        // Keep assessed_by history on the document, but clear it so the assessor can re-sign after corrections.
        if (Schema::hasTable('report_signatures')) {
            DB::table('report_signatures')
                ->where('field_assessment_id', $assessment->id)
                ->whereIn('signature_role', ['assessed_by', 'verified_by', 'approved_by'])
                ->delete();
        }

        $this->logger->log(
            $user,
            'field_assessment_returned',
            'Returned '.$assessment->assessment_reference.' for correction. '.$reason,
            'field_assessment',
            (int) $assessment->id,
        );
    }

    public function canSignBarangay(User $user, object $report): bool
    {
        return ($report->workflow_stage ?? null) === null
            && $user->role?->code === 'barangay_captain'
            && in_array((int) $report->barangay_id, app(AssignmentScope::class)->activeBarangayIds($user->id), true);
    }

    public function signBarangay(User $user, object $report): void
    {
        $this->assertNoOpenFlags('barangay_report', (int) $report->id);
        if (! $this->canSignBarangay($user, $report)) {
            abort(403, 'Only the authorized barangay signer can sign this report.');
        }
        $this->storeSignature($user, 'barangay_report_id', (int) $report->id, 'barangay_signer', $report);
        DB::table('barangay_reports')->where('id', $report->id)->update(['signed_at' => now(), 'updated_at' => now()]);
        $this->logger->log($user, 'barangay_report_signed', 'Signed barangay report '.$report->report_reference.'.', 'barangay_report', (int) $report->id);
    }

    public function returnBarangayReport(User $user, object $report, string $reason): int
    {
        if ($report->report_status !== 'review') {
            abort(422, 'Only a report awaiting review can be returned.');
        }

        $now = now();
        DB::table('barangay_reports')->where('id', $report->id)->update([
            'report_status' => 'returned',
            'return_reason' => $reason,
            'returned_at' => $now,
            'updated_at' => $now,
        ]);
        $this->logger->log($user, 'barangay_report_returned', 'Returned '.$report->report_reference.'. '.$reason, 'barangay_report', (int) $report->id);

        return (int) $report->id;
    }

    public function copyReturnedSuccessor(User $user, object $report, string $reason): int
    {
        $now = now();
        $id = DB::table('barangay_reports')->insertGetId([
            'report_reference' => $this->reference('BR'),
            'report_description' => $report->report_description,
            'report_status' => 'returned',
            'return_reason' => $reason,
            'returned_at' => $now,
            'supersedes_id' => $report->id,
            'disaster_incident_id' => $report->disaster_incident_id,
            'barangay_id' => $report->barangay_id,
            'prepared_by' => $report->prepared_by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (DB::table('barangay_report_buildings')->where('barangay_report_id', $report->id)->get() as $link) {
            DB::table('barangay_report_buildings')->insert([
                'barangay_report_id' => $id,
                'building_id' => $link->building_id,
                'observations' => $link->observations,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->logger->log($user, 'barangay_report_returned', 'Opened a correction copy of '.$report->report_reference.'.', 'barangay_report', $id);

        return $id;
    }

    public function startAssessment(User $assessor, object $report): int
    {
        if (! Schema::hasTable('field_assessments')) {
            return 0;
        }

        $existing = DB::table('field_assessments')
            ->where('barangay_report_id', $report->id)
            ->where('assigned_assessor_id', $assessor->id)
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $payload = [
            'assessment_reference' => $this->reference('FA'),
            'findings' => null,
            'assessment_status' => 'draft',
            'barangay_report_id' => $report->id,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
            $payload['workflow_stage'] = 'assessor_draft';
        }
        $id = DB::table('field_assessments')->insertGetId($payload);
        $this->logger->log($assessor, 'field_assessment_started', 'Started field assessment for '.$report->report_reference.'.', 'field_assessment', $id);

        return $id;
    }

    public function saveFindings(User $user, object $assessment, ?string $findings, ?string $updatedAt): void
    {
        $this->guardVersion($assessment, $updatedAt);
        DB::table('field_assessments')->where('id', $assessment->id)->update([
            'findings' => $findings,
            'updated_at' => now(),
        ]);
        $this->logger->log($user, 'field_assessment_updated', 'Saved findings for '.$assessment->assessment_reference.'.', 'field_assessment', (int) $assessment->id);
    }

    public function saveVerification(User $user, object $assessment, array $data): void
    {
        $buildingId = (int) $data['building_id'];
        $classId = (int) $data['verified_damage_class_id'];
        $existing = DB::table('building_verifications')
            ->where('field_assessment_id', $assessment->id)
            ->where('building_id', $buildingId)
            ->first();

        $payload = [
            'location_verified' => (bool) $data['location_verified'],
            'household_link_verified' => (bool) $data['household_link_verified'],
            'verification_notes' => $data['verification_notes'] ?? null,
            'verified_damage_class_id' => $classId,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'updated_at' => now(),
        ];
        if (array_key_exists('estimated_repair_cost', $data) && $data['estimated_repair_cost'] !== null && $data['estimated_repair_cost'] !== '') {
            $this->assertRepairCostAllowed($user, $assessment);
            if (Schema::hasColumn('building_verifications', 'estimated_repair_cost')) {
                $payload['estimated_repair_cost'] = $data['estimated_repair_cost'];
            }
        }

        if ($existing) {
            DB::table('building_verifications')->where('id', $existing->id)->update($payload);
            if (Schema::hasTable('building_verification_corrections') && (int) $existing->verified_damage_class_id !== $classId) {
                DB::table('building_verification_corrections')->insert([
                    'building_verification_id' => $existing->id,
                    'field_assessment_id' => $assessment->id,
                    'building_id' => $buildingId,
                    'previous_damage_class_id' => $existing->verified_damage_class_id,
                    'new_damage_class_id' => $classId,
                    'note' => $data['verification_notes'] ?? null,
                    'corrected_by' => $user->id,
                    'corrected_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            $payload['field_assessment_id'] = $assessment->id;
            $payload['building_id'] = $buildingId;
            $payload['created_at'] = now();
            DB::table('building_verifications')->insert($payload);
        }

        $this->logger->log($user, 'building_verification_saved', 'Recorded field verification for '.$assessment->assessment_reference.'.', 'field_assessment', (int) $assessment->id);
    }

    public function signAssessment(User $user, object $assessment): void
    {
        $this->assertNoOpenFlags('field_assessment', (int) $assessment->id);
        $verifierEmail = (string) config('mapr.assessor_verifier_email');
        $matches = $user->role?->code === 'rdana' && $verifierEmail !== '' && strcasecmp($user->email, $verifierEmail) === 0;
        if (($assessment->workflow_stage ?? null) !== null || ! $matches || (int) $assessment->assigned_assessor_id !== (int) $user->id) {
            abort(403, 'Only the assigned verifying officer can verify this assessment.');
        }
        if (trim((string) $assessment->findings) === '') {
            abort(422, 'Record field findings before verification.');
        }

        $this->storeSignature($user, 'field_assessment_id', (int) $assessment->id, 'verified_by', $assessment);
        DB::table('field_assessments')->where('id', $assessment->id)->update([
            'assessment_status' => 'signed',
            'verified_at' => now(),
            'signed_at' => now(),
            'updated_at' => now(),
        ]);
        $this->logger->log($user, 'field_assessment_verified', 'Verified '.$assessment->assessment_reference.'. Approval is still required.', 'field_assessment', (int) $assessment->id);
    }

    public function submitAssessment(User $user, object $assessment, ?string $updatedAt): void
    {
        $this->assertNoOpenFlags('field_assessment', (int) $assessment->id);
        $this->guardVersion($assessment, $updatedAt);
        if ($assessment->assessment_status === 'submitted') {
            abort(409, 'This assessment was already submitted.');
        }
        if ($assessment->assessment_status !== 'signed' || ! $this->hasSignature('field_assessment_id', (int) $assessment->id, 'verified_by')) {
            abort(422, 'Verify the assessment before submission.');
        }
        if (! $this->hasSignature('field_assessment_id', (int) $assessment->id, 'approved_by')) {
            abort(422, 'Approval by the Acting City Assessor is still required. This account cannot apply that signature.');
        }

        DB::table('field_assessments')->where('id', $assessment->id)->update([
            'assessment_status' => 'submitted',
            'submitted_at' => now(),
            'updated_at' => now(),
        ]);
        $this->logger->log($user, 'field_assessment_submitted', 'Submitted '.$assessment->assessment_reference.' to CDRRMO.', 'field_assessment', (int) $assessment->id);
    }

    public function approveAssessment(User $user, object $assessment): void
    {
        $this->assertNoOpenFlags('field_assessment', (int) $assessment->id);
        if (($assessment->workflow_stage ?? null) !== null || ! $this->matchesApprover($user) || (int) $assessment->assigned_assessor_id === (int) $user->id) {
            abort(403, 'Only the Acting City Assessor can approve this assessment.');
        }
        if (! in_array($assessment->assessment_status, ['draft', 'review', 'signed'], true)) {
            abort(409, 'This assessment can no longer receive an approval signature.');
        }

        $this->storeSignature($user, 'field_assessment_id', (int) $assessment->id, 'approved_by', $assessment);
        $this->logger->log($user, 'field_assessment_approved', 'Approved '.$assessment->assessment_reference.'.', 'field_assessment', (int) $assessment->id);
    }

    public function noteConsolidated(User $user, object $report): void
    {
        $this->assertNoOpenFlags('consolidated_report', (int) $report->id);
        if ($this->consolidatedCapacity($user) !== 'noted_by') {
            abort(403, 'Only the noted-by officer can sign in that capacity.');
        }
        $this->storeSignature($user, 'consolidated_report_id', (int) $report->id, 'noted_by', $report);
        $this->logger->log($user, 'consolidated_report_noted', 'Noted '.$report->report_reference.'.', 'consolidated_report', (int) $report->id);
    }

    public function approveConsolidated(User $user, object $report): void
    {
        $this->assertNoOpenFlags('consolidated_report', (int) $report->id);
        if ($this->consolidatedCapacity($user) !== 'approved_by') {
            abort(403, 'Only the CDRRMO approving officer can sign in that capacity.');
        }
        $this->storeSignature($user, 'consolidated_report_id', (int) $report->id, 'approved_by', $report);
        $this->logger->log($user, 'consolidated_report_approved', 'Approved '.$report->report_reference.'.', 'consolidated_report', (int) $report->id);
    }

    public function assertRepairCostAllowed(User $user, object $assessment): void
    {
        $user->loadMissing('role');
        if ($user->role?->code !== 'rdana' || (int) $assessment->assigned_assessor_id !== (int) $user->id) {
            abort(403, 'Only the assigned Assessor can record the estimated repair cost.');
        }
        if (! in_array($assessment->assessment_status, ['draft', 'returned'], true)) {
            abort(422, 'The estimated repair cost can only be changed while the assessment is in draft or returned.');
        }
        $report = DB::table('barangay_reports')->where('id', $assessment->barangay_report_id)->first();
        if ($report === null || empty($report->submitted_at)) {
            abort(422, 'BDRRM must submit the barangay report before an estimated repair cost can be recorded.');
        }
    }

    private function assertNoOpenFlags(string $subjectType, int $subjectId): void
    {
        if (! Schema::hasTable('report_flags')) {
            return;
        }
        $open = DB::table('report_flags')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', 'open')
            ->count();
        if ($open > 0) {
            abort(422, 'Resolve open flags before signing or forwarding this report.');
        }
    }

    private function matchesApprover(User $user): bool
    {
        $email = (string) config('mapr.assessor_approver_email');

        return $user->role?->code === 'rdana_city_assessor'
            || ($user->role?->code === 'rdana' && $email !== '' && strcasecmp($user->email, $email) === 0);
    }

    public function consolidatedCapacity(?User $user): ?string
    {
        if ($user?->role?->code !== 'cdrrmo_admin') {
            return null;
        }

        foreach (config('mapr.consolidated_signer_emails') as $capacity => $email) {
            if (is_string($email) && $email !== '' && strcasecmp($user->email, $email) === 0) {
                return $capacity;
            }
        }

        return null;
    }

    /**
     * @param  array{use_saved?: bool, file?: mixed}|null  $endorsement
     */
    private function resolveEndorsementAsset(User $user, ?array $endorsement, ?string &$storedPath): ?int
    {
        if ($endorsement === null) {
            abort(422, 'Draw a signature or choose your saved signature before continuing.');
        }
        $file = $endorsement['file'] ?? null;
        if ($file instanceof UploadedFile) {
            return $this->storeHeldSignature($user, $file, $storedPath);
        }
        if (! empty($endorsement['use_saved'])) {
            $id = $this->currentSignatureAssetId($user);
            if ($id === null) {
                abort(422, 'No saved signature is available.');
            }

            return $id;
        }

        abort(422, 'Draw a signature or choose your saved signature before continuing.');
    }

    private function storeHeldSignature(User $user, UploadedFile $file, ?string &$storedPath): int
    {
        $path = $file->getRealPath();
        $bytes = $path ? (int) filesize($path) : 0;
        if ($bytes < 1 || $bytes > 2 * 1024 * 1024) {
            abort(422, 'The electronic-signature image must be between 1 byte and 2 MB.');
        }
        $contents = $path ? (string) file_get_contents($path) : '';
        if (preg_match('/<\s*(svg|script|html)\b|<\?xml/i', $contents) === 1) {
            abort(422, 'SVG and other non-image files are not accepted.');
        }
        $info = $path ? @getimagesize($path) : false;
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            abort(422, 'Only a PNG or JPEG electronic-signature image is accepted.');
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 16 || $height < 16 || $width > 4000 || $height > 4000) {
            abort(422, 'The electronic-signature image must be between 16 and 4000 pixels on each side.');
        }
        $extension = $mime === 'image/png' ? 'png' : 'jpg';
        $storedPath = 'signature-images/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($storedPath, $contents);
        $stamp = now();

        return (int) DB::table('user_signature_assets')->insertGetId([
            'user_id' => $user->id,
            'storage_path' => $storedPath,
            'mime_type' => $mime,
            'byte_size' => $bytes,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $contents),
            'is_current' => false,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    private function storeSignature(User $user, string $column, int $id, string $role, object $document, ?int $assetId = null): void
    {
        if ($role === 'approved_by' && (int) $user->id === (int) ($document->assigned_assessor_id ?? 0)) {
            abort(403, 'The verifying officer cannot apply the approving signature.');
        }
        if ($this->hasSignature($column, $id, $role)) {
            abort(409, 'This signature was already recorded.');
        }

        $payload = [
            'signature_reference' => (string) Str::uuid(),
            'document_reference' => $document->report_reference ?? $document->assessment_reference,
            'document_hash' => hash('sha256', $column.'|'.$id.'|'.$role.'|'.($document->updated_at ?? '')),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => $role,
            'signed_at' => now(),
            'verification_status' => 'verified',
            $column => $id,
            'signer_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('report_signatures', 'user_signature_asset_id')) {
            $payload['user_signature_asset_id'] = $assetId ?? $this->currentSignatureAssetId($user);
        }
        if (Schema::hasColumn('report_signatures', 'signature_statement')) {
            $payload['signature_statement'] = null;
        }

        DB::table('report_signatures')->insert($payload);
    }

    private function currentSignatureAssetId(User $user): ?int
    {
        if (! Schema::hasTable('user_signature_assets')) {
            return null;
        }

        $id = DB::table('user_signature_assets')
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function linkBuilding(int $reportId, int $buildingId, ?string $observations): void
    {
        $existing = DB::table('barangay_report_buildings')
            ->where('barangay_report_id', $reportId)
            ->where('building_id', $buildingId)
            ->exists();
        if ($existing) {
            DB::table('barangay_report_buildings')
                ->where('barangay_report_id', $reportId)
                ->where('building_id', $buildingId)
                ->update(['observations' => $observations, 'updated_at' => now()]);

            return;
        }

        DB::table('barangay_report_buildings')->insert([
            'barangay_report_id' => $reportId,
            'building_id' => $buildingId,
            'observations' => $observations,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hasSignature(string $column, int $id, string $role): bool
    {
        return Schema::hasTable('report_signatures')
            && DB::table('report_signatures')->where($column, $id)->where('signature_role', $role)->exists();
    }

    private function guardVersion(object $row, ?string $updatedAt): void
    {
        if ($updatedAt === null || $updatedAt === '') {
            return;
        }
        if (substr((string) $row->updated_at, 0, 19) !== substr($updatedAt, 0, 19)) {
            abort(409, 'This report changed while you were editing. Reload it and try again.');
        }
    }

    private function reference(string $prefix): string
    {
        return $prefix.'-'.now()->year.'-'.Str::upper(Str::random(6));
    }

    private function assertBarangayMember(User $user, int $barangayId): void
    {
        $ids = app(AssignmentScope::class)->activeBarangayIds($user->id);
        if (! in_array($barangayId, $ids, true)) {
            abort(403, 'That barangay is outside your assignment.');
        }
    }
}
