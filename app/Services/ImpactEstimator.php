<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImpactEstimator
{
    public function __construct(private PolygonSpatial $spatial) {}

    public function preview(array $coordinates, string $spatialRule = 'includes_boundary'): array
    {
        $wkt = $this->spatial->polygonWkt($coordinates);
        $predicate = $this->spatial->spatialPredicate($spatialRule);

        $officialBuildings = $this->countBuildings($wkt, $predicate, 'verified');
        $sourceBuildings = $this->countBuildings($wkt, $predicate, null);

        $officialHouseholds = $this->countHouseholds($wkt, $predicate, 'verified');
        $sourceHouseholds = $this->countHouseholds($wkt, $predicate, null);

        $officialResidents = $this->countResidents($wkt, $predicate, 'verified');
        $sourceResidents = $this->countResidents($wkt, $predicate, null);

        $missing = [
            'buildings_without_verified_point' => $sourceBuildings - $officialBuildings,
            'households_without_verified_building' => $sourceHouseholds - $officialHouseholds,
            'residents_without_verified_building_link' => $sourceResidents - $officialResidents,
            'verified_vulnerability_records' => (int) DB::table('resident_vulnerabilities')->where('verification_status', 'verified')->count(),
        ];

        return [
            'label' => 'Estimated potentially affected residents — subject to field validation.',
            'spatial_rule' => $spatialRule,
            'spatial_predicate' => $predicate === 'ST_Intersects' ? 'ST_Intersects (boundary included)' : 'ST_Within (boundary excluded)',
            'estimation_method' => 'area_population',
            'official' => [
                'buildings' => $officialBuildings,
                'households' => $officialHouseholds,
                'residents' => $officialResidents,
                'unique_vulnerable_residents' => 0,
            ],
            'source_registry_unverified' => [
                'buildings' => $sourceBuildings,
                'households' => $sourceHouseholds,
                'residents' => $sourceResidents,
            ],
            'verified_damaged_buildings' => [
                'buildings' => 0,
                'households' => 0,
                'residents' => 0,
            ],
            'missing_data' => $missing,
            'can_snapshot' => DB::table('disaster_incidents')->where('incident_status', 'active')->exists(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function countBuildings(string $wkt, string $predicate, ?string $status): int
    {
        $sql = "SELECT COUNT(DISTINCT id) AS aggregate FROM buildings
                WHERE geographic_point IS NOT NULL
                  AND {$predicate}(geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))";
        $bindings = [$wkt];

        if ($status !== null) {
            $sql .= ' AND location_verification_status = ?';
            $bindings[] = $status;
        }

        return (int) DB::selectOne($sql, $bindings)->aggregate;
    }

    private function countHouseholds(string $wkt, string $predicate, ?string $status): int
    {
        $sql = "SELECT COUNT(DISTINCT households.id) AS aggregate
                FROM households
                JOIN buildings ON buildings.id = households.building_id
                WHERE households.household_status = 'active'
                  AND buildings.geographic_point IS NOT NULL
                  AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))";
        $bindings = [$wkt];

        if ($status !== null) {
            $sql .= ' AND buildings.location_verification_status = ?';
            $bindings[] = $status;
        }

        return (int) DB::selectOne($sql, $bindings)->aggregate;
    }

    private function countResidents(string $wkt, string $predicate, ?string $status): int
    {
        $sql = "SELECT COUNT(DISTINCT residents.id) AS aggregate
                FROM residents
                JOIN households ON households.id = residents.household_id
                JOIN buildings ON buildings.id = households.building_id
                WHERE residents.residency_status = 'active'
                  AND households.household_status = 'active'
                  AND buildings.geographic_point IS NOT NULL
                  AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))";
        $bindings = [$wkt];

        if ($status !== null) {
            $sql .= ' AND buildings.location_verification_status = ?';
            $bindings[] = $status;
        }

        return (int) DB::selectOne($sql, $bindings)->aggregate;
    }

    public function polygonWkt(array $coordinates): string
    {
        return $this->spatial->polygonWkt($coordinates);
    }
}
