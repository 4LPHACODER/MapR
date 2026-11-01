<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Services\ProfilePhotoStorage;
use App\Services\ResidentReportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfilePhotoController extends Controller
{
    public function __construct(
        private readonly ProfilePhotoStorage $photos,
        private readonly ResidentReportWorkflow $reports,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $file = $request->file('photo');
        if ($file === null) {
            return response()->json([
                'message' => 'Choose a JPEG, PNG, or WEBP image.',
                'errors' => ['photo' => ['Choose a JPEG, PNG, or WEBP image.']],
            ], 422);
        }

        $this->photos->store($request->user(), $file);

        return response()->json(['data' => $this->reports->profile($request->user()->fresh())]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->photos->remove($request->user());

        return response()->json(['data' => $this->reports->profile($request->user()->fresh())]);
    }

    public function show(Request $request): StreamedResponse
    {
        $path = $request->user()->profile_photo_path;
        abort_unless(is_string($path) && str_starts_with($path, 'profile-photos/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';

        return Storage::disk('local')->response($path, 'profile-photo', [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
