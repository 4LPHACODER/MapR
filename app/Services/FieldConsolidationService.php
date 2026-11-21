<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FieldConsolidationService
{
    public function targetBarangayNames(): array
    {
        $names = config('mapr.field_consolidation_barangays', []);

        return array_values(array_filter(array_map(
            fn ($name) => is_string($name) ? trim($name) : '',
            is_array($names) ? $names : [],
        )));
    }

    /**
     * @return list<array{id:int|null,name:string,barangay_code:?string,has_boundary:bool}>
     */
    public function resolveTargetBarangays(): array
    {
        $targets = $this->targetBarangayNames();
        if ($targets === [] || ! Schema::hasTable('barangays')) {
            return array_map(fn (string $name) => [
                'id' => null,
                'name' => $name,
                'barangay_code' => null,
                'has_boundary' => false,
            ], $targets);
        }

        $columns = ['id', 'barangay_name', 'barangay_code'];
        if (Schema::hasColumn('barangays', 'boundary_polygon')) {
            $columns[] = 'boundary_polygon';
        }
        $rows = DB::table('barangays')->get($columns);
        $aliases = config('mapr.field_consolidation_barangay_aliases', []);
        $aliases = is_array($aliases) ? $aliases : [];
        $resolved = [];
        foreach ($targets as $name) {
            $candidates = array_values(array_unique(array_filter(array_merge(
                [$name],
                is_array($aliases[$name] ?? null) ? $aliases[$name] : [],
            ), fn ($value) => is_string($value) && trim($value) !== '')));

            $match = null;
            foreach ($candidates as $candidate) {
                $normalized = Str::lower(Str::ascii(trim($candidate)));
                $match = $rows->first(function ($row) use ($candidate, $normalized) {
                    $rowName = (string) ($row->barangay_name ?? '');
                    $rowCode = (string) ($row->barangay_code ?? '');

                    return strcasecmp($rowName, $candidate) === 0
                        || Str::lower(Str::ascii($rowName)) === $normalized
                        || ($rowCode !== '' && strcasecmp($rowCode, $candidate) === 0);
                });
                if ($match !== null) {
                    break;
                }
            }

            $resolved[] = [
                'id' => $match ? (int) $match->id : null,
                'name' => $name,
                'barangay_code' => $match->barangay_code ?? null,
                'has_boundary' => $match !== null && isset($match->boundary_polygon) && $match->boundary_polygon !== null,
            ];
        }

        return $resolved;
    }

    public function eligibleAssessmentsQuery(?int $barangayId = null, bool $includeForwarded = false)
    {
        $stages = $includeForwarded
            ? ['ready_for_consolidation', 'forwarded_cdrrmo']
            : ['ready_for_consolidation'];

        $query = DB::table('field_assessments as fa')
            ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
            ->leftJoin('barangays as b', 'b.id', '=', 'br.barangay_id')
            ->leftJoin('disaster_incidents as di', 'di.id', '=', 'br.disaster_incident_id')
            ->leftJoin('users as assessor', 'assessor.id', '=', 'fa.assigned_assessor_id')
            ->where('fa.assessment_status', '!=', 'returned');

        if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
            $query->whereIn('fa.workflow_stage', $stages);
        } else {
            $query->where('fa.assessment_status', 'submitted');
        }

        if ($barangayId !== null) {
            $query->where('br.barangay_id', $barangayId);
        } else {
            $ids = collect($this->resolveTargetBarangays())->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('br.barangay_id', $ids);
            }
        }

        $select = [
            'fa.id',
            'fa.assessment_reference',
            'fa.assessment_status',
            'fa.workflow_stage',
            'fa.findings',
            'fa.submitted_at',
            'fa.updated_at',
            'fa.assigned_assessor_id',
            'fa.barangay_report_id',
            'br.report_reference as barangay_report_reference',
            'br.barangay_id',
            'br.disaster_incident_id',
            'br.submitted_at as barangay_submitted_at',
            'b.barangay_name',
            'di.incident_name',
            DB::raw("trim(concat(coalesce(assessor.first_name,''), ' ', coalesce(assessor.last_name,''))) as assessor_name"),
        ];
        if (Schema::hasColumn('disaster_incidents', 'incident_date')) {
            $select[] = 'di.incident_date';
        }

        return $query->select($select);
    }

    /**
     * @return Collection<int, object>
     */
    public function eligibleAssessments(?int $barangayId = null, bool $includeForwarded = false): Collection
    {
        return $this->eligibleAssessmentsQuery($barangayId, $includeForwarded)
            ->orderByDesc('fa.updated_at')
            ->orderByDesc('fa.id')
            ->get();
    }

    public function dashboard(User $user): array
    {
        $barangays = $this->resolveTargetBarangays();
        $ready = $this->eligibleAssessments(null, false);
        $forwarded = $this->eligibleAssessments(null, true)->filter(
            fn ($row) => (string) ($row->workflow_stage ?? '') === 'forwarded_cdrrmo'
        );
        $cards = [];
        foreach ($barangays as $barangay) {
            $cards[] = $this->barangayCard($barangay, $ready, $forwarded);
        }

        $packages = Schema::hasTable('field_report_packages')
            ? DB::table('field_report_packages')->orderByDesc('id')->get()
            : collect();

        $residentStats = $this->residentSubmissionStats($ready->pluck('barangay_report_id')->map(fn ($id) => (int) $id)->all());

        return [
            'can_export' => $this->canManage($user),
            'can_submit' => app(ReportWorkflow::class)->canSubmitFieldPackage($user),
            'kpis' => [
                'reviewed_reports' => $ready->count(),
                'barangays_represented' => $ready->pluck('barangay_id')->filter()->unique()->count(),
                'awaiting_consolidation' => $ready->count(),
                'packages_generated' => $packages->count(),
                'packages_ready_for_submission' => $packages->where('package_status', 'generated')->count(),
                'last_consolidation_at' => optional($packages->first())->created_at,
                'resident_submissions' => $residentStats['total'],
                'resident_complete' => $residentStats['complete'],
                'resident_needing_review' => $residentStats['needing_review'],
                'packages_submitted' => $packages->whereIn('package_status', ['submitted', 'acknowledged'])->count(),
                'packages_pending_ack' => $packages->where('package_status', 'submitted')->count(),
                'last_submission_at' => optional($packages->first(fn ($row) => $row->submitted_at))->submitted_at,
            ],
            'barangays' => $cards,
            'exports' => $this->exportSummaries($barangays),
        ];
    }

    /**
     * @param  array{id:int|null,name:string,barangay_code:?string,has_boundary:bool}  $barangay
     * @param  Collection<int, object>  $ready
     * @param  Collection<int, object>  $forwarded
     */
    public function barangayCard(array $barangay, Collection $ready, Collection $forwarded): array
    {
        $id = $barangay['id'];
        $rows = $id === null ? collect() : $ready->where('barangay_id', $id)->values();
        $submittedRows = $id === null ? collect() : $forwarded->where('barangay_id', $id)->values();
        $reportIds = $rows->pluck('barangay_report_id')->map(fn ($value) => (int) $value)->unique()->values()->all();
        $damage = $this->damageCounts($reportIds);
        $residents = $this->residentSubmissionStats($reportIds);
        $latest = $rows->sortByDesc('updated_at')->first();
        $signatures = $latest ? $this->signatureNames((int) $latest->id) : ['verified_by' => null, 'approved_by' => null, 'verified_at' => null, 'approved_at' => null];

        $status = 'empty';
        if ($submittedRows->isNotEmpty() && $rows->isEmpty()) {
            $status = 'submitted';
        } elseif ($rows->isNotEmpty()) {
            $status = 'ready';
        }

        $statusLabel = match ($status) {
            'submitted' => 'Submitted',
            'ready' => 'Ready for Consolidation',
            default => 'No Reviewed Reports',
        };

        return [
            'barangay_id' => $id,
            'barangay_name' => $barangay['name'],
            'barangay_code' => $barangay['barangay_code'],
            'has_boundary' => $barangay['has_boundary'],
            'consolidation_status' => $status,
            'consolidation_status_label' => $statusLabel,
            'report_count' => $rows->count(),
            'submitted_count' => $submittedRows->count(),
            'assessed_buildings' => $damage['assessed_buildings'],
            'damage_classes' => $damage['classes'],
            'partially_damaged' => $damage['partially_damaged'],
            'totally_damaged' => $damage['totally_damaged'],
            'resident_submissions' => $residents['total'],
            'potentially_affected_residents' => $residents['potentially_affected'],
            'latest_assessment_reference' => $latest->assessment_reference ?? null,
            'latest_assessment_at' => $latest->updated_at ?? null,
            'verified_by' => $signatures['verified_by'],
            'reviewed_by' => $signatures['approved_by'],
            'last_updated_at' => $latest->updated_at ?? null,
            'can_submit' => $rows->isNotEmpty(),
        ];
    }

    /**
     * @return array{total:int,complete:int,needing_review:int,potentially_affected:int|null}
     */
    public function residentSubmissionStats(array $barangayReportIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $barangayReportIds))));
        if ($ids === [] || ! Schema::hasTable('resident_incident_reports')) {
            return [
                'total' => 0,
                'complete' => 0,
                'needing_review' => 0,
                'potentially_affected' => null,
            ];
        }

        $rows = DB::table('resident_incident_reports')
            ->whereIn('barangay_report_id', $ids)
            ->get(['id', 'report_status', 'reporter_user_id']);

        $completeStatuses = ['accepted', 'reviewed', 'handed_to_rdana', 'submitted'];
        $complete = $rows->filter(fn ($row) => in_array((string) $row->report_status, $completeStatuses, true))->count();

        $affected = null;
        if (Schema::hasTable('household_members') && Schema::hasTable('barangay_report_buildings')) {
            $buildingIds = DB::table('barangay_report_buildings')
                ->whereIn('barangay_report_id', $ids)
                ->pluck('building_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();
            if ($buildingIds !== [] && Schema::hasTable('households')) {
                $householdIds = DB::table('households')->whereIn('building_id', $buildingIds)->pluck('id');
                if ($householdIds->isNotEmpty()) {
                    $affected = (int) DB::table('household_members')->whereIn('household_id', $householdIds)->distinct('resident_id')->count('resident_id');
                }
            }
        }

        return [
            'total' => $rows->count(),
            'complete' => $complete,
            'needing_review' => max(0, $rows->count() - $complete),
            'potentially_affected' => $affected,
        ];
    }

    /**
     * @return array{assessed_buildings:int,classes:array<string,int>,partially_damaged:int,totally_damaged:int}
     */
    public function damageCounts(array $barangayReportIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $barangayReportIds))));
        $empty = [
            'assessed_buildings' => 0,
            'classes' => [],
            'partially_damaged' => 0,
            'totally_damaged' => 0,
        ];
        if ($ids === []) {
            return $empty;
        }

        $classes = [];
        $buildingIds = [];

        if (Schema::hasTable('building_damage_cost_assessments') && Schema::hasTable('damage_classes')) {
            $rows = DB::table('building_damage_cost_assessments as a')
                ->leftJoin('damage_classes as dc', 'dc.id', '=', 'a.confirmed_damage_class_id')
                ->whereIn('a.barangay_report_id', $ids)
                ->whereIn('a.assessment_status', ['draft', 'confirmed'])
                ->get(['a.building_id', 'dc.class_name', 'dc.class_code', 'dc.counts_as_damaged']);
            foreach ($rows as $row) {
                if ($row->building_id) {
                    $buildingIds[(int) $row->building_id] = true;
                }
                $label = trim((string) ($row->class_name ?: $row->class_code ?: 'Not Recorded'));
                $classes[$label] = ($classes[$label] ?? 0) + 1;
            }
        } elseif (Schema::hasTable('building_verifications') && Schema::hasTable('damage_classes')) {
            $rows = DB::table('building_verifications as bv')
                ->join('field_assessments as fa', 'fa.id', '=', 'bv.field_assessment_id')
                ->join('damage_classes as dc', 'dc.id', '=', 'bv.verified_damage_class_id')
                ->whereIn('fa.barangay_report_id', $ids)
                ->get(['bv.building_id', 'dc.class_name', 'dc.class_code']);
            foreach ($rows as $row) {
                if ($row->building_id) {
                    $buildingIds[(int) $row->building_id] = true;
                }
                $label = trim((string) ($row->class_name ?: $row->class_code ?: 'Not Recorded'));
                $classes[$label] = ($classes[$label] ?? 0) + 1;
            }
        }

        $partial = 0;
        $total = 0;
        foreach ($classes as $label => $count) {
            $key = Str::lower($label);
            if (str_contains($key, 'total') || str_contains($key, 'destroyed') || str_contains($key, 'collapse')) {
                $total += $count;
            } elseif (str_contains($key, 'partial') || str_contains($key, 'major') || str_contains($key, 'moderate')) {
                $partial += $count;
            }
        }

        return [
            'assessed_buildings' => count($buildingIds),
            'classes' => $classes,
            'partially_damaged' => $partial,
            'totally_damaged' => $total,
        ];
    }

    /**
     * @return array{verified_by:?string,approved_by:?string,verified_at:?string,approved_at:?string}
     */
    public function signatureNames(int $fieldAssessmentId): array
    {
        $payload = [
            'verified_by' => null,
            'approved_by' => null,
            'verified_at' => null,
            'approved_at' => null,
        ];
        if (! Schema::hasTable('report_signatures')) {
            return $payload;
        }

        $signerColumn = Schema::hasColumn('report_signatures', 'signer_user_id') ? 'signer_user_id' : 'signed_by';
        $rows = DB::table('report_signatures as rs')
            ->leftJoin('users as u', 'u.id', '=', 'rs.'.$signerColumn)
            ->where('rs.field_assessment_id', $fieldAssessmentId)
            ->whereIn('rs.signature_role', ['verified_by', 'approved_by'])
            ->get([
                'rs.signature_role',
                'rs.signed_at',
                DB::raw("trim(concat(coalesce(u.first_name,''), ' ', coalesce(u.last_name,''))) as signer_name"),
            ]);

        foreach ($rows as $row) {
            if ($row->signature_role === 'verified_by') {
                $payload['verified_by'] = trim((string) $row->signer_name) ?: 'Not Recorded';
                $payload['verified_at'] = $row->signed_at;
            }
            if ($row->signature_role === 'approved_by') {
                $payload['approved_by'] = trim((string) $row->signer_name) ?: 'Not Recorded';
                $payload['approved_at'] = $row->signed_at;
            }
        }

        return $payload;
    }

    public function mapPayload(?int $barangayId = null): array
    {
        $barangays = collect($this->resolveTargetBarangays())
            ->when($barangayId, fn ($rows) => $rows->where('id', $barangayId)->values())
            ->values();
        $boundaries = ['type' => 'FeatureCollection', 'features' => []];
        foreach ($barangays as $barangay) {
            if (! $barangay['id'] || ! $barangay['has_boundary']) {
                continue;
            }
            $geo = DB::selectOne('SELECT ST_AsGeoJSON(boundary_polygon) AS geojson FROM barangays WHERE id = ?', [$barangay['id']]);
            $geometry = json_decode($geo->geojson ?? 'null', true);
            if (! is_array($geometry)) {
                continue;
            }
            $boundaries['features'][] = [
                'type' => 'Feature',
                'properties' => [
                    'barangay_id' => $barangay['id'],
                    'barangay_name' => $barangay['name'],
                ],
                'geometry' => $geometry,
            ];
        }

        $assessments = $this->eligibleAssessments($barangayId, true);
        $reportIds = $assessments->pluck('barangay_report_id')->map(fn ($id) => (int) $id)->unique()->all();
        $markers = ['type' => 'FeatureCollection', 'features' => []];
        if ($reportIds !== [] && Schema::hasTable('building_verifications') && Schema::hasTable('buildings') && Schema::hasTable('damage_classes')) {
            $points = DB::table('building_verifications as bv')
                ->join('buildings', 'buildings.id', '=', 'bv.building_id')
                ->join('damage_classes', 'damage_classes.id', '=', 'bv.verified_damage_class_id')
                ->join('field_assessments as fa', 'fa.id', '=', 'bv.field_assessment_id')
                ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
                ->whereIn('fa.id', $assessments->pluck('id')->all())
                ->whereNotNull('buildings.geographic_point')
                ->get([
                    'buildings.building_reference',
                    'buildings.barangay_id',
                    'damage_classes.class_name',
                    'fa.id as field_assessment_id',
                    'fa.assessment_reference',
                    'fa.workflow_stage',
                    'br.barangay_id as report_barangay_id',
                    DB::raw('ST_Longitude(buildings.geographic_point) as lng'),
                    DB::raw('ST_Latitude(buildings.geographic_point) as lat'),
                ]);
            foreach ($points as $point) {
                if ($point->lng === null || $point->lat === null) {
                    continue;
                }
                $markers['features'][] = [
                    'type' => 'Feature',
                    'properties' => [
                        'building_reference' => $point->building_reference,
                        'damage_class' => $point->class_name,
                        'assessment_reference' => $point->assessment_reference,
                        'field_assessment_id' => (int) $point->field_assessment_id,
                        'barangay_id' => (int) ($point->report_barangay_id ?: $point->barangay_id),
                        'workflow_stage' => $point->workflow_stage,
                        'layer' => 'field_verified',
                    ],
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $point->lng, (float) $point->lat],
                    ],
                ];
            }
        }

        return [
            'boundaries' => $boundaries,
            'markers' => $markers,
            'eligible_count' => $assessments->where('workflow_stage', 'ready_for_consolidation')->count(),
        ];
    }

    public function reportRows(string $tab = 'ready', ?int $barangayId = null, ?string $search = null): array
    {
        $includeForwarded = in_array($tab, ['submitted', 'all'], true);
        $rows = $this->eligibleAssessments($barangayId, $includeForwarded || $tab === 'ready_for_submission');
        if ($tab === 'ready' || $tab === 'ready_for_submission') {
            $rows = $rows->where('workflow_stage', 'ready_for_consolidation')->values();
        } elseif ($tab === 'submitted') {
            $rows = $rows->where('workflow_stage', 'forwarded_cdrrmo')->values();
        } elseif ($tab === 'returned') {
            $query = DB::table('field_assessments as fa')
                ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
                ->leftJoin('barangays as b', 'b.id', '=', 'br.barangay_id')
                ->leftJoin('disaster_incidents as di', 'di.id', '=', 'br.disaster_incident_id')
                ->where('fa.assessment_status', 'returned');
            $ids = collect($this->resolveTargetBarangays())->pluck('id')->filter()->all();
            if ($barangayId) {
                $query->where('br.barangay_id', $barangayId);
            } elseif ($ids !== []) {
                $query->whereIn('br.barangay_id', $ids);
            }
            $rows = $query->orderByDesc('fa.updated_at')->get([
                'fa.id',
                'fa.assessment_reference',
                'fa.assessment_status',
                'fa.workflow_stage',
                'fa.updated_at',
                'fa.assigned_assessor_id',
                'fa.barangay_report_id',
                'br.report_reference as barangay_report_reference',
                'br.barangay_id',
                'b.barangay_name',
                'di.incident_name',
            ]);
        }

        if ($search) {
            $needle = Str::lower($search);
            $rows = $rows->filter(function ($row) use ($needle) {
                $hay = Str::lower(implode(' ', [
                    $row->assessment_reference ?? '',
                    $row->barangay_name ?? '',
                    $row->incident_name ?? '',
                    $row->assessor_name ?? '',
                ]));

                return str_contains($hay, $needle);
            })->values();
        }

        return $rows->map(function ($row) {
            $signatures = $this->signatureNames((int) $row->id);

            return [
                'id' => (int) $row->id,
                'reference' => $row->assessment_reference,
                'barangay_id' => (int) ($row->barangay_id ?? 0) ?: null,
                'barangay_name' => $row->barangay_name ?? null,
                'incident_name' => $row->incident_name ?? null,
                'assessor_name' => $row->assessor_name ?? null,
                'verified_by' => $signatures['verified_by'],
                'reviewed_by' => $signatures['approved_by'],
                'status' => $row->workflow_stage ?: $row->assessment_status,
                'label' => app(ReportWorkflow::class)->label($row->workflow_stage ?: $row->assessment_status),
                'updated_at' => $row->updated_at,
                'barangay_report_id' => (int) $row->barangay_report_id,
                'barangay_report_reference' => $row->barangay_report_reference ?? null,
            ];
        })->values()->all();
    }

    public function history(?int $barangayId = null, ?string $filter = null, int $limit = 80): array
    {
        if (! Schema::hasTable('activity_events')) {
            return [];
        }

        $assessmentIds = $this->eligibleAssessments($barangayId, true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $packageIds = Schema::hasTable('field_report_packages')
            ? DB::table('field_report_packages')->when($barangayId, fn ($q) => $q->where('barangay_id', $barangayId))->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $query = DB::table('activity_events as ae')
            ->leftJoin('users as u', 'u.id', '=', 'ae.user_id')
            ->where(function ($inner) use ($assessmentIds, $packageIds) {
                if ($assessmentIds !== []) {
                    $inner->orWhere(function ($q) use ($assessmentIds) {
                        $q->where('ae.subject_type', 'field_assessment')->whereIn('ae.subject_id', $assessmentIds);
                    });
                }
                if ($packageIds !== []) {
                    $inner->orWhere(function ($q) use ($packageIds) {
                        $q->where('ae.subject_type', 'field_report_package')->whereIn('ae.subject_id', $packageIds);
                    });
                }
                if ($assessmentIds === [] && $packageIds === []) {
                    $inner->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('ae.created_at')
            ->limit($limit)
            ->get([
                'ae.id',
                'ae.action_type',
                'ae.summary',
                'ae.subject_type',
                'ae.subject_id',
                'ae.created_at',
                DB::raw("trim(concat(coalesce(u.first_name,''), ' ', coalesce(u.last_name,''))) as actor_name"),
            ]);

        return $query->map(function ($row) use ($filter) {
            $category = $this->historyCategory((string) $row->action_type);
            if ($filter && $filter !== 'all' && $category !== $filter) {
                return null;
            }

            return [
                'id' => (int) $row->id,
                'action_type' => $row->action_type,
                'category' => $category,
                'summary' => $row->summary,
                'subject_type' => $row->subject_type,
                'subject_id' => (int) $row->subject_id,
                'actor_name' => trim((string) $row->actor_name) ?: 'System',
                'created_at' => $row->created_at,
            ];
        })->filter()->values()->all();
    }

    public function canManage(User $user): bool
    {
        $user->loadMissing('role');

        return in_array($user->role?->code, ['rdana_admin_officer', 'rdana_city_assessor'], true);
    }

    private function historyCategory(string $action): string
    {
        return match (true) {
            str_contains($action, 'export') => 'export',
            str_contains($action, 'package') || str_contains($action, 'forwarded') || str_contains($action, 'submit') => 'submission',
            str_contains($action, 'verified') => 'verification',
            str_contains($action, 'reviewed') || str_contains($action, 'approved') => 'review',
            str_contains($action, 'assess') => 'assessment',
            default => 'all',
        };
    }

    /**
     * @param  list<array{id:int|null,name:string}>  $barangays
     */
    private function exportSummaries(array $barangays): array
    {
        if (! Schema::hasTable('field_report_exports')) {
            return [
                'consolidated' => null,
                'barangays' => array_map(fn ($b) => [
                    'barangay_id' => $b['id'],
                    'barangay_name' => $b['name'],
                    'eligible_count' => 0,
                    'last_generated_at' => null,
                    'export_id' => null,
                ], $barangays),
            ];
        }

        $latestConsolidated = DB::table('field_report_exports')
            ->where('scope', 'consolidated')
            ->orderByDesc('id')
            ->first();

        $ready = $this->eligibleAssessments(null, false);
        $barangayExports = [];
        foreach ($barangays as $barangay) {
            $export = $barangay['id']
                ? DB::table('field_report_exports')->where('scope', 'barangay')->where('barangay_id', $barangay['id'])->orderByDesc('id')->first()
                : null;
            $barangayExports[] = [
                'barangay_id' => $barangay['id'],
                'barangay_name' => $barangay['name'],
                'eligible_count' => $barangay['id'] ? $ready->where('barangay_id', $barangay['id'])->count() : 0,
                'last_generated_at' => $export->generated_at ?? null,
                'export_id' => $export->id ?? null,
            ];
        }

        return [
            'consolidated' => $latestConsolidated ? [
                'export_id' => (int) $latestConsolidated->id,
                'filename' => $latestConsolidated->filename,
                'generated_at' => $latestConsolidated->generated_at,
                'eligible_count' => $ready->count(),
                'barangay_count' => collect($barangays)->whereNotNull('id')->count(),
            ] : [
                'export_id' => null,
                'filename' => null,
                'generated_at' => null,
                'eligible_count' => $ready->count(),
                'barangay_count' => collect($barangays)->whereNotNull('id')->count(),
            ],
            'barangays' => $barangayExports,
        ];
    }
}
