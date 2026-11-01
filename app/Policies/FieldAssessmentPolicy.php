<?php

namespace App\Policies;

use App\Models\Auth\User;

class FieldAssessmentPolicy
{
    public function view(User $user, object $assessment): bool
    {
        $user->loadMissing('role');

        return match ($user->role?->code) {
            'cdrrmo_admin' => true,
            'rdana' => (int) $assessment->assigned_assessor_id === (int) $user->id,
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
