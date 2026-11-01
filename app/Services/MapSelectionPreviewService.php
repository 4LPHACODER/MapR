<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MapSelectionPreviewService
{
    public function __construct(private PolygonSpatial $spatial) {}

    public function preview(
        array $geometry,
        string $spatialRule,
        ?int $barangayId,
        ?string $statusFilter,
        int $page,
        int $perPage,
    ): array {
        if (($geometry['type'] ?? null) !== 'Polygon') {
            throw new InvalidArgumentException('Only Polygon geometry is supported.');
        }

        $rings = $geometry['coordinates'] ?? null;

        if (! is_array($rings) || ! is_array($rings[0] ?? null)) {
            throw new InvalidArgumentException('Polygon coordinates are required.');
        }

        $wkt = $this->spatial->polygonWktFromGeoJson($rings);
        $predicate = $this->spatial->spatialPredicate($spatialRule);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $bindings = [$wkt];
        $where = "buildings.geographic_point IS NOT NULL
                  AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))";

        if ($barangayId !== null) {
            $where .= ' AND buildings.barangay_id = ?';
            $bindings[] = $barangayId;
        }

        if ($statusFilter !== null && in_array($statusFilter, ['unverified', 'verified', 'rejected'], true)) {
            $where .= ' AND buildings.location_verification_status = ?';
            $bindings[] = $statusFilter;
        }

        $totalsByStatus = [
            'unverified' => $this->countStatus($where, $bindings, 'unverified'),
            'verified' => $this->countStatus($where, $bindings, 'verified'),
            'rejected' => $this->countStatus($where, $bindings, 'rejected'),
        ];

        $total = $statusFilter !== null
            ? ($totalsByStatus[$statusFilter] ?? 0)
            : array_sum($totalsByStatus);

        $unmapped = (int) DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM buildings WHERE geographic_point IS NULL'.($barangayId ? ' AND barangay_id = ?' : ''),
            $barangayId ? [$barangayId] : [],
        )->aggregate;

        $linkedTotals = $this->linkedTotals($where, $bindings);

        $rows = DB::select(
            "SELECT buildings.id, buildings.building_reference, buildings.location_verification_status,
                    buildings.location_source, buildings.updated_at, barangays.barangay_name
             FROM buildings
             JOIN barangays ON barangays.id = buildings.barangay_id
             WHERE {$where}
             ORDER BY buildings.building_reference
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        $data = [];

        foreach ($rows as $row) {
            $householdCount = (int) DB::table('households')->where('building_id', $row->id)->count();
            $residentCount = (int) DB::table('residents')
                ->join('households', 'households.id', '=', 'residents.household_id')
                ->where('households.building_id', $row->id)
                ->count();

            $data[] = [
                'id' => (int) $row->id,
                'reference' => $row->building_reference,
                'barangay' => $row->barangay_name,
                'location_status' => $row->location_verification_status,
                'location_source' => $row->location_source,
                'household_count' => $householdCount,
                'resident_count' => $residentCount,
                'location_fingerprint' => $this->spatial->fingerprintBuilding((int) $row->id),
            ];
        }

        return [
            'selection_fingerprint' => hash('sha256', implode('|', [$wkt, $spatialRule, (string) $barangayId, (string) $statusFilter])),
            'spatial_rule' => $spatialRule,
            'wkt' => $wkt,
            'totals_by_status' => $totalsByStatus,
            'linked_totals' => $linkedTotals,
            'unmapped_buildings_in_scope' => $unmapped,
            'buildings' => [
                'data' => $data,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
                'per_page' => $perPage,
            ],
            'criteria' => [
                'barangay_id' => $barangayId,
                'status' => $statusFilter,
                'spatial_rule' => $spatialRule,
            ],
        ];
    }

    private function countStatus(string $where, array $bindings, string $status): int
    {
        return (int) DB::selectOne(
            "SELECT COUNT(*) AS aggregate FROM buildings WHERE {$where} AND buildings.location_verification_status = ?",
            [...$bindings, $status],
        )->aggregate;
    }

    private function linkedTotals(string $where, array $bindings): array
    {
        return [
            'households' => (int) DB::selectOne(
                "SELECT COUNT(DISTINCT households.id) AS aggregate FROM households
                 JOIN buildings ON buildings.id = households.building_id WHERE {$where}",
                $bindings,
            )->aggregate,
            'residents' => (int) DB::selectOne(
                "SELECT COUNT(DISTINCT residents.id) AS aggregate FROM residents
                 JOIN households ON households.id = residents.household_id
                 JOIN buildings ON buildings.id = households.building_id WHERE {$where}",
                $bindings,
            )->aggregate,
        ];
    }
}
