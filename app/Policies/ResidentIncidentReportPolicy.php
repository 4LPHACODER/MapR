<?php

namespace App\Policies;

use App\Models\Auth\User;
use App\Services\AssignmentScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResidentIncidentReportPolicy
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function view(User $user, object $report): bool
    {
        $user->loadMissing('role');

        return match ($user->role?->code) {
            'resident' => (int) $report->resident_user_id === (int) $user->id,
            'bdrrm' => $this->assignedOfficer($user, $report),
            'barangay_secretary', 'barangay_captain' => $report->report_status !== 'draft'
                && in_array((int) $report->barangay_id, $this->scope->activeBarangayIds($user->id), true),
            'cdrrmo_admin' => true,
            default => false,
        };
    }

    private function assignedOfficer(User $user, object $report): bool
    {
        if ($report->report_status === 'draft') {
            return false;
        }
        if (! in_array((int) $report->barangay_id, $this->scope->activeBarangayIds($user->id), true)) {
            return false;
        }
        if (! Schema::hasTable('report_assignments')) {
            return true;
        }

        return DB::table('report_assignments')
            ->where('subject_type', 'resident_incident_report')
            ->where('subject_id', (int) $report->id)
            ->where('assignment_role', 'bdrrm')
            ->where('is_active', true)
            ->where('assignee_user_id', $user->id)
            ->exists();
    }

    public function update(User $user, object $report): bool
    {
        $user->loadMissing('role');

        return $user->role?->code === 'resident'
            && (int) $report->resident_user_id === (int) $user->id
            && in_array($report->report_status, ['draft', 'returned'], true);
    }

    public function delete(User $user, object $report): bool
    {
        $user->loadMissing('role');

        return $user->role?->code === 'resident'
            && (int) $report->resident_user_id === (int) $user->id
            && $report->report_status === 'draft';
    }
}
