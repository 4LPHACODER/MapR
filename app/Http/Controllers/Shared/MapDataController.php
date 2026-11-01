<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Registry\Barangay;
use App\Models\Registry\Building;
use App\Services\AssignmentScope;
use App\Support\DamageClassColor;
use App\Support\MapLocationEligibility;
use App\Support\MapSourceLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MapDataController extends Controller
{
    public function boundaries(Request $request): JsonResponse
    {
        $query = Barangay::query()->whereNotNull('boundary_polygon');
        $this->limitBarangayQuery($query, $request, 'id');

        if ($request->filled('barangay_id')) {
            $query->where('id', $request->integer('barangay_id'));
        }

        $features = $query->get()->map(function (Barangay $barangay) {
            $geo = DB::selectOne('SELECT ST_AsGeoJSON(boundary_polygon) AS geojson FROM barangays WHERE id = ?', [$barangay->id]);

            return [
                'type' => 'Feature',
                'id' => $barangay->id,
                'properties' => [
                    'id' => $barangay->id,
                    'name' => $barangay->barangay_name,
                    'code' => $barangay->barangay_code,
                ],
                'geometry' => json_decode($geo->geojson ?? 'null', true),
            ];
        });

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    /** @deprecated Use householdLocations; registry source locations */
    public function buildings(Request $request): JsonResponse
    {
        return $this->householdLocations($request);
    }

    public function meta(?Request $request = null): JsonResponse
    {
        if (! Schema::hasTable('households') || ! Schema::hasTable('buildings')) {
            return response()->json([
                'source_marker_count' => 0,
                'official_marker_count' => 0,
                'eligible_marker_count' => 0,
                'total' => 0,
                'message_if_zero' => 'No source-recorded household locations are available.',
            ]);
        }

        $request ??= request();
        $source = MapSourceLocation::buildingSqlConstraint('buildings');
        $official = MapLocationEligibility::buildingSqlConstraint('buildings');
        [$sourceSql, $bindings] = $this->householdFilterSql($request, $source);
        [$officialSql, $officialBindings] = $this->householdFilterSql($request, $official);

        $sourceCount = (int) DB::selectOne(
            "SELECT COUNT(DISTINCT households.id) AS aggregate FROM households JOIN buildings ON buildings.id = households.building_id WHERE {$sourceSql}",
            $bindings,
        )->aggregate;
        $officialCount = (int) DB::selectOne(
            "SELECT COUNT(DISTINCT households.id) AS aggregate FROM households JOIN buildings ON buildings.id = households.building_id WHERE {$officialSql}",
            $officialBindings,
        )->aggregate;

        return response()->json([
            'source_marker_count' => $sourceCount,
            'official_marker_count' => $officialCount,
            'eligible_marker_count' => $sourceCount,
            'total' => $sourceCount,
            'message_if_zero' => 'No source-recorded household locations are available for the current filters.',
            'note' => 'Teal markers are source-recorded locations for browsing. Official impact estimates still require a verified location.',
        ]);
    }

    public function householdMapIndex(Request $request): JsonResponse
    {
        return $this->householdLocations($request);
    }

    public function householdLocations(Request $request): JsonResponse
    {
        if (! Schema::hasTable('households') || ! Schema::hasTable('buildings')) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
                'meta' => [
                    'eligible_count' => 0,
                    'message_if_empty' => 'No source-recorded household locations are available.',
                ],
            ]);
        }

        $source = MapSourceLocation::buildingSqlConstraint('buildings');
        [$where, $bindings] = $this->householdFilterSql($request, $source);
        $sql = "SELECT households.id AS household_id, households.household_reference,
                       buildings.id AS building_id, buildings.building_reference, buildings.barangay_id,
                       buildings.location_verification_status,
                       ST_Longitude(buildings.geographic_point) AS lng, ST_Latitude(buildings.geographic_point) AS lat,
                       (SELECT COUNT(*) FROM households h2 WHERE h2.building_id = buildings.id AND h2.household_status = 'active') AS household_count_at_building
                FROM households
                JOIN buildings ON buildings.id = households.building_id
                WHERE {$where}";

        $rows = DB::select($sql, $bindings);
        $features = array_map(fn ($row) => [
            'type' => 'Feature',
            'id' => (int) $row->household_id,
            'properties' => [
                'household_id' => (int) $row->household_id,
                'household_reference' => $row->household_reference,
                'building_id' => (int) $row->building_id,
                'building_reference' => $row->building_reference,
                'barangay_id' => (int) $row->barangay_id,
                'household_count_at_building' => (int) $row->household_count_at_building,
                'location_context' => 'source_recorded',
                'official_estimate_eligible' => $row->location_verification_status === MapLocationEligibility::STATUS_VERIFIED,
            ],
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float) $row->lng, (float) $row->lat],
            ],
        ], $rows);

        $meta = json_decode($this->meta($request)->getContent(), true);

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'total' => $meta['total'] ?? count($features),
                'returned' => count($features),
                'source_marker_count' => $meta['source_marker_count'] ?? count($features),
                'official_marker_count' => $meta['official_marker_count'] ?? 0,
                'eligible_count' => $meta['source_marker_count'] ?? count($features),
                'message_if_empty' => $meta['message_if_zero'] ?? 'No source-recorded household locations are available.',
                'note' => $meta['note'] ?? null,
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function householdFilterSql(Request $request, string $locationSql): array
    {
        $sql = "households.household_status = 'active' AND {$locationSql}";
        $bindings = [];

        if ($request->filled('barangay_id')) {
            $sql .= ' AND buildings.barangay_id = ?';
            $bindings[] = $request->integer('barangay_id');
        }

        [$scopeSql, $scopeBindings] = $this->barangayScopeSql($request, 'buildings.barangay_id');
        $sql .= $scopeSql;
        $bindings = [...$bindings, ...$scopeBindings];

        if ($search = trim((string) $request->input('search'))) {
            $sql .= ' AND (households.household_reference LIKE ? OR buildings.building_reference LIKE ?)';
            $bindings[] = '%'.$search.'%';
            $bindings[] = '%'.$search.'%';
        }

        if ($request->boolean('incident_linked_only') && $request->filled('incident_id') && Schema::hasTable('barangay_report_buildings')) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM barangay_report_buildings brb
                JOIN barangay_reports br ON br.id = brb.barangay_report_id
                WHERE brb.building_id = buildings.id AND br.disaster_incident_id = ?
            )';
            $bindings[] = $request->integer('incident_id');
        }

        return [$sql, $bindings];
    }

    public function fieldDamage(Request $request): JsonResponse
    {
        if (! Schema::hasTable('building_verifications') || ! Schema::hasTable('damage_classes')) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $source = MapSourceLocation::buildingSqlConstraint('buildings');
        $sql = "SELECT buildings.id, buildings.building_reference, damage_classes.class_name,
                       ST_Longitude(buildings.geographic_point) AS lng, ST_Latitude(buildings.geographic_point) AS lat
                FROM building_verifications bv
                JOIN buildings ON buildings.id = bv.building_id
                JOIN damage_classes ON damage_classes.id = bv.verified_damage_class_id
                JOIN field_assessments fa ON fa.id = bv.field_assessment_id
                JOIN barangay_reports br ON br.id = fa.barangay_report_id
                WHERE {$source}";
        $bindings = [];

        if ($request->filled('incident_id')) {
            $sql .= ' AND br.disaster_incident_id = ?';
            $bindings[] = $request->integer('incident_id');
        }

        if ($request->filled('barangay_id')) {
            $sql .= ' AND buildings.barangay_id = ?';
            $bindings[] = $request->integer('barangay_id');
        }

        [$scopeSql, $scopeBindings] = $this->barangayScopeSql($request, 'buildings.barangay_id');
        $sql .= $scopeSql;
        array_push($bindings, ...$scopeBindings);

        $rows = DB::select($sql, $bindings);

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => array_map(fn ($row) => [
                'type' => 'Feature',
                'properties' => [
                    'building_reference' => $row->building_reference,
                    'damage_class' => $row->class_name,
                    'damage_color' => DamageClassColor::hex($row->class_name),
                    'layer' => 'field_verified',
                ],
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $row->lng, (float) $row->lat]],
            ], $rows),
        ]);
    }

    public function aiPredictions(Request $request): JsonResponse
    {
        if (! Schema::hasTable('building_damage_predictions') || ! Schema::hasTable('damage_classes')) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $source = MapSourceLocation::buildingSqlConstraint('buildings');
        $sql = "SELECT buildings.id, buildings.building_reference, damage_classes.class_name,
                       ST_Longitude(buildings.geographic_point) AS lng, ST_Latitude(buildings.geographic_point) AS lat
                FROM building_damage_predictions bdp
                JOIN buildings ON buildings.id = bdp.building_id
                JOIN damage_classes ON damage_classes.id = bdp.predicted_damage_class_id
                WHERE {$source}";
        $bindings = [];

        if ($request->filled('barangay_id')) {
            $sql .= ' AND buildings.barangay_id = ?';
            $bindings[] = $request->integer('barangay_id');
        }

        [$scopeSql, $scopeBindings] = $this->barangayScopeSql($request, 'buildings.barangay_id');
        $sql .= $scopeSql;
        array_push($bindings, ...$scopeBindings);

        $rows = DB::select($sql, $bindings);

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => array_map(fn ($row) => [
                'type' => 'Feature',
                'properties' => [
                    'building_reference' => $row->building_reference,
                    'damage_class' => $row->class_name,
                    'damage_color' => DamageClassColor::hex($row->class_name),
                    'layer' => 'preliminary_ai',
                ],
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $row->lng, (float) $row->lat]],
            ], $rows),
        ]);
    }

    public function flood(Request $request): JsonResponse
    {
        if (! Schema::hasTable('flood_hazard_polygons')) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $sql = 'SELECT id, classification, ST_AsGeoJSON(geometry) AS geojson FROM flood_hazard_polygons';
        $bindings = [];

        $scoped = $this->scopedBarangayIds($request);
        if ($request->filled('barangay_id')) {
            $sql .= ' WHERE ST_Intersects(geometry, (SELECT boundary_polygon FROM barangays WHERE id = ?))';
            $bindings[] = $request->integer('barangay_id');
        } elseif (is_array($scoped)) {
            $ids = $scoped === [] ? [0] : $scoped;
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " WHERE EXISTS (SELECT 1 FROM barangays WHERE barangays.id IN ({$placeholders}) AND ST_Intersects(flood_hazard_polygons.geometry, barangays.boundary_polygon))";
            array_push($bindings, ...$ids);
        }

        $rows = DB::select($sql, $bindings);
        $features = array_map(fn ($row) => [
            'type' => 'Feature',
            'id' => (int) $row->id,
            'properties' => [
                'classification' => $row->classification,
            ],
            'geometry' => json_decode($row->geojson, true),
        ], $rows);

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    public function building(Request $request, Building $building): JsonResponse
    {
        app(AssignmentScope::class)->assertBarangay($request, (int) $building->barangay_id);
        $building->load('barangay');
        $householdCount = $building->households()->where('household_status', 'active')->count();
        $residentCount = (int) DB::table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->where('households.building_id', $building->id)
            ->where('residents.residency_status', 'active')
            ->count();
        $point = DB::getDriverName() === 'mysql'
            ? DB::selectOne('SELECT ST_Longitude(geographic_point) AS lng, ST_Latitude(geographic_point) AS lat FROM buildings WHERE id = ?', [$building->id])
            : null;
        $lng = $point?->lng !== null ? (float) $point->lng : null;
        $lat = $point?->lat !== null ? (float) $point->lat : null;
        $reason = MapSourceLocation::unavailableReason(true, $lng, $lat);
        $mapAvailable = $reason === null;

        return response()->json([
            'id' => $building->id,
            'reference' => $building->building_reference,
            'barangay' => $building->barangay?->barangay_name,
            'location_source' => $building->location_source,
            'household_count' => $householdCount,
            'resident_count' => $residentCount,
            'map_available' => $mapAvailable,
            'map_unavailable_reason' => $reason,
            'longitude' => $mapAvailable ? $lng : null,
            'latitude' => $mapAvailable ? $lat : null,
            'residents_url' => $this->workspacePrefix($request).'/residents?building_id='.$building->id,
        ]);
    }

    private function workspacePrefix(Request $request): string
    {
        return match ($request->attributes->get('field_role')) {
            'rdana' => '/rdana',
            'bdrrm' => '/bdrrm',
            default => '/cdrrmo',
        };
    }

    /**
     * @return list<int>|null
     */
    private function scopedBarangayIds(Request $request): ?array
    {
        $ids = $request->attributes->get('scoped_barangay_ids');

        return is_array($ids) ? array_map('intval', $ids) : null;
    }

    private function limitBarangayQuery($query, Request $request, string $column): void
    {
        $ids = $this->scopedBarangayIds($request);
        if ($ids === null) {
            return;
        }

        $query->whereIn($column, $ids === [] ? [0] : $ids);
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function barangayScopeSql(Request $request, string $column): array
    {
        $ids = $this->scopedBarangayIds($request);
        if ($ids === null) {
            return ['', []];
        }

        $ids = $ids === [] ? [0] : $ids;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return [" AND {$column} IN ({$placeholders})", $ids];
    }
}
