<?php

namespace App\Services;

use App\Support\MapSourceLocation;
use App\Support\SourceName;
use App\Support\SpatialSql;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResidentMapRegistry
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function markers(Request $request): array
    {
        $ids = $this->scope->ids($request);
        $filters = $this->filters($request);
        $authorized = $this->countResidents($this->population($ids));
        $filtered = $this->applySearch($this->population($ids), $filters['q']);
        if ($filters['barangay_id']) {
            $filtered->where('households.barangay_id', $filters['barangay_id']);
        }
        [$exists, $bindings] = $this->reportExistsSql($ids, $filters['incident_id']);
        $withReports = $this->countResidents((clone $filtered)->whereRaw($exists, $bindings));
        $withoutReports = $this->countResidents((clone $filtered)->whereRaw('NOT ('.$exists.')', $bindings));
        $scoped = clone $filtered;
        if ($filters['reports'] === 'with') {
            $scoped->whereRaw($exists, $bindings);
            $withReports = $this->countResidents(clone $scoped);
            $withoutReports = 0;
        } elseif ($filters['reports'] === 'without') {
            $scoped->whereRaw('NOT ('.$exists.')', $bindings);
            $withoutReports = $this->countResidents(clone $scoped);
            $withReports = 0;
        }
        $total = $this->countResidents(clone $scoped);
        $withoutLocation = $this->unmappedCount(clone $scoped);
        $features = $this->features(clone $scoped, $exists, $bindings);
        $matches = $filters['q'] === '' ? [] : $this->matches(clone $scoped, $exists, $bindings);

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'summary' => [
                'total' => $total,
                'with_reports' => $withReports,
                'without_reports' => $withoutReports,
                'without_location' => $withoutLocation,
                'authorized_total' => $authorized,
                'mapped_locations' => count($features),
                'mapped_residents' => $total - $withoutLocation,
            ],
            'matches' => $matches,
            'meta' => [
                'returned' => count($features),
                'total' => $total,
                'authorized_total' => $authorized,
            ],
        ];
    }

    public function group(Request $request, string $group): array
    {
        $ids = $this->scope->ids($request);
        $filters = $this->filters($request);
        $buildingIds = $this->buildingsForGroup($ids, $group);
        if ($buildingIds === []) {
            abort(404);
        }
        foreach ($buildingIds as $buildingId) {
            $barangayId = DB::table('buildings')->where('id', $buildingId)->value('barangay_id');
            $this->scope->assertBarangay($request, $barangayId === null ? null : (int) $barangayId);
        }
        $query = $this->applySearch($this->population($ids), $filters['q'])
            ->whereIn('households.building_id', $buildingIds);
        [$exists, $bindings] = $this->reportExistsSql($ids, $filters['incident_id']);
        if ($filters['reports'] === 'with') {
            $query->whereRaw($exists, $bindings);
        } elseif ($filters['reports'] === 'without') {
            $query->whereRaw('NOT ('.$exists.')', $bindings);
        }

        $building = DB::table('buildings')->where('id', $buildingIds[0])->first();
        $barangay = $building ? DB::table('barangays')->where('id', $building->barangay_id)->value('barangay_name') : null;
        $point = $this->pointForBuilding((int) $buildingIds[0]);

        return [
            'group_id' => $group,
            'building_ids' => $buildingIds,
            'building_reference' => $building->building_reference ?? null,
            'barangay' => $barangay,
            'address' => $this->text($building->address_description ?? null),
            'location_status' => $this->statusValue($building->location_verification_status ?? null),
            'location_source' => $this->publicSource($building->location_source ?? null),
            'map_available' => $point !== null,
            'map_unavailable_reason' => $point === null ? 'missing_coordinates' : null,
            'longitude' => $point['lng'] ?? null,
            'latitude' => $point['lat'] ?? null,
            'residents' => $this->roster($query, $ids, $filters['incident_id'], $exists, $bindings),
        ];
    }

    public function resident(Request $request, int $residentId): array
    {
        $ids = $this->scope->ids($request);
        $filters = $this->filters($request);
        $row = $this->population($ids)->where('residents.id', $residentId)->first([
            'residents.id',
            'households.barangay_id',
            'households.building_id',
        ]);
        if ($row === null) {
            $hidden = DB::table('residents')
                ->join('households', 'households.id', '=', 'residents.household_id')
                ->where('residents.id', $residentId)
                ->first(['households.barangay_id']);
            if ($hidden !== null) {
                $this->scope->assertBarangay($request, (int) $hidden->barangay_id);
            }
            abort(404);
        }
        $this->scope->assertBarangay($request, (int) $row->barangay_id);
        [$exists, $bindings] = $this->reportExistsSql($ids, $filters['incident_id']);
        $query = $this->applySearch($this->population($ids)->where('residents.id', $residentId), $filters['q']);
        if ($filters['reports'] === 'with') {
            $query->whereRaw($exists, $bindings);
        } elseif ($filters['reports'] === 'without') {
            $query->whereRaw('NOT ('.$exists.')', $bindings);
        }
        $residents = $this->roster($query, $ids, $filters['incident_id'], $exists, $bindings);
        abort_if($residents === [], 404);
        $resident = $residents[0];
        $point = $row->building_id ? $this->pointForBuilding((int) $row->building_id) : null;
        $building = $row->building_id ? DB::table('buildings')->where('id', $row->building_id)->first() : null;
        $resident['group_id'] = $row->building_id ? 'b'.$row->building_id : null;
        $resident['map_available'] = $point !== null;
        $resident['map_unavailable_reason'] = $point === null ? ($row->building_id ? 'missing_coordinates' : 'no_building') : null;
        $resident['location_status'] = $this->statusValue($building->location_verification_status ?? null);
        $resident['location_source'] = $this->publicSource($building->location_source ?? null);
        $resident['address'] = $this->text($building->address_description ?? null);
        $resident['longitude'] = $point['lng'] ?? null;
        $resident['latitude'] = $point['lat'] ?? null;

        return $resident;
    }

    private function features($query, string $exists, array $bindings): array
    {
        if (! $this->canReadPoint()) {
            return [];
        }
        $rows = (clone $query)
            ->join('buildings', 'buildings.id', '=', 'households.building_id')
            ->whereRaw(MapSourceLocation::buildingSqlConstraint('buildings'))
            ->select(['buildings.id as building_id'])
            ->selectRaw(SpatialSql::longitudeExpr('buildings.geographic_point').' as lng')
            ->selectRaw(SpatialSql::latitudeExpr('buildings.geographic_point').' as lat')
            ->selectRaw('CASE WHEN '.$exists.' THEN 1 ELSE 0 END as has_report', $bindings)
            ->get();
        $groups = [];
        foreach ($rows as $row) {
            $lng = (float) $row->lng;
            $lat = (float) $row->lat;
            if (! MapSourceLocation::isValidCoordinate($lng, $lat)) {
                continue;
            }
            $key = $this->pointKey($lng, $lat);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'lng' => $lng,
                    'lat' => $lat,
                    'building_ids' => [],
                    'residents' => 0,
                    'with_reports' => 0,
                ];
            }
            $groups[$key]['building_ids'][(int) $row->building_id] = (int) $row->building_id;
            $groups[$key]['residents']++;
            $groups[$key]['with_reports'] += (int) $row->has_report;
        }

        $features = [];
        foreach ($groups as $key => $group) {
            $with = $group['with_reports'];
            $count = $group['residents'];
            $features[] = [
                'type' => 'Feature',
                'id' => $key,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$group['lng'], $group['lat']],
                ],
                'properties' => [
                    'group_id' => $key,
                    'building_ids' => array_values($group['building_ids']),
                    'resident_count' => $count,
                    'with_report_count' => $with,
                    'without_report_count' => $count - $with,
                    'marker' => $with === 0 ? 'blue' : ($with === $count ? 'red' : 'mixed'),
                ],
            ];
        }

        return $features;
    }

    private function matches($query, string $exists, array $bindings): array
    {
        $rows = (clone $query)
            ->leftJoin('buildings', 'buildings.id', '=', 'households.building_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'households.barangay_id')
            ->select([
                'residents.id',
                'residents.first_name',
                'residents.middle_name',
                'residents.last_name',
                'residents.registry_reference',
                'households.household_reference',
                'households.building_id',
                'buildings.building_reference',
                'barangays.barangay_name',
            ])
            ->selectRaw('CASE WHEN '.$exists.' THEN 1 ELSE 0 END as has_report', $bindings)
            ->orderBy('residents.last_name')
            ->orderBy('residents.first_name')
            ->orderBy('residents.id')
            ->get();

        return $rows->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'name' => SourceName::display($row->first_name, $row->middle_name, $row->last_name),
                'registry_reference' => $row->registry_reference,
                'household_reference' => $row->household_reference,
                'building_reference' => $row->building_reference,
                'barangay' => $row->barangay_name,
                'has_report' => (bool) $row->has_report,
                'group_id' => $row->building_id ? 'b'.$row->building_id : null,
            ];
        })->all();
    }

    private function roster($query, array $ids, ?int $incidentId, string $exists, array $bindings): array
    {
        $rows = (clone $query)
            ->leftJoin('buildings', 'buildings.id', '=', 'households.building_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'households.barangay_id')
            ->select([
                'residents.id',
                'residents.first_name',
                'residents.middle_name',
                'residents.last_name',
                'residents.registry_reference',
                'households.household_reference',
                'households.building_id',
                'buildings.building_reference',
                'barangays.barangay_name',
            ])
            ->selectRaw('CASE WHEN '.$exists.' THEN 1 ELSE 0 END as has_report', $bindings)
            ->orderBy('residents.last_name')
            ->orderBy('residents.first_name')
            ->orderBy('residents.id')
            ->get();
        $reports = $this->reportsFor(array_map(fn ($row) => (int) $row->id, $rows->all()), $ids, $incidentId);

        return $rows->map(function ($row) use ($reports) {
            $list = $reports[(int) $row->id] ?? [];

            return [
                'id' => (int) $row->id,
                'name' => SourceName::display($row->first_name, $row->middle_name, $row->last_name),
                'registry_reference' => $row->registry_reference,
                'household_reference' => $row->household_reference,
                'building_reference' => $row->building_reference,
                'building_id' => $row->building_id ? (int) $row->building_id : null,
                'barangay' => $row->barangay_name,
                'has_report' => $list !== [],
                'report_count' => count($list),
                'account_linked' => $this->accountLinked((int) $row->id),
                'reports' => $list,
            ];
        })->all();
    }

    private function reportsFor(array $residentIds, array $ids, ?int $incidentId): array
    {
        if ($residentIds === [] || $ids === [] || ! Schema::hasTable('account_resident_links') || ! Schema::hasTable('resident_incident_reports')) {
            return [];
        }
        $links = DB::table('account_resident_links')->whereIn('resident_id', $residentIds)->get();
        $residentByUser = [];
        foreach ($links as $link) {
            $residentByUser[(int) $link->user_id] = (int) $link->resident_id;
        }
        if ($residentByUser === []) {
            return [];
        }
        $query = DB::table('resident_incident_reports as reports')
            ->leftJoin('disaster_incidents as incidents', 'incidents.id', '=', 'reports.disaster_incident_id')
            ->whereIn('reports.resident_user_id', array_keys($residentByUser))
            ->where('reports.report_status', '!=', 'draft')
            ->whereIn('reports.barangay_id', $ids)
            ->orderByDesc('reports.submitted_at')
            ->orderByDesc('reports.id')
            ->select([
                'reports.id',
                'reports.report_reference',
                'reports.report_status',
                'reports.submitted_at',
                'reports.resident_user_id',
                'reports.disaster_incident_id',
                'incidents.incident_name',
            ]);
        if (Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
            $query->addSelect('reports.barangay_report_id');
        }
        if ($incidentId) {
            $query->where('reports.disaster_incident_id', $incidentId);
        }
        $grouped = [];
        foreach ($query->get() as $report) {
            $residentId = $residentByUser[(int) $report->resident_user_id] ?? null;
            if ($residentId === null) {
                continue;
            }
            $grouped[$residentId][] = [
                'id' => (int) $report->id,
                'reference' => $report->report_reference,
                'incident_name' => $report->incident_name,
                'status' => $report->report_status,
                'label' => $this->label($report),
                'submitted_at' => $report->submitted_at,
            ];
        }

        return $grouped;
    }

    private function accountLinked(int $residentId): bool
    {
        if (! Schema::hasTable('account_resident_links')) {
            return false;
        }

        return DB::table('account_resident_links')->where('resident_id', $residentId)->exists();
    }

    private function buildingsForGroup(array $ids, string $group): array
    {
        if (preg_match('/^b(\d+)$/', $group, $matches) === 1) {
            $buildingId = (int) $matches[1];
            $barangayId = DB::table('buildings')->where('id', $buildingId)->value('barangay_id');
            if ($barangayId === null || ! in_array((int) $barangayId, $ids, true)) {
                return $barangayId === null ? [] : [$buildingId];
            }

            return [$buildingId];
        }
        if (! $this->canReadPoint() || preg_match('/^p(-?\d+)_(-?\d+)$/', $group, $matches) !== 1) {
            return [];
        }
        $targetLng = ((int) $matches[1]) / 100000;
        $targetLat = ((int) $matches[2]) / 100000;
        $rows = DB::table('buildings')
            ->whereIn('barangay_id', $ids === [] ? [0] : $ids)
            ->whereRaw(MapSourceLocation::buildingSqlConstraint('buildings'))
            ->select('buildings.id')
            ->selectRaw(SpatialSql::longitudeExpr('buildings.geographic_point').' as lng')
            ->selectRaw(SpatialSql::latitudeExpr('buildings.geographic_point').' as lat')
            ->get();
        $found = [];
        foreach ($rows as $row) {
            if ($this->pointKey((float) $row->lng, (float) $row->lat) === $this->pointKey($targetLng, $targetLat)) {
                $found[] = (int) $row->id;
            }
        }

        return $found;
    }

    private function population(array $ids)
    {
        $ids = $ids === [] ? [0] : $ids;

        return DB::table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->where('residents.residency_status', 'active')
            ->where('households.household_status', 'active')
            ->whereIn('households.barangay_id', $ids);
    }

    private function applySearch($query, string $term)
    {
        if ($term === '') {
            return $query;
        }
        $like = '%'.$this->escapeLike($term).'%';
        $query->where(function ($inner) use ($like) {
            $inner->where('residents.first_name', 'like', $like)
                ->orWhere('residents.last_name', 'like', $like)
                ->orWhere('residents.registry_reference', 'like', $like)
                ->orWhere('households.household_reference', 'like', $like);
        });

        return $query;
    }

    private function reportExistsSql(array $ids, ?int $incidentId): array
    {
        if ($ids === [] || ! Schema::hasTable('account_resident_links') || ! Schema::hasTable('resident_incident_reports')) {
            return ['0 = 1', []];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'EXISTS (SELECT 1 FROM account_resident_links links'
            .' INNER JOIN resident_incident_reports reports ON reports.resident_user_id = links.user_id'
            .' WHERE links.resident_id = residents.id'
            ." AND reports.report_status != 'draft'"
            ." AND reports.barangay_id IN ($placeholders)";
        $bindings = $ids;
        if ($incidentId) {
            $sql .= ' AND reports.disaster_incident_id = ?';
            $bindings[] = $incidentId;
        }
        $sql .= ')';

        return [$sql, $bindings];
    }

    private function unmappedCount($query): int
    {
        if (! $this->canReadPoint()) {
            return $this->countResidents($query);
        }
        $constraint = MapSourceLocation::buildingSqlConstraint('buildings');

        return $this->countResidents(
            $query->leftJoin('buildings', 'buildings.id', '=', 'households.building_id')
                ->where(function ($inner) use ($constraint) {
                    $inner->whereNull('buildings.id')->orWhereRaw('NOT ('.$constraint.')');
                })
        );
    }

    private function countResidents($query): int
    {
        $row = (clone $query)->selectRaw('COUNT(DISTINCT residents.id) as aggregate')->first();

        return (int) ($row->aggregate ?? 0);
    }

    private function pointForBuilding(int $buildingId): ?array
    {
        if (! $this->canReadPoint()) {
            return null;
        }
        $row = DB::table('buildings')
            ->where('id', $buildingId)
            ->whereRaw(MapSourceLocation::buildingSqlConstraint('buildings'))
            ->selectRaw(SpatialSql::longitudeExpr('geographic_point').' as lng, '.SpatialSql::latitudeExpr('geographic_point').' as lat')
            ->first();
        if ($row === null) {
            return null;
        }
        $lng = (float) $row->lng;
        $lat = (float) $row->lat;

        return MapSourceLocation::isValidCoordinate($lng, $lat) ? ['lng' => $lng, 'lat' => $lat] : null;
    }

    private function label(object $report): string
    {
        $status = (string) $report->report_status;
        if ($status === 'returned') {
            return 'Needs Correction';
        }
        if ($status === 'reviewed') {
            return $this->finalized($report) ? 'Finalized' : 'Accepted';
        }

        return 'Pending BDRRM Review';
    }

    private function finalized(object $report): bool
    {
        $barangayReportId = $report->barangay_report_id ?? null;
        if (! $barangayReportId || ! Schema::hasTable('field_assessments') || ! Schema::hasTable('consolidated_report_assessments') || ! Schema::hasTable('consolidated_reports')) {
            return false;
        }
        $assessmentId = DB::table('field_assessments')->where('barangay_report_id', $barangayReportId)->orderByDesc('id')->value('id');
        if (! $assessmentId) {
            return false;
        }
        $consolidatedId = DB::table('consolidated_report_assessments')->where('field_assessment_id', $assessmentId)->value('consolidated_report_id');
        if (! $consolidatedId) {
            return false;
        }

        return DB::table('consolidated_reports')->where('id', $consolidatedId)->value('report_status') === 'finalized';
    }

    private function filters(Request $request): array
    {
        $reports = $request->query('reports');
        $incident = $request->query('incident_id');
        $term = trim((string) $request->query('q', ''));

        return [
            'q' => mb_substr($term, 0, 80),
            'barangay_id' => $request->filled('barangay_id') ? $request->integer('barangay_id') : null,
            'incident_id' => ctype_digit((string) $incident) && (int) $incident > 0 ? (int) $incident : null,
            'reports' => in_array($reports, ['with', 'without'], true) ? $reports : '',
        ];
    }

    private function pointKey(float $lng, float $lat): string
    {
        return 'p'.(int) round($lng * 100000).'_'.(int) round($lat * 100000);
    }

    private function canReadPoint(): bool
    {
        return DB::getDriverName() === 'mysql' && Schema::hasColumn('buildings', 'geographic_point');
    }

    private function statusValue(mixed $status): string
    {
        $value = (string) $status;

        return in_array($value, ['unverified', 'verified', 'rejected'], true) ? $value : 'unverified';
    }

    private function publicSource(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null || str_contains($text, '/') || str_contains($text, '\\')) {
            return null;
        }

        return $text;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
