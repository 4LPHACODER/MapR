<?php

namespace App\Policies;

use App\Models\Auth\User;
use App\Services\AssignmentScope;

class BarangayReportPolicy
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function view(User $user, object $report): bool
    {
        $user->loadMissing('role');
        $barangayId = (int) $report->barangay_id;

        return match ($user->role?->code) {
            'cdrrmo_admin' => true,
            'bdrrm', 'barangay_secretary', 'barangay_captain' => in_array($barangayId, $this->scope->activeBarangayIds($user->id), true),
            'rdana', 'rdana_admin_officer', 'rdana_city_assessor' => $this->scope->assessorCanOpenReport($user, (int) $report->id),
            default => false,
        };
    }

    public function update(User $user, object $report): bool
    {
        $user->loadMissing('role');
        if ($user->role?->code !== 'bdrrm') {
            return false;
        }

        return in_array((int) $report->barangay_id, $this->scope->activeBarangayIds($user->id), true)
            && in_array($report->report_status, ['draft', 'returned'], true);
    }

    public function submit(User $user, object $report): bool
    {
        return $this->update($user, $report);
    }
}
