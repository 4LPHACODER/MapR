<?php

namespace App\Http\Controllers\Assessor;

use App\Http\Controllers\Controller;
use App\Jobs\ClassifyBuildingPhoto;
use App\Services\AssignmentScope;
use App\Services\BuildingCostEstimator;
use App\Services\BuildingDamageAssessmentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuildingDamageAssessmentController extends Controller
{
    public function __construct(
        private readonly AssignmentScope $scope,
        private readonly BuildingCostEstimator $estimator,
        private readonly BuildingDamageAssessmentWorkflow $workflow,
    ) {}

    public function show(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);
        $fieldAssessmentId = $this->workflow->ensureFieldAssessment($request->user(), $row);

        $buildings = Schema::hasTable('barangay_report_buildings')
            ? DB::table('barangay_report_buildings as link')
                ->join('buildings', 'buildings.id', '=', 'link.building_id')
                ->where('link.barangay_report_id', $row->id)
                ->get([
                    'buildings.id',
                    'buildings.building_reference',
                    'buildings.building_type',
                    'link.observations',
                ])
            : collect();

        $photos = collect();
        if (Schema::hasTable('incident_photos')) {
            $photoColumns = ['id', 'original_filename', 'mime_type', 'file_size', 'building_id'];
            if (Schema::hasColumn('incident_photos', 'captured_at')) {
                $photoColumns[] = 'captured_at';
            }
            $photos = DB::table('incident_photos')
                ->where('barangay_report_id', $row->id)
                ->orderBy('id')
                ->get($photoColumns)
                ->map(fn ($photo, $index) => [
                    'id' => (int) $photo->id,
                    'number' => $index + 1,
                    'original_filename' => $photo->original_filename,
                    'mime_type' => $photo->mime_type,
                    'file_size' => (int) $photo->file_size,
                    'building_id' => $photo->building_id ? (int) $photo->building_id : null,
                    'captured_at' => $photo->captured_at ?? null,
                    'url' => '/rdana/api/reports/'.(int) $row->id.'/photos/'.(int) $photo->id,
                ]);
        }

        $predictions = collect();
        if (Schema::hasTable('building_damage_predictions') && $photos->isNotEmpty()) {
            $predictions = DB::table('building_damage_predictions as p')
                ->leftJoin('damage_classes', 'damage_classes.id', '=', 'p.predicted_damage_class_id')
                ->leftJoin('ai_models', 'ai_models.id', '=', 'p.ai_model_id')
                ->whereIn('p.incident_photo_id', $photos->pluck('id')->all())
                ->orderByDesc('p.id')
                ->get([
                    'p.id',
                    'p.incident_photo_id',
                    'p.building_id',
                    'p.prediction_status',
                    'p.failure_reason',
                    'p.confidence_score',
                    'p.predicted_at',
                    'p.predicted_damage_class_id',
                    'damage_classes.class_code',
                    'damage_classes.class_name',
                    'ai_models.model_version',
                    'ai_models.architecture',
                ])
                ->map(fn ($prediction) => [
                    'id' => (int) $prediction->id,
                    'incident_photo_id' => (int) $prediction->incident_photo_id,
                    'building_id' => $prediction->building_id ? (int) $prediction->building_id : null,
                    'prediction_status' => $prediction->prediction_status,
                    'failure_reason' => $prediction->failure_reason,
                    'confidence_score' => $prediction->confidence_score !== null ? (float) $prediction->confidence_score : null,
                    'predicted_at' => $prediction->predicted_at,
                    'predicted_damage_class_id' => $prediction->predicted_damage_class_id ? (int) $prediction->predicted_damage_class_id : null,
                    'class_code' => $prediction->class_code,
                    'class_name' => $prediction->class_name,
                    'display_label' => $this->estimator->displayLabel($prediction->class_code, $prediction->class_name),
                    'model_version' => $prediction->model_version,
                    'architecture' => $prediction->architecture,
                ]);
        }

        $classes = Schema::hasTable('damage_classes')
            ? DB::table('damage_classes')->orderBy('display_order')->get(['id', 'class_code', 'class_name'])
                ->map(fn ($class) => [
                    'id' => (int) $class->id,
                    'class_code' => $class->class_code,
                    'class_name' => $class->class_name,
                    'display_label' => $this->estimator->displayLabel($class->class_code, $class->class_name),
                ])
            : collect();

        $assessments = $this->workflow->forReport((int) $row->id);
        $current = collect($assessments)
            ->filter(fn ($item) => in_array($item['assessment_status'], ['draft', 'confirmed'], true))
            ->groupBy('building_id')
            ->map(function ($group) {
                $draft = $group->firstWhere('assessment_status', 'draft');
                $confirmed = $group->firstWhere('assessment_status', 'confirmed');

                return $draft ?: $confirmed;
            })
            ->values()
            ->all();

        $barangay = Schema::hasTable('barangays')
            ? DB::table('barangays')->where('id', $row->barangay_id)->first(['id', 'barangay_name as name'])
            : null;
        $incident = Schema::hasTable('disaster_incidents')
            ? DB::table('disaster_incidents')->where('id', $row->disaster_incident_id)->first(['id', 'incident_name as name', 'incident_reference as reference'])
            : null;
        $linkage = $this->reportLinkage((int) $row->id, $buildings->first());

        return response()->json([
            'data' => [
                'report' => [
                    'id' => (int) $row->id,
                    'reference' => $row->report_reference,
                    'status' => $row->report_status,
                    'barangay' => $barangay,
                    'incident' => $incident,
                ],
                'linkage' => $linkage,
                'field_assessment_id' => $fieldAssessmentId,
                'buildings' => $buildings,
                'photos' => $photos,
                'predictions' => $predictions,
                'damage_classes' => $classes,
                'current_assessments' => $current,
                'schedule' => $this->estimator->scheduleMeta(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, object>|object|null  $primaryBuilding
     * @return array{resident_name: ?string, household_reference: ?string, building_reference: ?string, building_id: ?int, status: string}
     */
    private function reportLinkage(int $reportId, mixed $primaryBuilding): array
    {
        $building = is_object($primaryBuilding) ? $primaryBuilding : null;
        $buildingId = $building->id ?? null;
        $buildingReference = $building->building_reference ?? null;
        $householdReference = null;
        $residentName = null;

        $residentReport = Schema::hasTable('resident_incident_reports')
            ? DB::table('resident_incident_reports')->where('barangay_report_id', $reportId)->orderByDesc('id')->first()
            : null;
        if ($residentReport) {
            if (! $buildingId && ! empty($residentReport->building_id)) {
                $buildingId = (int) $residentReport->building_id;
            }
            if (Schema::hasTable('users')) {
                $user = DB::table('users')->where('id', $residentReport->resident_user_id)->first();
                if ($user) {
                    $residentName = trim(implode(' ', array_filter([
                        (string) ($user->first_name ?? ''),
                        (string) ($user->last_name ?? ''),
                    ], fn ($part) => $part !== ''))) ?: null;
                }
            }
        }

        if ($buildingId && Schema::hasTable('households')) {
            $householdReference = DB::table('households')->where('building_id', $buildingId)->value('household_reference');
            if (! $buildingReference && Schema::hasTable('buildings')) {
                $buildingReference = DB::table('buildings')->where('id', $buildingId)->value('building_reference');
            }
        }

        return [
            'resident_name' => $residentName,
            'household_reference' => is_string($householdReference) ? $householdReference : null,
            'building_reference' => is_string($buildingReference) ? $buildingReference : null,
            'building_id' => $buildingId ? (int) $buildingId : null,
            'status' => $buildingId ? 'linked' : 'pending_verification',
        ];
    }

    public function history(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);

        return response()->json(['data' => $this->workflow->forReport((int) $row->id)]);
    }

    public function calculate(Request $request, int $report): JsonResponse
    {
        $this->reportOrAbort($request, $report);
        $data = $request->validate([
            'occupancy_key' => ['nullable', 'string', 'max:64'],
            'construction_type' => ['nullable', 'string', 'max:8'],
            'construction_grade' => ['nullable', 'string', 'max:8'],
            'total_floor_area_m2' => ['nullable', 'numeric', 'min:0'],
            'assessed_floor_area_m2' => ['nullable', 'numeric', 'min:0'],
            'damage_class_code' => ['nullable', 'string', 'max:64'],
            'authorized_unit_cost_php' => ['nullable', 'numeric', 'min:0'],
            'rate_source_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->estimator->calculate($data)]);
    }

    public function store(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);
        $data = $this->validatedAssessment($request);
        $fieldAssessmentId = $this->workflow->ensureFieldAssessment($request->user(), $row);
        $saved = $this->workflow->saveDraft($request->user(), $row, $fieldAssessmentId, $data);

        return response()->json(['data' => $saved], isset($data['id']) ? 200 : 201);
    }

    public function confirm(Request $request, int $report, int $assessment): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);
        $saved = $this->workflow->confirm($request->user(), $row, $assessment);

        return response()->json(['data' => $saved]);
    }

    public function revise(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);
        $data = $request->validate([
            'confirmed_damage_class_id' => ['nullable', 'integer'],
            'estimated_cost_php' => ['nullable', 'numeric', 'min:0'],
            'correction_reason' => ['required', 'string', 'min:5', 'max:2000'],
            'cost_adjustment_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $saved = $this->workflow->supervisorRevise($request->user(), $row, $data);

        return response()->json(['data' => $saved]);
    }

    public function searchBuildings(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $term = trim((string) ($data['q'] ?? ''));
        if (! Schema::hasTable('buildings')) {
            return response()->json(['data' => []]);
        }

        $query = DB::table('buildings')->where('barangay_id', $row->barangay_id)->orderBy('building_reference')->limit(25);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('building_reference', 'like', $like);
                if (Schema::hasColumn('buildings', 'building_name')) {
                    $builder->orWhere('building_name', 'like', $like);
                }
                if (Schema::hasColumn('buildings', 'address_description')) {
                    $builder->orWhere('address_description', 'like', $like);
                }
            });
        }

        $linked = Schema::hasTable('barangay_report_buildings')
            ? DB::table('barangay_report_buildings')->where('barangay_report_id', $row->id)->pluck('building_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $rows = $query->get(['id', 'building_reference', 'building_type'])->map(fn ($building) => [
            'id' => (int) $building->id,
            'building_reference' => $building->building_reference,
            'building_type' => $building->building_type,
            'linked' => in_array((int) $building->id, $linked, true),
        ]);

        return response()->json(['data' => $rows]);
    }

    public function linkBuilding(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);
        $data = $request->validate([
            'building_id' => ['required', 'integer'],
            'tag_untagged_photos' => ['sometimes', 'boolean'],
        ]);
        $buildingId = (int) $data['building_id'];
        abort_unless(Schema::hasTable('buildings') && Schema::hasTable('barangay_report_buildings'), 422, 'Building linkage is unavailable.');

        $building = DB::table('buildings')->where('id', $buildingId)->first();
        abort_if($building === null, 404);
        abort_unless((int) $building->barangay_id === (int) $row->barangay_id, 422, 'Select a building from this report barangay.');

        $exists = DB::table('barangay_report_buildings')
            ->where('barangay_report_id', $row->id)
            ->where('building_id', $buildingId)
            ->exists();
        if (! $exists) {
            DB::table('barangay_report_buildings')->insert([
                'observations' => null,
                'barangay_report_id' => $row->id,
                'building_id' => $buildingId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (($data['tag_untagged_photos'] ?? true) && Schema::hasTable('incident_photos')) {
            DB::table('incident_photos')
                ->where('barangay_report_id', $row->id)
                ->whereNull('building_id')
                ->update([
                    'building_id' => $buildingId,
                    'updated_at' => now(),
                ]);
        }

        return $this->show($request, $report);
    }

    public function classify(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($request, $report);
        $data = $request->validate([
            'building_id' => ['required', 'integer'],
            'incident_photo_id' => ['nullable', 'integer'],
        ]);

        $buildingId = (int) $data['building_id'];
        abort_unless(
            DB::table('barangay_report_buildings')->where('barangay_report_id', $row->id)->where('building_id', $buildingId)->exists(),
            422,
            'Select a building linked to this barangay report.'
        );

        if (! Schema::hasTable('ai_models') || ! Schema::hasTable('building_damage_predictions')) {
            return response()->json([
                'classification' => 'unavailable',
                'message' => 'Classification service is unavailable. No damage class was assigned.',
            ], 422);
        }

        $model = DB::table('ai_models')
            ->where('architecture', 'EfficientNetB0')
            ->where('optimizer', 'AdamW')
            ->where('deployment_status', 'active')
            ->first();
        if ($model === null) {
            return response()->json([
                'classification' => 'unavailable',
                'message' => 'Classification service is unavailable. No damage class was assigned.',
            ], 422);
        }

        $photosQuery = DB::table('incident_photos')
            ->where('barangay_report_id', $row->id)
            ->where(function ($query) use ($buildingId) {
                $query->where('building_id', $buildingId)->orWhereNull('building_id');
            });
        if (! empty($data['incident_photo_id'])) {
            $photosQuery->where('id', (int) $data['incident_photo_id']);
        }
        $photos = $photosQuery->orderBy('id')->get();
        if ($photos->isEmpty()) {
            return response()->json([
                'classification' => 'unavailable',
                'message' => 'No photographs are available on this report for classification.',
            ], 422);
        }

        DB::table('incident_photos')
            ->where('barangay_report_id', $row->id)
            ->whereNull('building_id')
            ->whereIn('id', $photos->pluck('id')->all())
            ->update([
                'building_id' => $buildingId,
                'updated_at' => now(),
            ]);

        $results = [];
        foreach ($photos as $photo) {
            $id = DB::table('building_damage_predictions')->insertGetId([
                'prediction_status' => 'queued',
                'incident_photo_id' => $photo->id,
                'building_id' => $buildingId,
                'ai_model_id' => $model->id,
                'predicted_damage_class_id' => null,
                'requested_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            ClassifyBuildingPhoto::dispatchSync($id);
            $prediction = DB::table('building_damage_predictions')->where('id', $id)->first();
            $class = $prediction?->predicted_damage_class_id
                ? DB::table('damage_classes')->where('id', $prediction->predicted_damage_class_id)->first()
                : null;
            $results[] = [
                'id' => (int) $id,
                'incident_photo_id' => (int) $photo->id,
                'building_id' => $buildingId,
                'prediction_status' => $prediction->prediction_status ?? 'failed',
                'failure_reason' => $prediction->failure_reason ?? null,
                'predicted_damage_class_id' => $prediction->predicted_damage_class_id ? (int) $prediction->predicted_damage_class_id : null,
                'class_code' => $class->class_code ?? null,
                'display_label' => $this->estimator->displayLabel($class->class_code ?? null, $class->class_name ?? null),
                'confidence_score' => isset($prediction->confidence_score) && $prediction->confidence_score !== null
                    ? (float) $prediction->confidence_score
                    : null,
                'model_version' => $model->model_version ?? null,
                'architecture' => $model->architecture ?? 'EfficientNetB0',
            ];
        }

        return response()->json(['data' => $results]);
    }

    private function reportOrAbort(Request $request, int $report): object
    {
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        abort_unless($this->scope->assessorCanOpenReport($request->user(), (int) $row->id), 403);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAssessment(Request $request): array
    {
        return $request->validate([
            'id' => ['nullable', 'integer'],
            'building_id' => ['required', 'integer'],
            'ai_prediction_id' => ['nullable', 'integer'],
            'proposed_damage_class_id' => ['nullable', 'integer'],
            'confirmed_damage_class_id' => ['nullable', 'integer'],
            'classification_source' => ['nullable', 'string', 'in:ai,manual,ai_corrected'],
            'correction_reason' => ['nullable', 'string', 'max:5000'],
            'occupancy_key' => ['nullable', 'string', 'max:64'],
            'construction_type' => ['nullable', 'string', 'max:8'],
            'construction_grade' => ['nullable', 'string', 'max:8'],
            'total_floor_area_m2' => ['nullable', 'numeric', 'min:0'],
            'assessed_floor_area_m2' => ['nullable', 'numeric', 'min:0'],
            'authorized_unit_cost_php' => ['nullable', 'numeric', 'min:0'],
            'rate_source_note' => ['nullable', 'string', 'max:2000'],
            'damage_class_code' => ['nullable', 'string', 'max:64'],
            'estimated_cost_php' => ['nullable', 'numeric', 'min:0'],
            'cost_is_adjusted' => ['nullable', 'boolean'],
            'cost_adjustment_reason' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
