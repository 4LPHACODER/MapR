<?php

namespace App\Http\Controllers\Bdrrm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shared\ReportFlagController;
use App\Policies\BarangayReportPolicy;
use App\Policies\ResidentIncidentReportPolicy;
use App\Services\AssignmentScope;
use App\Services\ReportWorkflow;
use App\Services\ResidentReportWorkflow;
use App\Services\SignatureConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BarangayReportController extends Controller
{
    public function __construct(
        private readonly AssignmentScope $scope,
        private readonly ReportWorkflow $workflow,
        private readonly BarangayReportPolicy $reports,
        private readonly ResidentIncidentReportPolicy $submissions,
        private readonly ResidentReportWorkflow $residentReports,
        private readonly SignatureConfirmation $confirmation,
    ) {}

    public function submissions(Request $request): JsonResponse
    {
        $ids = $this->scope->ids($request);
        $query = DB::table('resident_incident_reports')
            ->whereIn('barangay_id', $ids === [] ? [0] : $ids)
            ->where('report_status', '!=', 'draft')
            ->orderByDesc('updated_at');
        $this->applyListFilters($query, $request, 'report_status');

        return response()->json($this->page($query, $request, fn ($row) => [
            'id' => $row->id,
            'reference' => $row->report_reference ?? ('RIR-'.$row->id),
            'status' => $row->report_status,
            'label' => $this->workflow->label($row->report_status),
            'barangay_id' => $row->barangay_id,
            'disaster_incident_id' => $row->disaster_incident_id ?? null,
            'updated_at' => $row->updated_at,
        ]));
    }

    public function showSubmission(Request $request, int $submission): JsonResponse
    {
        $row = DB::table('resident_incident_reports')->where('id', $submission)->first();
        abort_if($row === null, 404);
        abort_unless($this->submissions->view($request->user(), $row), 403);
        if ($request->user()->role?->code === 'bdrrm') {
            $this->residentReports->markUnderReview($row);
            $row = DB::table('resident_incident_reports')->where('id', $submission)->first();
        }

        return response()->json(['data' => $this->residentReports->present($row, true)]);
    }

    public function returnSubmission(Request $request, int $submission): JsonResponse
    {
        $row = $this->submission($request, $submission);
        $data = $request->validate([
            'return_reason' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        if ($request->exists('estimated_repair_cost')) {
            abort(422, 'BDRRM cannot record the estimated repair cost.');
        }
        $this->residentReports->returnSubmission($request->user(), $row, $data['return_reason']);

        return response()->json(['data' => $this->residentReports->present($this->submission($request, $submission), true)]);
    }

    public function acceptSubmission(Request $request, int $submission): JsonResponse
    {
        $row = $this->submission($request, $submission);
        if ($request->exists('estimated_repair_cost')) {
            abort(422, 'BDRRM cannot record the estimated repair cost.');
        }
        $barangayReportId = $this->residentReports->acceptSubmission($request->user(), $row, $this->workflow);

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
        $ids = $this->scope->ids($request);
        $query = DB::table('buildings')->whereIn('barangay_id', $ids === [] ? [0] : $ids)->orderBy('building_reference');
        if ($request->filled('search')) {
            $query->where('building_reference', 'like', '%'.$request->string('search').'%');
        }

        return response()->json($this->page($query, $request, fn ($row) => [
            'id' => $row->id,
            'reference' => $row->building_reference,
            'barangay_id' => $row->barangay_id,
            'location_source' => $row->location_source ?? null,
        ]));
    }

    public function index(Request $request): JsonResponse
    {
        $ids = $this->scope->ids($request);
        $query = DB::table('barangay_reports')->whereIn('barangay_id', $ids === [] ? [0] : $ids)->orderByDesc('updated_at');
        $this->applyListFilters($query, $request, 'report_status');

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
        $row = DB::table('barangay_reports')->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
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

        return [
            ...$this->reportRow($row),
            'report_description' => $row->report_description,
            'return_reason' => $row->return_reason ?? null,
            'buildings' => $buildings,
            'photos' => $photos,
            'predictions' => $predictions,
            'signatures' => $this->signatures('barangay_report_id', (int) $row->id),
            'timeline' => $this->timeline('barangay_report', (int) $row->id),
            'can_edit' => in_array($row->report_status, ['draft', 'returned'], true),
            'version' => 1,
            'incident' => Schema::hasTable('disaster_incidents') ? DB::table('disaster_incidents')->where('id', $row->disaster_incident_id)->first(['id', 'incident_reference as reference', 'incident_name as name']) : null,
            'barangay' => Schema::hasTable('barangays') ? DB::table('barangays')->where('id', $row->barangay_id)->first(['id', 'barangay_name as name']) : null,
            'flags' => ReportFlagController::forSubject('barangay_report', (int) $row->id),
            'open_flags' => collect(ReportFlagController::forSubject('barangay_report', (int) $row->id))->where('status', 'open')->count(),
            'checklist' => $this->checklist($row, $photos),
        ];
    }

    private function checklist(object $row, $photos): array
    {
        $description = trim((string) ($row->report_description ?? '')) !== '';
        $signed = $this->signatures('barangay_report_id', (int) $row->id);
        $hasSignature = collect($signed)->contains(fn ($signature) => $signature->signature_role === 'barangay_signer' && $signature->mechanism === 'authenticated_e_signature');
        $openFlags = collect(ReportFlagController::forSubject('barangay_report', (int) $row->id))->where('status', 'open')->count();
        $user = auth()->user();
        $captain = config('mapr_reference.barangay_captains.'.DB::table('barangays')->where('id', $row->barangay_id)->value('barangay_code'));
        $canSign = $user && is_string($captain) && strcasecmp(trim((string) $user->display_name), trim($captain)) === 0;
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
            'disaster_incident_id' => $row->disaster_incident_id,
            'prepared_by' => $row->prepared_by,
            'return_reason' => $row->return_reason ?? null,
            'supersedes_id' => $row->supersedes_id ?? null,
            'updated_at' => $row->updated_at,
        ];
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

    private function applyListFilters($query, Request $request, string $statusColumn): void
    {
        if ($request->filled('status')) {
            if ($request->input('status') === 'handed-off') {
                $query->whereIn($statusColumn, ['review', 'signed', 'submitted']);
            } else {
                $query->where($statusColumn, $request->string('status'));
            }
        }
        if ($request->filled('incident_id')) {
            $query->where('disaster_incident_id', $request->integer('incident_id'));
        }
        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->integer('barangay_id'));
        }
        if ($request->filled('search')) {
            $column = $statusColumn === 'report_status' && Schema::hasColumn($query->from, 'report_reference')
                ? 'report_reference'
                : null;
            if ($column) {
                $query->where($column, 'like', '%'.$request->string('search').'%');
            }
        }
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
