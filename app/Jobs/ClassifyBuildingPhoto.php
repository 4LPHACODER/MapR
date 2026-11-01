<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClassifyBuildingPhoto implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $predictionId) {}

    public function handle(): void
    {
        if (! Schema::hasTable('building_damage_predictions') || ! Schema::hasTable('ai_models')) {
            return;
        }

        $prediction = DB::table('building_damage_predictions')->where('id', $this->predictionId)->first();
        if ($prediction === null || $prediction->prediction_status === 'succeeded') {
            return;
        }

        $model = DB::table('ai_models')
            ->where('architecture', 'EfficientNetB0')
            ->where('optimizer', 'AdamW')
            ->where('deployment_status', 'active')
            ->first();

        DB::table('building_damage_predictions')->where('id', $this->predictionId)->update([
            'prediction_status' => 'failed',
            'failure_reason' => $model === null
                ? 'Classification service is unavailable. No damage class was assigned.'
                : 'The classification service did not return a result. No damage class was assigned.',
            'predicted_damage_class_id' => null,
            'updated_at' => now(),
        ]);
    }
}
