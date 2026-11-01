<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Bdrrm\BarangayReportController;
use App\Http\Controllers\Assessor\AssessmentController;
use App\Http\Controllers\Shared\ReportFlagController;
use App\Services\ReportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportIndexController extends Controller
{
    public function barangay(Request $request): JsonResponse
    {
        return $this->index($request, 'barangay');
    }

    public function field(Request $request): JsonResponse
    {
        return $this->index($request, 'field');
    }

    public function consolidated(Request $request): JsonResponse
    {
        return $this->index($request, 'consolidated');
    }

    public function document(Request $request, string $kind, int $id): JsonResponse
    {
        if ($kind === 'barangay') {
            return $this->reviewOnly(app(BarangayReportController::class)->show($request, $id), 'barangay');
        }
        if ($kind === 'field') {
            return $this->reviewOnly(app(AssessmentController::class)->show($request, $id), 'field');
        }

        $row = DB::table('consolidated_reports')->where('id', $id)->first();
        abort_if($row === null, 404);
        $incident = Schema::hasTable('disaster_incidents')
            ? DB::table('disaster_incidents')->where('id', $row->disaster_incident_id)->first()
            : null;
        $columns = ['signature_role', 'signed_at', 'mechanism'];
        if (Schema::hasTable('report_signatures') && Schema::hasColumn('report_signatures', 'signature_statement')) {
            $columns[] = 'signature_statement';
        }
        $signatures = Schema::hasTable('report_signatures')
            ? DB::table('report_signatures')->where('consolidated_report_id', $row->id)->get($columns)
            : collect();
        $flags = ReportFlagController::forSubject('consolidated_report', (int) $row->id);
        $authenticated = collect($signatures)->where('mechanism', 'authenticated_e_signature');
        $noted = $authenticated->contains(fn ($signature) => $signature->signature_role === 'noted_by');
        $approved = $authenticated->contains(fn ($signature) => $signature->signature_role === 'approved_by');
        $summary = trim((string) ($row->report_summary ?? '')) !== '';
        $openFlags = collect($flags)->where('status', 'open')->count();
        $user = $request->user();
        $email = strtolower((string) $user?->email);
        $canNote = in_array($email, ['lestertalan@mapr_admin.com', 'lestertalan@mapr.com'], true);
        $canApprove = $email === 'reyveen.geli@mapr.test';

        return response()->json(['data' => [
            'id' => $row->id,
            'kind' => 'consolidated',
            'reference' => $row->report_reference,
            'status' => $row->report_status,
            'label' => app(ReportWorkflow::class)->label($row->report_status),
            'title' => 'Consolidated Report',
            'findings' => $row->report_summary,
            'report_description' => $row->report_summary,
            'can_edit' => false,
            'version' => 1,
            'incident' => $incident ? [
                'id' => $incident->id,
                'reference' => $incident->incident_reference ?? null,
                'name' => $incident->incident_name ?? null,
            ] : null,
            'signatures' => $signatures,
            'timeline' => Schema::hasTable('activity_events')
                ? DB::table('activity_events')->where('subject_type', 'consolidated_report')->where('subject_id', $row->id)->orderBy('created_at')->get(['summary', 'created_at'])
                : [],
            'flags' => $flags,
            'open_flags' => $openFlags,
            'attachment' => $this->attachment('consolidated_report_id', (int) $row->id, (string) $row->report_reference),
            'checklist' => [
                ['key' => 'fields', 'label' => 'Required fields complete', 'met' => $summary, 'detail' => $summary ? 'A report summary is saved.' : 'The consolidated summary is not recorded.'],
                ['key' => 'evidence', 'label' => 'Required evidence attached', 'met' => true, 'detail' => 'A consolidated report uses its source assessments. It does not require a new photograph.'],
                ['key' => 'verification', 'label' => 'Required record links and verification complete', 'met' => $incident !== null, 'detail' => $incident ? 'The incident link is recorded.' : 'No incident is linked.'],
                ['key' => 'predecessor', 'label' => 'Required preceding signatures present', 'met' => $noted, 'detail' => $noted ? 'The noted-by signature is recorded.' : 'The noted-by signature is still required before approval.'],
                ['key' => 'authority', 'label' => 'Current user has signing authority', 'met' => $canNote || $canApprove, 'detail' => $canNote ? 'This account can sign as noted by.' : ($canApprove ? 'This account can sign as approved by.' : 'This account cannot sign a consolidated report.')],
                ['key' => 'flags', 'label' => 'No unresolved blocking flags', 'met' => $openFlags === 0, 'detail' => $openFlags === 0 ? 'No open flags.' : $openFlags.' open flag(s).'],
                ['key' => 'current', 'label' => 'Report version is current', 'met' => true, 'detail' => 'This is the current consolidated version.'],
            ],
            'photos' => [],
            'updated_at' => $row->updated_at,
            'approved' => $approved,
        ]]);
    }

    private function index(Request $request, string $type): JsonResponse
    {
        $spec = $this->spec($type);
        if (! Schema::hasTable($spec['table'])) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => 20],
                'summary' => ['total' => 0, 'awaiting_review' => 0, 'awaiting_signature' => 0, 'finalized' => 0],
                'filters' => ['incidents' => [], 'barangays' => []],
            ]);
        }

        $summaryQuery = $this->baseQuery($type);
        $query = $this->applyFilters($this->baseQuery($type), $request, $type);
        $page = max(1, $request->integer('page', 1));
        $perPage = min(50, max(1, $request->integer('per_page', 20)));
        $total = (clone $query)->count();
        $sort = $request->input('sort') === 'reference' ? $spec['reference'] : $spec['updated'];
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $rows = $query->orderBy($sort, $direction)->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $this->present($rows, $type),
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
            ],
            'summary' => $this->summary($summaryQuery, $type),
            'filters' => [
                'incidents' => $this->options($type, 'incident'),
                'barangays' => $this->options($type, 'barangay'),
            ],
        ]);
    }

    private function spec(string $type): array
    {
        return match ($type) {
            'field' => [
                'table' => 'field_assessments',
                'status' => 'fa.assessment_status',
                'reference' => 'fa.assessment_reference',
                'updated' => 'fa.updated_at',
                'id' => 'fa.id',
                'author' => 'fa.assigned_assessor_id',
                'subject' => 'field_assessment',
                'signature' => 'field_assessment_id',
                'terminal' => ['submitted'],
                'roles' => ['verified_by', 'approved_by'],
                'title' => 'fa.findings',
                'incident' => 'br.disaster_incident_id',
                'barangay' => 'b.id',
            ],
            'consolidated' => [
                'table' => 'consolidated_reports',
                'status' => 'cr.report_status',
                'reference' => 'cr.report_reference',
                'updated' => 'cr.updated_at',
                'id' => 'cr.id',
                'author' => 'cr.prepared_by',
                'subject' => 'consolidated_report',
                'signature' => 'consolidated_report_id',
                'terminal' => ['finalized'],
                'roles' => ['noted_by', 'approved_by'],
                'title' => 'cr.report_summary',
                'incident' => 'cr.disaster_incident_id',
                'barangay' => null,
            ],
            default => [
                'table' => 'barangay_reports',
                'status' => 'br.report_status',
                'reference' => 'br.report_reference',
                'updated' => 'br.updated_at',
                'id' => 'br.id',
                'author' => 'br.prepared_by',
                'subject' => 'barangay_report',
                'signature' => 'barangay_report_id',
                'terminal' => ['submitted'],
                'roles' => ['barangay_signer'],
                'title' => 'br.report_description',
                'incident' => 'br.disaster_incident_id',
                'barangay' => 'b.id',
            ],
        };
    }

    private function baseQuery(string $type)
    {
        if ($type === 'field') {
            return DB::query()->from('field_assessments as fa')
                ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
                ->join('barangays as b', 'b.id', '=', 'br.barangay_id')
                ->leftJoin('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
                ->select([
                    'fa.id',
                    'fa.assessment_reference as reference',
                    'fa.assessment_status as workflow_status',
                    'fa.findings as title_source',
                    'fa.updated_at',
                    'fa.assigned_assessor_id as author_id',
                    'br.report_reference as source_reference',
                    'br.disaster_incident_id as incident_id',
                    'i.incident_name',
                    'b.id as barangay_id',
                    'b.barangay_name',
                ]);
        }

        if ($type === 'consolidated') {
            return DB::query()->from('consolidated_reports as cr')
                ->leftJoin('disaster_incidents as i', 'i.id', '=', 'cr.disaster_incident_id')
                ->select([
                    'cr.id',
                    'cr.report_reference as reference',
                    'cr.report_status as workflow_status',
                    'cr.report_summary as title_source',
                    'cr.updated_at',
                    'cr.prepared_by as author_id',
                    'cr.disaster_incident_id as incident_id',
                    'i.incident_name',
                    DB::raw('null as barangay_id'),
                    DB::raw('null as barangay_name'),
                    DB::raw('null as source_reference'),
                ]);
        }

        return DB::query()->from('barangay_reports as br')
            ->join('barangays as b', 'b.id', '=', 'br.barangay_id')
            ->leftJoin('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
            ->select([
                'br.id',
                'br.report_reference as reference',
                'br.report_status as workflow_status',
                'br.report_description as title_source',
                'br.updated_at',
                'br.prepared_by as author_id',
                'br.disaster_incident_id as incident_id',
                'i.incident_name',
                'b.id as barangay_id',
                'b.barangay_name',
                DB::raw('null as source_reference'),
            ]);
    }

    private function applyFilters($query, Request $request, string $type)
    {
        $spec = $this->spec($type);
        $query->when($request->filled('q'), function ($inner) use ($request, $spec) {
            $term = '%'.trim((string) $request->input('q')).'%';
            $inner->where(function ($nested) use ($term, $spec) {
                $nested->where($spec['reference'], 'like', $term)
                    ->orWhere($spec['title'], 'like', $term)
                    ->orWhere('i.incident_name', 'like', $term);
            });
        });
        $query->when($request->filled('incident_id'), fn ($inner) => $inner->where($spec['incident'], $request->integer('incident_id')));
        if ($type !== 'consolidated' && $spec['barangay']) {
            $query->when($request->filled('barangay_id'), fn ($inner) => $inner->where($spec['barangay'], $request->integer('barangay_id')));
        }
        $query->when($request->filled('workflow_status'), fn ($inner) => $inner->where($spec['status'], (string) $request->input('workflow_status')));
        if ($request->filled('date_from')) {
            $query->where($spec['updated'], '>=', Carbon::parse((string) $request->input('date_from'), 'Asia/Manila')->startOfDay()->utc());
        }
        if ($request->filled('date_to')) {
            $query->where($spec['updated'], '<=', Carbon::parse((string) $request->input('date_to'), 'Asia/Manila')->endOfDay()->utc());
        }
        $this->applySignatureFilter($query, $request, $spec);
        $this->applyFlagFilter($query, $request, $spec);

        return $query;
    }

    private function applySignatureFilter($query, Request $request, array $spec): void
    {
        $wanted = (string) $request->input('signature_status');
        if ($wanted === '') {
            return;
        }
        if (! Schema::hasTable('report_signatures')) {
            if ($wanted !== 'unsigned') {
                $query->whereRaw('1 = 0');
            }

            return;
        }
        $roles = $spec['roles'];
        $column = $spec['signature'];
        $query->where(function ($outer) use ($wanted, $roles, $column, $spec) {
            if ($wanted === 'complete') {
                foreach ($roles as $role) {
                    $outer->whereExists(fn ($exists) => $this->signatureExists($exists, $column, $spec['id'], $role));
                }

                return;
            }
            if ($wanted === 'partial' && count($roles) > 1) {
                $outer->where(function ($either) use ($roles, $column, $spec) {
                    foreach ($roles as $role) {
                        $either->orWhereExists(fn ($exists) => $this->signatureExists($exists, $column, $spec['id'], $role));
                    }
                })->where(function ($missing) use ($roles, $column, $spec) {
                    foreach ($roles as $role) {
                        $missing->orWhereNotExists(fn ($exists) => $this->signatureExists($exists, $column, $spec['id'], $role));
                    }
                });

                return;
            }
            if ($wanted === 'partial') {
                $outer->whereRaw('1 = 0');

                return;
            }
            $outer->whereNotExists(function ($exists) use ($column, $spec) {
                $exists->from('report_signatures as rs')
                    ->whereColumn('rs.'.$column, $spec['id'])
                    ->where('rs.mechanism', 'authenticated_e_signature');
            });
        });
    }

    private function signatureExists($exists, string $column, string $id, string $role): void
    {
        $exists->from('report_signatures as rs')
            ->whereColumn('rs.'.$column, $id)
            ->where('rs.signature_role', $role)
            ->where('rs.mechanism', 'authenticated_e_signature');
    }

    private function applyFlagFilter($query, Request $request, array $spec): void
    {
        $wanted = (string) $request->input('flag_status');
        if ($wanted === '' || ! Schema::hasTable('report_flags')) {
            if ($wanted === 'open') {
                $query->whereRaw('1 = 0');
            }

            return;
        }
        $exists = function ($nested) use ($spec) {
            $nested->from('report_flags as rf')
                ->whereColumn('rf.subject_id', $spec['id'])
                ->where('rf.subject_type', $spec['subject'])
                ->where('rf.status', 'open');
        };
        if ($wanted === 'open') {
            $query->whereExists($exists);
        }
        if ($wanted === 'none') {
            $query->whereNotExists($exists);
        }
    }

    private function summary($query, string $type): array
    {
        $spec = $this->spec($type);
        $rows = (clone $query)->get([$spec['id'].' as id', $spec['status'].' as workflow_status']);
        $signatures = $this->signaturesFor($spec, $rows->pluck('id')->all());
        $awaitingSignature = 0;
        foreach ($rows as $row) {
            if ($row->workflow_status === 'draft') {
                continue;
            }
            $state = $this->signatureState($spec['roles'], $signatures[$row->id] ?? [])['code'];
            if ($state !== 'complete') {
                $awaitingSignature++;
            }
        }

        return [
            'total' => $rows->count(),
            'awaiting_review' => $rows->where('workflow_status', 'review')->count(),
            'awaiting_signature' => $awaitingSignature,
            'finalized' => $rows->whereIn('workflow_status', $spec['terminal'])->count(),
        ];
    }

    private function present($rows, string $type): array
    {
        $spec = $this->spec($type);
        $workflow = app(ReportWorkflow::class);
        $ids = collect($rows)->pluck('id')->all();
        $authors = $this->authors(collect($rows)->pluck('author_id')->filter()->all());
        $signatures = $this->signaturesFor($spec, $ids);
        $flags = $this->openFlagCounts($spec, $ids);
        $attachments = $this->attachmentsFor($spec, $rows);

        return collect($rows)->map(function ($row) use ($type, $workflow, $spec, $authors, $signatures, $flags, $attachments) {
            $signature = $this->signatureState($spec['roles'], $signatures[$row->id] ?? []);
            $title = trim((string) ($row->title_source ?? ''));
            $reference = (string) $row->reference;
            $attachment = $attachments[$row->id] ?? $attachments[$reference] ?? null;

            return [
                'id' => $row->id,
                'reference' => $reference,
                'report_reference' => $type === 'field' ? $row->source_reference : $reference,
                'assessment_reference' => $type === 'field' ? $reference : null,
                'title' => $title !== '' ? mb_strimwidth($title, 0, 90, '…') : 'Not recorded',
                'incident_id' => $row->incident_id,
                'incident_name' => $row->incident_name ?: 'Not recorded',
                'barangay_id' => $row->barangay_id,
                'barangay_name' => $row->barangay_name ?: ($type === 'consolidated' ? $this->incidentScope($row->incident_id) : 'Not recorded'),
                'submitted_by' => $authors[$row->author_id] ?? 'Not recorded',
                'workflow_status' => $row->workflow_status,
                'report_status' => $type === 'field' ? null : $row->workflow_status,
                'assessment_status' => $type === 'field' ? $row->workflow_status : null,
                'workflow_label' => $workflow->label($row->workflow_status),
                'signature_status' => $signature['code'],
                'signature_label' => $signature['label'],
                'open_flags' => $flags[$row->id] ?? 0,
                'updated_at' => $row->updated_at,
                'updated_label' => $this->manila($row->updated_at),
                'attachment' => $attachment,
                'preview_path' => $type === 'consolidated' ? null : '/cdrrmo/reports/'.$type.'/'.$row->id.'/preview',
            ];
        })->all();
    }

    private function signaturesFor(array $spec, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('report_signatures')) {
            return [];
        }
        $grouped = [];
        foreach (DB::table('report_signatures')->whereIn($spec['signature'], $ids)->get(['signature_role', 'mechanism', $spec['signature'].' as subject_id']) as $row) {
            $grouped[$row->subject_id][] = $row;
        }

        return $grouped;
    }

    private function signatureState(array $roles, array $rows): array
    {
        $authenticated = collect($rows)->where('mechanism', 'authenticated_e_signature');
        $present = 0;
        foreach ($roles as $role) {
            if ($authenticated->contains(fn ($row) => $row->signature_role === $role)) {
                $present++;
            }
        }
        if ($present === count($roles)) {
            return ['code' => 'complete', 'label' => 'Signed'];
        }
        if ($present > 0) {
            return ['code' => 'partial', 'label' => 'Partially signed'];
        }
        if (collect($rows)->contains(fn ($row) => $row->mechanism === 'simulated_demo')) {
            return ['code' => 'demonstration', 'label' => 'Demonstration record only'];
        }

        return ['code' => 'unsigned', 'label' => 'Awaiting signature'];
    }

    private function openFlagCounts(array $spec, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('report_flags')) {
            return [];
        }

        return DB::table('report_flags')
            ->where('subject_type', $spec['subject'])
            ->where('status', 'open')
            ->whereIn('subject_id', $ids)
            ->selectRaw('subject_id, count(*) as open_count')
            ->groupBy('subject_id')
            ->pluck('open_count', 'subject_id')
            ->all();
    }

    private function attachmentsFor(array $spec, $rows): array
    {
        if (! Schema::hasTable('imported_report_documents')) {
            return [];
        }
        $ids = collect($rows)->pluck('id')->all();
        $column = $spec['signature'];
        if (! Schema::hasColumn('imported_report_documents', $column)) {
            return [];
        }
        $found = [];
        foreach (DB::table('imported_report_documents')->whereIn($column, $ids)->get() as $row) {
            $found[$row->{$column}] = $this->attachmentPayload($row);
        }

        return $found;
    }

    private function attachment(string $column, int $id, string $reference): ?array
    {
        if (! Schema::hasTable('imported_report_documents')) {
            return null;
        }
        $query = DB::table('imported_report_documents')->where('import_key', $reference);
        if (Schema::hasColumn('imported_report_documents', $column)) {
            $query->orWhere($column, $id);
        }
        $row = $query->first();
        if ($row === null) {
            return null;
        }

        return $this->attachmentPayload($row);
    }

    private function attachmentPayload(object $row): array
    {
        return [
            'import_key' => $row->import_key,
            'filename' => $row->filename,
            'sha256' => $row->sha256 ?? null,
            'download_path' => '/cdrrmo/api/documents/'.$row->import_key,
            'preview_path' => '/cdrrmo/api/documents/'.$row->import_key.'/preview',
            'source_resident_reference' => property_exists($row, 'source_resident_reference') ? $row->source_resident_reference : null,
            'source_building_reference' => property_exists($row, 'source_building_reference') ? $row->source_building_reference : null,
            'source_household_reference' => property_exists($row, 'source_household_reference') ? $row->source_household_reference : null,
            'resolved_resident_id' => property_exists($row, 'resolved_resident_id') ? $row->resolved_resident_id : null,
            'resolved_building_id' => property_exists($row, 'resolved_building_id') ? $row->resolved_building_id : null,
            'resolved_household_id' => property_exists($row, 'resolved_household_id') ? $row->resolved_household_id : null,
        ];
    }

    private function authors(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('users')) {
            return [];
        }
        $names = [];
        foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'middle_name', 'last_name']) as $user) {
            $name = trim(implode(' ', array_filter([$user->first_name, $user->middle_name, $user->last_name])));
            $names[$user->id] = $name !== '' ? $name : 'Not recorded';
        }

        return $names;
    }

    private function options(string $type, string $kind): array
    {
        $query = $this->baseQuery($type);
        if ($kind === 'incident') {
            return $query->whereNotNull($this->spec($type)['incident'])
                ->select($this->spec($type)['incident'].' as id', 'i.incident_name as name')
                ->distinct()
                ->orderBy('i.incident_name')
                ->get()
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])
                ->all();
        }
        if ($type === 'consolidated') {
            return [];
        }

        return $query->whereNotNull($this->spec($type)['barangay'])
            ->select($this->spec($type)['barangay'].' as id', 'b.barangay_name as name')
            ->distinct()
            ->orderBy('b.barangay_name')
            ->get()
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])
            ->all();
    }

    private function incidentScope($incidentId): string
    {
        if (! $incidentId || ! Schema::hasTable('disaster_barangays') || ! Schema::hasTable('barangays')) {
            return 'Not recorded';
        }
        $names = DB::table('disaster_barangays as db')
            ->join('barangays as b', 'b.id', '=', 'db.barangay_id')
            ->where('db.disaster_incident_id', $incidentId)
            ->orderBy('b.barangay_name')
            ->pluck('b.barangay_name');

        return $names->isEmpty() ? 'Not recorded' : $names->implode(', ');
    }

    private function manila($value): string
    {
        if (! $value) {
            return 'Not recorded';
        }

        return Carbon::parse($value)->timezone('Asia/Manila')->format('d M Y, g:i A').' PHT';
    }

    private function reviewOnly(JsonResponse $response, string $kind): JsonResponse
    {
        $payload = $response->getData(true);
        $data = $payload['data'] ?? [];
        $data['can_edit'] = false;
        $column = $kind === 'field' ? 'field_assessment_id' : 'barangay_report_id';
        $data['attachment'] = $data['attachment'] ?? $this->attachment($column, (int) ($data['id'] ?? 0), (string) ($data['reference'] ?? ''));
        $payload['data'] = $data;

        return response()->json($payload);
    }
}
