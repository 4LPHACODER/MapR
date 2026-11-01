<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;

class ActivityLogger
{
    public function log(User $user, string $actionType, string $summary, ?string $subjectType = null, ?int $subjectId = null): void
    {
        if (! $this->tableExists()) {
            return;
        }

        DB::table('activity_events')->insert([
            'user_id' => $user->id,
            'action_type' => $actionType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'summary' => $summary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('activity_events');
    }
}
