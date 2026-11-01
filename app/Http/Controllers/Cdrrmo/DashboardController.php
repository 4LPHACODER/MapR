<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Models\Registry\Barangay;
use App\Models\Registry\Building;
use App\Models\Registry\FloodHazardPolygon;
use App\Models\Registry\Household;
use App\Models\Registry\Resident;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $barangays = Barangay::query()->orderBy('barangay_name')->get(['id', 'barangay_code', 'barangay_name']);

        $byBarangay = $barangays->map(function (Barangay $barangay) {
            return [
                'id' => $barangay->id,
                'code' => $barangay->barangay_code,
                'name' => $barangay->barangay_name,
                'buildings' => Building::query()->where('barangay_id', $barangay->id)->count(),
                'households' => Household::query()->where('barangay_id', $barangay->id)->count(),
                'residents' => Resident::query()->whereHas('household', fn ($query) => $query->where('barangay_id', $barangay->id))->count(),
                'verified_buildings' => Building::query()->where('barangay_id', $barangay->id)->where('location_verification_status', 'verified')->count(),
            ];
        });

        return response()->json([
            'barangays' => $byBarangay,
            'totals' => [
                'buildings' => Building::query()->count(),
                'households' => Household::query()->count(),
                'residents' => Resident::query()->count(),
                'verified_buildings' => Building::query()->where('location_verification_status', 'verified')->count(),
                'flood_polygons' => FloodHazardPolygon::query()->count(),
                'active_incidents' => DB::table('disaster_incidents')->where('incident_status', 'active')->count(),
            ],
            'notes' => [
                'CBMS geotagged locations are imported as unverified. Official polygon estimates use field-verified building points only.',
                'No disaster incident is required to review the map. Saving an estimate snapshot requires an active incident.',
                'Vulnerability counts stay empty until verified resident_vulnerabilities rows exist.',
            ],
        ]);
    }
}
