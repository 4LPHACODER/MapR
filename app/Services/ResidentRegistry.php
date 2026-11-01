<?php

namespace App\Services;

use App\Models\Registry\Household;
use App\Models\Registry\IncidentResidentInclusion;
use App\Models\Registry\IncidentResidentInclusionEvent;
use App\Models\Registry\Resident;
use App\Services\ActivityLogger;
use App\Services\PolygonSpatial;
use App\Support\MapSourceLocation;
use App\Support\SourceName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ResidentRegistry
{
    public function __construct(private ActivityLogger $activity) {}

    public function summary(Request $request): JsonResponse
    {
        if (! Schema::hasTable('residents')) {
            return response()->json([
                'registered_residents' => 0,
                'households_represented' => 0,
                'potentially_affected' => null,
                'incident_required' => ! $request->filled('incident_id'),
                'total_household_size' => 0,
                'households_missing_size' => 0,
                'household_size_partial' => false,
            ]);
        }

        $query = $this->scopedQuery($request, false, false)->where('residency_status', 'active');
        $registered = (clone $query)->count();
        $households = (clone $query)->distinct('household_id')->count('household_id');

        $affected = null;
        $incidentRequired = ! $request->filled('incident_id');
        if (! $incidentRequired && Schema::hasTable('incident_resident_inclusions')) {
            $affected = (clone $query)->whereHas('inclusions', function ($inclusion) use ($request) {
                $inclusion->where('disaster_incident_id', $request->integer('incident_id'))
                    ->where('status', 'active');
            })->count();
        }
        $size = $this->householdSizeTotals($this->scopedQuery($request, true));

        return response()->json([
            'registered_residents' => $registered,
            'households_represented' => $households,
            'potentially_affected' => $incidentRequired ? null : ($affected ?? 0),
            'incident_required' => $incidentRequired,
            'total_household_size' => $size['total_household_size'],
            'households_missing_size' => $size['households_missing_size'],
            'household_size_partial' => $size['household_size_partial'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('residents')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                    'per_page' => 25,
                    'registry_loaded' => false,
                ],
            ]);
        }

        $perPage = in_array($request->integer('per_page'), [10, 25, 50], true) ? $request->integer('per_page') : 25;
        $query = $this->scopedQuery($request, true)
            ->with(['household.building', 'household.barangay'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        $incidentId = $request->filled('incident_id') ? $request->integer('incident_id') : null;
        $page = $query->paginate($perPage)->withQueryString();
        $items = collect($page->items());
        $points = $this->pointsFor($items->pluck('household.building.id')->filter()->all());
        $reportCounts = $this->reportCountsFor($items, $incidentId);

        return response()->json([
            'data' => $items->map(fn (Resident $resident) => $this->serialize($resident, false, $incidentId, $points, $reportCounts[$resident->id] ?? 0)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'registry_loaded' => Schema::hasTable('buildings') && DB::table('buildings')->exists(),
            ],
        ]);
    }

    public function show(Request $request, Resident $resident): JsonResponse
    {
        $resident->load(['household.building', 'household.barangay', 'household.residents' => fn ($q) => $q->where('residency_status', 'active')]);
        $this->assertResidentVisible($request, $resident);
        $points = $this->pointsFor(array_filter([$resident->household?->building?->id]));
        $payload = $this->serialize($resident, true, $request->filled('incident_id') ? $request->integer('incident_id') : null, $points);
        $payload['history'] = $this->historyFor($resident);

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:191'],
            'middle_name' => ['nullable', 'string', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'household_id' => ['required', 'integer'],
            'sex' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date'],
            'record_source' => ['required', 'string', 'max:191'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ]);

        $household = Household::query()->where('household_status', 'active')->find($data['household_id']);
        if (! $household) {
            return response()->json(['message' => 'Choose an active household.'], 422);
        }

        $matches = $this->duplicateMatches($household->id, $data['first_name'], $data['last_name']);
        if ($matches->isNotEmpty() && ! $request->boolean('confirm_duplicate')) {
            return response()->json([
                'message' => 'Another resident in this household has the same name. Confirm to save a different person.',
                'matches' => $matches,
            ], 422);
        }

        $resident = Resident::query()->create([
            'registry_reference' => $this->nextReference(),
            'first_name' => trim($data['first_name']),
            'middle_name' => filled($data['middle_name'] ?? null) ? trim($data['middle_name']) : null,
            'last_name' => trim($data['last_name']),
            'sex' => $data['sex'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'residency_status' => 'active',
            'record_source' => trim($data['record_source']),
            'household_id' => $household->id,
        ]);

        $this->activity->log($request->user(), 'resident_created', 'Created resident '.$resident->registry_reference.'.', 'resident', $resident->id);

        return response()->json($this->serialize($resident->load('household.building', 'household.barangay'), true), 201);
    }

    public function update(Request $request, Resident $resident): JsonResponse
    {
        $data = $request->validate([
            'updated_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'first_name' => ['sometimes', 'string', 'max:191'],
            'middle_name' => ['nullable', 'string', 'max:191'],
            'last_name' => ['sometimes', 'string', 'max:191'],
            'household_id' => ['sometimes', 'integer'],
            'sex' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date'],
        ]);

        $stored = $resident->updated_at?->utc()->format('Y-m-d H:i:s');
        $submitted = \Illuminate\Support\Carbon::parse($data['updated_at'])->utc()->format('Y-m-d H:i:s');
        if ($stored !== $submitted) {
            return response()->json(['message' => 'This resident was updated by someone else. Refresh and try again.'], 409);
        }

        $changes = [];
        foreach (['first_name', 'middle_name', 'last_name', 'sex', 'birth_date'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $next = $field === 'birth_date' ? ($data[$field] ?: null) : (is_string($data[$field]) ? trim($data[$field]) : $data[$field]);
            if ((string) $resident->{$field} !== (string) $next) {
                $resident->{$field} = $next === '' ? null : $next;
                $changes[] = $field;
            }
        }

        if (array_key_exists('household_id', $data) && (int) $data['household_id'] !== (int) $resident->household_id) {
            $household = Household::query()->where('household_status', 'active')->find($data['household_id']);
            if (! $household) {
                return response()->json(['message' => 'Choose an active household.'], 422);
            }
            $resident->household_id = $household->id;
            $changes[] = 'household_id';
        }

        if ($changes === []) {
            return response()->json(['message' => 'No changes to save.'], 422);
        }

        $resident->save();
        $this->activity->log(
            $request->user(),
            'resident_updated',
            'Corrected '.$resident->registry_reference.' ('.implode(', ', $changes).'). Reason: '.$data['reason'].'.',
            'resident',
            $resident->id,
        );

        return response()->json($this->serialize($resident->fresh()->load('household.building', 'household.barangay'), true));
    }

    public function archive(Request $request, Resident $resident): JsonResponse
    {
        $data = $request->validate([
            'confirm_reference' => ['required', 'string'],
        ]);

        if ($data['confirm_reference'] !== $resident->registry_reference) {
            return response()->json(['message' => 'Enter the resident reference to archive this record.'], 422);
        }

        $resident->residency_status = 'inactive';
        $resident->save();
        $this->activity->log($request->user(), 'resident_archived', 'Archived resident '.$resident->registry_reference.'.', 'resident', $resident->id);

        return response()->json(['ok' => true, 'residency_status' => 'inactive']);
    }

    public function restore(Request $request, Resident $resident): JsonResponse
    {
        $resident->residency_status = 'active';
        $resident->save();
        $this->activity->log($request->user(), 'resident_restored', 'Restored resident '.$resident->registry_reference.'.', 'resident', $resident->id);

        return response()->json(['ok' => true, 'residency_status' => 'active']);
    }

    public function addInclusions(Request $request): JsonResponse
    {
        if (! Schema::hasTable('incident_resident_inclusions')) {
            return response()->json(['message' => 'Incident inclusion records are not available.'], 422);
        }

        $data = $this->validateInclusion($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $result = DB::transaction(function () use ($request, $data) {
            $added = 0;
            $reinstated = 0;
            $already = 0;
            foreach ($data['residents'] as $resident) {
                $inclusion = IncidentResidentInclusion::query()->firstOrNew([
                    'disaster_incident_id' => $data['incident_id'],
                    'resident_id' => $resident->id,
                ]);
                if ($inclusion->exists && $inclusion->status === 'active') {
                    $already++;
                    continue;
                }
                $action = $inclusion->exists ? 'reinstated' : 'added';
                $inclusion->fill([
                    'inclusion_basis' => $data['basis'],
                    'reference' => $data['reference'],
                    'notes' => $data['notes'],
                    'status' => 'active',
                    'removed_at' => null,
                    'removed_by' => null,
                    'added_by' => $inclusion->exists ? $inclusion->added_by : $request->user()->id,
                ]);
                $inclusion->save();
                IncidentResidentInclusionEvent::query()->create([
                    'inclusion_id' => $inclusion->id,
                    'action' => $action,
                    'user_id' => $request->user()->id,
                    'created_at' => now(),
                ]);
                $action === 'added' ? $added++ : $reinstated++;
            }

            return compact('added', 'reinstated', 'already');
        });

        $this->activity->log(
            $request->user(),
            'residents_marked_potentially_affected',
            'Added '.$result['added'].' and reinstated '.$result['reinstated'].' residents on incident '.$data['incident_id'].'. '.$result['already'].' were already listed.',
            'disaster_incident',
            $data['incident_id'],
        );

        return response()->json($result);
    }

    public function removeInclusions(Request $request): JsonResponse
    {
        if (! Schema::hasTable('incident_resident_inclusions')) {
            return response()->json(['message' => 'Incident inclusion records are not available.'], 422);
        }

        $data = $request->validate([
            'incident_id' => ['required', 'integer'],
            'resident_ids' => ['required', 'array', 'min:1', 'max:200'],
            'resident_ids.*' => ['integer'],
        ]);

        if (! DB::table('disaster_incidents')->where('id', $data['incident_id'])->exists()) {
            return response()->json(['message' => 'Choose a valid incident.'], 422);
        }

        $removed = DB::transaction(function () use ($request, $data) {
            $count = 0;
            $rows = IncidentResidentInclusion::query()
                ->where('disaster_incident_id', $data['incident_id'])
                ->whereIn('resident_id', $data['resident_ids'])
                ->where('status', 'active')
                ->get();
            foreach ($rows as $inclusion) {
                $inclusion->status = 'removed';
                $inclusion->removed_at = now();
                $inclusion->removed_by = $request->user()->id;
                $inclusion->save();
                IncidentResidentInclusionEvent::query()->create([
                    'inclusion_id' => $inclusion->id,
                    'action' => 'removed',
                    'user_id' => $request->user()->id,
                    'created_at' => now(),
                ]);
                $count++;
            }

            return $count;
        });

        $this->activity->log(
            $request->user(),
            'residents_removed_from_incident_list',
            'Removed '.$removed.' residents from incident '.$data['incident_id'].'.',
            'disaster_incident',
            (int) $data['incident_id'],
        );

        return response()->json(['removed' => $removed]);
    }

    public function reports(Request $request, Resident $resident): JsonResponse
    {
        $resident->load('household.barangay');
        $this->assertResidentVisible($request, $resident);
        $incidentId = $request->filled('incident_id') ? $request->integer('incident_id') : null;

        return response()->json([
            'submitted_by_account' => $this->accountReports($resident, $incidentId),
            'household_or_building' => $this->buildingReports($resident, $incidentId),
            'estimate_or_consolidated' => $this->estimateReports($resident, $incidentId),
        ]);
    }

    public function storeMapFocus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resident_ids' => ['required', 'array', 'min:1', 'max:200'],
            'resident_ids.*' => ['integer'],
        ]);

        $residents = Resident::query()->with('household.building')->whereIn('id', $data['resident_ids']);
        $scope = $request->attributes->get('scoped_barangay_ids');
        if (is_array($scope)) {
            $scope = array_map('intval', $scope);
            $residents->whereHas('household', fn ($household) => $household->whereIn('barangay_id', $scope === [] ? [0] : $scope));
        }
        $residents = $residents->get();
        $points = $this->pointsFor($residents->pluck('household.building.id')->filter()->all());
        $mapped = [];
        $missing = 0;
        foreach ($residents as $resident) {
            $buildingId = $resident->household?->building?->id;
            $point = $buildingId ? ($points[$buildingId] ?? null) : null;
            if (! $point) {
                $missing++;
                continue;
            }
            $mapped[] = [
                'household_id' => $resident->household_id,
                'longitude' => $point['lng'],
                'latitude' => $point['lat'],
            ];
        }

        $request->session()->put('cdrrmo.resident_map_focus', [
            'points' => $mapped,
            'missing' => $missing,
        ]);

        return response()->json([
            'mapped' => count($mapped),
            'missing' => $missing,
        ]);
    }

    public function showMapFocus(Request $request): JsonResponse
    {
        $focus = $request->session()->get('cdrrmo.resident_map_focus');

        return response()->json(is_array($focus) ? $focus : ['points' => [], 'missing' => 0]);
    }

    private function assertResidentVisible(Request $request, Resident $resident): void
    {
        $scope = $request->attributes->get('scoped_barangay_ids');
        if (! is_array($scope)) {
            return;
        }

        $barangayId = (int) ($resident->household?->barangay_id ?? 0);
        if (! in_array($barangayId, array_map('intval', $scope), true)) {
            abort(403, 'That record is outside your assignment.');
        }
    }

    private function reportOpenPath(string $kind, int $id): ?string
    {
        $role = request()->attributes->get('field_role');
        if ($role === 'rdana') {
            return match ($kind) {
                'barangay' => '/reports/barangay',
                'field' => '/assessments/'.$id,
                default => null,
            };
        }
        if ($role === 'bdrrm') {
            return $kind === 'barangay' ? '/reports/'.$id : null;
        }

        return match ($kind) {
            'barangay' => '/reports/document/barangay/'.$id,
            'field' => '/reports/document/field/'.$id,
            'consolidated' => '/reports/document/consolidated/'.$id,
            default => null,
        };
    }

    private function scopedQuery(Request $request, bool $inclusion, bool $applyStatus = true)
    {
        $status = $request->input('status', 'active');
        $query = Resident::query();
        if ($applyStatus && in_array($status, ['active', 'inactive'], true)) {
            $query->where('residency_status', $status);
        }

        $scope = $request->attributes->get('scoped_barangay_ids');
        if (is_array($scope)) {
            $scope = array_values(array_map('intval', $scope));
            $query->whereHas('household', fn ($household) => $household->whereIn('barangay_id', $scope === [] ? [0] : $scope));
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('registry_reference', 'like', '%'.$search.'%')
                    ->orWhereHas('household', fn ($household) => $household->where('household_reference', 'like', '%'.$search.'%'));
            });
        }

        if ($request->filled('barangay_id')) {
            $query->whereHas('household', fn ($household) => $household->where('barangay_id', $request->integer('barangay_id')));
        }

        if ($request->filled('household_id')) {
            $query->where('household_id', $request->integer('household_id'));
        }

        if ($request->filled('building_id')) {
            $query->whereHas('household', fn ($household) => $household->where('building_id', $request->integer('building_id')));
        }

        if ($request->boolean('area_scope')) {
            $this->applySessionAreaScope($request, $query);
        }

        if ($inclusion && $request->filled('incident_id') && in_array($request->input('inclusion'), ['included', 'not_included'], true) && Schema::hasTable('incident_resident_inclusions')) {
            $method = $request->input('inclusion') === 'included' ? 'whereHas' : 'whereDoesntHave';
            $query->{$method}('inclusions', function ($row) use ($request) {
                $row->where('disaster_incident_id', $request->integer('incident_id'))->where('status', 'active');
            });
        }

        $this->applyReportAvailability($query, $request);

        return $query;
    }

    private function applyReportAvailability($query, Request $request): void
    {
        $mode = (string) $request->input('report_availability', 'all');
        if (! in_array($mode, ['with', 'without'], true)) {
            return;
        }

        $supported = (Schema::hasTable('account_resident_links') && Schema::hasTable('resident_incident_reports'))
            || (Schema::hasTable('barangay_report_buildings') && Schema::hasTable('barangay_reports') && Schema::hasTable('households'));
        if (! $supported) {
            if ($mode === 'with') {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $method = $mode === 'with' ? 'where' : 'whereNot';
        $query->{$method}(function ($outer) use ($request) {
            $this->explicitReportConstraint($outer, $request->filled('incident_id') ? $request->integer('incident_id') : null);
        });
    }

    private function explicitReportConstraint($query, ?int $incidentId): void
    {
        $query->where(function ($outer) use ($incidentId) {
            $applied = false;
            if (Schema::hasTable('account_resident_links') && Schema::hasTable('resident_incident_reports')) {
                $outer->whereExists(function ($sub) use ($incidentId) {
                    $sub->selectRaw('1')
                        ->from('account_resident_links as links')
                        ->join('resident_incident_reports as reports', 'reports.resident_user_id', '=', 'links.user_id')
                        ->whereColumn('links.resident_id', 'residents.id')
                        ->when($incidentId, fn ($row) => $row->where('reports.disaster_incident_id', $incidentId));
                });
                $applied = true;
            }
            if (Schema::hasTable('barangay_report_buildings') && Schema::hasTable('barangay_reports') && Schema::hasTable('households')) {
                $method = $applied ? 'orWhereExists' : 'whereExists';
                $outer->{$method}(function ($sub) use ($incidentId) {
                    $sub->selectRaw('1')
                        ->from('households as report_households')
                        ->join('barangay_report_buildings as links', 'links.building_id', '=', 'report_households.building_id')
                        ->join('barangay_reports as reports', 'reports.id', '=', 'links.barangay_report_id')
                        ->whereColumn('report_households.id', 'residents.household_id')
                        ->whereNotNull('report_households.building_id')
                        ->when($incidentId, fn ($row) => $row->where('reports.disaster_incident_id', $incidentId));
                    if (Schema::hasColumn('barangay_reports', 'supersedes_id')) {
                        $sub->whereNotExists(function ($newer) {
                            $newer->selectRaw('1')
                                ->from('barangay_reports as newer')
                                ->whereColumn('newer.supersedes_id', 'reports.id');
                        });
                    }
                });
            }
        });
    }

    private function householdSizeTotals($query): array
    {
        if (! Schema::hasTable('households') || ! Schema::hasColumn('households', 'source_household_size')) {
            return ['total_household_size' => null, 'households_missing_size' => null, 'household_size_partial' => false];
        }

        $stats = DB::query()
            ->fromSub((clone $query)->select('residents.household_id')->distinct(), 'filtered_households')
            ->join('households', 'households.id', '=', 'filtered_households.household_id')
            ->selectRaw('COALESCE(SUM(households.source_household_size), 0) as total_household_size')
            ->selectRaw('SUM(CASE WHEN households.source_household_size IS NULL THEN 1 ELSE 0 END) as households_missing_size')
            ->first();
        $missing = (int) ($stats->households_missing_size ?? 0);

        return [
            'total_household_size' => (int) ($stats->total_household_size ?? 0),
            'households_missing_size' => $missing,
            'household_size_partial' => $missing > 0,
        ];
    }

    private function reportCountsFor($residents, ?int $incidentId): array
    {
        $counts = [];
        $residentIds = $residents->pluck('id')->filter()->all();
        if ($residentIds === []) {
            return $counts;
        }

        if (Schema::hasTable('account_resident_links') && Schema::hasTable('resident_incident_reports')) {
            $account = DB::table('account_resident_links as links')
                ->join('resident_incident_reports as reports', 'reports.resident_user_id', '=', 'links.user_id')
                ->whereIn('links.resident_id', $residentIds)
                ->when($incidentId, fn ($query) => $query->where('reports.disaster_incident_id', $incidentId))
                ->groupBy('links.resident_id')
                ->selectRaw('links.resident_id as resident_id, COUNT(DISTINCT reports.report_reference) as aggregate')
                ->get();
            foreach ($account as $row) {
                $counts[(int) $row->resident_id] = (int) $row->aggregate;
            }
        }

        if (Schema::hasTable('households') && Schema::hasTable('barangay_report_buildings') && Schema::hasTable('barangay_reports')) {
            $householdIds = $residents->pluck('household_id')->filter()->unique()->all();
            if ($householdIds !== []) {
                $building = DB::table('households')
                    ->join('barangay_report_buildings as links', 'links.building_id', '=', 'households.building_id')
                    ->join('barangay_reports as reports', 'reports.id', '=', 'links.barangay_report_id')
                    ->whereIn('households.id', $householdIds)
                    ->whereNotNull('households.building_id')
                    ->when($incidentId, fn ($query) => $query->where('reports.disaster_incident_id', $incidentId))
                    ->when(Schema::hasColumn('barangay_reports', 'supersedes_id'), function ($query) {
                        $query->whereNotExists(function ($newer) {
                            $newer->selectRaw('1')->from('barangay_reports as newer')->whereColumn('newer.supersedes_id', 'reports.id');
                        });
                    })
                    ->groupBy('households.id')
                    ->selectRaw('households.id as household_id, COUNT(DISTINCT reports.report_reference) as aggregate')
                    ->pluck('aggregate', 'household_id');
                foreach ($residents as $resident) {
                    $counts[$resident->id] = ($counts[$resident->id] ?? 0) + (int) ($building[$resident->household_id] ?? 0);
                }
            }
        }

        return $counts;
    }

    private function applySessionAreaScope(Request $request, $query): void
    {
        $context = $request->session()->get('cdrrmo.report_context');
        if (! is_array($context) || empty($context['coordinates']) || ! Schema::hasTable('buildings')) {
            $query->whereRaw('1 = 0');

            return;
        }

        try {
            $spatial = app(PolygonSpatial::class);
            $wkt = $spatial->polygonWkt($context['coordinates']);
            $predicate = $spatial->spatialPredicate($context['spatial_rule'] ?? 'includes_boundary');
        } catch (InvalidArgumentException) {
            $query->whereRaw('1 = 0');

            return;
        }

        $source = MapSourceLocation::buildingSqlConstraint('buildings');
        $query->whereHas('household.building', function ($building) use ($wkt, $predicate, $source) {
            $building->whereRaw(
                "{$source} AND {$predicate}(buildings.geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))",
                [$wkt],
            );
        });
    }

    private function validateInclusion(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'incident_id' => ['required', 'integer'],
            'resident_ids' => ['required', 'array', 'min:1', 'max:200'],
            'resident_ids.*' => ['integer'],
            'inclusion_basis' => ['required', Rule::in(['manual_review', 'selected_area', 'authorized_report'])],
            'reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! Schema::hasTable('disaster_incidents') || ! DB::table('disaster_incidents')->where('id', $data['incident_id'])->exists()) {
            return response()->json(['message' => 'Choose a valid incident before adding residents.'], 422);
        }

        if ($data['inclusion_basis'] === 'selected_area') {
            $context = $request->session()->get('cdrrmo.report_context');
            if (! is_array($context) || empty($context['coordinates'])) {
                return response()->json(['message' => 'Draw or select an area before using that inclusion basis.'], 422);
            }
        }

        $residents = Resident::query()->with('household')->whereIn('id', $data['resident_ids'])->where('residency_status', 'active')->get();
        if ($residents->count() !== count(array_unique($data['resident_ids']))) {
            return response()->json(['message' => 'One or more residents are not active registry records.'], 422);
        }

        if ($data['inclusion_basis'] === 'authorized_report') {
            if (! filled($data['reference'] ?? null) || ! $this->reportCoversResidents($data['reference'], $residents)) {
                return response()->json(['message' => 'The report reference must match a building linked to each selected resident.'], 422);
            }
        }

        return [
            'incident_id' => (int) $data['incident_id'],
            'basis' => $data['inclusion_basis'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'residents' => $residents,
        ];
    }

    private function reportCoversResidents(string $reference, $residents): bool
    {
        if (! Schema::hasTable('barangay_reports') || ! Schema::hasTable('barangay_report_buildings')) {
            return false;
        }

        $reportIds = DB::table('barangay_reports')->where('report_reference', $reference)->pluck('id');
        if (Schema::hasTable('field_assessments')) {
            $linked = DB::table('field_assessments')->where('assessment_reference', $reference)->pluck('barangay_report_id');
            $reportIds = $reportIds->merge($linked)->unique();
        }
        if ($reportIds->isEmpty()) {
            return false;
        }

        $buildingIds = DB::table('barangay_report_buildings')->whereIn('barangay_report_id', $reportIds)->pluck('building_id');

        return $residents->every(fn (Resident $resident) => $resident->household?->building_id && $buildingIds->contains($resident->household->building_id));
    }

    private function duplicateMatches(int $householdId, string $first, string $last)
    {
        return Resident::query()
            ->where('household_id', $householdId)
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower(trim($first))])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower(trim($last))])
            ->get(['id', 'registry_reference', 'residency_status'])
            ->map(fn (Resident $resident) => [
                'id' => $resident->id,
                'registry_reference' => $resident->registry_reference,
                'residency_status' => $resident->residency_status,
            ])
            ->values();
    }

    private function nextReference(): string
    {
        do {
            $reference = 'MAPR-RES-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        } while (Resident::query()->where('registry_reference', $reference)->exists());

        return $reference;
    }

    private function pointsFor(array $buildingIds): array
    {
        $buildingIds = array_values(array_unique(array_filter($buildingIds)));
        if ($buildingIds === [] || ! Schema::hasTable('buildings') || ! Schema::hasColumn('buildings', 'geographic_point')) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($buildingIds), '?'));
            $rows = DB::select(
                "SELECT id, ST_Longitude(geographic_point) AS lng, ST_Latitude(geographic_point) AS lat FROM buildings WHERE id IN ({$placeholders})",
                $buildingIds,
            );
        } catch (\Throwable) {
            return [];
        }

        $points = [];
        foreach ($rows as $row) {
            $lng = $row->lng !== null ? (float) $row->lng : null;
            $lat = $row->lat !== null ? (float) $row->lat : null;
            if (MapSourceLocation::isValidCoordinate($lng, $lat)) {
                $points[(int) $row->id] = ['lng' => $lng, 'lat' => $lat];
            }
        }

        return $points;
    }

    private function serialize(Resident $resident, bool $detailed = false, ?int $incidentId = null, array $points = [], int $reportCount = 0): array
    {
        $household = $resident->household;
        $building = $household?->building;
        $point = $building?->id ? ($points[$building->id] ?? null) : null;
        $studyCodes = collect(config('mapr_reference.barangays', []))->pluck('barangay_code');
        $inStudy = $household?->barangay?->barangay_code === null || $studyCodes->contains($household->barangay->barangay_code);
        $reason = MapSourceLocation::unavailableReason($building !== null, $point['lng'] ?? null, $point['lat'] ?? null, $inStudy);
        $plain = SourceName::plain($resident->first_name, $resident->middle_name, $resident->last_name);

        $potentiallyAffected = null;
        if ($incidentId && Schema::hasTable('incident_resident_inclusions')) {
            $potentiallyAffected = IncidentResidentInclusion::query()
                ->where('resident_id', $resident->id)
                ->where('disaster_incident_id', $incidentId)
                ->where('status', 'active')
                ->exists();
        }

        $payload = [
            'id' => $resident->id,
            'registry_reference' => $resident->registry_reference,
            'display_name' => $plain !== '' ? $plain : 'Name unavailable',
            'residency_status' => $resident->residency_status,
            'barangay' => $household?->barangay?->barangay_name,
            'barangay_id' => $household?->barangay_id,
            'household_id' => $household?->id,
            'household_reference' => $household?->household_reference,
            'building_id' => $building?->id,
            'building_reference' => $building?->building_reference,
            'map_available' => $reason === null,
            'map_limitation' => MapSourceLocation::reasonMessage($reason),
            'potentially_affected' => $potentiallyAffected,
            'report_count' => $reportCount,
            'updated_at' => $resident->updated_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $payload;
        }

        $linkedCount = $household ? (int) $household->residents()->where('residency_status', 'active')->count() : 0;
        $members = [];
        if ($household && $household->relationLoaded('residents')) {
            $members = $household->residents->map(function ($member) use ($resident) {
                $name = SourceName::plain($member->first_name, $member->middle_name, $member->last_name);

                return [
                    'id' => $member->id,
                    'registry_reference' => $member->registry_reference,
                    'display_name' => $name !== '' ? $name : 'Name unavailable',
                    'current' => (int) $member->id === (int) $resident->id,
                ];
            })->values()->all();
        }
        $payload['first_name'] = $resident->first_name;
        $payload['middle_name'] = $resident->middle_name;
        $payload['last_name'] = $resident->last_name;
        $payload['sex'] = $resident->sex;
        $payload['birth_date'] = $resident->birth_date?->format('Y-m-d');
        $payload['record_source'] = $this->safeSource($resident->record_source);
        $payload['household'] = $household ? [
            'source_household_size' => $household->source_household_size ?? null,
            'linked_resident_count' => $linkedCount,
            'roster_incomplete' => $household->source_household_size !== null && (int) $household->source_household_size > $linkedCount,
            'family_count' => $household->source_nuclear_families,
            'members' => $members,
        ] : null;
        $payload['longitude'] = $reason === null ? $point['lng'] : null;
        $payload['latitude'] = $reason === null ? $point['lat'] : null;

        return $payload;
    }

    private function historyFor(Resident $resident): array
    {
        $events = [];
        if (Schema::hasTable('activity_events')) {
            $events = DB::table('activity_events')
                ->where('subject_type', 'resident')
                ->where('subject_id', $resident->id)
                ->orderByDesc('id')
                ->limit(20)
                ->get(['action_type', 'summary', 'created_at'])
                ->map(fn ($row) => [
                    'action' => $row->action_type,
                    'summary' => $row->summary,
                    'created_at' => $row->created_at,
                ])
                ->all();
        }

        return $events;
    }

    private function accountReports(Resident $resident, ?int $incidentId = null): array
    {
        if (! Schema::hasTable('account_resident_links') || ! Schema::hasTable('resident_incident_reports')) {
            return [];
        }

        $userId = DB::table('account_resident_links')->where('resident_id', $resident->id)->value('user_id');
        if (! $userId) {
            return [];
        }

        return DB::table('resident_incident_reports as r')
            ->join('disaster_incidents as i', 'i.id', '=', 'r.disaster_incident_id')
            ->where('r.resident_user_id', $userId)
            ->when($incidentId, fn ($query) => $query->where('r.disaster_incident_id', $incidentId))
            ->orderByDesc('r.submitted_at')
            ->get(['r.report_reference', 'r.report_status', 'r.submitted_at', 'i.incident_name'])
            ->map(fn ($row) => [
                'report_reference' => $row->report_reference,
                'report_type' => 'Resident incident report',
                'incident' => $row->incident_name,
                'relationship' => 'Submitted by the linked account',
                'status' => $row->report_status,
                'prepared_at' => $row->submitted_at,
                'open_path' => null,
            ])
            ->all();
    }

    private function buildingReports(Resident $resident, ?int $incidentId = null): array
    {
        $buildingId = $resident->household?->building_id;
        if (! $buildingId || ! Schema::hasTable('barangay_report_buildings') || ! Schema::hasTable('barangay_reports')) {
            return [];
        }

        $reports = DB::table('barangay_report_buildings as brb')
            ->join('barangay_reports as br', 'br.id', '=', 'brb.barangay_report_id')
            ->join('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
            ->where('brb.building_id', $buildingId)
            ->when($incidentId, fn ($query) => $query->where('br.disaster_incident_id', $incidentId))
            ->get(['br.id', 'br.report_reference', 'br.report_status', 'br.updated_at', 'i.incident_name'])
            ->map(fn ($row) => [
                'report_reference' => $row->report_reference,
                'report_type' => 'Barangay report',
                'incident' => $row->incident_name,
                'relationship' => 'Linked to this building',
                'status' => $row->report_status,
                'prepared_at' => $row->updated_at,
                'open_path' => $this->reportOpenPath('barangay', (int) $row->id),
            ])
            ->all();

        if (! Schema::hasTable('field_assessments')) {
            return $reports;
        }

        $field = DB::table('field_assessments as fa')
            ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
            ->join('barangay_report_buildings as brb', 'brb.barangay_report_id', '=', 'br.id')
            ->join('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
            ->where('brb.building_id', $buildingId)
            ->when($incidentId, fn ($query) => $query->where('br.disaster_incident_id', $incidentId))
            ->get(['fa.id', 'fa.assessment_reference', 'fa.assessment_status', 'fa.updated_at', 'i.incident_name'])
            ->map(fn ($row) => [
                'report_reference' => $row->assessment_reference,
                'report_type' => 'Field assessment',
                'incident' => $row->incident_name,
                'relationship' => 'Linked to this building',
                'status' => $row->assessment_status,
                'prepared_at' => $row->updated_at,
                'open_path' => $this->reportOpenPath('field', (int) $row->id),
            ])
            ->all();

        return array_merge($reports, $field);
    }

    private function estimateReports(Resident $resident, ?int $incidentId = null): array
    {
        if (! Schema::hasTable('impact_estimate_residents') || ! Schema::hasTable('resident_impact_estimates')) {
            return [];
        }

        $rows = DB::table('impact_estimate_residents as ier')
            ->join('resident_impact_estimates as rie', 'rie.id', '=', 'ier.resident_impact_estimate_id')
            ->join('disaster_incidents as i', 'i.id', '=', 'rie.disaster_incident_id')
            ->leftJoin('consolidated_reports as cr', 'cr.resident_impact_estimate_id', '=', 'rie.id')
            ->where('ier.resident_id', $resident->id)
            ->when($incidentId, fn ($query) => $query->where('rie.disaster_incident_id', $incidentId))
            ->get(['rie.id', 'rie.generated_at', 'i.incident_name', 'cr.id as consolidated_id', 'cr.report_reference', 'cr.report_status', 'cr.updated_at']);

        return $rows->map(function ($row) {
            $reference = $row->report_reference ?: 'Estimate '.$row->id;

            return [
                'report_reference' => $reference,
                'report_type' => $row->report_reference ? 'Consolidated report' : 'Impact estimate snapshot',
                'incident' => $row->incident_name,
                'relationship' => 'Stored estimate membership',
                'status' => $row->report_status ?: 'snapshot',
                'prepared_at' => $row->updated_at ?: $row->generated_at,
                'open_path' => $row->consolidated_id ? $this->reportOpenPath('consolidated', (int) $row->consolidated_id) : null,
            ];
        })->all();
    }

    private function safeSource(?string $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '' || str_contains($text, '/') || str_contains($text, '\\')) {
            return null;
        }

        return $text;
    }
}
