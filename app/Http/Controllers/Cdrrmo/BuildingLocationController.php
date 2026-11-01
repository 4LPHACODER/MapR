<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\BuildingLocationReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BuildingLocationController extends Controller
{
    public function verifyBulk(Request $request, BuildingLocationReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'selection_id' => ['required', 'string'],
            'building_ids' => ['required', 'array', 'min:1', 'max:100'],
            'building_ids.*' => ['integer'],
            'action' => ['required', 'in:verify,reject'],
            'basis' => ['required', 'string', 'min:10', 'max:2000'],
            'expected_fingerprints' => ['nullable', 'array'],
        ]);

        $sessionSelection = $request->session()->get('cdrrmo.map_selection');

        if (! is_array($sessionSelection) || ($sessionSelection['selection_id'] ?? null) !== $data['selection_id']) {
            return response()->json(['message' => 'The map selection expired or does not match. Redraw the area and preview again.'], 422);
        }

        $wkt = $sessionSelection['wkt'] ?? null;
        $spatialRule = $sessionSelection['spatial_rule'] ?? 'includes_boundary';

        if (! is_string($wkt) || $wkt === '') {
            return response()->json(['message' => 'The map selection is incomplete. Preview the area again.'], 422);
        }

        try {
            $result = $reviews->applyBulk(
                $request->user(),
                $data['selection_id'],
                $data['building_ids'],
                $data['action'],
                $data['basis'],
                $data['expected_fingerprints'] ?? [],
                $wkt,
                $spatialRule,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }
}
