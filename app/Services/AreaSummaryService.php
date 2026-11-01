<?php

namespace App\Services;

use App\Support\MapLocationEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AreaSummaryService
{
    public function __construct(private PolygonSpatial $spatial) {}

    public function summarize(array $coordinates, string $spatialRule = 'includes_boundary'): array
    {
        $wkt = $this->spatial->polygonWkt($coordinates);
        $predicate = $this->spatial->spatialPredicate($spatialRule);

        $officialBuildings = $this->countBuildings($wkt, $predicate, MapLocationEligibility::STATUS_VERIFIED);
        $officialHouseholds = $this->countHouseholds($wkt, $predicate, MapLocationEligibility::STATUS_VERIFIED);
        $officialResidents = $this->countResidents($wkt, $predicate, MapLocationEligibility::STATUS_VERIFIED);

        $sourceBuildings = $this->countBuildings($wkt, $predicate, null);
        $sourceHouseholds = $this->countHouseholds($wkt, $predicate, null);
        $sourceResidents = $this->countResidents($wkt, $predicate, null);

        $damaged = $this->countDamagedInPolygon($wkt, $predicate);
        $vulnerabilities = $this->vulnerabilityBreakdown($wkt, $predicate);
        $completeness = $this->completeness($wkt, $predicate);

        return [
            'label' => 'Current selected area',
            'barangay_coverage' => $this->barangayCoverage($coordinates, $wkt, $predicate),
            'spatial_rule' => $spatialRule,
            'spatial_predicate' => $predicate === 'ST_Intersects' ? 'ST_Intersects (boundary included)' : 'ST_Within (boundary excluded)',
            'estimation_method' => 'area_population',
            'within_selected_area' => [
                'buildings' => $officialBuildings,
                'households' => $officialHouseholds,
                'residents' => $officialResidents,
            ],
            'official' => [
                'buildings' => $officialBuildings,
                'households' => $officialHouseholds,
                'residents' => $officialResidents,
                'unique_vulnerable_residents' => $vulnerabilities['unique_residents'],
            ],
            'source_registry_unverified' => [
                'buildings' => $sourceBuildings,
                'households' => $sourceHouseholds,
                'residents' => $sourceResidents,
                'reported_household_size_total' => $this->sumSourceHouseholdSize($wkt, $predicate),
            ],
            'field_verified_damaged' => $damaged,
            'verified_damaged_buildings' => $damaged,
            'vulnerable_groups' => $vulnerabilities,
            'data_completeness' => $completeness,
            'missing_data' => $completeness,
            'can_snapshot' => Schema::hasTable('disaster_incidents')
                && DB::table('disaster_incidents')->where('incident_status', 'active')->exists(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function countBuildings(string $wkt, string $predicate, ?string $status): int
    {
        if (! Schema::hasTable('buildings')) {
            return 0;
        }

        $sql = "SELECT COUNT(DISTINCT id) AS aggregate FROM buildings
                WHERE geographic_point IS NOT NULL
                  AND {$predicate}(geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))";
        $bindings = [$wkt];

        if ($status !== null) {
            $sql .= ' AND location_verification_status = ?';
            $bindings[] = $status;
        }

        return (int) (DB::selectOne($sql, $bindings)->aggregate ?? 0);
    }

    private function countHouseholds(string $wkt, string $predicate, ?string $status): int
    {
        if (! Schema::hasTable('households')) {
            return 0;
        }

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

        return (int) (DB::selectOne($sql, $bindings)->aggregate ?? 0);
    }

    private function countResidents(string $wkt, string $predicate, ?string $status): int
    {
        if (! Schema::hasTable('residents')) {
            return 0;
        }

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

        return (int) (DB::selectOne($sql, $bindings)->aggregate ?? 0);
    }

    private function sumSourceHouseholdSize(string $wkt, string $predicate): ?int
    {
        if (! Schema::hasTable('households') || ! Schema::hasColumn('households', 'source_household_size')) {
            return null;
        }

        $source = \App\Support\MapSourceLocation::buildingSqlConstraint('buildings');

        return (int) (DB::selectOne(
            "SELECT COALESCE(SUM(households.source_household_size), 0) AS aggregate
             FROM households
             JOIN buildings ON buildings.id = households.building_id
             WHERE households.household_status = 'active'
               AND {$source}
               AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
            [$wkt],
        )->aggregate ?? 0);
    }

    private function countDamagedInPolygon(string $wkt, string $predicate): array
    {
        if (! Schema::hasTable('building_verifications')) {
            return ['buildings' => 0, 'households' => 0, 'residents' => 0, 'available' => false];
        }

        $eligible = MapLocationEligibility::buildingSqlConstraint('buildings');
        $buildings = (int) (DB::selectOne(
            "SELECT COUNT(DISTINCT bv.building_id) AS aggregate
             FROM building_verifications bv
             JOIN buildings ON buildings.id = bv.building_id
             WHERE {$eligible}
               AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
            [$wkt],
        )->aggregate ?? 0);

        $households = (int) (DB::selectOne(
            "SELECT COUNT(DISTINCT households.id) AS aggregate
             FROM building_verifications bv
             JOIN buildings ON buildings.id = bv.building_id
             JOIN households ON households.building_id = buildings.id AND households.household_status = 'active'
             WHERE {$eligible}
               AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
            [$wkt],
        )->aggregate ?? 0);

        $residents = (int) (DB::selectOne(
            "SELECT COUNT(DISTINCT residents.id) AS aggregate
             FROM building_verifications bv
             JOIN buildings ON buildings.id = bv.building_id
             JOIN households ON households.building_id = buildings.id AND households.household_status = 'active'
             JOIN residents ON residents.household_id = households.id AND residents.residency_status = 'active'
             WHERE {$eligible}
               AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
            [$wkt],
        )->aggregate ?? 0);

        return [
            'buildings' => $buildings,
            'households' => $households,
            'residents' => $residents,
            'available' => $buildings > 0,
        ];
    }

    private function vulnerabilityBreakdown(string $wkt, string $predicate): array
    {
        if (! Schema::hasTable('resident_vulnerabilities') || ! Schema::hasTable('vulnerability_categories')) {
            return ['categories' => [], 'unique_residents' => 0, 'overlap_note' => 'Categories may overlap; totals are not additive.'];
        }

        $eligible = MapLocationEligibility::buildingSqlConstraint('buildings');
        $rows = DB::select(
            "SELECT vc.category_name, COUNT(DISTINCT rv.resident_id) AS cnt
             FROM resident_vulnerabilities rv
             JOIN vulnerability_categories vc ON vc.id = rv.vulnerability_category_id
             JOIN residents ON residents.id = rv.resident_id AND residents.residency_status = 'active'
             JOIN households ON households.id = residents.household_id AND households.household_status = 'active'
             JOIN buildings ON buildings.id = households.building_id
             WHERE {$eligible}
               AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))
             GROUP BY vc.category_name",
            [$wkt],
        );

        $unique = (int) (DB::selectOne(
            "SELECT COUNT(DISTINCT rv.resident_id) AS aggregate
             FROM resident_vulnerabilities rv
             JOIN residents ON residents.id = rv.resident_id AND residents.residency_status = 'active'
             JOIN households ON households.id = residents.household_id
             JOIN buildings ON buildings.id = households.building_id
             WHERE {$eligible}
               AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
            [$wkt],
        )->aggregate ?? 0);

        return [
            'categories' => array_map(fn ($r) => ['name' => $r->category_name, 'count' => (int) $r->cnt], $rows),
            'unique_residents' => $unique,
            'overlap_note' => 'Categories may overlap; unique total counts distinct residents with any listed category.',
        ];
    }

    private function barangayCoverage(array $coordinates, string $wkt, string $predicate): array
    {
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'boundary_polygon')) {
            return [];
        }

        try {
            $samples = $this->interiorSamples($coordinates);
            if ($samples !== []) {
                $clauses = [];
                $bindings = [];
                foreach ($samples as [$lng, $lat]) {
                    $clauses[] = "ST_Contains(boundary_polygon, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))";
                    $bindings[] = sprintf('POINT(%F %F)', $lng, $lat);
                }
                $rows = DB::select(
                    'SELECT DISTINCT barangay_name FROM barangays
                     WHERE boundary_polygon IS NOT NULL AND ('.implode(' OR ', $clauses).')
                     ORDER BY barangay_name',
                    $bindings,
                );
                $names = array_values(array_filter(array_map(fn ($row) => $row->barangay_name, $rows)));
                if ($names !== []) {
                    return $names;
                }
            }

            $rows = DB::select(
                "SELECT barangay_name FROM barangays
                 WHERE boundary_polygon IS NOT NULL
                   AND {$predicate}(boundary_polygon, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))
                 ORDER BY barangay_name",
                [$wkt],
            );

            return array_values(array_filter(array_map(fn ($row) => $row->barangay_name, $rows)));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array{0: float|int, 1: float|int}>  $ring
     * @return list<array{0: float, 1: float}>
     */
    private function interiorSamples(array $ring): array
    {
        $points = array_values(array_filter($ring, fn ($pair) => is_array($pair) && count($pair) >= 2));
        if (count($points) < 4) {
            return [];
        }

        $lngs = array_map(fn ($pair) => (float) $pair[0], $points);
        $lats = array_map(fn ($pair) => (float) $pair[1], $points);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        $minLat = min($lats);
        $maxLat = max($lats);
        $samples = [];
        foreach ([0.35, 0.5, 0.65] as $x) {
            foreach ([0.35, 0.5, 0.65] as $y) {
                $lng = $minLng + (($maxLng - $minLng) * $x);
                $lat = $minLat + (($maxLat - $minLat) * $y);
                if ($this->pointInsideRing($lng, $lat, $points)) {
                    $samples[] = [$lng, $lat];
                }
            }
        }

        return $samples;
    }

    private function pointInsideRing(float $lng, float $lat, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];
            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < (($xj - $xi) * ($lat - $yi)) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function completeness(string $wkt, string $predicate): array
    {
        if (! Schema::hasTable('buildings')) {
            return [];
        }

        $eligible = MapLocationEligibility::buildingSqlConstraint('buildings');
        $inPoly = "{$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))";

        $buildingsWithoutHousehold = Schema::hasTable('households')
            ? (int) (DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM buildings
                 WHERE {$eligible} AND {$inPoly}
                   AND NOT EXISTS (SELECT 1 FROM households h WHERE h.building_id = buildings.id AND h.household_status = 'active')",
                [$wkt],
            )->aggregate ?? 0)
            : 0;

        $householdsWithoutBuilding = Schema::hasTable('households')
            ? (int) (DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM households
                 WHERE households.household_status = 'active' AND households.building_id IS NULL",
            )->aggregate ?? 0)
            : 0;

        $residentsInPolyWithoutEligibleMap = Schema::hasTable('residents')
            ? (int) (DB::selectOne(
                "SELECT COUNT(DISTINCT residents.id) AS aggregate
                 FROM residents
                 JOIN households ON households.id = residents.household_id
                 LEFT JOIN buildings ON buildings.id = households.building_id
                 WHERE residents.residency_status = 'active'
                   AND households.household_status = 'active'
                   AND (buildings.id IS NULL OR NOT ({$eligible}) OR NOT {$inPoly})",
                [$wkt],
            )->aggregate ?? 0)
            : 0;

        return [
            'buildings_without_household_links' => $buildingsWithoutHousehold,
            'households_without_building_links' => $householdsWithoutBuilding,
            'residents_not_in_eligible_map_area' => $residentsInPolyWithoutEligibleMap,
        ];
    }
}
