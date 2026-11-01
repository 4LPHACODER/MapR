<?php

namespace App\Policies;

use App\Models\Auth\User;
use App\Services\AssignmentScope;

class ResidentIncidentReportPolicy
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function view(User $user, object $report): bool
    {
        $user->loadMissing('role');

        return match ($user->role?->code) {
            'resident' => (int) $report->resident_user_id === (int) $user->id,
            'bdrrm' => $report->report_status !== 'draft'
                && in_array((int) $report->barangay_id, $this->scope->activeBarangayIds($user->id), true),
            'cdrrmo_admin' => true,
            default => false,
        };
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
