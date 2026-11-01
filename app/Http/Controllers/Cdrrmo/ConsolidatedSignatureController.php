<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\ReportWorkflow;
use App\Services\SignatureConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsolidatedSignatureController extends Controller
{
    public function __construct(
        private readonly ReportWorkflow $workflow,
        private readonly SignatureConfirmation $confirmation,
    ) {}

    public function note(Request $request, int $report): JsonResponse
    {
        $row = $this->report($report);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $this->workflow->noteConsolidated($request->user(), $row);

        return response()->json(['data' => ['id' => $row->id, 'reference' => $row->report_reference, 'capacity' => 'noted_by']]);
    }

    public function approve(Request $request, int $report): JsonResponse
    {
        $row = $this->report($report);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $this->workflow->approveConsolidated($request->user(), $row);

        return response()->json(['data' => ['id' => $row->id, 'reference' => $row->report_reference, 'capacity' => 'approved_by']]);
    }

    private function report(int $id): object
    {
        $row = DB::table('consolidated_reports')->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }
}
