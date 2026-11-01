<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;

use App\Services\AssignmentScope;
use App\Services\DocxPreviewConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportedDocumentController extends Controller
{
    public function __construct(
        private readonly AssignmentScope $scope,
        private readonly DocxPreviewConverter $previews,
    ) {}

    public function download(Request $request, string $importKey): StreamedResponse
    {
        $document = DB::table('imported_report_documents')->where('import_key', $importKey)->first();
        abort_if($document === null, 404);
        abort_unless($this->canDownload($request, $document), 403);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download($document->storage_path, $document->filename);
    }

    public function preview(Request $request, string $importKey): BinaryFileResponse|JsonResponse
    {
        $document = DB::table('imported_report_documents')->where('import_key', $importKey)->first();
        abort_if($document === null, 404);
        abort_unless($this->canDownload($request, $document), 403);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        $source = Storage::disk('local')->path($document->storage_path);
        $download = [
            'status' => 'failed',
            'message' => 'The original document could not be converted.',
            'download_path' => '/cdrrmo/api/documents/'.$document->import_key,
        ];
        if (str_ends_with(strtolower((string) $document->filename), '.pdf')) {
            return response()->file($source, $this->pdfHeaders($document->filename));
        }

        $converted = $this->previews->convert($source);
        if (! $converted['ok'] || $converted['path'] === null) {
            $download['message'] = $converted['error'] ?? $download['message'];

            return response()->json($download, 422);
        }

        $name = pathinfo((string) $document->filename, PATHINFO_FILENAME).'.pdf';

        return response()->file($converted['path'], $this->pdfHeaders($name));
    }

    private function pdfHeaders(string $filename): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function canDownload(Request $request, object $document): bool
    {
        $user = $request->user();
        $role = $user->role?->code;

        if ($role === 'cdrrmo_admin') {
            return true;
        }

        if ($role === 'resident') {
            return (int) $document->resident_user_id === (int) $user->id;
        }

        if (! in_array($role, ['bdrrm', 'rdana'], true) || $document->barangay_id === null) {
            return false;
        }

        if ($role === 'rdana' && $document->field_assessment_id) {
            $assigned = DB::table('field_assessments')
                ->where('id', $document->field_assessment_id)
                ->where('assigned_assessor_id', $user->id)
                ->exists();
            if ($assigned) {
                return true;
            }
        }

        return in_array((int) $document->barangay_id, $this->scope->ids($request), true);
    }
}
