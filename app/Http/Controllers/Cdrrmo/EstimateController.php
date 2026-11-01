<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\AreaSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EstimateController extends Controller
{
    public function preview(Request $request, AreaSummaryService $summarizer): JsonResponse
    {
        $data = $request->validate([
            'coordinates' => ['required', 'array', 'min:4'],
            'coordinates.*' => ['array'],
            'spatial_rule' => ['nullable', 'in:includes_boundary,strict_interior'],
        ]);

        try {
            $result = $summarizer->summarize(
                $data['coordinates'],
                $data['spatial_rule'] ?? 'includes_boundary',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $result['snapshot_note'] = $result['can_snapshot']
            ? 'An active disaster incident exists. Snapshot persistence is available in a later save step.'
            : 'No active disaster incident exists. Live counts are shown and no estimate snapshot was saved.';

        return response()->json($result);
    }
}
