<?php

namespace App\Http\Controllers\Bdrrm;

use App\Http\Controllers\Controller;
use App\Models\Registry\Building;
use App\Services\AssignmentScope;
use App\Services\BuildingRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuildingPhotoController extends Controller
{
    public function __construct(
        private readonly AssignmentScope $scope,
        private readonly BuildingRegistry $registry,
    ) {}

    public function index(Request $request, Building $building): JsonResponse
    {
        $this->registry->denyPersonnel($request->user());
        $this->scope->assertBarangay($request, (int) $building->barangay_id);

        return response()->json([
            'data' => $this->registry->buildingPhotos((int) $building->id, $this->scope->ids($request)),
        ]);
    }

    public function show(Request $request, Building $building, int $photo): StreamedResponse
    {
        $this->registry->denyPersonnel($request->user());
        $this->scope->assertBarangay($request, (int) $building->barangay_id);
        $stored = $this->registry->authorizedPhoto((int) $building->id, $photo, $this->scope->ids($request));
        abort_if($stored === null, 404);
        abort_unless(Storage::disk('local')->exists($stored->file_path), 404);

        return Storage::disk('local')->response($stored->file_path, $stored->original_filename, [
            'Content-Type' => $stored->mime_type,
        ]);
    }
}
