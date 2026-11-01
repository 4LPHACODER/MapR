<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Models\Registry\Barangay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkspaceDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->payload($request);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'The disaster assessment dashboard could not load authorized data.',
            ], 500);
        }
    }

    private function payload(Request $request): JsonResponse
    {
        $incidentId = $request->integer('incident_id') ?: null;
        $barangayId = $request->integer('barangay_id') ?: null;

        $incidents = Schema::hasTable('disaster_incidents')
            ? DB::table('disaster_incidents')->orderByDesc('occurred_at')->limit(50)->get()
                ->reject(fn ($incident) => $this->isDemoIncident($incident))
                ->values()
            : collect();

        if ($incidentId && ! $incidents->contains('id', $incidentId)) {
            $incidentId = null;
        }

        $selectedIncident = $incidentId ? $incidents->firstWhere('id', $incidentId) : null;

        $barangays = Schema::hasTable('barangays')
            ? Barangay::query()->orderBy('barangay_name')->get(['id', 'barangay_name', 'barangay_code'])
            : collect();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'selected_incident' => $selectedIncident,
            'incidents' => $incidents,
            'barangays' => $barangays,
            'filters' => [
                'incident_id' => $selectedIncident?->id,
                'barangay_id' => $barangayId,
                'period_start' => $request->input('period_start'),
                'period_end' => $request->input('period_end'),
            ],
            'summary' => $this->summaryCards($incidentId, $barangayId),
            'reports_awaiting_review' => $this->reportsAwaitingReview($incidentId, $barangayId),
            'recent_activity' => $this->recentActivity(),
            'prepare_consolidated' => [
                'enabled' => $this->hasOperationalIncident(),
                'reason' => 'An active disaster incident is required before a consolidated report can be prepared.',
            ],
            'registry_note' => 'Building, household, and resident totals reflect the authorized registry import, not incident-specific field reporting unless linked through reports.',
        ]);
    }

    private function summaryCards(?int $incidentId, ?int $barangayId): array
    {
        $buildingQuery = Schema::hasTable('buildings') ? DB::table('buildings') : null;
        if ($buildingQuery && $barangayId) {
            $buildingQuery->where('barangay_id', $barangayId);
        }

        $reportedBuildings = 0;
        $reportedBuildingsNote = null;
        if ($incidentId && Schema::hasTable('barangay_reports')) {
            try {
                if (Schema::hasTable('barangay_report_buildings')) {
                    $sql = 'SELECT COUNT(DISTINCT brb.building_id) AS aggregate
                            FROM barangay_report_buildings brb
                            JOIN barangay_reports br ON br.id = brb.barangay_report_id
                            WHERE br.disaster_incident_id = ?';
                    $bindings = [$incidentId];
                    if ($barangayId) {
                        $sql .= ' AND br.barangay_id = ?';
                        $bindings[] = $barangayId;
                    }
                    $reportedBuildings = (int) (DB::selectOne($sql, $bindings)->aggregate ?? 0);
                }

                $reportCount = (int) DB::table('barangay_reports')
                    ->where('disaster_incident_id', $incidentId)
                    ->when($barangayId, fn ($q) => $q->where('barangay_id', $barangayId))
                    ->count();

                if ($reportCount > 0 && $reportedBuildings === 0) {
                    $reportedBuildingsNote = 'Field reports exist for this incident, but none are linked to building findings.';
                }
            } catch (\Throwable) {
                $reportedBuildingsNote = 'Incident building totals could not be calculated.';
            }
        }

        $fieldVerified = Schema::hasTable('building_verifications')
            ? (int) DB::table('building_verifications')->distinct()->count('building_id')
            : 0;

        $fieldAwaiting = $this->submittedFieldReportCount($incidentId, $barangayId);
        $consolidatedAwaiting = $this->countIfTable('consolidated_reports', fn ($q) => $q->whereIn('report_status', ['draft', 'review']));

        return [
            'reported_buildings' => [
                'value' => $reportedBuildings,
                'label' => 'Reported buildings (incident-linked)',
                'note' => $reportedBuildingsNote,
            ],
            'field_verified_assessments' => ['value' => $fieldVerified, 'label' => 'Field-verified building assessments'],
            'field_reports_awaiting_review' => ['value' => $fieldAwaiting, 'label' => 'Field reports awaiting CDRRMO review'],
            'consolidated_reports_awaiting' => ['value' => $consolidatedAwaiting, 'label' => 'Consolidated reports awaiting finalization'],
            'location_verified_buildings' => [
                'value' => $buildingQuery
                    ? (int) (clone $buildingQuery)->where('location_verification_status', 'verified')->count()
                    : 0,
                'label' => 'Building locations verified (official estimate)',
            ],
        ];
    }

    private function reportsAwaitingReview(?int $incidentId, ?int $barangayId): array
    {
        if (! Schema::hasTable('field_assessments')) {
            return [];
        }

        return DB::table('field_assessments as fa')
            ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
            ->join('barangays as b', 'b.id', '=', 'br.barangay_id')
            ->join('users as u', 'u.id', '=', 'fa.assigned_assessor_id')
            ->join('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
            ->when($incidentId, fn ($q) => $q->where('br.disaster_incident_id', $incidentId))
            ->when($barangayId, fn ($q) => $q->where('br.barangay_id', $barangayId))
            ->where('fa.assessment_status', 'submitted')
            ->orderBy('fa.submitted_at')
            ->orderBy('fa.updated_at')
            ->limit(20)
            ->get([
                'fa.id',
                'fa.assessment_reference',
                'fa.assessment_status',
                'fa.updated_at',
                'fa.submitted_at',
                'br.report_reference',
                'b.barangay_name',
                'i.incident_name',
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as assessor_name"),
            ])
            ->map(function ($row) {
                $row = (array) $row;
                $row['status_label'] = 'Submitted';

                return $row;
            })
            ->all();
    }

    private function isDemoIncident(object $incident): bool
    {
        $reference = strtoupper((string) ($incident->incident_reference ?? ''));
        $name = strtolower(trim((string) ($incident->incident_name ?? '')));

        return str_starts_with($reference, 'DEMO-') || str_starts_with($name, 'demo ');
    }

    private function hasOperationalIncident(): bool
    {
        if (! Schema::hasTable('disaster_incidents')) {
            return false;
        }

        return DB::table('disaster_incidents')
            ->where('incident_status', 'active')
            ->where('incident_reference', 'not like', 'DEMO-%')
            ->where('incident_name', 'not like', 'demo %')
            ->exists();
    }

    private function submittedFieldReportCount(?int $incidentId, ?int $barangayId): int
    {
        if (! Schema::hasTable('field_assessments') || ! Schema::hasTable('barangay_reports') || ! Schema::hasTable('disaster_incidents')) {
            return 0;
        }

        return (int) DB::table('field_assessments as fa')
            ->join('barangay_reports as br', 'br.id', '=', 'fa.barangay_report_id')
            ->join('disaster_incidents as i', 'i.id', '=', 'br.disaster_incident_id')
            ->when($incidentId, fn ($q) => $q->where('br.disaster_incident_id', $incidentId))
            ->when($barangayId, fn ($q) => $q->where('br.barangay_id', $barangayId))
            ->where('fa.assessment_status', 'submitted')
            ->count();
    }

    private function recentActivity(): array
    {
        $events = Schema::hasTable('activity_events')
            ? DB::table('activity_events as e')
                ->join('users as u', 'u.id', '=', 'e.user_id')
                ->orderByDesc('e.created_at')
                ->limit(15)
                ->get([
                    'e.summary',
                    'e.action_type',
                    'e.created_at',
                    DB::raw("CONCAT(u.first_name, ' ', u.last_name) as actor_name"),
                ])
            : collect();

        $reviews = Schema::hasTable('building_location_reviews')
            ? DB::table('building_location_reviews as r')
                ->join('users as u', 'u.id', '=', 'r.reviewed_by')
                ->orderByDesc('r.reviewed_at')
                ->limit(10)
                ->get([
                    'r.new_status',
                    'r.reviewed_at as created_at',
                    DB::raw("CONCAT(u.first_name, ' ', u.last_name) as actor_name"),
                    DB::raw("'building_location_review' as action_type"),
                    DB::raw("CONCAT('Building location marked ', r.new_status) as summary"),
                ])
            : collect();

        return $events->merge($reviews)
            ->sortByDesc('created_at')
            ->take(15)
            ->values()
            ->all();
    }

    private function countIfTable(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $callback($query);

        return (int) $query->count();
    }
}
