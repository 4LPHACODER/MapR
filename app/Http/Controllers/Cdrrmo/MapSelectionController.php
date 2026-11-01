<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\MapSelectionPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MapSelectionController extends Controller
{
    public function preview(Request $request, MapSelectionPreviewService $previewService): JsonResponse
    {
        $data = $request->validate([
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'in:Polygon'],
            'geometry.coordinates' => ['required', 'array', 'min:1'],
            'spatial_rule' => ['nullable', 'in:includes_boundary,strict_interior'],
            'barangay_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:unverified,verified,rejected'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $result = $previewService->preview(
                $data['geometry'],
                $data['spatial_rule'] ?? 'includes_boundary',
                isset($data['barangay_id']) ? (int) $data['barangay_id'] : null,
                $data['status'] ?? null,
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 50),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $selectionId = (string) Str::uuid();

        $request->session()->put('cdrrmo.map_selection', [
            'selection_id' => $selectionId,
            'selection_fingerprint' => $result['selection_fingerprint'],
            'wkt' => $result['wkt'],
            'spatial_rule' => $result['spatial_rule'],
            'stored_at' => now()->toIso8601String(),
        ]);

        unset($result['wkt']);
        $result['selection_id'] = $selectionId;

        return response()->json($result);
    }
}
