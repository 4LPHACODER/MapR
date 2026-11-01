<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Policies\ResidentIncidentReportPolicy;
use App\Services\ResidentReportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function __construct(
        private readonly ResidentIncidentReportPolicy $policy,
        private readonly ResidentReportWorkflow $reports,
    ) {}

    public function store(Request $request, int $report): JsonResponse
    {
        $row = $this->editable($request, $report);
        if ($this->reports->photoCount((int) $row->id) >= ResidentReportWorkflow::MAX_PHOTOS) {
            abort(422, 'A report can include up to 8 photographs.');
        }
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'source' => ['nullable', 'in:capture,upload'],
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
            'image_source' => ($data['source'] ?? 'upload') === 'capture' ? 'resident_capture' : 'resident_upload',
            'incident_report_id' => $row->id,
            'uploaded_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('incident_photos')->where('id', $id)->first(['id', 'original_filename', 'mime_type', 'file_size'])], 201);
    }

    public function destroy(Request $request, int $report, int $photo): JsonResponse
    {
        $row = $this->editable($request, $report);
        $stored = DB::table('incident_photos')->where('id', $photo)->where('incident_report_id', $row->id)->first();
        abort_if($stored === null, 404);
        Storage::disk('local')->delete($stored->file_path);
        DB::table('incident_photos')->where('id', $photo)->delete();

        return response()->json(['deleted' => true]);
    }

    public function show(Request $request, int $report, int $photo): StreamedResponse
    {
        $row = DB::table('resident_incident_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        abort_unless($this->policy->view($request->user(), $row), 403);
        $stored = DB::table('incident_photos')->where('id', $photo)->where('incident_report_id', $row->id)->first();
        abort_if($stored === null, 404);

        return Storage::disk('local')->response($stored->file_path, $stored->original_filename, [
            'Content-Type' => $stored->mime_type,
        ]);
    }

    private function editable(Request $request, int $report): object
    {
        $row = DB::table('resident_incident_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        abort_unless($this->policy->update($request->user(), $row), 403);

        return $row;
    }
}
