<?php

namespace App\Http\Controllers\Assessor;

use App\Http\Controllers\Controller;
use App\Services\FieldAssessmentExcelExporter;
use App\Services\FieldConsolidationService;
use App\Services\FieldReportPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldConsolidationController extends Controller
{
    public function __construct(
        private readonly FieldConsolidationService $consolidation,
        private readonly FieldAssessmentExcelExporter $exporter,
        private readonly FieldReportPackageService $packages,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $this->assertCanView($request);

        return response()->json(['data' => $this->consolidation->dashboard($request->user())]);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->assertCanView($request);
        $data = $request->validate([
            'tab' => ['nullable', 'in:ready,ready_for_submission,submitted,returned,all'],
            'barangay_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->consolidation->reportRows(
                $data['tab'] ?? 'ready',
                isset($data['barangay_id']) ? (int) $data['barangay_id'] : null,
                $data['search'] ?? null,
            ),
        ]);
    }

    public function map(Request $request): JsonResponse
    {
        $this->assertCanView($request);
        $barangayId = $request->filled('barangay_id') ? $request->integer('barangay_id') : null;

        return response()->json(['data' => $this->consolidation->mapPayload($barangayId)]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->assertCanView($request);
        $data = $request->validate([
            'barangay_id' => ['nullable', 'integer'],
            'filter' => ['nullable', 'in:all,assessment,verification,review,export,submission'],
        ]);

        return response()->json([
            'data' => $this->consolidation->history(
                isset($data['barangay_id']) ? (int) $data['barangay_id'] : null,
                $data['filter'] ?? 'all',
            ),
        ]);
    }

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        abort_unless($this->consolidation->canManage($request->user()), 403);
        $data = $request->validate([
            'scope' => ['required', 'in:barangay,consolidated'],
            'barangay_id' => ['nullable', 'integer'],
        ]);
        $export = $this->exporter->generate(
            $request->user(),
            $data['scope'],
            isset($data['barangay_id']) ? (int) $data['barangay_id'] : null,
        );
        $file = $this->exporter->downloadPath((int) $export['id']);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function downloadExport(Request $request, int $export): BinaryFileResponse
    {
        abort_unless($this->consolidation->canManage($request->user()), 403);
        $file = $this->exporter->downloadPath($export);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function previewSubmit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:barangay,consolidated'],
            'barangay_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->packages->preview(
                $request->user(),
                $data['scope'],
                isset($data['barangay_id']) ? (int) $data['barangay_id'] : null,
            ),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:barangay,consolidated'],
            'barangay_id' => ['nullable', 'integer'],
            'confirmed' => ['required', 'accepted'],
        ]);

        return response()->json([
            'data' => $this->packages->submit(
                $request->user(),
                $data['scope'],
                isset($data['barangay_id']) ? (int) $data['barangay_id'] : null,
            ),
        ], 201);
    }

    private function assertCanView(Request $request): void
    {
        $user = $request->user();
        $user->loadMissing('role');
        abort_unless(in_array($user->role?->code, ['rdana', 'rdana_admin_officer', 'rdana_city_assessor'], true), 403);
    }
}
