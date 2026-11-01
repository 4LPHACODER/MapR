<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Registry\Building;
use App\Models\Registry\Household;
use App\Services\AssignmentScope;
use App\Support\MapSourceLocation;
use App\Support\SourceName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HouseholdController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('search'));
        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $rows = Household::query()
            ->with(['barangay', 'building'])
            ->where('household_status', 'active')
            ->where('household_reference', 'like', '%'.$term.'%')
            ->when(is_array($request->attributes->get('scoped_barangay_ids')), function ($query) use ($request) {
                $ids = $request->attributes->get('scoped_barangay_ids');
                $query->whereIn('barangay_id', $ids === [] ? [0] : $ids);
            })
            ->orderBy('household_reference')
            ->limit(15)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Household $household) => [
                'id' => $household->id,
                'household_reference' => $household->household_reference,
                'barangay' => $household->barangay?->barangay_name,
                'building_reference' => $household->building?->building_reference,
            ])->values(),
        ]);
    }

    public function show(Request $request, Household $household): JsonResponse
    {
        app(AssignmentScope::class)->assertBarangay($request, (int) $household->barangay_id);
        $household->load(['building.barangay', 'barangay', 'residents' => fn ($q) => $q->where('residency_status', 'active')->orderBy('last_name')]);

        $building = $household->building;
        $point = ($building?->id && DB::getDriverName() === 'mysql')
            ? DB::selectOne('SELECT ST_Longitude(geographic_point) AS lng, ST_Latitude(geographic_point) AS lat FROM buildings WHERE id = ?', [$building->id])
            : null;

        $lng = $point?->lng !== null ? (float) $point->lng : null;
        $lat = $point?->lat !== null ? (float) $point->lat : null;
        $reason = MapSourceLocation::unavailableReason($building !== null, $lng, $lat);
        $mapAvailable = $reason === null;

        $linkedCount = $household->residents->count();

        return response()->json([
            'id' => $household->id,
            'household_reference' => $household->household_reference,
            'barangay' => $household->barangay?->barangay_name ?? $building?->barangay?->barangay_name,
            'building_id' => $building?->id,
            'building_reference' => $building?->building_reference,
            'record_source' => $household->record_source,
            'updated_at' => $household->updated_at?->toIso8601String(),
            'source_household_size' => $household->source_household_size ?? null,
            'source_nuclear_families' => $household->source_nuclear_families ?? null,
            'source_no_males' => $household->source_no_males ?? null,
            'source_no_females' => $household->source_no_females ?? null,
            'linked_resident_count' => $linkedCount,
            'family_count_label' => $household->source_nuclear_families !== null
                ? (string) $household->source_nuclear_families
                : 'Family count not available in source data.',
            'members' => $household->residents->map(fn ($r, $index) => [
                'id' => $r->id,
                'registry_reference' => $r->registry_reference,
                'display_name' => SourceName::display($r->first_name, $r->middle_name, $r->last_name),
                'sex' => $r->sex,
                'birth_date' => $r->birth_date?->format('Y-m-d'),
                'relationship_label' => $index === 0 ? 'CBMS listed member (person 01)' : null,
            ])->values(),
            'map_available' => $mapAvailable,
            'map_unavailable_reason' => $reason,
            'map_limitation' => MapSourceLocation::reasonMessage($reason),
            'location_note' => 'Source-recorded location. Official impact estimates still require a verified location.',
            'longitude' => $mapAvailable ? $lng : null,
            'latitude' => $mapAvailable ? $lat : null,
        ]);
    }

    public function forBuilding(Request $request, Building $building): JsonResponse
    {
        app(AssignmentScope::class)->assertBarangay($request, (int) $building->barangay_id);
        $rows = Household::query()
            ->where('building_id', $building->id)
            ->where('household_status', 'active')
            ->orderBy('household_reference')
            ->get(['id', 'household_reference']);

        return response()->json(['data' => $rows]);
    }
}
