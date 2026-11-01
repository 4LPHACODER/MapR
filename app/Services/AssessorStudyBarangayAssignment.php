<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssessorStudyBarangayAssignment
{
    /**
     * Give every active Assessor the four study barangays.
     * Existing active assignments, accounts, and report links are left unchanged.
     *
     * @return array{assessors: int, assigned: int, already_assigned: int, missing_barangays: list<string>}
     */
    public function assignActiveAssessors(): array
    {
        $result = [
            'assessors' => 0,
            'assigned' => 0,
            'already_assigned' => 0,
            'missing_barangays' => [],
        ];

        if (! Schema::hasTable('user_barangay_assignments') || ! Schema::hasTable('barangays') || ! Schema::hasTable('users')) {
            $result['missing_barangays'] = $this->studyCodes();

            return $result;
        }

        $barangays = DB::table('barangays')
            ->whereIn('barangay_code', $this->studyCodes())
            ->pluck('id', 'barangay_code');
        $result['missing_barangays'] = array_values(array_filter(
            $this->studyCodes(),
            fn (string $code) => ! $barangays->has($code),
        ));
        if ($barangays->isEmpty()) {
            return $result;
        }

        $assessors = User::query()
            ->where('account_status', 'active')
            ->whereHas('role', fn ($query) => $query->where('code', 'rdana'))
            ->get(['id']);
        $result['assessors'] = $assessors->count();
        $fallbackAssigner = User::query()
            ->where('account_status', 'active')
            ->whereHas('role', fn ($query) => $query->where('code', 'cdrrmo_admin'))
            ->orderBy('id')
            ->value('id');

        foreach ($assessors as $assessor) {
            $assignedBy = $fallbackAssigner ?: $assessor->id;
            foreach ($barangays as $barangayId) {
                $exists = DB::table('user_barangay_assignments')
                    ->where('user_id', $assessor->id)
                    ->where('barangay_id', $barangayId)
                    ->whereNull('revoked_at')
                    ->exists();
                if ($exists) {
                    $result['already_assigned']++;

                    continue;
                }

                DB::table('user_barangay_assignments')->insert([
                    'assigned_at' => now(),
                    'user_id' => $assessor->id,
                    'barangay_id' => (int) $barangayId,
                    'assigned_by' => (int) $assignedBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $result['assigned']++;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function studyCodes(): array
    {
        return collect(config('mapr_reference.barangays', []))
            ->pluck('barangay_code')
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->values()
            ->all();
    }
}
