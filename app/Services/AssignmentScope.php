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
        if ($user === null || ! in_array($code, ['bdrrm', 'rdana'], true)) {
            return;
        }

        $ids = $code === 'bdrrm'
            ? $this->activeBarangayIds($user->id)
            : array_values(array_unique(array_merge(
                $this->activeBarangayIds($user->id),
                $this->assignedReportBarangayIds($user->id),
            )));

        $request->attributes->set('scoped_barangay_ids', $ids);
        $request->attributes->set('field_role', $code);

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
        if ($user->role?->code !== 'rdana') {
            return false;
        }

        $report = DB::table('barangay_reports')->where('id', $barangayReportId)->first();
        if ($report === null) {
            return false;
        }

        if (in_array((int) $report->barangay_id, $this->activeBarangayIds($user->id), true)) {
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
}
