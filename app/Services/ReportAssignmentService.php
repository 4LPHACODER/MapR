<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportAssignmentService
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function assign(User $actor, string $subjectType, int $subjectId, int $assigneeId, string $assignmentRole, ?string $remarks, ?string $replacedReason): void
    {
        if (! Schema::hasTable('report_assignments')) {
            abort(422, 'Report assignments are not available.');
        }
        if (! in_array($subjectType, ['resident_incident_report', 'barangay_report', 'field_assessment'], true)) {
            abort(422, 'That report cannot be assigned.');
        }
        if (! in_array($assignmentRole, ['bdrrm', 'rdana'], true)) {
            abort(422, 'Choose a BDRRM or RDANA assignment.');
        }

        $actor->loadMissing('role');
        $allowed = $assignmentRole === 'bdrrm'
            ? ['barangay_secretary', 'barangay_captain']
            : ['rdana_admin_officer', 'rdana_city_assessor'];
        if (! in_array($actor->role?->code, $allowed, true)) {
            abort(403, 'You cannot assign this report.');
        }

        $barangayId = $this->barangayId($subjectType, $subjectId);
        if (! in_array($barangayId, $this->scope->activeBarangayIds($actor->id), true)) {
            abort(403, 'That barangay is outside your assignment.');
        }

        $assignee = User::query()->with('role')->find($assigneeId);
        if ($assignee === null || $assignee->role?->code !== $assignmentRole) {
            abort(422, 'Choose an active officer for this barangay.');
        }
        if (! in_array($barangayId, $this->scope->activeBarangayIds($assignee->id), true)) {
            abort(422, 'That officer is not assigned to this barangay.');
        }

        $current = DB::table('report_assignments')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('assignment_role', $assignmentRole)
            ->where('is_active', true)
            ->first();
        if ($current && (int) $current->assignee_user_id === $assigneeId) {
            return;
        }
        if ($current) {
            $reason = trim((string) $replacedReason);
            if ($reason === '') {
                abort(422, 'A reason is required when replacing the assigned officer.');
            }
            DB::table('report_assignments')->where('id', $current->id)->update([
                'is_active' => false,
                'replaced_reason' => $reason,
                'updated_at' => now(),
            ]);
        }

        DB::table('report_assignments')->insert([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'assignee_user_id' => $assigneeId,
            'assigned_by' => $actor->id,
            'assignment_role' => $assignmentRole,
            'remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function candidates(int $barangayId, string $assignmentRole): array
    {
        if (! Schema::hasTable('user_barangay_assignments') || ! Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('user_barangay_assignments as assignments')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.code', $assignmentRole)
            ->where('assignments.barangay_id', $barangayId)
            ->whereNull('assignments.revoked_at')
            ->where('users.account_status', 'active')
            ->orderBy('users.last_name')
            ->get(['users.id', 'users.first_name', 'users.last_name'])
            ->map(function ($row) use ($assignmentRole) {
                $workload = $this->workload((int) $row->id, $assignmentRole);

                return [
                    'id' => (int) $row->id,
                    'name' => trim($row->first_name.' '.$row->last_name),
                    'active_count' => $workload['active'],
                    'pending_count' => $workload['pending'],
                    'assessing_count' => $workload['assessing'] ?? 0,
                    'completed_count' => $workload['completed'] ?? ($workload['reviewed'] ?? 0),
                    'reviewed_count' => $workload['reviewed'] ?? ($workload['completed'] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * @return array{active: int, pending: int, reviewed: int, assessing?: int, completed?: int}
     */
    private function workload(int $userId, string $assignmentRole): array
    {
        $empty = ['active' => 0, 'pending' => 0, 'reviewed' => 0, 'assessing' => 0, 'completed' => 0];
        if (! Schema::hasTable('report_assignments')) {
            return $empty;
        }

        if ($assignmentRole === 'rdana') {
            return $this->rdanaWorkload($userId);
        }

        if ($assignmentRole !== 'bdrrm' || ! Schema::hasTable('resident_incident_reports')) {
            return $empty;
        }
        $base = DB::table('report_assignments')
            ->join('resident_incident_reports', 'resident_incident_reports.id', '=', 'report_assignments.subject_id')
            ->where('report_assignments.subject_type', 'resident_incident_report')
            ->where('report_assignments.assignment_role', 'bdrrm')
            ->where('report_assignments.is_active', true)
            ->where('report_assignments.assignee_user_id', $userId);

        return [
            'active' => (clone $base)->count(),
            'pending' => (clone $base)->whereIn('resident_incident_reports.report_status', ['submitted', 'under_review'])->count(),
            'reviewed' => (clone $base)->where('resident_incident_reports.report_status', 'reviewed')->count(),
            'assessing' => 0,
            'completed' => (clone $base)->where('resident_incident_reports.report_status', 'reviewed')->count(),
        ];
    }

    /**
     * @return array{active: int, pending: int, reviewed: int, assessing: int, completed: int}
     */
    private function rdanaWorkload(int $userId): array
    {
        if (! Schema::hasTable('barangay_reports')) {
            return ['active' => 0, 'pending' => 0, 'reviewed' => 0, 'assessing' => 0, 'completed' => 0];
        }

        $assignedReportIds = DB::table('report_assignments')
            ->where('subject_type', 'barangay_report')
            ->where('assignment_role', 'rdana')
            ->where('is_active', true)
            ->where('assignee_user_id', $userId)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($assignedReportIds === []) {
            return ['active' => 0, 'pending' => 0, 'reviewed' => 0, 'assessing' => 0, 'completed' => 0];
        }

        $pending = 0;
        $assessing = 0;
        $completed = 0;
        foreach ($assignedReportIds as $reportId) {
            $bucket = $this->assessmentBucket($reportId);
            if ($bucket === 'completed') {
                $completed++;
            } elseif ($bucket === 'assessing' || $bucket === 'draft') {
                $assessing++;
            } else {
                $pending++;
            }
        }

        return [
            'active' => count($assignedReportIds),
            'pending' => $pending,
            'assessing' => $assessing,
            'completed' => $completed,
            'reviewed' => $completed,
        ];
    }

    /**
     * Classify an assigned barangay report for workload / queue UI.
     *
     * @return 'not_opened'|'assessing'|'draft'|'completed'
     */
    public function assessmentBucket(int $barangayReportId): string
    {
        if (! Schema::hasTable('field_assessments')) {
            return 'not_opened';
        }

        $assessment = DB::table('field_assessments')
            ->where('barangay_report_id', $barangayReportId)
            ->orderByDesc('id')
            ->first();

        if ($assessment === null) {
            return 'not_opened';
        }

        $status = (string) $assessment->assessment_status;
        if ($status === 'returned') {
            return 'draft';
        }

        $stage = (string) ($assessment->workflow_stage ?? '');
        if (in_array($stage, ['pending_admin_verification', 'pending_city_assessor', 'forwarded_cdrrmo'], true)) {
            return 'completed';
        }
        if (in_array($status, ['signed', 'submitted'], true)) {
            return 'completed';
        }
        if (Schema::hasTable('report_signatures')) {
            $signed = DB::table('report_signatures')
                ->where('field_assessment_id', $assessment->id)
                ->where('signature_role', 'assessed_by')
                ->exists();
            if ($signed) {
                return 'completed';
            }
        }

        $hasFindings = trim((string) ($assessment->findings ?? '')) !== '';
        $hasVerification = Schema::hasTable('building_verifications')
            && DB::table('building_verifications')->where('field_assessment_id', $assessment->id)->exists();
        if ($hasFindings || $hasVerification || $status === 'draft') {
            return 'draft';
        }

        return 'assessing';
    }

    /**
     * Distribute selected unassigned barangay reports to assessors in selection order.
     *
     * @param  list<int>  $reportIds
     * @param  list<array{assignee_user_id: int, count: int}>  $distribution
     * @return list<array{report_id: int, assignee_user_id: int}>
     */
    public function distributeBarangayReports(User $actor, array $reportIds, array $distribution, ?string $remarks): array
    {
        $ids = array_values(array_unique(array_map('intval', $reportIds)));
        if ($ids === []) {
            abort(422, 'Select at least one barangay report.');
        }

        $planned = [];
        $cursor = 0;
        foreach ($distribution as $slice) {
            $assigneeId = (int) ($slice['assignee_user_id'] ?? 0);
            $count = (int) ($slice['count'] ?? 0);
            if ($assigneeId < 1 || $count < 1) {
                continue;
            }
            for ($i = 0; $i < $count; $i++) {
                if ($cursor >= count($ids)) {
                    abort(422, 'Distribution exceeds the number of selected reports.');
                }
                $planned[] = [
                    'report_id' => $ids[$cursor],
                    'assignee_user_id' => $assigneeId,
                ];
                $cursor++;
            }
        }
        if ($cursor !== count($ids)) {
            abort(422, 'Assign every selected report before confirming.');
        }

        DB::transaction(function () use ($actor, $planned, $remarks) {
            foreach ($planned as $row) {
                $this->assign(
                    $actor,
                    'barangay_report',
                    $row['report_id'],
                    $row['assignee_user_id'],
                    'rdana',
                    $remarks,
                    null,
                );
            }
        });

        return $planned;
    }

    /**
     * @param  list<int>  $subjectIds
     */
    public function assignMany(User $actor, string $subjectType, array $subjectIds, int $assigneeId, string $assignmentRole, ?string $remarks, ?string $replacedReason): void
    {
        $ids = array_values(array_unique(array_map('intval', $subjectIds)));
        if ($ids === []) {
            abort(422, 'Select at least one resident submission.');
        }
        DB::transaction(function () use ($actor, $subjectType, $ids, $assigneeId, $assignmentRole, $remarks, $replacedReason) {
            foreach ($ids as $id) {
                $this->assign($actor, $subjectType, $id, $assigneeId, $assignmentRole, $remarks, $replacedReason);
            }
        });
    }

    public function assertAssignee(User $user, string $subjectType, int $subjectId, string $assignmentRole, bool $required = false): void
    {
        if (! Schema::hasTable('report_assignments')) {
            return;
        }
        $active = DB::table('report_assignments')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('assignment_role', $assignmentRole)
            ->where('is_active', true)
            ->first();
        if ($active === null) {
            if ($required) {
                abort(403, 'This report is not assigned to you.');
            }

            return;
        }
        if ((int) $active->assignee_user_id !== (int) $user->id) {
            abort(403, 'This report is assigned to another officer.');
        }
    }

    private function barangayId(string $subjectType, int $subjectId): int
    {
        $table = match ($subjectType) {
            'resident_incident_report' => 'resident_incident_reports',
            'barangay_report' => 'barangay_reports',
            'field_assessment' => 'field_assessments',
        };
        $row = DB::table($table)->where('id', $subjectId)->first();
        abort_if($row === null, 404);
        if ($subjectType === 'field_assessment') {
            $report = DB::table('barangay_reports')->where('id', $row->barangay_report_id)->first();
            abort_if($report === null, 404);

            return (int) $report->barangay_id;
        }

        return (int) $row->barangay_id;
    }
}
