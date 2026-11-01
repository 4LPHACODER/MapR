<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Models\Registry\Barangay;
use App\Services\AreaSummaryService;
use App\Services\PolygonSpatial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AreaContextController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $context = $request->session()->get('cdrrmo.report_context');

        if (! is_array($context) || empty($context['coordinates'])) {
            return response()->json(['active' => false]);
        }

        $barangayNames = $this->barangaysInPolygon($context['coordinates'], $context['spatial_rule'] ?? 'includes_boundary');

        return response()->json([
            'active' => true,
            'label' => 'Current selected area',
            'stored_at' => $context['stored_at'] ?? null,
            'spatial_rule' => $context['spatial_rule'] ?? 'includes_boundary',
            'barangays' => $barangayNames,
            'summary' => $context['summary'] ?? $context['estimate'] ?? null,
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->session()->forget('cdrrmo.report_context');

        return response()->json(['ok' => true]);
    }

    private function barangaysInPolygon(array $coordinates, string $spatialRule): array
    {
        try {
            $spatial = app(PolygonSpatial::class);
            $wkt = $spatial->polygonWkt($coordinates);
            $predicate = $spatial->spatialPredicate($spatialRule);
        } catch (InvalidArgumentException) {
            return [];
        }

        return Barangay::query()
            ->whereNotNull('boundary_polygon')
            ->whereRaw("{$predicate}(boundary_polygon, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))", [$wkt])
            ->orderBy('barangay_name')
            ->pluck('barangay_name')
            ->all();
    }
}
