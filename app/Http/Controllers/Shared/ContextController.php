<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Services\AssignmentScope;
use App\Services\ReportWorkflow;
use App\Support\MapSourceLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContextController extends Controller
{
    public function __construct(
        private readonly AssignmentScope $scope,
        private readonly ReportWorkflow $workflow,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $ids = $this->scope->ids($request);
        $user = $request->user();
        $role = $user->role?->code;
        $barangays = $ids === []
            ? collect()
            : DB::table('barangays')->whereIn('id', $ids)->orderBy('barangay_name')->get(['id', 'barangay_code', 'barangay_name']);

        $cards = $role === 'bdrrm'
            ? $this->bdrrmCards($ids)
            : $this->assessorCards($user->id, $ids);

        return response()->json([
            'role' => $role,
            'barangays' => $barangays->map(fn ($row) => [
                'id' => $row->id,
                'code' => $row->barangay_code,
                'name' => $row->barangay_name,
            ])->values(),
            'cards' => $cards,
            'totals' => [
                'buildings' => $this->countIn('buildings', 'barangay_id', $ids),
                'households' => $this->countIn('households', 'barangay_id', $ids),
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $scoped = $this->scope->ids($request);
        $filtered = $scoped;
        if ($request->filled('barangay_id')) {
            $filtered = array_values(array_intersect($scoped, [$request->integer('barangay_id')]));
        }
        $userId = (int) $request->user()->id;
        $incidentId = $request->filled('incident_id') ? $request->integer('incident_id') : null;
        $queueStatus = (string) $request->input('assessment_status', '');
        if (! in_array($queueStatus, ['draft', 'review', 'returned', 'signed', 'submitted'], true)) {
            $queueStatus = 'review';
        }

        return response()->json([
            'barangays' => $this->barangayPayload($scoped),
            'incidents' => $this->incidents($request)->getData(true)['data'] ?? [],
            'cards' => $this->assessorCards($userId, $filtered, $request),
            'mapped_buildings' => $this->mappedBuildingCount($filtered),
            'overview' => $this->barangayOverview($scoped),
            'review_queue' => $this->reviewQueue($request, $userId, $filtered, $incidentId, $queueStatus),
            'recent_activity' => $this->recentAssessments($request, $userId, $filtered, $incidentId),
            'resume_draft' => $this->resumeDraft($userId),
            'registry_note' => 'Resident totals follow the registry filters. The date range applies to report and assessment counts.',
        ]);
    }

    public function navigation(Request $request): JsonResponse
    {
        $ids = $this->scope->ids($request);
        $user = $request->user();

        if ($user->role?->code === 'bdrrm') {
            return response()->json([
                'drafts' => $this->reportCount($ids, ['draft']),
                'returned' => $this->reportCount($ids, ['returned']),
                'submitted' => $this->reportCount($ids, ['review', 'signed', 'submitted']),
                'submissions' => $this->submissionCount($ids),
            ]);
        }

        return response()->json([
            'barangay_reports' => $this->assignedReviewCount($user->id, $ids),
            'drafts' => $this->assessmentCount($user->id, ['draft']),
            'returned' => $this->assessmentCount($user->id, ['returned']),
            'submitted' => $this->assessmentCount($user->id, ['submitted']),
            'field_assessments' => $this->assessmentCount($user->id, ['draft', 'returned', 'signed', 'review']),
        ]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $ids = $this->scope->ids($request);
        if ($ids === [] || ! Schema::hasTable('disaster_incidents')) {
            return response()->json(['data' => []]);
        }

        $query = DB::table('disaster_incidents')->orderByDesc('occurred_at');
        if (Schema::hasTable('disaster_barangays')) {
            $query->whereExists(function ($inner) use ($ids) {
                $inner->selectRaw('1')
                    ->from('disaster_barangays')
                    ->whereColumn('disaster_barangays.disaster_incident_id', 'disaster_incidents.id')
                    ->whereIn('disaster_barangays.barangay_id', $ids);
            });
        }

        $rows = $query->get();
        $barangayNames = collect();
        if ($rows->isNotEmpty() && Schema::hasTable('disaster_barangays') && Schema::hasTable('barangays')) {
            $barangayNames = DB::table('disaster_barangays')
                ->join('barangays', 'barangays.id', '=', 'disaster_barangays.barangay_id')
                ->whereIn('disaster_barangays.disaster_incident_id', $rows->pluck('id'))
                ->whereIn('disaster_barangays.barangay_id', $ids)
                ->orderBy('barangays.barangay_name')
                ->get(['disaster_barangays.disaster_incident_id', 'barangays.barangay_name'])
                ->groupBy('disaster_incident_id');
        }

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'incident_name' => $row->incident_name,
                'incident_reference' => $row->incident_reference ?? null,
                'incident_status' => $row->incident_status,
                'occurred_at' => $row->occurred_at,
                'barangay_name' => $barangayNames->get($row->id, collect())->pluck('barangay_name')->implode(', '),
            ])->values(),
        ]);
    }

    public function residents(Request $request): JsonResponse
    {
        $ids = $this->scope->ids($request);
        if ($ids === [] || ! Schema::hasTable('residents')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $query = DB::table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->whereIn('households.barangay_id', $ids)
            ->where('residents.residency_status', 'active')
            ->orderBy('residents.last_name')
            ->select('residents.id', 'residents.first_name', 'residents.last_name', 'residents.registry_reference', 'households.barangay_id', 'households.household_reference');

        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage()],
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->display_name,
            'email' => $user->email,
            'job_title' => $user->job_title,
            'role' => $user->role?->name,
            'role_code' => $user->role?->code,
            'signing_capacity' => $this->signingCapacity($user),
            'signature' => $this->currentSignature($user->id),
        ]);
    }

    private function bdrrmCards(array $ids): array
    {
        return [
            ['key' => 'submissions', 'label' => 'Resident submissions', 'count' => $this->submissionCount($ids)],
            ['key' => 'drafts', 'label' => 'Draft reports', 'count' => $this->reportCount($ids, ['draft'])],
            ['key' => 'returned', 'label' => 'Returned for correction', 'count' => $this->reportCount($ids, ['returned'])],
            ['key' => 'submitted', 'label' => 'Submitted reports', 'count' => $this->reportCount($ids, ['review', 'signed', 'submitted'])],
        ];
    }

    private function assessorCards(int $userId, array $ids, ?Request $request = null): array
    {
        return [
            ['key' => 'review', 'label' => 'Barangay reports awaiting review', 'count' => $this->assignedReviewCount($userId, $ids, $request)],
            ['key' => 'drafts', 'label' => 'Field assessments in draft', 'count' => $this->assessmentCount($userId, ['draft'], $request)],
            ['key' => 'returned', 'label' => 'Returned for correction', 'count' => $this->assessmentCount($userId, ['returned'], $request)],
            ['key' => 'submitted', 'label' => 'Submitted field assessments', 'count' => $this->assessmentCount($userId, ['submitted'], $request)],
        ];
    }

    private function reportCount(array $ids, array $statuses): int
    {
        if ($ids === [] || ! Schema::hasTable('barangay_reports')) {
            return 0;
        }

        return DB::table('barangay_reports')->whereIn('barangay_id', $ids)->whereIn('report_status', $statuses)->count();
    }

    private function submissionCount(array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable('resident_incident_reports')) {
            return 0;
        }

        return DB::table('resident_incident_reports')
            ->whereIn('barangay_id', $ids)
            ->where('report_status', '!=', 'draft')
            ->count();
    }

    private function assessmentCount(int $userId, array $statuses, ?Request $request = null): int
    {
        if (! Schema::hasTable('field_assessments')) {
            return 0;
        }

        $query = DB::table('field_assessments')
            ->where('assigned_assessor_id', $userId)
            ->whereIn('assessment_status', $statuses);
        $this->applyPeriod($query, $request, 'updated_at');

        return $query->count();
    }

    private function successorCount(int $userId): int
    {
        if (! Schema::hasTable('field_assessments') || ! Schema::hasColumn('field_assessments', 'supersedes_id')) {
            return 0;
        }

        return DB::table('field_assessments')
            ->where('assigned_assessor_id', $userId)
            ->whereNotNull('supersedes_id')
            ->whereIn('assessment_status', ['draft', 'returned'])
            ->count();
    }

    private function assignedReviewCount(int $userId, array $ids, ?Request $request = null): int
    {
        if (! Schema::hasTable('barangay_reports')) {
            return 0;
        }

        $query = DB::table('barangay_reports')
            ->where('report_status', 'review')
            ->where(function ($inner) use ($userId, $ids) {
                $applied = false;
                if ($ids !== []) {
                    $inner->whereIn('barangay_id', $ids);
                    $applied = true;
                }
                if (Schema::hasTable('field_assessments')) {
                    $method = $applied ? 'orWhereIn' : 'whereIn';
                    $inner->{$method}('id', function ($assigned) use ($userId) {
                        $assigned->select('barangay_report_id')->from('field_assessments')->where('assigned_assessor_id', $userId);
                    });
                } elseif (! $applied) {
                    $inner->whereRaw('1 = 0');
                }
            });
        $this->applyPeriod($query, $request, Schema::hasColumn('barangay_reports', 'submitted_at') ? 'submitted_at' : 'updated_at');

        return $query->count();
    }

    private function applyPeriod($query, ?Request $request, string $column): void
    {
        if ($request === null) {
            return;
        }
        if ($request->filled('period_start')) {
            $query->whereDate($column, '>=', $request->input('period_start'));
        }
        if ($request->filled('period_end')) {
            $query->whereDate($column, '<=', $request->input('period_end'));
        }
    }

    private function barangayPayload(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('barangays')) {
            return [];
        }

        return DB::table('barangays')
            ->whereIn('id', $ids)
            ->orderBy('barangay_name')
            ->get(['id', 'barangay_code', 'barangay_name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'code' => $row->barangay_code,
                'name' => $row->barangay_name,
            ])
            ->values()
            ->all();
    }

    private function mappedBuildingCount(array $ids): ?int
    {
        if ($ids === [] || ! Schema::hasTable('buildings') || ! Schema::hasColumn('buildings', 'geographic_point')) {
            return null;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $constraint = MapSourceLocation::buildingSqlConstraint('buildings');

            return (int) DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM buildings WHERE barangay_id IN ({$placeholders}) AND {$constraint}",
                $ids,
            )->aggregate;
        } catch (\Throwable) {
            return null;
        }
    }

    private function barangayOverview(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('barangays')) {
            return [];
        }

        $residents = collect();
        $sizes = collect();
        if (Schema::hasTable('residents') && Schema::hasTable('households')) {
            $residents = DB::table('residents')
                ->join('households', 'households.id', '=', 'residents.household_id')
                ->where('residents.residency_status', 'active')
                ->whereIn('households.barangay_id', $ids)
                ->groupBy('households.barangay_id')
                ->selectRaw('households.barangay_id as barangay_id, COUNT(*) as aggregate')
                ->pluck('aggregate', 'barangay_id');
            if (Schema::hasColumn('households', 'source_household_size')) {
                $sizes = DB::table('households')
                    ->whereIn('barangay_id', $ids)
                    ->where('household_status', 'active')
                    ->whereExists(function ($query) {
                        $query->selectRaw('1')
                            ->from('residents')
                            ->whereColumn('residents.household_id', 'households.id')
                            ->where('residents.residency_status', 'active');
                    })
                    ->groupBy('barangay_id')
                    ->selectRaw('barangay_id, COALESCE(SUM(source_household_size), 0) as population, SUM(CASE WHEN source_household_size IS NULL THEN 1 ELSE 0 END) as missing')
                    ->get()
                    ->keyBy('barangay_id');
            }
        }

        $mapped = [];
        $mappedTotal = $this->mappedBuildingCount($ids);
        if ($mappedTotal !== null) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $constraint = MapSourceLocation::buildingSqlConstraint('buildings');
            foreach (DB::select(
                "SELECT barangay_id, COUNT(*) AS aggregate FROM buildings WHERE barangay_id IN ({$placeholders}) AND {$constraint} GROUP BY barangay_id",
                $ids,
            ) as $row) {
                $mapped[(int) $row->barangay_id] = (int) $row->aggregate;
            }
        }

        $waiting = Schema::hasTable('barangay_reports')
            ? DB::table('barangay_reports')
                ->whereIn('barangay_id', $ids)
                ->where('report_status', 'review')
                ->groupBy('barangay_id')
                ->selectRaw('barangay_id, COUNT(*) as aggregate')
                ->pluck('aggregate', 'barangay_id')
            : collect();

        return DB::table('barangays')
            ->whereIn('id', $ids)
            ->orderBy('barangay_name')
            ->get(['id', 'barangay_name'])
            ->map(function ($row) use ($residents, $sizes, $mapped, $mappedTotal, $waiting) {
                $size = $sizes->get($row->id);

                return [
                    'id' => $row->id,
                    'name' => $row->barangay_name,
                    'registered_residents' => (int) ($residents[$row->id] ?? 0),
                    'household_population' => $size ? (int) $size->population : null,
                    'households_missing_size' => $size ? (int) $size->missing : null,
                    'mapped_buildings' => $mappedTotal === null ? null : (int) ($mapped[(int) $row->id] ?? 0),
                    'reports_awaiting_review' => (int) ($waiting[$row->id] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function reviewQueue(Request $request, int $userId, array $ids, ?int $incidentId, string $status): array
    {
        if (! Schema::hasTable('barangay_reports') || ($ids === [] && $status === 'review')) {
            return [];
        }

        $fieldQueue = in_array($status, ['draft', 'returned', 'signed', 'submitted'], true);
        if ($fieldQueue) {
            return $this->assessmentQueue($request, $userId, $ids, $incidentId, $status);
        }

        $query = DB::table('barangay_reports as br')
            ->leftJoin('barangays', 'barangays.id', '=', 'br.barangay_id')
            ->leftJoin('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
            ->leftJoin('users as preparer', 'preparer.id', '=', 'br.prepared_by')
            ->where('br.report_status', 'review')
            ->whereIn('br.barangay_id', $ids === [] ? [0] : $ids)
            ->orderBy('br.submitted_at')
            ->orderBy('br.updated_at');
        if ($incidentId) {
            $query->where('br.disaster_incident_id', $incidentId);
        }
        $this->applyPeriod($query, $request, 'br.submitted_at');

        $drafts = Schema::hasTable('field_assessments')
            ? DB::table('field_assessments')
                ->where('assigned_assessor_id', $userId)
                ->whereIn('assessment_status', ['draft', 'returned'])
                ->pluck('id', 'barangay_report_id')
            : collect();

        return $query->limit(12)->get([
            'br.id',
            'br.report_reference',
            'br.report_status',
            'br.disaster_incident_id',
            'br.submitted_at',
            'br.updated_at',
            'barangays.barangay_name',
            'i.incident_name',
            'preparer.first_name',
            'preparer.middle_name',
            'preparer.last_name',
        ])->map(function ($row) use ($drafts) {
            $building = Schema::hasTable('barangay_report_buildings') && Schema::hasTable('buildings')
                ? DB::table('barangay_report_buildings')
                    ->join('buildings', 'buildings.id', '=', 'barangay_report_buildings.building_id')
                    ->where('barangay_report_id', $row->id)
                    ->first(['buildings.building_reference'])
                : null;

            return [
                'id' => $row->id,
                'kind' => 'barangay_report',
                'reference' => $row->report_reference,
                'incident' => $row->incident_name,
                'incident_id' => $row->disaster_incident_id,
                'barangay' => $row->barangay_name,
                'submitted_by' => $this->personName($row),
                'received_at' => $row->submitted_at ?: $row->updated_at,
                'status' => $row->report_status,
                'label' => $this->workflow->label($row->report_status),
                'building_reference' => $building->building_reference ?? null,
                'draft_id' => $drafts[$row->id] ?? null,
            ];
        })->all();
    }

    private function assessmentQueue(Request $request, int $userId, array $ids, ?int $incidentId, string $status): array
    {
        if (! Schema::hasTable('field_assessments')) {
            return [];
        }

        $query = DB::table('field_assessments as fa')
            ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'br.barangay_id')
            ->leftJoin('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
            ->leftJoin('users as preparer', 'preparer.id', '=', 'br.prepared_by')
            ->where('fa.assigned_assessor_id', $userId)
            ->where('fa.assessment_status', $status)
            ->when($ids !== [], fn ($inner) => $inner->whereIn('br.barangay_id', $ids))
            ->orderByDesc('fa.updated_at');
        if ($incidentId) {
            $query->where('br.disaster_incident_id', $incidentId);
        }
        $this->applyPeriod($query, $request, 'fa.updated_at');

        return $query->limit(12)->get([
            'fa.id',
            'fa.assessment_reference',
            'fa.assessment_status',
            'fa.updated_at',
            'br.disaster_incident_id',
            'barangays.barangay_name',
            'i.incident_name',
            'preparer.first_name',
            'preparer.middle_name',
            'preparer.last_name',
        ])->map(fn ($row) => [
            'id' => $row->id,
            'kind' => 'field_assessment',
            'reference' => $row->assessment_reference,
            'incident' => $row->incident_name,
            'incident_id' => $row->disaster_incident_id,
            'barangay' => $row->barangay_name,
            'submitted_by' => $this->personName($row),
            'received_at' => $row->updated_at,
            'status' => $row->assessment_status,
            'label' => $this->workflow->label($row->assessment_status),
            'building_reference' => null,
            'draft_id' => in_array($row->assessment_status, ['draft', 'returned'], true) ? $row->id : null,
        ])->all();
    }

    private function recentAssessments(Request $request, int $userId, array $ids, ?int $incidentId): array
    {
        if (! Schema::hasTable('field_assessments') || ! Schema::hasTable('barangay_reports')) {
            return [];
        }

        $query = DB::table('field_assessments as fa')
            ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'br.barangay_id')
            ->where('fa.assigned_assessor_id', $userId)
            ->when($ids !== [], fn ($inner) => $inner->whereIn('br.barangay_id', $ids))
            ->orderByDesc('fa.updated_at');
        if ($incidentId) {
            $query->where('br.disaster_incident_id', $incidentId);
        }
        $this->applyPeriod($query, $request, 'fa.updated_at');

        return $query->limit(6)->get([
            'fa.assessment_reference',
            'fa.assessment_status',
            'fa.updated_at',
            'barangays.barangay_name',
        ])->map(fn ($row) => [
            'reference' => $row->assessment_reference,
            'status' => $this->workflow->label($row->assessment_status),
            'barangay' => $row->barangay_name,
            'updated_at' => $row->updated_at,
        ])->all();
    }

    private function resumeDraft(int $userId): ?array
    {
        if (! Schema::hasTable('field_assessments')) {
            return null;
        }

        $draft = DB::table('field_assessments')
            ->where('assigned_assessor_id', $userId)
            ->where('assessment_status', 'draft')
            ->orderByDesc('updated_at')
            ->first(['id', 'assessment_reference']);

        return $draft ? ['id' => $draft->id, 'reference' => $draft->assessment_reference] : null;
    }

    private function personName(object $row): string
    {
        $name = trim(implode(' ', array_filter([
            $row->first_name ?? null,
            $row->middle_name ?? null,
            $row->last_name ?? null,
        ])));

        return $name !== '' ? $name : 'Not recorded';
    }

    private function countIn(string $table, string $column, array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->whereIn($column, $ids)->count();
    }

    private function signingCapacity($user): ?string
    {
        $email = strtolower((string) $user->email);
        if ($email === 'mark.merillo@mapr.test') {
            return 'verified_by';
        }
        if ($email === 'lovy.dedicatoria@mapr.test') {
            return 'approved_by';
        }

        if (Schema::hasTable('barangays') && $user->barangay_id) {
            $code = (string) DB::table('barangays')->where('id', $user->barangay_id)->value('barangay_code');
            $captain = config('mapr_reference.barangay_captains.'.$code);
            if (is_string($captain) && strcasecmp(trim($user->display_name), trim($captain)) === 0) {
                return 'barangay_signer';
            }
        }

        if (Schema::hasTable('user_barangay_assignments') && Schema::hasTable('barangays')) {
            $assigned = DB::table('user_barangay_assignments')
                ->join('barangays', 'barangays.id', '=', 'user_barangay_assignments.barangay_id')
                ->where('user_barangay_assignments.user_id', $user->id)
                ->whereNull('user_barangay_assignments.revoked_at')
                ->value('barangays.barangay_code');
            $captain = config('mapr_reference.barangay_captains.'.$assigned);
            if (is_string($captain) && strcasecmp(trim($user->display_name), trim($captain)) === 0) {
                return 'barangay_signer';
            }
        }

        return null;
    }

    private function currentSignature(int $userId): ?array
    {
        if (! Schema::hasTable('user_signature_assets')) {
            return null;
        }

        $asset = DB::table('user_signature_assets')->where('user_id', $userId)->where('is_current', true)->first();
        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->id,
            'width' => $asset->width,
            'height' => $asset->height,
            'label' => 'Electronic-signature image',
        ];
    }
}
