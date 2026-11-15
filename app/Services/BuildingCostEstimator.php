<?php

namespace App\Services;

class BuildingCostEstimator
{
    /**
     * @param  array{
     *     occupancy_key?: ?string,
     *     construction_type?: ?string,
     *     construction_grade?: ?string,
     *     total_floor_area_m2?: mixed,
     *     assessed_floor_area_m2?: mixed,
     *     damage_class_code?: ?string,
     *     authorized_unit_cost_php?: mixed,
     *     rate_source_note?: ?string
     * }  $input
     * @return array{
     *     estimated_cost_php: ?float,
     *     cost_status: string,
     *     unit_cost_php: ?float,
     *     rate_source: ?string,
     *     criteria_version: string,
     *     missing: list<string>,
     *     breakdown: list<array{component: string, quantity: ?float, unit_cost: ?float, estimated_cost: ?float}>,
     *     message: string
     * }
     */
    public function calculate(array $input): array
    {
        $version = (string) config('mapr_unit_cost_schedule.criteria_version', 'assessor-criteria-2019-partial-v1');
        $missing = [];

        $occupancy = is_string($input['occupancy_key'] ?? null) ? trim($input['occupancy_key']) : '';
        $type = is_string($input['construction_type'] ?? null) ? strtoupper(trim($input['construction_type'])) : '';
        $grade = is_string($input['construction_grade'] ?? null) ? strtoupper(trim($input['construction_grade'])) : '';
        $damageCode = is_string($input['damage_class_code'] ?? null) ? trim($input['damage_class_code']) : '';

        $occupancies = config('mapr_unit_cost_schedule.occupancies', []);
        if ($occupancy === '' || ! array_key_exists($occupancy, $occupancies)) {
            $missing[] = 'occupancy_key';
        }

        $types = config('mapr_unit_cost_schedule.construction_types', []);
        if ($type === '' || ! array_key_exists($type, $types)) {
            $missing[] = 'construction_type';
        }

        $grades = config('mapr_unit_cost_schedule.grades', ['A', 'B', 'C']);
        if ($grade === '' || ! in_array($grade, $grades, true)) {
            $missing[] = 'construction_grade';
        }

        $totalArea = $this->numeric($input['total_floor_area_m2'] ?? null);
        if ($totalArea === null || $totalArea <= 0) {
            $missing[] = 'total_floor_area_m2';
        }

        $assessedArea = $this->numeric($input['assessed_floor_area_m2'] ?? null);
        if ($damageCode === 'not_affected') {
            $assessedArea = 0.0;
        } elseif ($damageCode === 'totally_damaged' && $assessedArea === null && $totalArea !== null && $totalArea > 0) {
            $assessedArea = $totalArea;
        }

        if ($assessedArea === null) {
            $missing[] = 'assessed_floor_area_m2';
        } elseif ($assessedArea < 0) {
            $missing[] = 'assessed_floor_area_m2_negative';
        } elseif ($totalArea !== null && $assessedArea > $totalArea) {
            $missing[] = 'assessed_floor_area_m2_exceeds_total';
        }

        $scheduleRate = null;
        if ($occupancy !== '' && $type !== '' && $grade !== '') {
            $scheduleRate = config("mapr_unit_cost_schedule.rates.{$occupancy}.{$type}.{$grade}");
            $scheduleRate = is_numeric($scheduleRate) ? round((float) $scheduleRate, 2) : null;
        }

        $authorized = $this->numeric($input['authorized_unit_cost_php'] ?? null);
        $note = is_string($input['rate_source_note'] ?? null) ? trim($input['rate_source_note']) : '';

        $unitCost = null;
        $rateSource = null;
        if ($scheduleRate !== null) {
            $unitCost = $scheduleRate;
            $rateSource = 'schedule';
        } elseif ($authorized !== null && $authorized > 0 && $note !== '') {
            $unitCost = round($authorized, 2);
            $rateSource = 'assessor_authorized';
        } else {
            $missing[] = 'unit_cost';
            if ($scheduleRate === null && ($authorized === null || $authorized <= 0 || $note === '')) {
                $missing[] = 'authorized_unit_cost_or_schedule_rate';
            }
        }

        $missing = array_values(array_unique($missing));

        if ($missing !== []) {
            return [
                'estimated_cost_php' => null,
                'cost_status' => 'pending_inputs',
                'unit_cost_php' => $unitCost,
                'rate_source' => $rateSource,
                'criteria_version' => $version,
                'missing' => $missing,
                'breakdown' => [[
                    'component' => 'Floor area × unit base construction cost',
                    'quantity' => $assessedArea,
                    'unit_cost' => $unitCost,
                    'estimated_cost' => null,
                ]],
                'message' => 'Estimated Cost: Pending Required Assessment Data',
            ];
        }

        $amount = round(((float) $assessedArea) * ((float) $unitCost), 2);
        $status = abs($amount) < 0.005 ? 'zero_confirmed' : 'calculated';

        return [
            'estimated_cost_php' => $amount,
            'cost_status' => $status,
            'unit_cost_php' => $unitCost,
            'rate_source' => $rateSource,
            'criteria_version' => $version,
            'missing' => [],
            'breakdown' => [[
                'component' => 'Floor area × unit base construction cost',
                'quantity' => round((float) $assessedArea, 4),
                'unit_cost' => $unitCost,
                'estimated_cost' => $amount,
            ]],
            'message' => $status === 'zero_confirmed'
                ? 'Estimated Cost: ₱0.00'
                : 'Estimated Cost: ₱'.number_format($amount, 2, '.', ','),
        ];
    }

    public function displayLabel(?string $classCode, ?string $className = null): string
    {
        $aliases = config('mapr_unit_cost_schedule.damage_class_display', []);
        if (is_string($classCode) && isset($aliases[$classCode])) {
            return $aliases[$classCode];
        }

        return $className ?: '—';
    }

    public function scheduleMeta(): array
    {
        return [
            'criteria_version' => config('mapr_unit_cost_schedule.criteria_version'),
            'source' => config('mapr_unit_cost_schedule.source'),
            'currency' => config('mapr_unit_cost_schedule.currency', 'PHP'),
            'occupancies' => array_values(config('mapr_unit_cost_schedule.occupancies', [])),
            'construction_types' => collect(config('mapr_unit_cost_schedule.construction_types', []))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'grades' => config('mapr_unit_cost_schedule.grades', []),
            'damage_class_display' => config('mapr_unit_cost_schedule.damage_class_display', []),
        ];
    }

    private function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
