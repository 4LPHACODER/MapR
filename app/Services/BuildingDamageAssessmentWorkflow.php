<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuildingDamageAssessmentWorkflow
{
    public function __construct(
        private readonly BuildingCostEstimator $estimator,
        private readonly ReportWorkflow $reports,
        private readonly ActivityLogger $logger,
    ) {}

    public function ensureFieldAssessment(User $user, object $report): int
    {
        return $this->reports->startAssessment($user, $report);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveDraft(User $user, object $report, int $fieldAssessmentId, array $data): array
    {
        $buildingId = (int) $data['building_id'];
        $this->assertBuildingOnReport($report, $buildingId);

        $calc = $this->estimator->calculate([
            'occupancy_key' => $data['occupancy_key'] ?? null,
            'construction_type' => $data['construction_type'] ?? null,
            'construction_grade' => $data['construction_grade'] ?? null,
            'total_floor_area_m2' => $data['total_floor_area_m2'] ?? null,
            'assessed_floor_area_m2' => $data['assessed_floor_area_m2'] ?? null,
            'damage_class_code' => $data['damage_class_code'] ?? null,
            'authorized_unit_cost_php' => $data['authorized_unit_cost_php'] ?? null,
            'rate_source_note' => $data['rate_source_note'] ?? null,
        ]);

        $classId = $this->resolveClassId($data['confirmed_damage_class_id'] ?? $data['proposed_damage_class_id'] ?? null);
        $proposedId = $this->resolveClassId($data['proposed_damage_class_id'] ?? null);
        $source = $data['classification_source'] ?? 'manual';
        if (! in_array($source, ['ai', 'manual', 'ai_corrected'], true)) {
            $source = 'manual';
        }

        $existingId = isset($data['id']) ? (int) $data['id'] : null;
        $existing = null;
        if ($existingId) {
            $existing = DB::table('building_damage_cost_assessments')
                ->where('id', $existingId)
                ->where('barangay_report_id', $report->id)
                ->where('assessment_status', 'draft')
                ->first();
            abort_if($existing === null, 404);
        } else {
            $existing = DB::table('building_damage_cost_assessments')
                ->where('barangay_report_id', $report->id)
                ->where('building_id', $buildingId)
                ->where('assessment_status', 'draft')
                ->orderByDesc('id')
                ->first();
        }

        $calculated = $calc['estimated_cost_php'];
        $adjusted = ! empty($data['cost_is_adjusted']);
        $final = $calculated;
        $adjustmentReason = isset($data['cost_adjustment_reason']) ? trim((string) $data['cost_adjustment_reason']) : '';
        if ($adjusted) {
            if (! array_key_exists('estimated_cost_php', $data) || $data['estimated_cost_php'] === null || $data['estimated_cost_php'] === '') {
                abort(422, 'Enter the adjusted estimated cost assessment amount.');
            }
            $final = round((float) $data['estimated_cost_php'], 2);
            if ($final < 0) {
                abort(422, 'Estimated cost assessment cannot be negative.');
            }
            if ($adjustmentReason === '') {
                abort(422, 'Provide a reason when adjusting the estimated cost assessment.');
            }
        } elseif (array_key_exists('estimated_cost_php', $data)
            && $data['estimated_cost_php'] !== null
            && $data['estimated_cost_php'] !== ''
            && $calculated !== null
            && abs((float) $data['estimated_cost_php'] - (float) $calculated) > 0.009) {
            $adjusted = true;
            $final = round((float) $data['estimated_cost_php'], 2);
            if ($adjustmentReason === '') {
                abort(422, 'Provide a reason when adjusting the estimated cost assessment.');
            }
        }

        $costStatus = $calc['cost_status'];
        if ($adjusted && $final !== null && ! in_array($costStatus, ['calculated', 'zero_confirmed'], true)) {
            $costStatus = abs($final) < 0.005 ? 'zero_confirmed' : 'calculated';
        }

        $payload = [
            'barangay_report_id' => $report->id,
            'field_assessment_id' => $fieldAssessmentId,
            'building_id' => $buildingId,
            'ai_prediction_id' => $data['ai_prediction_id'] ?? null,
            'proposed_damage_class_id' => $proposedId,
            'confirmed_damage_class_id' => $classId,
            'classification_source' => $source,
            'correction_reason' => $data['correction_reason'] ?? null,
            'occupancy_key' => $data['occupancy_key'] ?? null,
            'construction_type' => isset($data['construction_type']) ? strtoupper((string) $data['construction_type']) : null,
            'construction_grade' => isset($data['construction_grade']) ? strtoupper((string) $data['construction_grade']) : null,
            'total_floor_area_m2' => $data['total_floor_area_m2'] ?? null,
            'assessed_floor_area_m2' => $data['assessed_floor_area_m2'] ?? null,
            'unit_cost_php' => $calc['unit_cost_php'],
            'rate_source' => $calc['rate_source'],
            'rate_source_note' => $data['rate_source_note'] ?? null,
            'criteria_version' => $calc['criteria_version'],
            'estimated_cost_php' => $final,
            'calculated_estimated_cost_php' => $calculated,
            'cost_is_adjusted' => $adjusted,
            'cost_adjustment_reason' => $adjusted ? $adjustmentReason : null,
            'cost_status' => $costStatus,
            'assessment_status' => 'draft',
            'assessed_by' => $user->id,
            'remarks' => $data['remarks'] ?? null,
            'breakdown_json' => json_encode($calc['breakdown']),
            'updated_at' => now(),
        ];
        $payload = array_filter(
            $payload,
            fn ($column) => Schema::hasColumn('building_damage_cost_assessments', $column),
            ARRAY_FILTER_USE_KEY,
        );

        if ($existing) {
            DB::table('building_damage_cost_assessments')->where('id', $existing->id)->update($payload);
            $id = (int) $existing->id;
        } else {
            $payload['created_at'] = now();
            $id = DB::table('building_damage_cost_assessments')->insertGetId($payload);
        }

        $this->logger->log($user, 'building_damage_assessment_draft', 'Saved damage assessment draft for report '.$report->report_reference.'.', 'barangay_report', (int) $report->id);

        return $this->detail($id);
    }

    public function confirm(User $user, object $report, int $assessmentId): array
    {
        $row = DB::table('building_damage_cost_assessments')
            ->where('id', $assessmentId)
            ->where('barangay_report_id', $report->id)
            ->first();
        abort_if($row === null, 404);
        abort_unless($row->assessment_status === 'draft', 422, 'Only draft assessments can be confirmed.');
        abort_if($row->confirmed_damage_class_id === null, 422, 'Confirm a damage classification before saving.');
        $adjusted = Schema::hasColumn('building_damage_cost_assessments', 'cost_is_adjusted') && (bool) ($row->cost_is_adjusted ?? false);
        $hasFinal = $row->estimated_cost_php !== null;
        abort_unless(
            in_array($row->cost_status, ['calculated', 'zero_confirmed'], true) || ($adjusted && $hasFinal),
            422,
            'Complete required cost inputs or record an adjusted estimated cost before confirming.'
        );
        if ($adjusted && trim((string) ($row->cost_adjustment_reason ?? '')) === '') {
            abort(422, 'Provide a reason when adjusting the estimated cost assessment.');
        }
        if ($row->classification_source === 'ai_corrected' && trim((string) $row->correction_reason) === '') {
            abort(422, 'Provide a reason when correcting the AI classification.');
        }

        $fieldAssessmentId = (int) ($row->field_assessment_id ?: $this->ensureFieldAssessment($user, $report));
        $assessment = DB::table('field_assessments')->where('id', $fieldAssessmentId)->first();
        abort_if($assessment === null, 404);

        return DB::transaction(function () use ($user, $report, $row, $assessment, $fieldAssessmentId) {
            DB::table('building_damage_cost_assessments')
                ->where('barangay_report_id', $report->id)
                ->where('building_id', $row->building_id)
                ->where('assessment_status', 'confirmed')
                ->where('id', '!=', $row->id)
                ->update([
                    'assessment_status' => 'superseded',
                    'updated_at' => now(),
                ]);

            DB::table('building_damage_cost_assessments')->where('id', $row->id)->update([
                'field_assessment_id' => $fieldAssessmentId,
                'assessment_status' => 'confirmed',
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->reports->saveVerification($user, $assessment, [
                'building_id' => (int) $row->building_id,
                'verified_damage_class_id' => (int) $row->confirmed_damage_class_id,
                'location_verified' => true,
                'household_link_verified' => false,
                'verification_notes' => $row->remarks,
                'estimated_repair_cost' => $row->estimated_cost_php,
            ]);

            if (Schema::hasColumn('building_verifications', 'damage_prediction_id') && $row->ai_prediction_id) {
                DB::table('building_verifications')
                    ->where('field_assessment_id', $fieldAssessmentId)
                    ->where('building_id', $row->building_id)
                    ->update(['damage_prediction_id' => $row->ai_prediction_id]);
            }

            $this->logger->log($user, 'building_damage_assessment_confirmed', 'Confirmed damage assessment for report '.$report->report_reference.'.', 'barangay_report', (int) $report->id);

            return $this->detail((int) $row->id);
        });
    }

    /**
     * Supervisor correction of a confirmed damage assessment.
     * Supersedes the current confirmed row and inserts a new confirmed revision with audit metadata.
     * Keeps assessed_by FA signature; clears later verified_by/approved_by so AO/city re-sign after material edits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function supervisorRevise(User $user, object $report, array $data): array
    {
        $user->loadMissing('role');
        $role = $user->role?->code;
        if (! in_array($role, ['rdana_admin_officer', 'rdana_city_assessor'], true)) {
            abort(403, 'Only RDANA supervisors can revise a confirmed damage assessment.');
        }

        $reason = trim((string) ($data['correction_reason'] ?? $data['cost_adjustment_reason'] ?? ''));
        abort_if($reason === '', 422, 'Provide a reason for the supervisory correction.');

        $current = DB::table('building_damage_cost_assessments')
            ->where('barangay_report_id', $report->id)
            ->where('assessment_status', 'confirmed')
            ->orderByDesc('id')
            ->first();
        abort_if($current === null, 422, 'No confirmed damage assessment is available to revise.');

        $fieldAssessment = null;
        if (! empty($current->field_assessment_id) && Schema::hasTable('field_assessments')) {
            $fieldAssessment = DB::table('field_assessments')->where('id', $current->field_assessment_id)->first();
        }
        $stage = (string) ($fieldAssessment->workflow_stage ?? '');
        if ($fieldAssessment && ! in_array($stage, ['pending_admin_verification', 'pending_city_assessor'], true)) {
            abort(422, 'Damage assessments can only be revised while awaiting administrative or city review.');
        }

        $newClassId = array_key_exists('confirmed_damage_class_id', $data)
            ? $this->resolveClassId($data['confirmed_damage_class_id'])
            : (int) $current->confirmed_damage_class_id;
        abort_if($newClassId === null, 422, 'Choose a valid building status.');

        $costAdjusted = array_key_exists('estimated_cost_php', $data) && $data['estimated_cost_php'] !== null && $data['estimated_cost_php'] !== '';
        $newCost = $costAdjusted
            ? round((float) $data['estimated_cost_php'], 2)
            : ($current->estimated_cost_php !== null ? (float) $current->estimated_cost_php : null);
        if ($costAdjusted && $newCost < 0) {
            abort(422, 'Estimated cost assessment cannot be negative.');
        }

        $classChanged = (int) $current->confirmed_damage_class_id !== (int) $newClassId;
        $costChanged = $costAdjusted && abs((float) ($current->estimated_cost_php ?? 0) - (float) $newCost) > 0.009;
        abort_unless($classChanged || $costChanged, 422, 'No building status or cost change was provided.');

        return DB::transaction(function () use ($user, $report, $current, $fieldAssessment, $newClassId, $newCost, $classChanged, $costChanged, $reason) {
            DB::table('building_damage_cost_assessments')->where('id', $current->id)->update([
                'assessment_status' => 'superseded',
                'updated_at' => now(),
            ]);

            $payload = (array) $current;
            unset($payload['id']);
            $payload['confirmed_damage_class_id'] = $newClassId;
            $payload['classification_source'] = 'supervisor_corrected';
            $payload['correction_reason'] = $reason;
            $payload['estimated_cost_php'] = $newCost;
            if (Schema::hasColumn('building_damage_cost_assessments', 'cost_is_adjusted')) {
                $payload['cost_is_adjusted'] = $costChanged || (bool) ($current->cost_is_adjusted ?? false);
            }
            if (Schema::hasColumn('building_damage_cost_assessments', 'cost_adjustment_reason') && $costChanged) {
                $payload['cost_adjustment_reason'] = $reason;
            }
            if (Schema::hasColumn('building_damage_cost_assessments', 'supersedes_id')) {
                $payload['supersedes_id'] = $current->id;
            }
            $payload['assessment_status'] = 'confirmed';
            $payload['confirmed_by'] = $user->id;
            $payload['confirmed_at'] = now();
            $payload['assessed_by'] = $user->id;
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
            $payload = array_filter(
                $payload,
                fn ($column) => Schema::hasColumn('building_damage_cost_assessments', $column),
                ARRAY_FILTER_USE_KEY,
            );

            $id = DB::table('building_damage_cost_assessments')->insertGetId($payload);

            if ($fieldAssessment && Schema::hasTable('report_signatures')) {
                // Material supervisory edits after assessor submit invalidate later office signatures only.
                DB::table('report_signatures')
                    ->where('field_assessment_id', $fieldAssessment->id)
                    ->whereIn('signature_role', ['verified_by', 'approved_by'])
                    ->delete();
                if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
                    DB::table('field_assessments')->where('id', $fieldAssessment->id)->update([
                        'workflow_stage' => 'pending_admin_verification',
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($fieldAssessment && Schema::hasTable('building_verifications')) {
                $verification = DB::table('building_verifications')
                    ->where('field_assessment_id', $fieldAssessment->id)
                    ->where('building_id', $current->building_id)
                    ->first();
                $verificationPayload = [
                    'verified_damage_class_id' => (int) $newClassId,
                    'verification_notes' => $reason,
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('building_verifications', 'estimated_repair_cost')) {
                    $verificationPayload['estimated_repair_cost'] = $newCost;
                }
                if ($verification) {
                    DB::table('building_verifications')->where('id', $verification->id)->update($verificationPayload);
                } else {
                    $verificationPayload['field_assessment_id'] = $fieldAssessment->id;
                    $verificationPayload['building_id'] = (int) $current->building_id;
                    $verificationPayload['location_verified'] = true;
                    $verificationPayload['household_link_verified'] = false;
                    $verificationPayload['created_at'] = now();
                    $verificationPayload = array_filter(
                        $verificationPayload,
                        fn ($column) => Schema::hasColumn('building_verifications', $column),
                        ARRAY_FILTER_USE_KEY,
                    );
                    DB::table('building_verifications')->insert($verificationPayload);
                }
            }

            $summary = 'Supervisory revision on '.$report->report_reference.': ';
            $parts = [];
            if ($classChanged) {
                $parts[] = 'building status updated';
            }
            if ($costChanged) {
                $parts[] = 'estimated cost updated';
            }
            $this->logger->log($user, 'building_damage_assessment_revised', $summary.implode(' and ', $parts).'. '.$reason, 'barangay_report', (int) $report->id);

            return $this->detail($id);
        });
    }

    public function detail(int $id): array
    {
        $row = DB::table('building_damage_cost_assessments as a')
            ->leftJoin('damage_classes as proposed', 'proposed.id', '=', 'a.proposed_damage_class_id')
            ->leftJoin('damage_classes as confirmed', 'confirmed.id', '=', 'a.confirmed_damage_class_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'a.building_id')
            ->leftJoin('users as assessor', 'assessor.id', '=', 'a.assessed_by')
            ->leftJoin('users as confirmer', 'confirmer.id', '=', 'a.confirmed_by')
            ->where('a.id', $id)
            ->first([
                'a.*',
                'proposed.class_code as proposed_class_code',
                'proposed.class_name as proposed_class_name',
                'confirmed.class_code as confirmed_class_code',
                'confirmed.class_name as confirmed_class_name',
                'buildings.building_reference',
                'assessor.first_name as assessed_by_first_name',
                'assessor.last_name as assessed_by_last_name',
                'confirmer.first_name as confirmed_by_first_name',
                'confirmer.last_name as confirmed_by_last_name',
            ]);
        abort_if($row === null, 404);

        return $this->serialize($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forReport(int $reportId, ?string $status = null): array
    {
        if (! Schema::hasTable('building_damage_cost_assessments')) {
            return [];
        }

        $query = DB::table('building_damage_cost_assessments as a')
            ->leftJoin('damage_classes as proposed', 'proposed.id', '=', 'a.proposed_damage_class_id')
            ->leftJoin('damage_classes as confirmed', 'confirmed.id', '=', 'a.confirmed_damage_class_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'a.building_id')
            ->leftJoin('users as assessor', 'assessor.id', '=', 'a.assessed_by')
            ->leftJoin('users as confirmer', 'confirmer.id', '=', 'a.confirmed_by')
            ->where('a.barangay_report_id', $reportId)
            ->orderByDesc('a.id');

        if ($status) {
            $query->where('a.assessment_status', $status);
        }

        return $query->get([
            'a.*',
            'proposed.class_code as proposed_class_code',
            'proposed.class_name as proposed_class_name',
            'confirmed.class_code as confirmed_class_code',
            'confirmed.class_name as confirmed_class_name',
            'buildings.building_reference',
            'assessor.first_name as assessed_by_first_name',
            'assessor.last_name as assessed_by_last_name',
            'confirmer.first_name as confirmed_by_first_name',
            'confirmer.last_name as confirmed_by_last_name',
        ])->map(fn ($row) => $this->serialize($row))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(object $row): array
    {
        $breakdown = [];
        if (! empty($row->breakdown_json)) {
            $decoded = json_decode((string) $row->breakdown_json, true);
            $breakdown = is_array($decoded) ? $decoded : [];
        }

        $confirmedCode = $row->confirmed_class_code ?? null;
        $proposedCode = $row->proposed_class_code ?? null;

        return [
            'id' => (int) $row->id,
            'barangay_report_id' => (int) $row->barangay_report_id,
            'field_assessment_id' => $row->field_assessment_id ? (int) $row->field_assessment_id : null,
            'building_id' => (int) $row->building_id,
            'building_reference' => $row->building_reference ?? null,
            'ai_prediction_id' => $row->ai_prediction_id ? (int) $row->ai_prediction_id : null,
            'proposed_damage_class_id' => $row->proposed_damage_class_id ? (int) $row->proposed_damage_class_id : null,
            'confirmed_damage_class_id' => $row->confirmed_damage_class_id ? (int) $row->confirmed_damage_class_id : null,
            'proposed_class_code' => $proposedCode,
            'confirmed_class_code' => $confirmedCode,
            'proposed_label' => $this->estimator->displayLabel($proposedCode, $row->proposed_class_name ?? null),
            'confirmed_label' => $this->estimator->displayLabel($confirmedCode, $row->confirmed_class_name ?? null),
            'classification_source' => $row->classification_source,
            'correction_reason' => $row->correction_reason,
            'occupancy_key' => $row->occupancy_key,
            'construction_type' => $row->construction_type,
            'construction_grade' => $row->construction_grade,
            'total_floor_area_m2' => $row->total_floor_area_m2 !== null ? (float) $row->total_floor_area_m2 : null,
            'assessed_floor_area_m2' => $row->assessed_floor_area_m2 !== null ? (float) $row->assessed_floor_area_m2 : null,
            'unit_cost_php' => $row->unit_cost_php !== null ? (float) $row->unit_cost_php : null,
            'rate_source' => $row->rate_source,
            'rate_source_note' => $row->rate_source_note,
            'criteria_version' => $row->criteria_version,
            'estimated_cost_php' => $row->estimated_cost_php !== null ? (float) $row->estimated_cost_php : null,
            'calculated_estimated_cost_php' => isset($row->calculated_estimated_cost_php) && $row->calculated_estimated_cost_php !== null
                ? (float) $row->calculated_estimated_cost_php
                : null,
            'cost_is_adjusted' => (bool) ($row->cost_is_adjusted ?? false),
            'cost_adjustment_reason' => $row->cost_adjustment_reason ?? null,
            'cost_status' => $row->cost_status,
            'assessment_status' => $row->assessment_status,
            'supersedes_id' => $row->supersedes_id ? (int) $row->supersedes_id : null,
            'assessed_by' => (int) $row->assessed_by,
            'assessed_by_name' => $this->personName($row->assessed_by_first_name ?? null, $row->assessed_by_last_name ?? null),
            'confirmed_by' => $row->confirmed_by ? (int) $row->confirmed_by : null,
            'confirmed_by_name' => $this->personName($row->confirmed_by_first_name ?? null, $row->confirmed_by_last_name ?? null),
            'confirmed_at' => $row->confirmed_at,
            'remarks' => $row->remarks,
            'breakdown' => $breakdown,
            'updated_at' => $row->updated_at,
            'created_at' => $row->created_at,
        ];
    }

    private function assertBuildingOnReport(object $report, int $buildingId): void
    {
        $exists = DB::table('barangay_report_buildings')
            ->where('barangay_report_id', $report->id)
            ->where('building_id', $buildingId)
            ->exists();
        abort_unless($exists, 422, 'Select a building linked to this barangay report.');
    }

    private function resolveClassId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $classId = (int) $id;
        abort_unless(DB::table('damage_classes')->where('id', $classId)->exists(), 422, 'Choose a valid damage class.');

        return $classId;
    }

    private function personName(mixed $first, mixed $last): ?string
    {
        $name = trim(implode(' ', array_filter([(string) $first, (string) $last], fn ($part) => $part !== '')));

        return $name !== '' ? $name : null;
    }
}
