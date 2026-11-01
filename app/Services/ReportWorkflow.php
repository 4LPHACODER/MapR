<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->assignReviewingAssessor($report);
        $this->logger->log($user, 'barangay_report_submitted', 'Submitted '.$report->report_reference.' for assessor review.', 'barangay_report', (int) $report->id);
    }

    private function assignReviewingAssessor(object $report): void
    {
        if (! Schema::hasTable('user_barangay_assignments') || ! Schema::hasTable('roles')) {
            return;
        }

        $assessorId = DB::table('user_barangay_assignments as assignments')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.code', 'rdana')
            ->where('assignments.barangay_id', $report->barangay_id)
            ->whereNull('assignments.revoked_at')
            ->orderBy('assignments.assigned_at')
            ->value('users.id');

        $assessor = $assessorId ? User::query()->find($assessorId) : null;
        if ($assessor) {
            $this->startAssessment($assessor, $report);
        }
    }

    public function signBarangay(User $user, object $report): void
    {
        $this->assertNoOpenFlags('barangay_report', (int) $report->id);
        $captain = config('mapr_reference.barangay_captains.'.$this->barangayCode((int) $report->barangay_id));
        if (! is_string($captain) || strcasecmp(trim($user->display_name), trim($captain)) !== 0) {
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
        $existing = DB::table('field_assessments')
            ->where('barangay_report_id', $report->id)
            ->where('assigned_assessor_id', $assessor->id)
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $id = DB::table('field_assessments')->insertGetId([
            'assessment_reference' => $this->reference('FA'),
            'findings' => null,
            'assessment_status' => 'draft',
            'barangay_report_id' => $report->id,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        $verifierName = (string) config('mapr_reference.assessor_verifier.name');
        $matches = ($verifierEmail !== '' && strcasecmp($user->email, $verifierEmail) === 0)
            || ($verifierName !== '' && strcasecmp(trim($user->display_name), trim($verifierName)) === 0);
        if (! $matches || (int) $assessment->assigned_assessor_id !== (int) $user->id) {
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
        if (! $this->matchesApprover($user) || (int) $assessment->assigned_assessor_id === (int) $user->id) {
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
        if (! in_array(strtolower($user->email), ['lestertalan@mapr_admin.com', 'lestertalan@mapr.com'], true)) {
            abort(403, 'Only the noted-by officer can sign in that capacity.');
        }
        $this->storeSignature($user, 'consolidated_report_id', (int) $report->id, 'noted_by', $report);
        $this->logger->log($user, 'consolidated_report_noted', 'Noted '.$report->report_reference.'.', 'consolidated_report', (int) $report->id);
    }

    public function approveConsolidated(User $user, object $report): void
    {
        $this->assertNoOpenFlags('consolidated_report', (int) $report->id);
        if (strcasecmp($user->email, 'reyveen.geli@mapr.test') !== 0) {
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
        $name = (string) config('mapr_reference.assessor_approver.name');

        return strcasecmp($user->email, 'lovy.dedicatoria@mapr.test') === 0
            || ($email !== '' && strcasecmp($user->email, $email) === 0)
            || ($name !== '' && strcasecmp(trim($user->display_name), trim($name)) === 0);
    }

    private function storeSignature(User $user, string $column, int $id, string $role, object $document): void
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
            $payload['user_signature_asset_id'] = $this->currentSignatureAssetId($user);
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

    private function barangayCode(int $barangayId): string
    {
        return (string) DB::table('barangays')->where('id', $barangayId)->value('barangay_code');
    }
}
