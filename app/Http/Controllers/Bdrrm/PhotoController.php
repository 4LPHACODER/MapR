<?php

namespace App\Http\Controllers\Bdrrm;

use App\Http\Controllers\Controller;
use App\Jobs\ClassifyBuildingPhoto;
use App\Policies\BarangayReportPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function __construct(private readonly BarangayReportPolicy $reports) {}

    public function store(Request $request, int $report): JsonResponse
    {
        $row = $this->editableReport($request, $report);
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'building_id' => ['nullable', 'integer'],
        ]);
        $file = $data['photo'];
        if ($file->getSize() < 1) {
            return response()->json(['message' => 'The photograph is empty.', 'errors' => ['photo' => ['The photograph is empty.']]], 422);
        }

        $path = $file->store('incident-photos', 'local');
        $id = DB::table('incident_photos')->insertGetId([
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_sha256' => hash_file('sha256', $file->getRealPath()),
            'image_source' => 'bdrrm_upload',
            'barangay_report_id' => $row->id,
            'building_id' => $data['building_id'] ?? null,
            'uploaded_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('incident_photos')->where('id', $id)->first(['id', 'original_filename', 'mime_type', 'file_size'])], 201);
    }

    public function destroy(Request $request, int $report, int $photo): JsonResponse
    {
        $row = $this->editableReport($request, $report);
        $stored = DB::table('incident_photos')->where('id', $photo)->where('barangay_report_id', $row->id)->first();
        abort_if($stored === null, 404);
        Storage::disk('local')->delete($stored->file_path);
        DB::table('incident_photos')->where('id', $photo)->delete();

        return response()->json(['deleted' => true]);
    }

    public function classify(Request $request, int $report): JsonResponse
    {
        $row = $this->editableReport($request, $report);
        $data = $request->validate([
            'incident_photo_id' => ['required', 'integer'],
            'building_id' => ['nullable', 'integer'],
        ]);
        $photo = DB::table('incident_photos')->where('id', $data['incident_photo_id'])->where('barangay_report_id', $row->id)->first();
        abort_if($photo === null, 404);

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

        $id = DB::table('building_damage_predictions')->insertGetId([
            'prediction_status' => 'queued',
            'incident_photo_id' => $photo->id,
            'building_id' => $data['building_id'] ?? $photo->building_id,
            'ai_model_id' => $model->id,
            'predicted_damage_class_id' => null,
            'requested_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ClassifyBuildingPhoto::dispatchSync($id);
        $prediction = DB::table('building_damage_predictions')->where('id', $id)->first();

        return response()->json([
            'classification' => $prediction->prediction_status,
            'failure_reason' => $prediction->failure_reason,
            'predicted_damage_class_id' => $prediction->predicted_damage_class_id,
        ]);
    }

    private function editableReport(Request $request, int $report): object
    {
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        abort_unless($this->reports->update($request->user(), $row), 403);

        return $row;
    }
}
