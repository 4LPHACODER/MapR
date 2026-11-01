<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\AreaSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReportContextController extends Controller
{
    public function store(Request $request, AreaSummaryService $summarizer): JsonResponse
    {
        $data = $request->validate([
            'coordinates' => ['required', 'array', 'min:4'],
            'coordinates.*' => ['array'],
            'spatial_rule' => ['nullable', 'in:includes_boundary,strict_interior'],
        ]);

        try {
            $summary = $summarizer->summarize(
                $data['coordinates'],
                $data['spatial_rule'] ?? 'includes_boundary',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $request->session()->put('cdrrmo.report_context', [
            'coordinates' => $data['coordinates'],
            'spatial_rule' => $data['spatial_rule'] ?? 'includes_boundary',
            'summary' => $summary,
            'stored_at' => now()->toIso8601String(),
        ]);

        return response()->json(['ok' => true, 'summary' => $summary]);
    }
}
