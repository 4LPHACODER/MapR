<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\FieldAssessmentExcelExporter;
use App\Services\FieldReportPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldPackageController extends Controller
{
    public function __construct(
        private readonly FieldReportPackageService $packages,
        private readonly FieldAssessmentExcelExporter $exporter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('field_report_packages')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $rows = DB::table('field_report_packages as p')
            ->leftJoin('users as submitter', 'submitter.id', '=', 'p.submitted_by')
            ->leftJoin('barangays as b', 'b.id', '=', 'p.barangay_id')
            ->orderByDesc('p.id')
            ->get([
                'p.*',
                'b.barangay_name',
                DB::raw("trim(concat(coalesce(submitter.first_name,''), ' ', coalesce(submitter.last_name,''))) as submitted_by_name"),
            ]);

        $data = $rows->map(function ($row) {
            $itemCount = Schema::hasTable('field_report_package_items')
                ? DB::table('field_report_package_items')->where('field_report_package_id', $row->id)->count()
                : 0;

            return [
                'id' => (int) $row->id,
                'reference' => $row->package_reference,
                'scope' => $row->scope,
                'barangay_name' => $row->barangay_name,
                'status' => $row->package_status,
                'submitted_by' => trim((string) $row->submitted_by_name) ?: null,
                'submitted_at' => $row->submitted_at,
                'assessment_count' => $itemCount,
                'destination' => $row->destination,
            ];
        })->values();

        return response()->json(['data' => $data, 'meta' => ['total' => $data->count()]]);
    }

    public function show(int $package): JsonResponse
    {
        $row = DB::table('field_report_packages as p')
            ->leftJoin('users as submitter', 'submitter.id', '=', 'p.submitted_by')
            ->leftJoin('barangays as b', 'b.id', '=', 'p.barangay_id')
            ->where('p.id', $package)
            ->first([
                'p.*',
                'b.barangay_name',
                DB::raw("trim(concat(coalesce(submitter.first_name,''), ' ', coalesce(submitter.last_name,''))) as submitted_by_name"),
            ]);
        abort_if($row === null, 404);

        $items = DB::table('field_report_package_items')->where('field_report_package_id', $package)->get();
        $export = DB::table('field_report_exports')->where('field_report_package_id', $package)->orderByDesc('id')->first();

        return response()->json([
            'data' => [
                'id' => (int) $row->id,
                'reference' => $row->package_reference,
                'scope' => $row->scope,
                'barangay_name' => $row->barangay_name,
                'status' => $row->package_status,
                'submitted_by' => trim((string) $row->submitted_by_name) ?: null,
                'submitted_at' => $row->submitted_at,
                'acknowledged_at' => $row->acknowledged_at,
                'return_reason' => $row->return_reason,
                'meta' => json_decode((string) ($row->meta ?: '{}'), true) ?: [],
                'items' => $items->map(fn ($item) => [
                    'field_assessment_id' => (int) $item->field_assessment_id,
                    'assessment_reference' => $item->assessment_reference,
                ])->values(),
                'export' => $export ? [
                    'id' => (int) $export->id,
                    'filename' => $export->filename,
                ] : null,
            ],
        ]);
    }

    public function download(int $package): BinaryFileResponse
    {
        $export = DB::table('field_report_exports')->where('field_report_package_id', $package)->orderByDesc('id')->first();
        abort_if($export === null, 404, 'No Excel export is attached to this package.');
        $file = $this->exporter->downloadPath((int) $export->id);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function acknowledge(Request $request, int $package): JsonResponse
    {
        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->packages->acknowledge($request->user(), $package, $data['remarks'] ?? null);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function returnPackage(Request $request, int $package): JsonResponse
    {
        $data = $request->validate([
            'return_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $this->packages->returnPackage($request->user(), $package, $data['return_reason']);

        return response()->json(['data' => ['ok' => true]]);
    }
}
