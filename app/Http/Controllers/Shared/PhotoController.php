<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Policies\BarangayReportPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function __construct(private readonly BarangayReportPolicy $reports) {}

    public function show(Request $request, int $report, int $photo): StreamedResponse
    {
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        abort_unless($this->reports->view($request->user(), $row), 403);
        $stored = DB::table('incident_photos')->where('id', $photo)->where('barangay_report_id', $row->id)->first();
        abort_if($stored === null, 404);

        return Storage::disk('local')->response($stored->file_path, $stored->original_filename, [
            'Content-Type' => $stored->mime_type,
        ]);
    }
}
