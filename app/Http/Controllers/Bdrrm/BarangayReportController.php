<?php

namespace App\Http\Controllers\Bdrrm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shared\ReportFlagController;
use App\Policies\BarangayReportPolicy;
use App\Policies\ResidentIncidentReportPolicy;
use App\Services\AssignmentScope;
use App\Services\BuildingDamageAssessmentWorkflow;
use App\Services\BuildingRegistry;
use App\Services\ReportAssignmentService;
use App\Services\ReportSignatoryCatalog;
use App\Services\ReportWorkflow;
use App\Services\ResidentReportDocument;
use App\Services\ResidentReportWorkflow;
use App\Services\SignatureConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BarangayReportController extends Controller
{
    public function __construct(
        private readonly AssignmentScope $scope,
        private readonly BuildingRegistry $registry,
        private readonly ReportWorkflow $workflow,
        private readonly ReportSignatoryCatalog $signatories,
        private readonly BarangayReportPolicy $reports,
        private readonly ResidentIncidentReportPolicy $submissions,
        private readonly ResidentReportWorkflow $residentReports,
        private readonly SignatureConfirmation $confirmation,
    ) {}

    public function submissions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,submitted,under_review,returned,reviewed,not_opened,reviewing,next_office,secretary_checked,awaiting_endorsement'],
            'queue' => ['nullable', 'in:pending,reviewed,unassigned,checked'],
            'search' => ['nullable', 'string', 'max:80'],
            'submitted_from' => ['nullable', 'date_format:Y-m-d'],
            'submitted_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (! empty($filters['submitted_from']) && ! empty($filters['submitted_to']) && $filters['submitted_from'] > $filters['submitted_to']) {
            abort(422, 'The start date must be on or before the end date.');
        }
        $role = $request->user()->role?->code;
        $supervisor = in_array($role, ['barangay_secretary', 'barangay_captain'], true);
        $requested = $filters['status'] ?? null;
        $queue = $supervisor ? ($filters['queue'] ?? 'unassigned') : null;
        if ($role === 'barangay_captain' && $queue === 'reviewed') {
            $queue = 'checked';
        }
        if ($role === 'barangay_secretary' && $queue === 'checked') {
            $queue = 'reviewed';
        }
        $status = $this->resolveQueueStatus($queue, $requested, $role);
        $ids = $this->scope->ids($request);
        $query = $this->residentReports->queueQuery(
            $ids,
            $status,
            $filters['search'] ?? null,
            $filters['submitted_from'] ?? null,
            $filters['submitted_to'] ?? null,
        );
        if ($queue === 'reviewed' || $queue === 'checked') {
            $this->requireReviewerSignature($query);
            $this->applyOfficeQueueFilter($query, $queue, $requested, $role);
        } elseif ($role === 'bdrrm' && $status === 'reviewed') {
            $this->applyReviewedStageFilter($query, $requested);
        }
        if ($queue === 'unassigned') {
            $this->limitToUnassigned($query);
        }
        if ($queue === 'pending') {
            $this->limitToAssigned($query);
        }
        if ($role === 'bdrrm') {
            $this->limitToAssignee($query, (int) $request->user()->id);
        }
        $payload = $this->page($query, $request, fn ($row) => $this->residentReports->queueRow($row));
        if ($supervisor) {
            $payload['counts'] = $this->submissionCounts($ids, $role);
        } elseif ($role === 'bdrrm') {
            $payload['counts'] = $this->personnelCounts($ids, (int) $request->user()->id);
        }

        return response()->json($payload);
    }

    private function resolveQueueStatus(?string $queue, ?string $requested, ?string $role): string
    {
        if ($queue === 'reviewed' || $queue === 'checked') {
            return 'reviewed';
        }
        if ($queue === 'pending' || $queue === 'unassigned') {
            return match ($requested) {
                'not_opened', 'submitted' => 'submitted',
                'reviewing', 'under_review' => 'under_review',
                default => 'pending',
            };
        }
        if ($role === 'bdrrm') {
            return match ($requested) {
                'reviewed' => 'reviewed',
                'returned' => 'returned',
                'not_opened', 'submitted' => 'submitted',
                'reviewing', 'under_review' => 'under_review',
                default => 'pending',
            };
        }

        return match ($requested) {
            'not_opened' => 'submitted',
            'reviewing' => 'under_review',
            default => $requested ?: 'pending',
        };
    }

    private function applyOfficeQueueFilter($query, string $queue, ?string $requested, ?string $role): void
    {
        if ($queue === 'checked' || ($queue === 'reviewed' && $role === 'barangay_captain')) {
            $this->limitToPendingCaptain($query);

            return;
        }
        if ($queue === 'reviewed') {
            if ($requested === 'secretary_checked') {
                $this->limitToPendingCaptain($query);

                return;
            }
            $this->limitToPendingSecretary($query);
        }
    }

    private function applyReviewedStageFilter($query, ?string $requested): void
    {
        if ($requested === 'next_office') {
            $this->limitToPendingSecretary($query);
        } elseif (in_array($requested, ['secretary_checked', 'awaiting_endorsement'], true)) {
            $this->limitToPendingCaptain($query);
        }
    }

    private function limitToPendingSecretary($query): void
    {
        if (! Schema::hasColumn('barangay_reports', 'workflow_stage') || ! Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
            return;
        }
        $query->whereExists(function ($stage) {
            $stage->selectRaw('1')
                ->from('barangay_reports')
                ->whereColumn('barangay_reports.id', 'reports.barangay_report_id')
                ->where('barangay_reports.workflow_stage', 'pending_secretary');
        });
        if (Schema::hasTable('report_signatures') && Schema::hasColumn('report_signatures', 'barangay_report_id')) {
            $query->whereNotExists(function ($signed) {
                $signed->selectRaw('1')
                    ->from('report_signatures')
                    ->whereColumn('report_signatures.barangay_report_id', 'reports.barangay_report_id')
                    ->where('report_signatures.signature_role', 'barangay_secretary');
            });
        }
    }

    private function limitToPendingCaptain($query): void
    {
        if (! Schema::hasColumn('barangay_reports', 'workflow_stage') || ! Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
            return;
        }
        $query->whereExists(function ($stage) {
            $stage->selectRaw('1')
                ->from('barangay_reports')
                ->whereColumn('barangay_reports.id', 'reports.barangay_report_id')
                ->where('barangay_reports.workflow_stage', 'pending_captain');
        });
        if (Schema::hasTable('report_signatures') && Schema::hasColumn('report_signatures', 'barangay_report_id')) {
            $query->whereExists(function ($signed) {
                $signed->selectRaw('1')
                    ->from('report_signatures')
                    ->whereColumn('report_signatures.barangay_report_id', 'reports.barangay_report_id')
                    ->where('report_signatures.signature_role', 'barangay_secretary');
            });
        }
    }

    private function requireReviewerSignature($query): void
    {
        if (! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return;
        }
        $query->whereExists(function ($signature) {
            $signature->selectRaw('1')
                ->from('report_signatures')
                ->whereColumn('report_signatures.resident_incident_report_id', 'reports.id')
                ->where('report_signatures.signature_role', 'bdrrm_reviewer');
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array{pending: int, reviewed: int, unassigned: int, total: int}
     */
    private function submissionCounts(array $ids, ?string $role = null): array
    {
        $pending = $this->residentReports->queueQuery($ids, 'pending', null);
        $this->limitToAssigned($pending);
        $reviewed = $this->residentReports->queueQuery($ids, 'reviewed', null);
        $this->requireReviewerSignature($reviewed);
        if ($role === 'barangay_captain') {
            $this->limitToPendingCaptain($reviewed);
        } else {
            $this->limitToPendingSecretary($reviewed);
        }
        $returned = $this->residentReports->queueQuery($ids, 'returned', null);
        $unassigned = $this->residentReports->queueQuery($ids, 'pending', null);
        $this->limitToUnassigned($unassigned);
        $pendingCount = $pending->count();
        $reviewedCount = $reviewed->count();
        $unassignedCount = $unassigned->count();

        return [
            'pending' => $pendingCount,
            'reviewed' => $reviewedCount,
            'checked' => $role === 'barangay_captain' ? $reviewedCount : 0,
            'unassigned' => $unassignedCount,
            'total' => $unassignedCount + $pendingCount + $reviewedCount + $returned->count(),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array{pending: int, reviewed: int}
     */
    private function personnelCounts(array $ids, int $userId): array
    {
        $pending = $this->residentReports->queueQuery($ids, 'pending', null);
        $reviewed = $this->residentReports->queueQuery($ids, 'reviewed', null);
        $this->requireReviewerSignature($reviewed);
        $this->limitToAssignee($pending, $userId);
        $this->limitToAssignee($reviewed, $userId);

        return [
            'pending' => $pending->count(),
            'reviewed' => $reviewed->count(),
        ];
    }

    private function limitToAssignee($query, int $userId): void
    {
        if (! Schema::hasTable('report_assignments')) {
            return;
        }
        $query->whereExists(function ($assigned) use ($userId) {
            $assigned->selectRaw('1')
                ->from('report_assignments')
                ->whereColumn('report_assignments.subject_id', 'reports.id')
                ->where('report_assignments.subject_type', 'resident_incident_report')
                ->where('report_assignments.assignment_role', 'bdrrm')
                ->where('report_assignments.is_active', true)
                ->where('report_assignments.assignee_user_id', $userId);
        });
    }

    private function limitToAssigned($query): void
    {
        if (! Schema::hasTable('report_assignments')) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->whereExists(function ($assigned) {
            $assigned->selectRaw('1')
                ->from('report_assignments')
                ->whereColumn('report_assignments.subject_id', 'reports.id')
                ->where('report_assignments.subject_type', 'resident_incident_report')
                ->where('report_assignments.assignment_role', 'bdrrm')
                ->where('report_assignments.is_active', true);
        });
    }

    private function limitToUnassigned($query): void
    {
        if (! Schema::hasTable('report_assignments')) {
            return;
        }
        $query->whereNotExists(function ($none) {
            $none->selectRaw('1')
                ->from('report_assignments')
                ->whereColumn('report_assignments.subject_id', 'reports.id')
                ->where('report_assignments.subject_type', 'resident_incident_report')
                ->where('report_assignments.assignment_role', 'bdrrm')
                ->where('report_assignments.is_active', true);
        });
    }

    public function showSubmission(Request $request, int $submission): JsonResponse
    {
        $row = $this->submission($request, $submission);

        return response()->json(['data' => $this->residentReports->present($row, true)]);
    }

    public function startReview(Request $request, int $submission): JsonResponse
    {
        $row = $this->submission($request, $submission);
        if ($request->user()->role?->code !== 'bdrrm') {
            abort(403, 'Only the assigned BDRRM officer can start this review.');
        }
        app(ReportAssignmentService::class)->assertAssignee($request->user(), 'resident_incident_report', $submission, 'bdrrm', true);
        $this->residentReports->startReview($request->user(), $row);
        $fresh = DB::table('resident_incident_reports')->where('id', $submission)->first();

        return response()->json(['data' => $this->residentReports->present($fresh, true)]);
    }

    public function submissionSummary(Request $request, int $submission): JsonResponse
    {
        $row = DB::table('resident_incident_reports')->where('id', $submission)->first();
        abort_if($row === null, 404);
        abort_unless($this->submissions->view($request->user(), $row), 403);

        return response()->json(['data' => $this->residentReports->selectionSummary($row)]);
    }

    public function householdReports(Request $request, int $household): JsonResponse
    {
        abort_unless(Schema::hasTable('households'), 404);
        $row = DB::table('households')->where('id', $household)->first();
        abort_if($row === null, 404);
        $this->scope->assertBarangay($request, isset($row->barangay_id) ? (int) $row->barangay_id : null);

        return response()->json([
            'data' => $this->residentReports->linkedForHousehold($this->scope->ids($request), $household),
        ]);
    }

    public function reviewSignature(Request $request, int $submission): StreamedResponse
    {
        $row = $this->submission($request, $submission);
        abort_unless(Schema::hasTable('report_signatures') && Schema::hasColumn('report_signatures', 'resident_incident_report_id'), 404);
        $signature = DB::table('report_signatures')
            ->where('resident_incident_report_id', $row->id)
            ->where('signature_role', 'bdrrm_reviewer')
            ->first();
        abort_if($signature === null || empty($signature->user_signature_asset_id), 404);
        $asset = DB::table('user_signature_assets')->where('id', $signature->user_signature_asset_id)->first();
        abort_if($asset === null, 404);
        abort_unless(Storage::disk('local')->exists($asset->storage_path), 404);
        $extension = $asset->mime_type === 'image/png' ? 'png' : 'jpg';

        return Storage::disk('local')->response($asset->storage_path, 'bdrrm-signature.'.$extension, [
            'Content-Type' => $asset->mime_type,
        ]);
    }

    public function buildingReports(Request $request, int $building): JsonResponse
    {
        $this->registry->denyPersonnel($request->user());
        abort_unless(Schema::hasTable('buildings'), 404);
        $row = DB::table('buildings')->where('id', $building)->first();
        abort_if($row === null, 404);
        $this->scope->assertBarangay($request, isset($row->barangay_id) ? (int) $row->barangay_id : null);

        return response()->json([
            'data' => $this->residentReports->linkedForBuilding($this->scope->ids($request), $building),
        ]);
    }

    public function returnSubmission(Request $request, int $submission): JsonResponse
    {
        $row = $this->submission($request, $submission);
        if (is_string($request->input('return_reason'))) {
            $request->merge(['return_reason' => trim($request->input('return_reason'))]);
        }
        $data = $request->validate([
            'return_reason' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        if ($request->exists('estimated_repair_cost')) {
            abort(422, 'BDRRM cannot record the estimated repair cost.');
        }
        $this->residentReports->returnSubmission($request->user(), $row, $data['return_reason']);

        return response()->json(['data' => $this->residentReports->present($this->submission($request, $submission), true)]);
    }

    public function submissionDocument(Request $request, int $submission, ResidentReportDocument $documents): Response
    {
        $row = $this->submission($request, $submission);
        $pdf = $documents->render($row);
        $name = 'MapR_Resident_Report_'.$row->report_reference.'.pdf';
        if ($request->boolean('download')) {
            return $pdf->download($name);
        }

        return $pdf->stream($name);
    }

    public function acceptSubmission(Request $request, int $submission): JsonResponse
    {
        $row = $this->submission($request, $submission);
        if ($request->user()->role?->code !== 'bdrrm') {
            abort(403, 'Only assigned BDRRM personnel can accept this report.');
        }
        app(ReportAssignmentService::class)->assertAssignee($request->user(), 'resident_incident_report', $submission, 'bdrrm', true);
        if ($request->exists('estimated_repair_cost')) {
            abort(422, 'BDRRM cannot record the estimated repair cost.');
        }
        $request->validate([
            'confirmed' => ['accepted'],
            'content_revision' => ['required', 'string', 'size:64'],
            'use_saved_signature' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'file'],
        ]);
        if (! $request->hasFile('signature') && ! $request->boolean('use_saved_signature')) {
            abort(422, 'Draw a signature or choose your saved signature before accepting.');
        }
        $endorsement = [
            'revision' => $request->string('content_revision')->toString(),
            'use_saved' => $request->boolean('use_saved_signature'),
            'file' => $request->file('signature'),
        ];
        $barangayReportId = $this->residentReports->acceptSubmission($request->user(), $row, $this->workflow, $endorsement);

        return response()->json([
            'data' => $this->residentReports->present(DB::table('resident_incident_reports')->where('id', $submission)->first(), true),
            'barangay_report_id' => $barangayReportId,
        ]);
    }

    private function rejectRepairCost(Request $request): void
    {
        if ($request->exists('estimated_repair_cost')) {
            abort(422, 'BDRRM cannot record the estimated repair cost.');
        }
    }

    private function submission(Request $request, int $submission): object
    {
        $row = DB::table('resident_incident_reports')->where('id', $submission)->first();
        abort_if($row === null, 404);
        abort_unless($this->submissions->view($request->user(), $row), 403);

        return $row;
    }

    public function buildings(Request $request): JsonResponse
    {
        if ($request->user()->role?->code === 'bdrrm') {
            $term = trim((string) $request->input('q'));
            if ($request->input('lookup') !== '1' || mb_strlen($term) < 2) {
                abort(403, 'Building Records are limited to the barangay secretary and captain.');
            }

            return response()->json($this->registry->lookup($request, $term));
        }

        return response()->json($this->registry->page($request));
    }

    public function index(Request $request): JsonResponse
    {
        $ids = $this->scope->ids($request);
        $query = $this->reportListQuery()->whereIn('barangay_reports.barangay_id', $ids === [] ? [0] : $ids);
        $this->applyListFilters($query, $request);
        $this->applyReportSort($query, $request);

        return response()->json($this->page($query, $request, fn ($row) => $this->reportRow($row)));
    }

    public function show(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($report);
        abort_unless($this->reports->view($request->user(), $row), 403);

        return response()->json(['data' => $this->detail($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->rejectRepairCost($request);
        $data = $request->validate([
            'disaster_incident_id' => ['required', 'integer'],
            'barangay_id' => ['required', 'integer'],
            'building_id' => ['nullable', 'integer'],
            'report_description' => ['nullable', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'resident_report_id' => ['nullable', 'integer'],
        ]);
        $this->scope->assertBarangay($request, (int) $data['barangay_id']);
        $this->assertIncidentIncludesBarangay((int) $data['disaster_incident_id'], (int) $data['barangay_id']);
        if (! empty($data['building_id'])) {
            $this->assertBuilding((int) $data['building_id'], (int) $data['barangay_id']);
        }

        $id = $this->workflow->createBarangayReport($request->user(), $data, (int) $data['barangay_id']);

        return response()->json(['data' => $this->detail($this->reportOrAbort($id))], 201);
    }

    public function update(Request $request, int $report): JsonResponse
    {
        $this->rejectRepairCost($request);
        $row = $this->reportOrAbort($report);
        abort_unless($this->reports->update($request->user(), $row), 403);
        $data = $request->validate([
            'report_description' => ['nullable', 'string', 'max:5000'],
            'building_id' => ['nullable', 'integer'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'updated_at' => ['nullable', 'string'],
        ]);
        if (! empty($data['building_id'])) {
            $this->assertBuilding((int) $data['building_id'], (int) $row->barangay_id);
        }
        $this->workflow->updateDraft($request->user(), $row, $data);

        return response()->json(['data' => $this->detail($this->reportOrAbort($report))]);
    }

    public function sign(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($report);
        abort_unless($this->reports->view($request->user(), $row), 403);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $this->workflow->signBarangay($request->user(), $row);

        return response()->json(['data' => $this->detail($this->reportOrAbort($report))]);
    }

    public function submit(Request $request, int $report): JsonResponse
    {
        $row = $this->reportOrAbort($report);
        abort_unless($this->reports->view($request->user(), $row), 403);
        if (! $this->reports->submit($request->user(), $row)) {
            abort(in_array($row->report_status, ['review', 'signed', 'submitted'], true) ? 409 : 403, 'This report was already submitted.');
        }
        $this->workflow->submitBarangayReport($request->user(), $row, $request->input('updated_at'));

        return response()->json(['data' => $this->detail($this->reportOrAbort($report))]);
    }

    private function reportOrAbort(int $id): object
    {
        $row = $this->reportListQuery()->where('barangay_reports.id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function reportListQuery()
    {
        $query = DB::table('barangay_reports')
            ->select('barangay_reports.*');
        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', 'barangays.id', '=', 'barangay_reports.barangay_id')
                ->addSelect('barangays.barangay_name as barangay_name');
        }
        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'barangay_reports.disaster_incident_id')
                ->addSelect('disaster_incidents.incident_name as incident_name');
        }
        if (Schema::hasTable('barangay_report_buildings') && Schema::hasTable('buildings')) {
            $query->selectRaw('(select buildings.building_reference from barangay_report_buildings inner join buildings on buildings.id = barangay_report_buildings.building_id where barangay_report_buildings.barangay_report_id = barangay_reports.id order by barangay_report_buildings.id limit 1) as building_reference');
        }
        if (Schema::hasTable('resident_incident_reports') && Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
            $query->selectRaw('(select resident_incident_reports.report_reference from resident_incident_reports where resident_incident_reports.barangay_report_id = barangay_reports.id order by resident_incident_reports.id limit 1) as resident_reference');
        }
        if (Schema::hasTable('report_signatures') && Schema::hasColumn('report_signatures', 'barangay_report_id')) {
            $query->selectRaw("case when exists (select 1 from report_signatures where report_signatures.barangay_report_id = barangay_reports.id and report_signatures.signature_role = 'barangay_signer' and report_signatures.mechanism = 'authenticated_e_signature') then 1 else 0 end as signature_recorded");
        }

        return $query;
    }

    private function detail(object $row): array
    {
        $buildings = Schema::hasTable('barangay_report_buildings')
            ? DB::table('barangay_report_buildings')->where('barangay_report_id', $row->id)->get()
            : collect();
        $photos = Schema::hasTable('incident_photos')
            ? DB::table('incident_photos')->where('barangay_report_id', $row->id)->get(['id', 'original_filename', 'mime_type', 'file_size'])
            : collect();
        $predictions = Schema::hasTable('building_damage_predictions')
            ? DB::table('building_damage_predictions')
                ->whereIn('incident_photo_id', $photos->pluck('id')->all() ?: [0])
                ->get(['id', 'incident_photo_id', 'prediction_status', 'failure_reason', 'predicted_damage_class_id'])
            : collect();

        $signaturePayload = $this->signatories->barangayReportViewerPayload($row);

        $fieldAssessment = Schema::hasTable('field_assessments')
            ? DB::table('field_assessments')->where('barangay_report_id', $row->id)->orderByDesc('id')->first()
            : null;
        $assigneeName = null;
        if ($fieldAssessment && Schema::hasTable('users') && ! empty($fieldAssessment->assigned_assessor_id)) {
            $assessor = DB::table('users')->where('id', $fieldAssessment->assigned_assessor_id)->first();
            $assigneeName = $assessor
                ? trim(implode(' ', array_filter([$assessor->first_name ?? null, $assessor->last_name ?? null])))
                : null;
        }
        $damage = null;
        if (Schema::hasTable('building_damage_cost_assessments') && Schema::hasTable('damage_classes')) {
            $damageRows = app(BuildingDamageAssessmentWorkflow::class)->forReport((int) $row->id, 'confirmed');
            $damage = $damageRows[0] ?? null;
            if ($damage === null) {
                $draftRows = app(BuildingDamageAssessmentWorkflow::class)->forReport((int) $row->id, 'draft');
                $damage = $draftRows[0] ?? null;
            }
        }
        $faStage = (string) ($fieldAssessment->workflow_stage ?? '');
        $verificationLabel = match ($faStage) {
            'pending_admin_verification' => 'Awaiting Administrative Verification',
            'pending_city_assessor' => 'Awaiting City Assessor Notation',
            'forwarded_cdrrmo' => 'Forwarded to CDRRMO',
            default => null,
        };
        if ($fieldAssessment && (string) $fieldAssessment->assessment_status === 'returned') {
            $verificationLabel = 'Returned for Correction';
        }

        return [
            ...$this->reportRow($row),
            'report_description' => $row->report_description,
            'return_reason' => $row->return_reason ?? null,
            'buildings' => $buildings,
            'photos' => $photos,
            'predictions' => $predictions,
            // Raw barangay-parent rows kept for compatibility; UI should prefer signature_slots.
            'signatures' => $this->signatures('barangay_report_id', (int) $row->id),
            'signature_slots' => $signaturePayload['slots'],
            'signature_signed_count' => $signaturePayload['signed_count'],
            'lineage' => $signaturePayload['lineage'],
            'timeline' => $this->timeline('barangay_report', (int) $row->id),
            'can_edit' => in_array($row->report_status, ['draft', 'returned'], true),
            'version' => 1,
            'incident' => Schema::hasTable('disaster_incidents') ? DB::table('disaster_incidents')->where('id', $row->disaster_incident_id)->first(['id', 'incident_reference as reference', 'incident_name as name']) : null,
            'barangay' => Schema::hasTable('barangays') ? DB::table('barangays')->where('id', $row->barangay_id)->first(['id', 'barangay_name as name']) : null,
            'flags' => ReportFlagController::forSubject('barangay_report', (int) $row->id),
            'open_flags' => collect(ReportFlagController::forSubject('barangay_report', (int) $row->id))->where('status', 'open')->count(),
            'checklist' => $this->checklist($row, $photos),
            'assessment_id' => $fieldAssessment ? (int) $fieldAssessment->id : null,
            'assessment_status' => $fieldAssessment->assessment_status ?? null,
            'assessment_workflow_stage' => $fieldAssessment->workflow_stage ?? null,
            'assessment_return_reason' => ($fieldAssessment && property_exists($fieldAssessment, 'return_reason')) ? $fieldAssessment->return_reason : null,
            'assignee_name' => $assigneeName,
            'verification_label' => $verificationLabel,
            'damage_summary' => $damage,
            'can_verify_officer' => $faStage === 'pending_admin_verification',
            'can_forward_city' => $faStage === 'pending_city_assessor',
            'can_return_assessment' => in_array($faStage, ['pending_admin_verification', 'pending_city_assessor'], true),
            'can_revise_damage' => in_array($faStage, ['pending_admin_verification', 'pending_city_assessor'], true) && $damage !== null,
        ];
    }

    private function checklist(object $row, $photos): array
    {
        $description = trim((string) ($row->report_description ?? '')) !== '';
        $signed = $this->signatures('barangay_report_id', (int) $row->id);
        $hasSignature = collect($signed)->contains(fn ($signature) => $signature->signature_role === 'barangay_signer' && $signature->mechanism === 'authenticated_e_signature');
        $openFlags = collect(ReportFlagController::forSubject('barangay_report', (int) $row->id))->where('status', 'open')->count();
        $user = auth()->user();
        $canSign = $user && $this->workflow->canSignBarangay($user, $row);
        $successor = Schema::hasColumn('barangay_reports', 'supersedes_id')
            && DB::table('barangay_reports')->where('supersedes_id', $row->id)->exists();

        return [
            ['key' => 'fields', 'label' => 'Required fields complete', 'met' => $description, 'detail' => $description ? 'The report description is saved.' : 'Record the report description before signing.'],
            ['key' => 'evidence', 'label' => 'Required evidence attached', 'met' => $photos->isNotEmpty(), 'detail' => $photos->isNotEmpty() ? $photos->count().' photograph(s) attached.' : 'No photograph is attached.'],
            ['key' => 'verification', 'label' => 'Required location and household verification complete', 'met' => $row->barangay_id && $description, 'detail' => 'The report stays on its assigned barangay.'],
            ['key' => 'predecessor', 'label' => 'Required predecessor signatures present', 'met' => true, 'detail' => $hasSignature ? 'The barangay captain has signed this version.' : 'A barangay report has no predecessor office. The captain signature is still required before forwarding.'],
            ['key' => 'authority', 'label' => 'Current user has signing authority', 'met' => $canSign, 'detail' => $canSign ? 'This account can sign as the barangay captain.' : 'This account cannot sign this barangay report.'],
            ['key' => 'flags', 'label' => 'No unresolved blocking flags', 'met' => $openFlags === 0, 'detail' => $openFlags === 0 ? 'No open flags.' : $openFlags.' open flag(s).'],
            ['key' => 'current', 'label' => 'Report version is current', 'met' => ! $successor, 'detail' => $successor ? 'A newer revision exists.' : 'This is the current version.'],
        ];
    }

    private function reportRow(object $row): array
    {
        return [
            'id' => $row->id,
            'reference' => $row->report_reference,
            'status' => $row->report_status,
            'label' => $this->workflow->label($row->report_status),
            'barangay_id' => $row->barangay_id,
            'barangay_name' => $this->rowValue($row, 'barangay_name'),
            'disaster_incident_id' => $row->disaster_incident_id,
            'incident_name' => $this->rowValue($row, 'incident_name'),
            'prepared_by' => $row->prepared_by,
            'return_reason' => $this->rowValue($row, 'return_reason'),
            'supersedes_id' => $this->rowValue($row, 'supersedes_id'),
            'description' => $this->excerpt($this->rowValue($row, 'report_description')),
            'building_reference' => $this->rowValue($row, 'building_reference'),
            'resident_reference' => $this->rowValue($row, 'resident_reference'),
            'signature_recorded' => (int) $this->rowValue($row, 'signature_recorded') === 1,
            'submitted_at' => $this->rowValue($row, 'submitted_at'),
            'returned_at' => $this->rowValue($row, 'returned_at'),
            'updated_at' => $this->rowValue($row, 'updated_at'),
        ];
    }

    private function rowValue(object $row, string $key): mixed
    {
        return property_exists($row, $key) ? $row->{$key} : null;
    }

    private function excerpt(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= 180) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 177)).'...';
    }

    private function signatures(string $column, int $id): array
    {
        if (! Schema::hasTable('report_signatures')) {
            return [];
        }

        $columns = ['signature_role', 'signed_at', 'signer_user_id', 'mechanism'];
        if (Schema::hasColumn('report_signatures', 'signature_statement')) {
            $columns[] = 'signature_statement';
        }

        return DB::table('report_signatures')->where($column, $id)->get($columns)->all();
    }

    private function timeline(string $type, int $id): array
    {
        if (! Schema::hasTable('activity_events')) {
            return [];
        }

        return DB::table('activity_events')
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderBy('created_at')
            ->get(['action_type', 'summary', 'created_at'])
            ->all();
    }

    private function assertIncidentIncludesBarangay(int $incidentId, int $barangayId): void
    {
        if (! Schema::hasTable('disaster_barangays')) {
            abort_unless(DB::table('disaster_incidents')->where('id', $incidentId)->exists(), 422, 'Choose an incident that includes this barangay.');

            return;
        }

        $linked = DB::table('disaster_barangays')
            ->where('disaster_incident_id', $incidentId)
            ->where('barangay_id', $barangayId)
            ->exists();
        abort_unless($linked, 422, 'Choose an incident that includes this barangay.');
    }

    private function assertBuilding(int $buildingId, int $barangayId): void
    {
        $building = DB::table('buildings')->where('id', $buildingId)->first();
        abort_unless($building && (int) $building->barangay_id === $barangayId, 422, 'Choose a building in the assigned barangay.');
    }

    private function applyListFilters($query, Request $request): void
    {
        $status = (string) $request->input('status', '');
        if ($status === 'handed-off') {
            $query->whereIn('barangay_reports.report_status', ['review', 'signed', 'submitted']);
        } elseif (in_array($status, ['draft', 'returned', 'review', 'signed', 'submitted', 'finalized'], true)) {
            $query->where('barangay_reports.report_status', $status);
        } elseif ($status !== '') {
            $query->whereRaw('1 = 0');
        }
        if ($request->filled('incident_id') && ctype_digit((string) $request->input('incident_id'))) {
            $query->where('barangay_reports.disaster_incident_id', $request->integer('incident_id'));
        }
        if ($request->filled('barangay_id') && ctype_digit((string) $request->input('barangay_id'))) {
            $query->where('barangay_reports.barangay_id', $request->integer('barangay_id'));
        }
        $term = trim(mb_substr((string) ($request->input('q') ?: $request->input('search', '')), 0, 80));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('barangay_reports.report_reference', 'like', $like);
                if (Schema::hasTable('disaster_incidents')) {
                    $inner->orWhere('disaster_incidents.incident_name', 'like', $like);
                }
                if (Schema::hasTable('barangays')) {
                    $inner->orWhere('barangays.barangay_name', 'like', $like);
                }
            });
        }
        foreach ([
            'updated_at' => ['updated_from', 'updated_to'],
            'returned_at' => ['returned_from', 'returned_to'],
            'submitted_at' => ['submitted_from', 'submitted_to'],
        ] as $column => [$fromKey, $toKey]) {
            if (! Schema::hasColumn('barangay_reports', $column)) {
                continue;
            }
            $from = $this->dateBound($request->input($fromKey));
            $to = $this->dateBound($request->input($toKey));
            if ($from !== null) {
                $query->whereDate('barangay_reports.'.$column, '>=', $from);
            }
            if ($to !== null) {
                $query->whereDate('barangay_reports.'.$column, '<=', $to);
            }
        }
    }

    private function applyReportSort($query, Request $request): void
    {
        $sort = (string) $request->input('sort', 'updated_at');
        $allowed = ['updated_at', 'submitted_at', 'returned_at', 'report_reference'];
        if (! in_array($sort, $allowed, true) || ! Schema::hasColumn('barangay_reports', $sort)) {
            $sort = Schema::hasColumn('barangay_reports', 'updated_at') ? 'updated_at' : 'id';
        }
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy('barangay_reports.'.$sort, $direction)->orderByDesc('barangay_reports.id');
    }

    private function dateBound(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function page($query, Request $request, callable $map): array
    {
        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }
        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())->map($map)->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }
}
