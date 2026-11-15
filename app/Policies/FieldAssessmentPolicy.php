<?php

namespace App\Policies;

use App\Models\Auth\User;
use App\Services\AssignmentScope;
use Illuminate\Support\Facades\DB;

class FieldAssessmentPolicy
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function view(User $user, object $assessment): bool
    {
        $user->loadMissing('role');
        $report = DB::table('barangay_reports')->where('id', $assessment->barangay_report_id)->first();
        $inBarangay = $report && in_array((int) $report->barangay_id, $this->scope->activeBarangayIds($user->id), true);

        return match ($user->role?->code) {
            'cdrrmo_admin' => true,
            'rdana' => (int) $assessment->assigned_assessor_id === (int) $user->id,
            'rdana_admin_officer', 'rdana_city_assessor' => $inBarangay,
            default => false,
        };
    }

    public function update(User $user, object $assessment): bool
    {
        return $this->view($user, $assessment)
            && $user->role?->code === 'rdana'
            && in_array($assessment->assessment_status, ['draft', 'returned'], true);
    }

    public function verify(User $user, object $assessment): bool
    {
        return $this->update($user, $assessment);
    }
}
