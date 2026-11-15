<?php

namespace App\Services;

use App\Models\Auth\User;
use App\Support\MapSourceLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuildingRegistry
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function denyPersonnel(?User $user): void
    {
        $user?->loadMissing('role');
        if ($user?->role?->code === 'bdrrm') {
            abort(403, 'Building Records are limited to the barangay secretary and captain.');
        }
    }

    /**
     * Barangay-scoped typeahead for creating a barangay report.
     * Returns the building reference, barangay, and id only.
     *
     * @return array{data: list<array{id: int, reference: string, barangay_name: ?string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function lookup(Request $request, string $term): array
    {
        $ids = $this->scope->ids($request);
        $scoped = $ids === [] ? [0] : $ids;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $query = DB::table('buildings')->whereIn('buildings.barangay_id', $scoped);
        $query->where(function ($inner) use ($like) {
            $inner->whereRaw('buildings.building_reference LIKE ? ESCAPE ?', [$like, '\\']);
            if (Schema::hasColumn('buildings', 'address_description')) {
                $inner->orWhereRaw('buildings.address_description LIKE ? ESCAPE ?', [$like, '\\']);
            }
        });
        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', 'barangays.id', '=', 'buildings.barangay_id');
        }
        $total = (clone $query)->count();
        $columns = ['buildings.id', 'buildings.building_reference'];
        if (Schema::hasTable('barangays') && Schema::hasColumn('barangays', 'barangay_name')) {
            $columns[] = 'barangays.barangay_name';
        }
        $rows = $query->orderBy('buildings.building_reference')->limit(10)->get($columns);

        return [
            'data' => collect($rows)->map(fn ($row) => [
                'id' => (int) $row->id,
                'reference' => $row->building_reference,
                'barangay_name' => $row->barangay_name ?? null,
            ])->values()->all(),
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 10,
                'total' => $total,
            ],
        ];
    }

    public function page(Request $request): array
    {
        $ids = $this->scope->ids($request);
        $scopedIds = $ids === [] ? [0] : $ids;
        $authorized = $this->baseQuery($scopedIds);
        $filtered = $this->applyFilters(clone $authorized, $request, $scopedIds);
        $summary = [
            'total' => (clone $filtered)->count(),
            'with_recorded_location' => $this->countRecorded(clone $filtered, true),
            'with_households' => $this->countHouseholds(clone $filtered, true),
            'with_reports' => $this->countReports(clone $filtered, $scopedIds, true),
            'authorized_total' => (clone $authorized)->count(),
        ];
        $perPage = $this->perPage($request);
        $listed = $this->selectRows(clone $filtered, $scopedIds);
        $this->applySort($listed, $request);
        $page = $listed->paginate($perPage);
        $rows = collect($page->items())->map(fn ($row) => $this->listRow($row))->values();
        $payload = [
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'authorized_total' => $summary['authorized_total'],
            ],
            'summary' => $summary,
            'barangays' => $this->barangays($ids),
        ];
        if ($this->wantsResidentMatch($request)) {
            $term = $this->searchTerm($request);
            $pageIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $matches = $this->matchedResidents($pageIds, $term, $scopedIds);
            $photos = $this->leadPhotos($pageIds, $scopedIds);
            $payload['data'] = $rows->map(function (array $row) use ($matches, $photos) {
                $match = $matches[$row['id']] ?? ['count' => 0, 'residents' => []];
                $row['matched_residents'] = $match['residents'];
                $row['matched_resident_count'] = $match['count'];
                $row['photo'] = $photos[$row['id']] ?? null;

                return $row;
            })->values();
            $payload['resident_without_building'] = $page->total() === 0
                && $term !== ''
                && $this->residentWithoutBuilding($request, $scopedIds, $term);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(object $building, array $barangayIds, bool $mapAvailable): array
    {
        $id = (int) $building->id;
        $status = $this->statusValue($building->location_verification_status ?? null);
        $payload = [
            'address' => $this->text($building->address_description ?? null),
            'location_status' => $status,
            'location_recorded' => $mapAvailable,
            'resident_reports' => $this->residentReports($id, $barangayIds),
            'barangay_reports' => $this->barangayReports($id, $barangayIds),
            'assessments' => $this->assessments($id, $barangayIds),
        ];
        $name = $this->text($building->building_name ?? null);
        $type = $this->text($building->building_type ?? null);
        if ($name !== null) {
            $payload['building_name'] = $name;
        }
        if ($type !== null) {
            $payload['building_type'] = $type;
        }
        if (! empty($building->location_verified_at)) {
            $payload['location_verified_at'] = $building->location_verified_at;
        }

        return $payload;
    }

    /**
     * @param  list<int>  $scopeIds
     * @return list<array<string, mixed>>
     */
    public function buildingPhotos(int $buildingId, array $scopeIds): array
    {
        return array_slice($this->authorizedPhotoRows([$buildingId], $scopeIds), 0, 8);
    }

    /**
     * @param  list<int>  $scopeIds
     */
    public function authorizedPhoto(int $buildingId, int $photoId, array $scopeIds): ?object
    {
        $photos = $this->photoRows([$buildingId]);
        $allowed = $this->allowedPhotoIds($photos, $scopeIds);
        foreach ($photos as $photo) {
            if ((int) $photo->id === $photoId && isset($allowed[$photoId])) {
                return $photo;
            }
        }

        return null;
    }

    private function baseQuery(array $ids)
    {
        return DB::table('buildings')->whereIn('buildings.barangay_id', $ids);
    }

    private function applyFilters($query, Request $request, array $ids)
    {
        $term = $this->searchTerm($request);
        if ($term !== '') {
            if ($this->wantsResidentMatch($request)) {
                $this->applyResidentMatch($query, $term, $ids);
            } else {
                $like = '%'.$this->escapeLike($term).'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('buildings.building_reference', 'like', $like);
                    if (Schema::hasColumn('buildings', 'address_description')) {
                        $inner->orWhere('buildings.address_description', 'like', $like);
                    }
                });
            }
        }

        if ($request->filled('barangay_id')) {
            $query->where('buildings.barangay_id', $request->integer('barangay_id'));
        }

        $location = (string) $request->input('location', '');
        if ($location === 'recorded' || $location === 'missing') {
            $this->constrainRecorded($query, $location === 'recorded');
        }

        $verification = (string) $request->input('verification', '');
        if (in_array($verification, ['unverified', 'verified', 'rejected'], true) && Schema::hasColumn('buildings', 'location_verification_status')) {
            $query->where('buildings.location_verification_status', $verification);
        }

        $households = (string) $request->input('households', '');
        if ($households === 'with' || $households === 'without') {
            $this->constrainHouseholds($query, $households === 'with');
        }

        $reports = (string) $request->input('reports', '');
        if ($reports === 'with' || $reports === 'without') {
            $this->constrainReports($query, $ids, $reports === 'with');
        }

        return $query;
    }

    private function selectRows($query, array $ids)
    {
        $columns = [
            'buildings.id',
            'buildings.building_reference',
            'buildings.barangay_id',
        ];
        foreach (['location_source', 'address_description', 'location_verification_status', 'created_at'] as $column) {
            if (Schema::hasColumn('buildings', $column)) {
                $columns[] = 'buildings.'.$column;
            }
        }

        $query->leftJoin('barangays', 'barangays.id', '=', 'buildings.barangay_id')
            ->select($columns)
            ->addSelect('barangays.barangay_name');

        if ($this->canReadPoint()) {
            $sql = MapSourceLocation::buildingSqlConstraint('buildings');
            $query->selectRaw("CASE WHEN {$sql} THEN 1 ELSE 0 END as location_recorded");
        } else {
            $query->selectRaw('0 as location_recorded');
        }

        if (Schema::hasTable('households')) {
            $query->selectRaw("(SELECT COUNT(*) FROM households WHERE households.building_id = buildings.id AND households.household_status = 'active') as household_count");
        } else {
            $query->selectRaw('0 as household_count');
        }

        $residentCount = $this->residentCountSql(count($ids));
        $barangayCount = $this->barangayReportCountSql(count($ids));
        $query->selectRaw("({$residentCount}) + ({$barangayCount}) as report_count", $this->reportCountBindings($ids));

        return $query;
    }

    private function applySort($query, Request $request): void
    {
        $term = $this->searchTerm($request);
        if ($this->wantsResidentMatch($request) && $term !== '' && $this->canMatchResidents()) {
            $query->orderByRaw(
                'CASE WHEN buildings.building_reference = ? THEN 0 WHEN EXISTS (
                    SELECT 1 FROM residents
                    INNER JOIN households ON households.id = residents.household_id
                    WHERE households.building_id = buildings.id
                    AND households.household_status = ?
                    AND residents.residency_status = ?
                    AND residents.registry_reference = ?
                ) THEN 1 ELSE 2 END',
                [$term, 'active', 'active', $term]
            );
        }

        if ((string) $request->input('sort') === 'newest' && Schema::hasColumn('buildings', 'created_at')) {
            $query->orderByDesc('buildings.created_at')->orderByDesc('buildings.id');

            return;
        }

        $query->orderBy('buildings.building_reference')->orderBy('buildings.id');
    }

    private function listRow(object $row): array
    {
        $recorded = (int) ($row->location_recorded ?? 0) === 1;

        return [
            'id' => (int) $row->id,
            'reference' => $row->building_reference,
            'barangay_id' => (int) $row->barangay_id,
            'location_source' => $row->location_source ?? null,
            'barangay_name' => $row->barangay_name ?? null,
            'address' => $this->text($row->address_description ?? null),
            'location_status' => $this->statusValue($row->location_verification_status ?? null),
            'location_recorded' => $recorded,
            'map_available' => $recorded,
            'household_count' => (int) ($row->household_count ?? 0),
            'report_count' => (int) ($row->report_count ?? 0),
        ];
    }

    private function countRecorded($query, bool $recorded): int
    {
        $this->constrainRecorded($query, $recorded);

        return $query->count();
    }

    private function countHouseholds($query, bool $with): int
    {
        $this->constrainHouseholds($query, $with);

        return $query->count();
    }

    private function countReports($query, array $ids, bool $with): int
    {
        $this->constrainReports($query, $ids, $with);

        return $query->count();
    }

    private function constrainRecorded($query, bool $recorded): void
    {
        if (! $this->canReadPoint()) {
            if ($recorded) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $sql = MapSourceLocation::buildingSqlConstraint('buildings');
        $query->whereRaw($recorded ? $sql : "NOT ({$sql})");
    }

    private function constrainHouseholds($query, bool $with): void
    {
        if (! Schema::hasTable('households')) {
            if ($with) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $method = $with ? 'whereExists' : 'whereNotExists';
        $query->{$method}(function ($inner) {
            $inner->selectRaw('1')
                ->from('households')
                ->whereColumn('households.building_id', 'buildings.id')
                ->where('households.household_status', 'active');
        });
    }

    private function constrainReports($query, array $ids, bool $with): void
    {
        $method = $with ? 'where' : 'whereNot';
        $query->{$method}(function ($inner) use ($ids) {
            $inner->where(function ($either) use ($ids) {
                $this->residentReportExists($either, $ids);
                $this->barangayReportExists($either, $ids, true);
            });
        });
    }

    private function residentReportExists($query, array $ids, bool $or = false): void
    {
        if (! Schema::hasTable('resident_incident_reports') || ! Schema::hasColumn('resident_incident_reports', 'building_id')) {
            if (! $or) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $method = $or ? 'orWhereExists' : 'whereExists';
        $query->{$method}(function ($inner) use ($ids) {
            $inner->selectRaw('1')
                ->from('resident_incident_reports')
                ->whereColumn('resident_incident_reports.building_id', 'buildings.id')
                ->where('resident_incident_reports.report_status', '!=', 'draft')
                ->whereIn('resident_incident_reports.barangay_id', $ids);
        });
    }

    private function barangayReportExists($query, array $ids, bool $or = false): void
    {
        if (! Schema::hasTable('barangay_report_buildings') || ! Schema::hasTable('barangay_reports')) {
            return;
        }

        $method = $or ? 'orWhereExists' : 'whereExists';
        $query->{$method}(function ($inner) use ($ids) {
            $inner->selectRaw('1')
                ->from('barangay_report_buildings')
                ->join('barangay_reports', 'barangay_reports.id', '=', 'barangay_report_buildings.barangay_report_id')
                ->whereColumn('barangay_report_buildings.building_id', 'buildings.id')
                ->whereIn('barangay_reports.barangay_id', $ids);
        });
    }

    private function residentCountSql(int $count): string
    {
        if (! Schema::hasTable('resident_incident_reports') || ! Schema::hasColumn('resident_incident_reports', 'building_id')) {
            return '0';
        }

        return "SELECT COUNT(*) FROM resident_incident_reports WHERE resident_incident_reports.building_id = buildings.id AND resident_incident_reports.report_status != 'draft' AND resident_incident_reports.barangay_id IN (".$this->placeholders($count).')';
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function reportCountBindings(array $ids): array
    {
        $bindings = [];
        if (Schema::hasTable('resident_incident_reports') && Schema::hasColumn('resident_incident_reports', 'building_id')) {
            $bindings = [...$bindings, ...$ids];
        }
        if (Schema::hasTable('barangay_report_buildings') && Schema::hasTable('barangay_reports')) {
            $bindings = [...$bindings, ...$ids];
        }

        return $bindings;
    }

    private function barangayReportCountSql(int $count): string
    {
        if (! Schema::hasTable('barangay_report_buildings') || ! Schema::hasTable('barangay_reports')) {
            return '0';
        }

        return 'SELECT COUNT(*) FROM barangay_report_buildings INNER JOIN barangay_reports ON barangay_reports.id = barangay_report_buildings.barangay_report_id WHERE barangay_report_buildings.building_id = buildings.id AND barangay_reports.barangay_id IN ('.$this->placeholders($count).')';
    }

    private function placeholders(int $count): string
    {
        if ($count < 1) {
            return '0';
        }

        return implode(',', array_fill(0, $count, '?'));
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, reference: string, incident_name: ?string, status: string, submitted_at: ?string}>
     */
    private function residentReports(int $buildingId, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('resident_incident_reports') || ! Schema::hasColumn('resident_incident_reports', 'building_id')) {
            return [];
        }

        $query = DB::table('resident_incident_reports as reports')
            ->where('reports.building_id', $buildingId)
            ->where('reports.report_status', '!=', 'draft')
            ->whereIn('reports.barangay_id', $ids)
            ->orderByDesc('reports.submitted_at')
            ->orderByDesc('reports.id');

        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'reports.disaster_incident_id')
                ->select('reports.id', 'reports.report_reference', 'reports.report_status', 'reports.submitted_at', 'disaster_incidents.incident_name');
        } else {
            $query->select('reports.id', 'reports.report_reference', 'reports.report_status', 'reports.submitted_at');
        }

        return $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'reference' => $row->report_reference,
            'incident_name' => $row->incident_name ?? null,
            'status' => $row->report_status,
            'submitted_at' => $row->submitted_at,
        ])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, reference: string, incident_name: ?string, status: string}>
     */
    private function barangayReports(int $buildingId, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('barangay_report_buildings') || ! Schema::hasTable('barangay_reports')) {
            return [];
        }

        $query = DB::table('barangay_report_buildings as links')
            ->join('barangay_reports as reports', 'reports.id', '=', 'links.barangay_report_id')
            ->where('links.building_id', $buildingId)
            ->whereIn('reports.barangay_id', $ids)
            ->orderByDesc('reports.updated_at')
            ->orderByDesc('reports.id');

        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'reports.disaster_incident_id')
                ->select('reports.id', 'reports.report_reference', 'reports.report_status', 'disaster_incidents.incident_name');
        } else {
            $query->select('reports.id', 'reports.report_reference', 'reports.report_status');
        }

        return $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'reference' => $row->report_reference,
            'incident_name' => $row->incident_name ?? null,
            'status' => $row->report_status,
        ])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{incident_name: ?string, verified_at: ?string, damage_class: ?string}>
     */
    private function assessments(int $buildingId, array $ids): array
    {
        foreach (['building_verifications', 'field_assessments', 'barangay_reports', 'damage_classes'] as $table) {
            if (! Schema::hasTable($table)) {
                return [];
            }
        }
        if ($ids === []) {
            return [];
        }

        $query = DB::table('building_verifications as verifications')
            ->join('field_assessments', 'field_assessments.id', '=', 'verifications.field_assessment_id')
            ->join('barangay_reports', 'barangay_reports.id', '=', 'field_assessments.barangay_report_id')
            ->join('damage_classes', 'damage_classes.id', '=', 'verifications.verified_damage_class_id')
            ->where('verifications.building_id', $buildingId)
            ->whereIn('barangay_reports.barangay_id', $ids)
            ->orderByDesc('verifications.verified_at')
            ->orderByDesc('verifications.id');

        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'barangay_reports.disaster_incident_id')
                ->select('disaster_incidents.incident_name', 'verifications.verified_at', 'damage_classes.class_name');
        } else {
            $query->select('verifications.verified_at', 'damage_classes.class_name');
        }

        return $query->get()->map(fn ($row) => [
            'incident_name' => $row->incident_name ?? null,
            'verified_at' => $row->verified_at,
            'damage_class' => $row->class_name,
        ])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string}>
     */
    private function barangays(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('barangays')) {
            return [];
        }

        return DB::table('barangays')
            ->whereIn('id', $ids)
            ->orderBy('barangay_name')
            ->get(['id', 'barangay_name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->barangay_name,
            ])
            ->all();
    }

    private function canReadPoint(): bool
    {
        return DB::getDriverName() === 'mysql' && Schema::hasColumn('buildings', 'geographic_point');
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 10);

        return in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
    }

    private function statusValue(mixed $status): string
    {
        $value = (string) $status;

        return in_array($value, ['unverified', 'verified', 'rejected'], true) ? $value : 'unverified';
    }

    private function rowValue(?object $row, string $key): mixed
    {
        return $row !== null && property_exists($row, $key) ? $row->{$key} : null;
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

    private function wantsResidentMatch(Request $request): bool
    {
        return (string) $request->input('match') === 'residents';
    }

    private function searchTerm(Request $request): string
    {
        $term = trim((string) ($request->input('q') ?: $request->input('search', '')));
        $term = preg_replace('/\s+/u', ' ', $term) ?? $term;

        return mb_substr($term, 0, 80);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $term): array
    {
        $parts = preg_split('/\s+/u', $term) ?: [];

        return array_values(array_filter($parts, fn ($part) => $part !== ''));
    }

    private function canMatchResidents(): bool
    {
        return Schema::hasTable('residents')
            && Schema::hasTable('households')
            && Schema::hasColumn('residents', 'household_id')
            && Schema::hasColumn('residents', 'registry_reference')
            && Schema::hasColumn('households', 'building_id');
    }

    /**
     * @param  list<int>  $scopeIds
     */
    private function applyResidentMatch($query, string $term, array $scopeIds): void
    {
        $like = '%'.$this->escapeLike($term).'%';
        $query->where(function ($inner) use ($like, $term, $scopeIds) {
            $inner->where('buildings.building_reference', 'like', $like);
            if (Schema::hasColumn('buildings', 'address_description')) {
                $inner->orWhere('buildings.address_description', 'like', $like);
            }
            if ($this->canMatchResidents()) {
                $inner->orWhereExists(function ($exists) use ($term, $scopeIds) {
                    $exists->selectRaw('1')
                        ->from('residents')
                        ->join('households', 'households.id', '=', 'residents.household_id')
                        ->whereColumn('households.building_id', 'buildings.id')
                        ->where('households.household_status', 'active')
                        ->where('residents.residency_status', 'active')
                        ->whereIn('households.barangay_id', $scopeIds === [] ? [0] : $scopeIds);
                    $this->wherePersonMatches($exists, $term);
                });
            }
        });
    }

    private function wherePersonMatches($query, string $term): void
    {
        $tokens = $this->tokens($term);
        $query->where(function ($person) use ($term, $tokens) {
            $person->where('residents.registry_reference', 'like', '%'.$this->escapeLike($term).'%');
            if ($tokens === []) {
                return;
            }
            $person->orWhere(function ($names) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.$this->escapeLike($token).'%';
                    $names->where(function ($part) use ($like) {
                        $part->where('residents.first_name', 'like', $like)
                            ->orWhere('residents.middle_name', 'like', $like)
                            ->orWhere('residents.last_name', 'like', $like);
                    });
                }
            });
        });
    }

    /**
     * @param  list<int>  $buildingIds
     * @param  list<int>  $scopeIds
     * @return array<int, array{count: int, residents: list<array{name: string, resident_id: string}>}>
     */
    private function matchedResidents(array $buildingIds, string $term, array $scopeIds): array
    {
        if ($buildingIds === [] || $term === '' || ! $this->canMatchResidents()) {
            return [];
        }

        $query = DB::table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->whereIn('households.building_id', $buildingIds)
            ->whereIn('households.barangay_id', $scopeIds === [] ? [0] : $scopeIds)
            ->where('households.household_status', 'active')
            ->where('residents.residency_status', 'active')
            ->orderBy('residents.last_name')
            ->orderBy('residents.first_name');
        $this->wherePersonMatches($query, $term);
        $grouped = [];
        foreach ($query->get(['households.building_id', 'residents.first_name', 'residents.middle_name', 'residents.last_name', 'residents.registry_reference']) as $row) {
            $grouped[(int) $row->building_id][] = [
                'name' => $this->personName($row),
                'resident_id' => (string) $row->registry_reference,
            ];
        }

        $result = [];
        foreach ($grouped as $id => $people) {
            $result[$id] = [
                'count' => count($people),
                'residents' => array_slice($people, 0, 8),
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $scopeIds
     */
    private function residentWithoutBuilding(Request $request, array $scopeIds, string $term): bool
    {
        if (! $this->canMatchResidents()) {
            return false;
        }
        $barangays = $request->filled('barangay_id')
            ? [$request->integer('barangay_id')]
            : ($scopeIds === [] ? [0] : $scopeIds);
        $query = DB::table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->whereNull('households.building_id')
            ->where('households.household_status', 'active')
            ->where('residents.residency_status', 'active')
            ->whereIn('households.barangay_id', $barangays);
        $this->wherePersonMatches($query, $term);

        return $query->exists();
    }

    private function personName(object $row): string
    {
        $name = trim(implode(' ', array_filter([
            $this->text($row->first_name ?? null),
            $this->text($row->middle_name ?? null),
            $this->text($row->last_name ?? null),
        ])));

        return $name !== '' ? $name : 'Name unavailable';
    }

    /**
     * @param  list<int>  $buildingIds
     * @param  list<int>  $scopeIds
     * @return array<int, array<string, mixed>>
     */
    private function leadPhotos(array $buildingIds, array $scopeIds): array
    {
        $lead = [];
        foreach ($this->authorizedPhotoRows($buildingIds, $scopeIds) as $photo) {
            $buildingId = (int) $photo['building_id'];
            if (! array_key_exists($buildingId, $lead)) {
                $lead[$buildingId] = $photo;
            }
        }

        return $lead;
    }

    /**
     * @param  list<int>  $buildingIds
     * @param  list<int>  $scopeIds
     * @return list<array<string, mixed>>
     */
    private function authorizedPhotoRows(array $buildingIds, array $scopeIds): array
    {
        $photos = $this->photoRows($buildingIds);
        $allowed = $this->allowedPhotoIds($photos, $scopeIds);
        $context = $this->photoContext($photos);
        $rows = [];
        foreach ($photos as $photo) {
            if (! isset($allowed[(int) $photo->id])) {
                continue;
            }
            $rows[] = $this->photoPayload($photo, $context);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $buildingIds
     * @return list<object>
     */
    private function photoRows(array $buildingIds): array
    {
        if ($buildingIds === [] || ! Schema::hasTable('incident_photos') || ! Schema::hasColumn('incident_photos', 'building_id')) {
            return [];
        }

        return DB::table('incident_photos')
            ->whereIn('building_id', $buildingIds)
            ->whereNotNull('building_id')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * @param  list<object>  $photos
     * @param  list<int>  $scopeIds
     * @return array<int, true>
     */
    private function allowedPhotoIds(array $photos, array $scopeIds): array
    {
        $allowed = array_fill_keys($scopeIds === [] ? [0] : $scopeIds, true);
        $barangayReports = $this->rowsById('barangay_reports', $this->photoParentIds($photos, 'barangay_report_id'));
        $residentReports = $this->rowsById('resident_incident_reports', $this->photoParentIds($photos, 'incident_report_id'));
        $assessments = $this->rowsById('field_assessments', $this->photoParentIds($photos, 'field_assessment_id'));
        $assessmentReports = $this->rowsById('barangay_reports', array_map(
            fn ($row) => (int) $row->barangay_report_id,
            array_values($assessments)
        ));
        $ids = [];
        foreach ($photos as $photo) {
            $barangayId = null;
            if (! empty($photo->barangay_report_id)) {
                $barangayId = $this->rowValue($barangayReports[(int) $photo->barangay_report_id] ?? null, 'barangay_id');
            } elseif (! empty($photo->incident_report_id)) {
                $barangayId = $this->rowValue($residentReports[(int) $photo->incident_report_id] ?? null, 'barangay_id');
            } elseif (! empty($photo->field_assessment_id)) {
                $assessment = $assessments[(int) $photo->field_assessment_id] ?? null;
                $reportId = $this->rowValue($assessment, 'barangay_report_id');
                $barangayId = $this->rowValue($reportId ? ($assessmentReports[(int) $reportId] ?? null) : null, 'barangay_id');
            }
            if ($barangayId !== null && isset($allowed[(int) $barangayId])) {
                $ids[(int) $photo->id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param  list<object>  $photos
     * @return array{barangay_reports: array<int, object>, resident_reports: array<int, object>, assessments: array<int, object>, incidents: array<int, string>}
     */
    private function photoContext(array $photos): array
    {
        $barangayReports = $this->rowsById('barangay_reports', $this->photoParentIds($photos, 'barangay_report_id'));
        $residentReports = $this->rowsById('resident_incident_reports', $this->photoParentIds($photos, 'incident_report_id'));
        $assessments = $this->rowsById('field_assessments', $this->photoParentIds($photos, 'field_assessment_id'));
        $assessmentReports = $this->rowsById('barangay_reports', array_map(
            fn ($row) => (int) $row->barangay_report_id,
            array_values($assessments)
        ));
        $incidentIds = [];
        foreach ([...$barangayReports, ...$residentReports, ...$assessmentReports] as $row) {
            if (! empty($row->disaster_incident_id)) {
                $incidentIds[] = (int) $row->disaster_incident_id;
            }
        }

        return [
            'barangay_reports' => $barangayReports + $assessmentReports,
            'resident_reports' => $residentReports,
            'assessments' => $assessments,
            'incidents' => $this->incidentNames($incidentIds),
        ];
    }

    /**
     * @param  list<object>  $photos
     * @return list<int>
     */
    private function photoParentIds(array $photos, string $column): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($photo) => empty($photo->{$column}) ? null : (int) $photo->{$column},
            $photos
        ))));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function rowsById(string $table, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->whereIn('id', $ids)->get()->keyBy(fn ($row) => (int) $row->id)->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function incidentNames(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('disaster_incidents')) {
            return [];
        }
        $names = [];
        foreach (DB::table('disaster_incidents')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'incident_name']) as $row) {
            $name = $this->text($row->incident_name);
            if ($name !== null) {
                $names[(int) $row->id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array{barangay_reports: array<int, object>, resident_reports: array<int, object>, assessments: array<int, object>, incidents: array<int, string>}  $context
     * @return array<string, mixed>
     */
    private function photoPayload(object $photo, array $context): array
    {
        $reference = null;
        $incidentName = null;
        $recordedAt = $this->rowValue($photo, 'captured_at');
        if (! empty($photo->barangay_report_id)) {
            $report = $context['barangay_reports'][(int) $photo->barangay_report_id] ?? null;
            $reference = $this->rowValue($report, 'report_reference');
            $recordedAt = $recordedAt ?: $this->rowValue($report, 'submitted_at');
            $incidentName = $context['incidents'][(int) ($this->rowValue($report, 'disaster_incident_id') ?? 0)] ?? null;
        } elseif (! empty($photo->incident_report_id)) {
            $report = $context['resident_reports'][(int) $photo->incident_report_id] ?? null;
            $reference = $this->rowValue($report, 'report_reference');
            $recordedAt = $recordedAt ?: $this->rowValue($report, 'submitted_at');
            $incidentName = $context['incidents'][(int) ($this->rowValue($report, 'disaster_incident_id') ?? 0)] ?? null;
        } elseif (! empty($photo->field_assessment_id)) {
            $assessment = $context['assessments'][(int) $photo->field_assessment_id] ?? null;
            $reference = $this->rowValue($assessment, 'assessment_reference');
            $report = $context['barangay_reports'][(int) ($this->rowValue($assessment, 'barangay_report_id') ?? 0)] ?? null;
            $recordedAt = $recordedAt ?: $this->rowValue($report, 'submitted_at');
            $incidentName = $context['incidents'][(int) ($this->rowValue($report, 'disaster_incident_id') ?? 0)] ?? null;
        }

        return [
            'id' => (int) $photo->id,
            'building_id' => (int) $photo->building_id,
            'report_reference' => $reference,
            'incident_name' => $incidentName,
            'recorded_at' => $recordedAt,
            'source' => 'incident_report',
        ];
    }
}
