<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssignmentScope
{
    public function apply(Request $request): void
    {
        $user = $request->user();
        $code = $user?->role?->code;
        $barangayRoles = ['bdrrm', 'barangay_secretary', 'barangay_captain'];
        $assessorRoles = ['rdana', 'rdana_admin_officer', 'rdana_city_assessor'];
        if ($user === null || ! in_array($code, [...$barangayRoles, ...$assessorRoles], true)) {
            return;
        }

        $ids = in_array($code, $barangayRoles, true)
            ? $this->activeBarangayIds($user->id)
            : array_values(array_unique(array_merge(
                $this->activeBarangayIds($user->id),
                $this->assignedReportBarangayIds($user->id),
            )));

        $request->attributes->set('scoped_barangay_ids', $ids);
        $request->attributes->set('field_role', in_array($code, $barangayRoles, true) ? 'bdrrm' : 'rdana');

        if ($request->filled('barangay_id') && ! in_array($request->integer('barangay_id'), $ids, true)) {
            abort(403, 'That barangay is outside your assignment.');
        }
    }

    public function ids(Request $request): array
    {
        $ids = $request->attributes->get('scoped_barangay_ids');

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public function assertBarangay(Request $request, ?int $barangayId): void
    {
        $ids = $request->attributes->get('scoped_barangay_ids');
        if (! is_array($ids)) {
            return;
        }
        if ($barangayId === null || ! in_array($barangayId, array_map('intval', $ids), true)) {
            abort(403, 'That record is outside your assignment.');
        }
    }

    public function activeBarangayIds(int $userId): array
    {
        if (! Schema::hasTable('user_barangay_assignments')) {
            return [];
        }

        return DB::table('user_barangay_assignments')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->pluck('barangay_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function assignedReportBarangayIds(int $userId): array
    {
        if (! Schema::hasTable('field_assessments') || ! Schema::hasTable('barangay_reports')) {
            return [];
        }

        return DB::table('field_assessments as fa')
            ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
            ->where('fa.assigned_assessor_id', $userId)
            ->pluck('br.barangay_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function assessorCanOpenReport(User $user, int $barangayReportId): bool
    {
        if (! in_array($user->role?->code, ['rdana', 'rdana_admin_officer', 'rdana_city_assessor'], true)) {
            return false;
        }

        $report = DB::table('barangay_reports')->where('id', $barangayReportId)->first();
        if ($report === null) {
            return false;
        }

        $inBarangay = in_array((int) $report->barangay_id, $this->activeBarangayIds($user->id), true);

        // Ordinary assessors may only open reports assigned to them.
        if ($user->role?->code === 'rdana') {
            if (Schema::hasTable('report_assignments')) {
                $assigned = DB::table('report_assignments')
                    ->where('subject_type', 'barangay_report')
                    ->where('subject_id', $barangayReportId)
                    ->where('assignment_role', 'rdana')
                    ->where('is_active', true)
                    ->where('assignee_user_id', $user->id)
                    ->exists();
                if ($assigned) {
                    return true;
                }
            }

            return Schema::hasTable('field_assessments')
                && DB::table('field_assessments')
                    ->where('barangay_report_id', $barangayReportId)
                    ->where('assigned_assessor_id', $user->id)
                    ->exists();
        }

        if ($inBarangay) {
            return true;
        }

        if (! Schema::hasTable('field_assessments')) {
            return false;
        }

        return DB::table('field_assessments')
            ->where('barangay_report_id', $barangayReportId)
            ->where('assigned_assessor_id', $user->id)
            ->exists();
    }

    /**
     * Match assessorCanOpenReport: active barangay assignment OR per-report field assessment.
     * Do not use merged ids() barangays from unrelated assessments.
     */
    public function constrainAssessorVisibleReports($query, User $user, string $reportIdColumn = 'reports.id', string $barangayColumn = 'reports.barangay_id')
    {
        $active = $this->activeBarangayIds($user->id);

        return $query->where(function ($inner) use ($user, $active, $reportIdColumn, $barangayColumn) {
            $applied = false;
            if ($active !== []) {
                $inner->whereIn($barangayColumn, $active);
                $applied = true;
            }
            if (Schema::hasTable('field_assessments')) {
                $method = $applied ? 'orWhereIn' : 'whereIn';
                $inner->{$method}($reportIdColumn, function ($assigned) use ($user) {
                    $assigned->select('barangay_report_id')
                        ->from('field_assessments')
                        ->where('assigned_assessor_id', $user->id);
                });
            } elseif (! $applied) {
                $inner->whereRaw('1 = 0');
            }
        });
    }
}
