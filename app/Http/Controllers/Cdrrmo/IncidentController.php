<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IncidentController extends Controller
{
    private const DISPLAY_TIMEZONE = 'Asia/Manila';

    public function __construct(private ActivityLogger $activity) {}

    public function summary(Request $request): JsonResponse
    {
        $filtersApplied = $request->filled('search') || $request->filled('disaster_type_id') || $request->filled('barangay_id');
        $counts = ['total' => 0, 'active' => 0, 'closed' => 0];

        if (Schema::hasTable('disaster_incidents')) {
            $query = $this->filteredQuery($request, false);
            $counts['total'] = (clone $query)->count();
            $counts['active'] = (clone $query)->where('i.incident_status', 'active')->count();
            $counts['closed'] = (clone $query)->where('i.incident_status', 'closed')->count();
        }

        return response()->json([
            'counts' => $counts,
            'filters_applied' => $filtersApplied,
            'scope_note' => $filtersApplied
                ? 'Counts follow the search, disaster type, and barangay filters. They are not limited to this page.'
                : 'Counts include every incident, not only this page.',
            'disaster_types' => $this->disasterTypes(),
            'barangays' => $this->studyBarangays(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('disaster_incidents')) {
            return response()->json([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 25],
            ]);
        }

        $query = $this->filteredQuery($request, true)->orderByDesc('i.occurred_at')->orderByDesc('i.id');

        if ($request->filled('per_page')) {
            $perPage = in_array($request->integer('per_page'), [10, 25, 50], true) ? $request->integer('per_page') : 25;
            $page = $query->paginate($perPage)->withQueryString();
            $rows = collect($page->items());
            $meta = [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ];
        } else {
            $rows = $query->get();
            $meta = [
                'current_page' => 1,
                'last_page' => 1,
                'total' => $rows->count(),
                'per_page' => max(1, $rows->count()),
            ];
        }

        return response()->json([
            'data' => $this->presentMany($rows),
            'meta' => $meta,
        ]);
    }

    public function show(int $incident): JsonResponse
    {
        $row = $this->findIncident($incident);
        if (! $row) {
            return response()->json(['message' => 'Incident not found.'], 404);
        }

        $payload = $this->present($row, $this->barangaysFor([$row->id])->get($row->id, []));
        $payload['reports'] = $this->relatedReports($row->id);

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'incident_name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['required', 'date'],
            'disaster_type_id' => ['required', 'integer'],
            'incident_status' => ['required', 'in:active,closed'],
            'barangay_ids' => ['required', 'array', 'min:1'],
            'barangay_ids.*' => ['integer'],
            'confirm_close' => ['sometimes', 'boolean'],
        ]);

        $type = $this->findType((int) $data['disaster_type_id']);
        if (! $type) {
            return response()->json(['message' => 'Choose a disaster type from the approved list.', 'errors' => ['disaster_type_id' => ['Choose a disaster type from the approved list.']]], 422);
        }

        $barangays = $this->resolveBarangays($data['barangay_ids']);
        if ($barangays instanceof JsonResponse) {
            return $barangays;
        }

        if ($data['incident_status'] === 'closed' && ! $request->boolean('confirm_close')) {
            return response()->json(['message' => 'Confirm that closing this incident does not approve or complete its reports.'], 422);
        }

        $occurredAt = $this->fromManila($data['occurred_at']);
        $reference = $this->nextReference();

        $id = DB::transaction(function () use ($request, $data, $barangays, $occurredAt, $reference) {
            $id = DB::table('disaster_incidents')->insertGetId([
                'incident_reference' => $reference,
                'incident_name' => trim($data['incident_name']),
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'incident_status' => $data['incident_status'],
                'disaster_type_id' => (int) $data['disaster_type_id'],
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($barangays as $barangay) {
                DB::table('disaster_barangays')->insert([
                    'disaster_incident_id' => $id,
                    'barangay_id' => $barangay->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $id;
        });

        $this->activity->log($request->user(), 'incident_created', 'Created incident '.$reference.'.', 'disaster_incident', $id);

        return response()->json($this->show($id)->getData(true), 201);
    }

    public function update(Request $request, int $incident): JsonResponse
    {
        $current = $this->findIncident($incident);
        if (! $current) {
            return response()->json(['message' => 'Incident not found.'], 404);
        }

        $data = $request->validate([
            'incident_name' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['sometimes', 'required', 'date'],
            'disaster_type_id' => ['sometimes', 'required', 'integer'],
            'incident_status' => ['sometimes', 'required', 'in:active,closed'],
            'barangay_ids' => ['sometimes', 'array', 'min:1'],
            'barangay_ids.*' => ['integer'],
            'confirm_close' => ['sometimes', 'boolean'],
        ]);

        if (($data['incident_status'] ?? $current->incident_status) === 'closed' && $current->incident_status !== 'closed' && ! $request->boolean('confirm_close')) {
            return response()->json(['message' => 'Confirm that closing this incident does not approve or complete its reports.'], 422);
        }

        if (array_key_exists('disaster_type_id', $data) && ! $this->findType((int) $data['disaster_type_id'])) {
            return response()->json(['message' => 'Choose a disaster type from the approved list.', 'errors' => ['disaster_type_id' => ['Choose a disaster type from the approved list.']]], 422);
        }

        $barangays = null;
        if (array_key_exists('barangay_ids', $data)) {
            $barangays = $this->resolveBarangays($data['barangay_ids']);
            if ($barangays instanceof JsonResponse) {
                return $barangays;
            }
            $blocked = $this->blockedBarangayRemoval($incident, $barangays->pluck('id')->all());
            if ($blocked !== []) {
                return response()->json([
                    'message' => implode(' ', $blocked).' The association was kept because a report still depends on it.',
                ], 422);
            }
        }

        $changes = DB::transaction(function () use ($current, $data, $barangays) {
            $updates = [];
            $changed = [];

            if (array_key_exists('incident_name', $data) && trim($data['incident_name']) !== $current->incident_name) {
                $updates['incident_name'] = trim($data['incident_name']);
                $changed[] = 'incident_name';
            }
            if (array_key_exists('description', $data)) {
                $next = filled($data['description'] ?? null) ? trim($data['description']) : null;
                if ($next !== $current->description) {
                    $updates['description'] = $next;
                    $changed[] = 'description';
                }
            }
            if (array_key_exists('occurred_at', $data)) {
                $next = $this->fromManila($data['occurred_at'])->format('Y-m-d H:i:s');
                if (Carbon::parse($current->occurred_at, config('app.timezone'))->format('Y-m-d H:i:s') !== $next) {
                    $updates['occurred_at'] = $next;
                    $changed[] = 'occurred_at';
                }
            }
            if (array_key_exists('disaster_type_id', $data) && (int) $data['disaster_type_id'] !== (int) $current->disaster_type_id) {
                $updates['disaster_type_id'] = (int) $data['disaster_type_id'];
                $changed[] = 'disaster_type_id';
            }
            if (array_key_exists('incident_status', $data) && $data['incident_status'] !== $current->incident_status) {
                $updates['incident_status'] = $data['incident_status'];
                $changed[] = 'incident_status';
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('disaster_incidents')->where('id', $current->id)->update($updates);
            }

            if ($barangays !== null) {
                $existing = DB::table('disaster_barangays')->where('disaster_incident_id', $current->id)->pluck('barangay_id')->map(fn ($id) => (int) $id)->all();
                $next = $barangays->pluck('id')->map(fn ($id) => (int) $id)->all();
                $remove = array_values(array_diff($existing, $next));
                $add = array_values(array_diff($next, $existing));
                if ($remove !== []) {
                    DB::table('disaster_barangays')->where('disaster_incident_id', $current->id)->whereIn('barangay_id', $remove)->delete();
                    $changed[] = 'barangays';
                }
                foreach ($add as $barangayId) {
                    DB::table('disaster_barangays')->insert([
                        'disaster_incident_id' => $current->id,
                        'barangay_id' => $barangayId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $changed[] = 'barangays';
                }
            }

            return array_values(array_unique($changed));
        });

        if ($changes === []) {
            return response()->json(['message' => 'No changes to save.'], 422);
        }

        $action = in_array('incident_status', $changes, true) && ($data['incident_status'] ?? '') === 'closed'
            ? 'incident_closed'
            : (in_array('incident_status', $changes, true) ? 'incident_reopened' : 'incident_updated');
        $summary = $action === 'incident_closed'
            ? 'Closed incident '.$current->incident_reference.'. Reports were not approved or completed.'
            : ($action === 'incident_reopened'
                ? 'Reopened incident '.$current->incident_reference.'.'
                : 'Updated incident '.$current->incident_reference.' ('.implode(', ', $changes).').');
        $this->activity->log($request->user(), $action, $summary, 'disaster_incident', (int) $current->id);

        return response()->json($this->show((int) $current->id)->getData(true));
    }

    private function filteredQuery(Request $request, bool $applyStatus)
    {
        $query = DB::table('disaster_incidents as i')
            ->leftJoin('disaster_types as t', 't.id', '=', 'i.disaster_type_id')
            ->select([
                'i.id',
                'i.incident_reference',
                'i.incident_name',
                'i.description',
                'i.occurred_at',
                'i.incident_status',
                'i.disaster_type_id',
                'i.updated_at',
                't.name as disaster_type',
            ]);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('i.incident_name', 'like', '%'.$search.'%')
                    ->orWhere('i.incident_reference', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('disaster_type_id')) {
            $query->where('i.disaster_type_id', $request->integer('disaster_type_id'));
        }

        if ($applyStatus && in_array($request->input('incident_status'), ['active', 'closed'], true)) {
            $query->where('i.incident_status', $request->input('incident_status'));
        }

        if ($request->filled('barangay_id') && Schema::hasTable('disaster_barangays')) {
            $query->whereExists(function ($inner) use ($request) {
                $inner->select(DB::raw('1'))
                    ->from('disaster_barangays as db')
                    ->whereColumn('db.disaster_incident_id', 'i.id')
                    ->where('db.barangay_id', $request->integer('barangay_id'));
            });
        }

        return $query;
    }

    private function presentMany($rows): array
    {
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $barangays = $this->barangaysFor($ids);

        return $rows->map(fn ($row) => $this->present($row, $barangays->get((int) $row->id, [])))->values()->all();
    }

    private function present(object $row, array $barangays): array
    {
        $occurred = Carbon::parse($row->occurred_at, config('app.timezone'))->timezone(self::DISPLAY_TIMEZONE);
        $reference = (string) $row->incident_reference;
        $name = (string) $row->incident_name;

        return [
            'id' => (int) $row->id,
            'incident_reference' => $reference,
            'incident_name' => $name,
            'description' => $row->description,
            'disaster_type' => $row->disaster_type,
            'disaster_type_id' => $row->disaster_type_id ? (int) $row->disaster_type_id : null,
            'incident_status' => $row->incident_status,
            'occurred_at' => Carbon::parse($row->occurred_at, config('app.timezone'))->toIso8601String(),
            'occurred_at_local' => $occurred->format('Y-m-d\TH:i'),
            'occurred_at_display' => $occurred->format('j M Y, g:i A').' PHT',
            'barangays' => $barangays,
            'is_demo' => str_starts_with($reference, 'DEMO-') || str_starts_with(strtolower($name), 'demo '),
        ];
    }

    private function barangaysFor(array $incidentIds)
    {
        if ($incidentIds === [] || ! Schema::hasTable('disaster_barangays') || ! Schema::hasTable('barangays')) {
            return collect();
        }

        return DB::table('disaster_barangays as db')
            ->join('barangays as b', 'b.id', '=', 'db.barangay_id')
            ->whereIn('db.disaster_incident_id', $incidentIds)
            ->orderBy('b.barangay_name')
            ->get(['db.disaster_incident_id', 'b.id', 'b.barangay_name'])
            ->groupBy('disaster_incident_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->barangay_name,
            ])->values()->all());
    }

    private function relatedReports(int $incidentId): array
    {
        $reports = [];

        if (Schema::hasTable('barangay_reports')) {
            $reports = array_merge($reports, DB::table('barangay_reports as br')
                ->leftJoin('barangays as b', 'b.id', '=', 'br.barangay_id')
                ->where('br.disaster_incident_id', $incidentId)
                ->orderByDesc('br.updated_at')
                ->limit(20)
                ->get(['br.report_reference', 'br.report_status', 'b.barangay_name'])
                ->map(fn ($row) => [
                    'reference' => $row->report_reference,
                    'type' => 'Barangay report',
                    'context' => $row->barangay_name,
                    'status' => $row->report_status,
                    'open_path' => '/reports/barangay?reference='.urlencode($row->report_reference),
                ])->all());
        }

        if (Schema::hasTable('field_assessments') && Schema::hasTable('barangay_reports')) {
            $reports = array_merge($reports, DB::table('field_assessments as fa')
                ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
                ->where('br.disaster_incident_id', $incidentId)
                ->orderByDesc('fa.updated_at')
                ->limit(20)
                ->get(['fa.assessment_reference', 'fa.assessment_status'])
                ->map(fn ($row) => [
                    'reference' => $row->assessment_reference,
                    'type' => 'Field assessment',
                    'context' => null,
                    'status' => $row->assessment_status,
                    'open_path' => '/reports/field?reference='.urlencode($row->assessment_reference),
                ])->all());
        }

        if (Schema::hasTable('consolidated_reports')) {
            $reports = array_merge($reports, DB::table('consolidated_reports')
                ->where('disaster_incident_id', $incidentId)
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get(['report_reference', 'report_status'])
                ->map(fn ($row) => [
                    'reference' => $row->report_reference,
                    'type' => 'Consolidated report',
                    'context' => null,
                    'status' => $row->report_status,
                    'open_path' => '/reports/consolidated?reference='.urlencode($row->report_reference),
                ])->all());
        }

        return $reports;
    }

    private function findIncident(int $id): ?object
    {
        if (! Schema::hasTable('disaster_incidents')) {
            return null;
        }

        return DB::table('disaster_incidents as i')
            ->leftJoin('disaster_types as t', 't.id', '=', 'i.disaster_type_id')
            ->where('i.id', $id)
            ->first([
                'i.id',
                'i.incident_reference',
                'i.incident_name',
                'i.description',
                'i.occurred_at',
                'i.incident_status',
                'i.disaster_type_id',
                'i.updated_at',
                't.name as disaster_type',
            ]);
    }

    private function disasterTypes(): array
    {
        if (! Schema::hasTable('disaster_types')) {
            return [];
        }

        return DB::table('disaster_types')->orderBy('name')->get(['id', 'name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
            ->all();
    }

    private function studyBarangays(): array
    {
        if (! Schema::hasTable('barangays')) {
            return [];
        }

        $codes = collect(config('mapr_reference.barangays', []))->pluck('barangay_code')->filter()->values();
        $query = DB::table('barangays')->orderBy('barangay_name');
        if ($codes->isNotEmpty() && Schema::hasColumn('barangays', 'barangay_code')) {
            $query->whereIn('barangay_code', $codes->all());
        }

        return $query->get(['id', 'barangay_name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->barangay_name])
            ->all();
    }

    private function findType(int $id): ?object
    {
        if (! Schema::hasTable('disaster_types')) {
            return null;
        }

        return DB::table('disaster_types')->where('id', $id)->first();
    }

    private function resolveBarangays(array $ids): JsonResponse|\Illuminate\Support\Collection
    {
        $allowed = collect($this->studyBarangays())->keyBy('id');
        $unique = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        if ($unique->isEmpty() || $unique->contains(fn ($id) => ! $allowed->has($id))) {
            return response()->json([
                'message' => 'Select at least one study barangay.',
                'errors' => ['barangay_ids' => ['Select at least one study barangay.']],
            ], 422);
        }

        return $unique->map(fn ($id) => (object) $allowed->get($id));
    }

    private function blockedBarangayRemoval(int $incidentId, array $keepIds): array
    {
        if (! Schema::hasTable('disaster_barangays')) {
            return [];
        }

        $current = DB::table('disaster_barangays as db')
            ->join('barangays as b', 'b.id', '=', 'db.barangay_id')
            ->where('db.disaster_incident_id', $incidentId)
            ->get(['b.id', 'b.barangay_name']);

        $messages = [];
        foreach ($current as $barangay) {
            if (in_array((int) $barangay->id, $keepIds, true)) {
                continue;
            }
            $blocked = false;
            if (Schema::hasTable('barangay_reports')) {
                $blocked = DB::table('barangay_reports')
                    ->where('disaster_incident_id', $incidentId)
                    ->where('barangay_id', $barangay->id)
                    ->exists();
            }
            if (! $blocked && Schema::hasTable('resident_incident_reports')) {
                $blocked = DB::table('resident_incident_reports')
                    ->where('disaster_incident_id', $incidentId)
                    ->where('barangay_id', $barangay->id)
                    ->exists();
            }
            if ($blocked) {
                $messages[] = $barangay->barangay_name.' cannot be removed because a report is linked to this incident and barangay.';
            }
        }

        return $messages;
    }

    private function fromManila(string $value): Carbon
    {
        return Carbon::parse($value, self::DISPLAY_TIMEZONE)->timezone(config('app.timezone'));
    }

    private function nextReference(): string
    {
        do {
            $reference = 'INC-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (DB::table('disaster_incidents')->where('incident_reference', $reference)->exists());

        return $reference;
    }
}
