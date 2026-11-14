<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class DamageInferenceClient
{
    /**
     * @return array{ok: bool, damage_class_id: ?int, damage_class_code: ?string, confidence: ?float, model_version: ?string, failure_reason: ?string}
     */
    public function classifyPhoto(object $photo, object $model): array
    {
        $url = trim((string) config('mapr.damage_inference_url', env('MAPR_DAMAGE_INFERENCE_URL', '')));
        if ($url === '') {
            return $this->fail('Classification service is unavailable. No damage class was assigned.');
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->attach('image', (string) file_get_contents(storage_path('app/'.$photo->file_path)), $photo->original_filename ?? 'photo.jpg')
                ->post($url, [
                    'architecture' => 'EfficientNetB0',
                    'optimizer' => 'AdamW',
                    'model_version' => $model->model_version ?? null,
                ]);
        } catch (\Throwable $exception) {
            return $this->fail('The classification service did not return a result. No damage class was assigned.');
        }

        if (! $response->successful()) {
            return $this->fail('The classification service did not return a result. No damage class was assigned.');
        }

        $payload = $response->json();
        $code = $this->normalizeClassCode(
            is_array($payload) ? ($payload['class_code'] ?? $payload['label'] ?? $payload['damage_class'] ?? null) : null
        );
        if ($code === null) {
            return $this->fail('The classification service returned an unsupported class label.');
        }

        if (! Schema::hasTable('damage_classes')) {
            return $this->fail('Damage class lookup is unavailable.');
        }

        $class = DB::table('damage_classes')->where('class_code', $code)->first();
        if ($class === null) {
            return $this->fail('The classification service returned an unsupported class label.');
        }

        $confidence = null;
        if (is_array($payload) && isset($payload['confidence']) && is_numeric($payload['confidence'])) {
            $confidence = (float) $payload['confidence'];
        }

        return [
            'ok' => true,
            'damage_class_id' => (int) $class->id,
            'damage_class_code' => (string) $class->class_code,
            'confidence' => $confidence,
            'model_version' => $model->model_version ?? null,
            'failure_reason' => null,
        ];
    }

    public function normalizeClassCode(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }
        $value = strtolower(trim((string) $raw));
        $value = str_replace([' ', '-'], '_', $value);

        return match ($value) {
            'not_affected', 'no_damage', 'nodamage', 'unaffected' => 'not_affected',
            'partially_damaged', 'partially_damage', 'partial_damage', 'partial' => 'partially_damaged',
            'totally_damaged', 'totally_damage', 'total_damage', 'destroyed' => 'totally_damaged',
            default => in_array($value, ['not_affected', 'partially_damaged', 'totally_damaged'], true) ? $value : null,
        };
    }

    /**
     * @return array{ok: bool, damage_class_id: ?int, damage_class_code: ?string, confidence: ?float, model_version: ?string, failure_reason: ?string}
     */
    private function fail(string $reason): array
    {
        return [
            'ok' => false,
            'damage_class_id' => null,
            'damage_class_code' => null,
            'confidence' => null,
            'model_version' => null,
            'failure_reason' => $reason,
        ];
    }
}
