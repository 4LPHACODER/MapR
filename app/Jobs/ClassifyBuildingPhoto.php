<?php

namespace App\Jobs;

use App\Services\DamageInferenceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClassifyBuildingPhoto implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $predictionId) {}

    public function handle(DamageInferenceClient $client): void
    {
        if (! Schema::hasTable('building_damage_predictions') || ! Schema::hasTable('ai_models')) {
            return;
        }

        $prediction = DB::table('building_damage_predictions')->where('id', $this->predictionId)->first();
        if ($prediction === null || $prediction->prediction_status === 'succeeded') {
            return;
        }

        $model = DB::table('ai_models')
            ->where('id', $prediction->ai_model_id)
            ->where('architecture', 'EfficientNetB0')
            ->where('optimizer', 'AdamW')
            ->where('deployment_status', 'active')
            ->first();

        if ($model === null) {
            $this->markFailed('Classification service is unavailable. No damage class was assigned.');

            return;
        }

        $photo = Schema::hasTable('incident_photos')
            ? DB::table('incident_photos')->where('id', $prediction->incident_photo_id)->first()
            : null;
        if ($photo === null) {
            $this->markFailed('The source photograph could not be found.');

            return;
        }

        if (! Storage::disk('local')->exists($photo->file_path) && ! is_file(storage_path('app/'.$photo->file_path))) {
            $this->markFailed('The source photograph file is missing.');

            return;
        }

        $result = $client->classifyPhoto($photo, $model);
        if (! $result['ok']) {
            $this->markFailed($result['failure_reason'] ?? 'The classification service did not return a result. No damage class was assigned.');

            return;
        }

        $this->markSucceeded(
            (int) $result['damage_class_id'],
            $result['confidence'],
        );
    }

    private function markFailed(string $reason): void
    {
        $this->writeStatus([
            'prediction_status' => 'failed',
            'failure_reason' => $reason,
            'predicted_damage_class_id' => null,
            'confidence_score' => null,
            'predicted_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function markSucceeded(int $classId, ?float $confidence): void
    {
        $this->writeStatus([
            'prediction_status' => 'succeeded',
            'failure_reason' => null,
            'predicted_damage_class_id' => $classId,
            'confidence_score' => $confidence,
            'predicted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeStatus(array $payload): void
    {
        try {
            DB::table('building_damage_predictions')->where('id', $this->predictionId)->update($payload);
        } catch (\Throwable) {
            // History guards may block updates; leave the queued row and rely on API failure messaging.
        }
    }
}
