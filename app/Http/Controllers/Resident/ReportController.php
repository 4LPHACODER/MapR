<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Policies\ResidentIncidentReportPolicy;
use App\Services\ResidentReportDocument;
use App\Services\ResidentReportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ResidentReportWorkflow $reports,
        private readonly ResidentIncidentReportPolicy $policy,
        private readonly ResidentReportDocument $documents,
    ) {}

    public function incidents(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->incidentsFor($request->user())]);
    }

    public function incidentUpdates(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->incidentUpdates($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('resident_incident_reports as reports')
            ->where('reports.resident_user_id', $request->user()->id)
            ->orderByDesc('reports.updated_at');
        if (DB::getSchemaBuilder()->hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents as incidents', 'incidents.id', '=', 'reports.disaster_incident_id')
                ->select('reports.*', 'incidents.incident_name');
        } else {
            $query->select('reports.*');
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('reports.report_reference', 'like', '%'.$search.'%');
                if (DB::getSchemaBuilder()->hasTable('disaster_incidents')) {
                    $inner->orWhere('incidents.incident_name', 'like', '%'.$search.'%');
                }
            });
        }
        if ($request->filled('status')) {
            $query->where('reports.report_status', $request->string('status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('reports.updated_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('reports.updated_at', '<=', $request->date('to'));
        }

        return response()->json([
            'data' => $query->get()->map(fn ($row) => $this->reports->present($row))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->reports->rejectForbiddenFields($request);
        $data = $request->validate([
            'disaster_incident_id' => ['required', 'integer'],
            'incident_description' => ['nullable', 'string', 'max:5000'],
            'reported_location' => ['nullable', 'string', 'max:2000'],
            'observed_at' => ['nullable', 'date'],
            'observed_at_approximate' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:4,15'],
            'longitude' => ['nullable', 'numeric', 'between:115,130'],
        ]);
        $id = $this->reports->create($request->user(), $data);

        return response()->json(['data' => $this->reports->present($this->find($id), true)], 201);
    }

    public function show(Request $request, int $report): JsonResponse
    {
        $row = $this->find($report);
        abort_unless($this->policy->view($request->user(), $row), 403);

        return response()->json(['data' => $this->reports->present($row, true)]);
    }

    public function update(Request $request, int $report): JsonResponse
    {
        $this->reports->rejectForbiddenFields($request);
        $row = $this->find($report);
        abort_unless($this->policy->update($request->user(), $row), 403);
        $data = $request->validate([
            'disaster_incident_id' => ['sometimes', 'integer'],
            'incident_description' => ['nullable', 'string', 'max:5000'],
            'reported_location' => ['nullable', 'string', 'max:2000'],
            'observed_at' => ['nullable', 'date'],
            'observed_at_approximate' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:4,15'],
            'longitude' => ['nullable', 'numeric', 'between:115,130'],
        ]);
        $this->reports->update($request->user(), $row, $data);

        return response()->json(['data' => $this->reports->present($this->find($report), true)]);
    }

    public function submit(Request $request, int $report): JsonResponse
    {
        $this->reports->rejectForbiddenFields($request);
        $request->validate(['confirmed' => ['accepted']]);
        $row = $this->find($report);
        abort_unless($this->policy->view($request->user(), $row), 403);
        if (! in_array($row->report_status, ['draft', 'returned'], true)) {
            return response()->json([
                'message' => 'This report was already submitted.',
                'reference' => $row->report_reference,
            ], 409);
        }
        $this->reports->submit($request->user(), $row);

        return response()->json(['data' => $this->reports->present($this->find($report), true)]);
    }

    public function document(Request $request, int $report): Response
    {
        $row = $this->find($report);
        abort_unless($this->policy->view($request->user(), $row), 403);
        $pdf = $this->documents->render($row);
        $name = 'MapR_Resident_Report_'.$row->report_reference.'.pdf';
        if ($request->boolean('download')) {
            return $pdf->download($name);
        }

        return $pdf->stream($name);
    }

    public function signature(Request $request, int $report): StreamedResponse
    {
        $row = $this->find($report);
        abort_unless($this->policy->view($request->user(), $row), 403);
        abort_unless(Schema::hasTable('report_signatures') && Schema::hasColumn('report_signatures', 'resident_incident_report_id'), 404);
        $signature = DB::table('report_signatures')
            ->where('resident_incident_report_id', $row->id)
            ->where('signature_role', 'resident_signer')
            ->first();
        abort_if($signature === null || empty($signature->user_signature_asset_id), 404);
        $asset = DB::table('user_signature_assets')->where('id', $signature->user_signature_asset_id)->first();
        abort_if($asset === null || (int) $asset->user_id !== (int) $row->resident_user_id, 404);
        abort_unless(Storage::disk('local')->exists($asset->storage_path), 404);
        $extension = $asset->mime_type === 'image/png' ? 'png' : 'jpg';

        return Storage::disk('local')->response($asset->storage_path, 'resident-signature.'.$extension, [
            'Content-Type' => $asset->mime_type,
        ]);
    }

    public function destroy(Request $request, int $report): JsonResponse
    {
        $row = $this->find($report);
        abort_unless($this->policy->view($request->user(), $row), 403);
        if ($row->report_status !== 'draft') {
            abort(422, 'Only a draft can be deleted.');
        }
        abort_unless($this->policy->delete($request->user(), $row), 403);
        $this->reports->deleteDraft($request->user(), $row);

        return response()->json(['deleted' => true]);
    }

    private function find(int $id): object
    {
        $row = DB::table('resident_incident_reports')->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }
}
