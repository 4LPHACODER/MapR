<?php

namespace App\Http\Controllers\Assessor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shared\ReportFlagController;
use App\Policies\BarangayReportPolicy;
use App\Policies\FieldAssessmentPolicy;
use App\Services\AssignmentScope;
use App\Services\BuildingCostEstimator;
use App\Services\ReportAssignmentService;
use App\Services\ReportLineage;
use App\Services\ReportSignatoryCatalog;
use App\Services\ReportWorkflow;
use App\Services\SignatureConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssessmentController extends Controller
{
    public function __construct(
        private readonly AssignmentScope $scope,
        private readonly ReportWorkflow $workflow,
        private readonly ReportAssignmentService $assignments,
        private readonly FieldAssessmentPolicy $assessments,
        private readonly BarangayReportPolicy $reports,
        private readonly SignatureConfirmation $confirmation,
        private readonly ReportSignatoryCatalog $signatories,
        private readonly ReportLineage $lineage,
        private readonly BuildingCostEstimator $estimator,
    ) {}

    public function barangayReports(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');
        $activeIds = $this->scope->activeBarangayIds($user->id);
        $isSupervisor = in_array($user->role?->code, ['rdana_admin_officer', 'rdana_city_assessor'], true);
        $queue = (string) $request->input('queue', '');
        if (! in_array($queue, ['unassigned', 'pending', 'completed'], true)) {
            $queue = $isSupervisor ? 'unassigned' : 'pending';
        }
        if (! $isSupervisor && $queue === 'unassigned') {
            $queue = 'pending';
        }

        $query = $this->barangayReportsBaseQuery($user);
        $this->applyAssessorQueueVisibility($query, $user, $isSupervisor);
        $this->applyQueueFilter($query, $queue);
        $this->applyBarangayReportFilters($query, $request, $activeIds, $isSupervisor);

        $sort = (string) $request->input('sort', 'updated_at');
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['updated_at', 'submitted_at', 'report_reference'];
        if (! in_array($sort, $allowedSort, true) || ! Schema::hasColumn('barangay_reports', $sort)) {
            $sort = Schema::hasColumn('barangay_reports', 'updated_at') ? 'updated_at' : 'id';
        }
        $query->orderBy('reports.'.$sort, $direction)->orderByDesc('reports.id');

        $counts = $this->barangayReportCounts($user, $request, $isSupervisor);
        $canAssign = $isSupervisor;
        $payload = $this->page($query, $request, function ($row) use ($user, $canAssign, $queue) {
            return $this->mapBarangayQueueRow($row, $user, $canAssign, $queue);
        });
        $payload['counts'] = $counts;
        $payload['queue'] = $queue;
        $payload['can_assign'] = $canAssign;
        $payload['barangays'] = $this->assignedBarangays($activeIds);
        $payload['personnel'] = $canAssign && $activeIds !== []
            ? $this->assignments->candidates($activeIds[0], 'rdana')
            : [];

        return response()->json($payload);
    }

    private function barangayReportsBaseQuery(object $user)
    {
        $query = DB::table('barangay_reports as reports')
            ->select('reports.*');
        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', 'barangays.id', '=', 'reports.barangay_id')
                ->addSelect('barangays.barangay_name');
        }
        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'reports.disaster_incident_id')
                ->addSelect('disaster_incidents.incident_name');
        }
        if (Schema::hasTable('users')) {
            $query->leftJoin('users as preparer', 'preparer.id', '=', 'reports.prepared_by');
            $query->addSelect('preparer.first_name as preparer_first_name', 'preparer.last_name as preparer_last_name');
            if (Schema::hasColumn('users', 'middle_name')) {
                $query->addSelect('preparer.middle_name as preparer_middle_name');
            }
        }
        $this->scope->constrainAssessorVisibleReports($query, $user);
        $query->whereIn('reports.report_status', ['review', 'signed', 'submitted', 'returned']);

        return $query;
    }

    private function applyAssessorQueueVisibility($query, object $user, bool $isSupervisor): void
    {
        if ($isSupervisor || ! Schema::hasTable('report_assignments')) {
            return;
        }

        $userId = (int) $user->id;
        $query->where(function ($inner) use ($userId) {
            $inner->whereExists(function ($assigned) use ($userId) {
                $assigned->selectRaw('1')
                    ->from('report_assignments')
                    ->whereColumn('report_assignments.subject_id', 'reports.id')
                    ->where('report_assignments.subject_type', 'barangay_report')
                    ->where('report_assignments.assignment_role', 'rdana')
                    ->where('report_assignments.is_active', true)
                    ->where('report_assignments.assignee_user_id', $userId);
            });
            if (Schema::hasTable('field_assessments')) {
                $inner->orWhereExists(function ($fa) use ($userId) {
                    $fa->selectRaw('1')
                        ->from('field_assessments')
                        ->whereColumn('field_assessments.barangay_report_id', 'reports.id')
                        ->where('field_assessments.assigned_assessor_id', $userId);
                });
            }
        });
    }

    private function applyQueueFilter($query, string $queue): void
    {
        if (! Schema::hasTable('report_assignments')) {
            if ($queue === 'unassigned') {
                return;
            }
            $query->whereRaw('1 = 0');

            return;
        }

        $hasActiveAssignment = function ($builder) {
            $builder->selectRaw('1')
                ->from('report_assignments')
                ->whereColumn('report_assignments.subject_id', 'reports.id')
                ->where('report_assignments.subject_type', 'barangay_report')
                ->where('report_assignments.assignment_role', 'rdana')
                ->where('report_assignments.is_active', true);
        };

        if ($queue === 'unassigned') {
            $query->where('reports.report_status', 'review')
                ->whereNotExists($hasActiveAssignment);
            if (Schema::hasTable('field_assessments')) {
                $query->whereNotExists(function ($fa) {
                    $fa->selectRaw('1')
                        ->from('field_assessments')
                        ->whereColumn('field_assessments.barangay_report_id', 'reports.id');
                });
            }

            return;
        }

        $query->where(function ($inner) use ($hasActiveAssignment) {
            $inner->whereExists($hasActiveAssignment);
            if (Schema::hasTable('field_assessments')) {
                $inner->orWhereExists(function ($fa) {
                    $fa->selectRaw('1')
                        ->from('field_assessments')
                        ->whereColumn('field_assessments.barangay_report_id', 'reports.id');
                });
            }
        });

        if ($queue === 'pending') {
            $query->where(function ($inner) {
                $inner->whereNotExists(function ($done) {
                    $done->selectRaw('1')
                        ->from('field_assessments')
                        ->whereColumn('field_assessments.barangay_report_id', 'reports.id')
                        ->where(function ($status) {
                            $status->whereIn('field_assessments.assessment_status', ['signed', 'submitted']);
                            if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
                                $status->orWhereIn('field_assessments.workflow_stage', [
                                    'pending_admin_verification',
                                    'pending_city_assessor',
                                    'forwarded_cdrrmo',
                                ]);
                            }
                        });
                });
            });

            return;
        }

        // completed
        $query->whereExists(function ($done) {
            $done->selectRaw('1')
                ->from('field_assessments')
                ->whereColumn('field_assessments.barangay_report_id', 'reports.id')
                ->where(function ($status) {
                    $status->whereIn('field_assessments.assessment_status', ['signed', 'submitted']);
                    if (Schema::hasColumn('field_assessments', 'workflow_stage')) {
                        $status->orWhereIn('field_assessments.workflow_stage', [
                            'pending_admin_verification',
                            'pending_city_assessor',
                            'forwarded_cdrrmo',
                        ]);
                    }
                });
        });
    }

    private function applyBarangayReportFilters($query, Request $request, array $activeIds, bool $isSupervisor): void
    {
        if ($request->filled('barangay_id')) {
            $barangayId = $request->integer('barangay_id');
            if (! in_array($barangayId, $activeIds, true)) {
                abort(403, 'That barangay is outside your assignment.');
            }
            $query->where('reports.barangay_id', $barangayId);
        }
        if ($request->filled('assignee_user_id') && Schema::hasTable('report_assignments')) {
            $assigneeId = $request->integer('assignee_user_id');
            $query->whereExists(function ($assigned) use ($assigneeId) {
                $assigned->selectRaw('1')
                    ->from('report_assignments')
                    ->whereColumn('report_assignments.subject_id', 'reports.id')
                    ->where('report_assignments.subject_type', 'barangay_report')
                    ->where('report_assignments.assignment_role', 'rdana')
                    ->where('report_assignments.is_active', true)
                    ->where('report_assignments.assignee_user_id', $assigneeId);
            });
        }
        if ($request->filled('assessment_status')) {
            $progress = (string) $request->string('assessment_status');
            if (in_array($progress, ['not_opened', 'assessing', 'draft'], true)) {
                // Filtered in map/post would be expensive; constrain via field_assessments when possible.
                if ($progress === 'not_opened') {
                    $query->whereNotExists(function ($fa) {
                        $fa->selectRaw('1')
                            ->from('field_assessments')
                            ->whereColumn('field_assessments.barangay_report_id', 'reports.id')
                            ->where(function ($inner) {
                                $inner->whereNotNull('field_assessments.findings')
                                    ->where('field_assessments.findings', '!=', '');
                            });
                    });
                }
            }
        }
        if ($request->filled('incident_id')) {
            $query->where('reports.disaster_incident_id', $request->integer('incident_id'));
        }
        if ($request->filled('submitted_from') && Schema::hasColumn('barangay_reports', 'submitted_at')) {
            $query->whereDate('reports.submitted_at', '>=', (string) $request->string('submitted_from'));
        }
        if ($request->filled('submitted_to') && Schema::hasColumn('barangay_reports', 'submitted_at')) {
            $query->whereDate('reports.submitted_at', '<=', (string) $request->string('submitted_to'));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            if ($term !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->where(function ($inner) use ($like) {
                    $inner->whereRaw('reports.report_reference LIKE ? ESCAPE ?', [$like, '\\']);
                    if (Schema::hasTable('barangays')) {
                        $inner->orWhereRaw('barangays.barangay_name LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                    if (Schema::hasTable('disaster_incidents')) {
                        $inner->orWhereRaw('disaster_incidents.incident_name LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                });
            }
        }
    }

    private function mapBarangayQueueRow(object $row, object $user, bool $canAssign, string $queue): array
    {
        $name = trim(implode(' ', array_filter([
            $row->preparer_first_name ?? null,
            $row->preparer_middle_name ?? null,
            $row->preparer_last_name ?? null,
        ], fn ($part) => is_string($part) && $part !== '')));

        $assignment = null;
        if (Schema::hasTable('report_assignments')) {
            $assignment = DB::table('report_assignments')
                ->where('subject_type', 'barangay_report')
                ->where('subject_id', $row->id)
                ->where('assignment_role', 'rdana')
                ->where('is_active', true)
                ->first();
        }

        $assigneeName = null;
        $assignedAt = $assignment?->created_at ?? null;
        if ($assignment && Schema::hasTable('users')) {
            $assignee = DB::table('users')->where('id', $assignment->assignee_user_id)->first();
            if ($assignee) {
                $assigneeName = trim($assignee->first_name.' '.$assignee->last_name);
            }
        }

        $assessment = null;
        if (Schema::hasTable('field_assessments')) {
            $assessment = DB::table('field_assessments')
                ->where('barangay_report_id', $row->id)
                ->orderByDesc('id')
                ->first();
        }

        $bucket = $queue === 'unassigned'
            ? 'unassigned'
            : $this->assignments->assessmentBucket((int) $row->id);
        $progressLabel = match ($bucket) {
            'unassigned' => 'Unassigned',
            'not_opened' => 'Not Opened',
            'assessing' => 'Assessing',
            'draft' => 'Assessment Draft',
            'completed' => 'Assessment Submitted',
            default => $this->assessorLabel($row->report_status),
        };

        $draftId = null;
        $assessmentId = $assessment ? (int) $assessment->id : null;
        if ($assessment && in_array((string) $assessment->assessment_status, ['draft', 'returned'], true)) {
            $draftId = (int) $assessment->id;
        }

        $residentName = null;
        if (Schema::hasTable('resident_incident_reports') && Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
            $source = DB::table('resident_incident_reports as rir')
                ->leftJoin('users as residents', 'residents.id', '=', 'rir.resident_user_id')
                ->where('rir.barangay_report_id', $row->id)
                ->first(['residents.first_name', 'residents.last_name', 'rir.report_reference']);
            if ($source) {
                $residentName = trim(($source->first_name ?? '').' '.($source->last_name ?? ''));
                if ($residentName === '') {
                    $residentName = $source->report_reference ?? null;
                }
            }
        }

        $buildingStatusLabel = null;
        $estimatedCost = null;
        $verificationLabel = $progressLabel;
        if ($assessment && Schema::hasTable('building_damage_cost_assessments')) {
            $damageQuery = DB::table('building_damage_cost_assessments as a')
                ->where('a.barangay_report_id', $row->id)
                ->whereIn('a.assessment_status', ['confirmed', 'draft'])
                ->orderByRaw("case when a.assessment_status = 'confirmed' then 0 else 1 end")
                ->orderByDesc('a.id');
            if (Schema::hasTable('damage_classes')) {
                $damage = $damageQuery
                    ->leftJoin('damage_classes as confirmed', 'confirmed.id', '=', 'a.confirmed_damage_class_id')
                    ->first(['a.estimated_cost_php', 'confirmed.class_code', 'confirmed.class_name']);
                if ($damage) {
                    $buildingStatusLabel = app(BuildingCostEstimator::class)
                        ->displayLabel($damage->class_code ?? null, $damage->class_name ?? null);
                    $estimatedCost = $damage->estimated_cost_php !== null ? (float) $damage->estimated_cost_php : null;
                }
            } else {
                $damage = $damageQuery->first(['a.estimated_cost_php']);
                $estimatedCost = $damage && $damage->estimated_cost_php !== null ? (float) $damage->estimated_cost_php : null;
            }
        }
        $faStage = (string) ($assessment->workflow_stage ?? '');
        $verificationLabel = match ($faStage) {
            'pending_admin_verification' => 'Awaiting Administrative Verification',
            'pending_city_assessor' => 'Awaiting City Assessor Notation',
            'forwarded_cdrrmo' => 'Forwarded to CDRRMO',
            default => $progressLabel,
        };
        if ($assessment && (string) $assessment->assessment_status === 'returned') {
            $verificationLabel = 'Returned for Correction';
        }

        return [
            'id' => (int) $row->id,
            'reference' => $row->report_reference,
            'status' => $row->report_status,
            'workflow_stage' => $row->workflow_stage ?? null,
            'assessment_workflow_stage' => $assessment->workflow_stage ?? null,
            'label' => $progressLabel,
            'verification_label' => $verificationLabel,
            'queue' => $queue,
            'assessment_progress' => $bucket,
            'barangay_id' => (int) $row->barangay_id,
            'barangay_name' => $row->barangay_name ?? null,
            'disaster_incident_id' => $row->disaster_incident_id,
            'incident_name' => $row->incident_name ?? null,
            'submitted_by' => $name !== '' ? $name : null,
            'reporting_resident' => $residentName,
            'submitted_at' => $row->submitted_at ?? null,
            'updated_at' => $row->updated_at,
            'return_reason' => $row->return_reason ?? null,
            'assignee_user_id' => $assignment ? (int) $assignment->assignee_user_id : ($assessment->assigned_assessor_id ?? null),
            'assignee_name' => $assigneeName,
            'assigned_at' => $assignedAt,
            'assessment_id' => $assessmentId,
            'draft_id' => $draftId,
            'building_status_label' => $buildingStatusLabel,
            'estimated_cost_php' => $estimatedCost,
            'can_assign' => $canAssign && $queue === 'unassigned',
            'can_open_assessment' => $assessmentId !== null || ($assignment && (int) ($assignment->assignee_user_id ?? 0) === (int) $user->id),
            'can_view' => true,
            'can_download' => true,
        ];
    }

    private function assessorLabel(?string $status): string
    {
        return match ($status) {
            'review' => 'Barangay Report',
            'signed' => 'Signed',
            'submitted' => 'Submitted',
            'returned' => 'Returned for Correction',
            default => $this->workflow->label($status),
        };
    }

    private function barangayReportCounts(object $user, Request $request, bool $isSupervisor): array
    {
        $unassigned = $this->countQueue($user, $request, 'unassigned', $isSupervisor);
        $pending = $this->countQueue($user, $request, 'pending', $isSupervisor);
        $completed = $this->countQueue($user, $request, 'completed', $isSupervisor);

        return [
            'unassigned' => $isSupervisor ? $unassigned : 0,
            'pending' => $pending,
            'completed' => $completed,
            'total' => ($isSupervisor ? $unassigned : 0) + $pending + $completed,
            // Legacy keys for older UI / tests during transition
            'awaiting' => $isSupervisor ? $unassigned + $pending : $pending,
            'returned' => 0,
            'processed' => $completed,
        ];
    }

    private function countQueue(object $user, Request $request, string $queue, bool $isSupervisor): int
    {
        if (! $isSupervisor && $queue === 'unassigned') {
            return 0;
        }
        $query = $this->barangayReportsBaseQuery($user);
        $this->applyAssessorQueueVisibility($query, $user, $isSupervisor);
        $this->applyQueueFilter($query, $queue);
        if ($request->filled('barangay_id')) {
            $query->where('reports.barangay_id', $request->integer('barangay_id'));
        }

        return (int) $query->count('reports.id');
    }

    private function assignedBarangays(array $ids): array
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

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('field_assessments')
            ->where('assigned_assessor_id', $request->user()->id)
            ->orderBy('updated_at');
        if ($request->filled('status')) {
            $query->where('assessment_status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $query->where('assessment_reference', 'like', '%'.$request->string('search').'%');
        }

        return response()->json($this->page($query, $request, fn ($row) => $this->row($row)));
    }

    public function show(Request $request, int $assessment): JsonResponse
    {
        $row = $this->find($assessment);
        abort_unless($this->assessments->view($request->user(), $row), 403);

        return response()->json(['data' => $this->detail($row)]);
    }

    public function start(Request $request, int $report): JsonResponse
    {
        $barangayReport = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($barangayReport === null, 404);
        abort_unless($this->reports->view($request->user(), $barangayReport), 403);
        abort_unless($barangayReport->report_status === 'review', 422, 'Only a report awaiting review can be assessed.');
        $id = $this->workflow->startAssessment($request->user(), $barangayReport);

        return response()->json(['data' => $this->detail($this->find($id))], 201);
    }

    public function findings(Request $request, int $assessment): JsonResponse
    {
        $row = $this->editable($request, $assessment);
        $data = $request->validate([
            'findings' => ['nullable', 'string', 'max:8000'],
            'updated_at' => ['nullable', 'string'],
        ]);
        $this->workflow->saveFindings($request->user(), $row, $data['findings'] ?? null, $data['updated_at'] ?? null);

        return response()->json(['data' => $this->detail($this->find($assessment))]);
    }

    public function verification(Request $request, int $assessment): JsonResponse
    {
        $row = $this->editable($request, $assessment);
        $data = $request->validate([
            'building_id' => ['required', 'integer'],
            'location_verified' => ['required', 'boolean'],
            'household_link_verified' => ['required', 'boolean'],
            'verified_damage_class_id' => ['required', 'integer'],
            'verification_notes' => ['nullable', 'string', 'max:5000'],
            'estimated_repair_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);
        $report = DB::table('barangay_reports')->where('id', $row->barangay_report_id)->first();
        $this->scope->assertBarangay($request, (int) $report->barangay_id);
        abort_unless(
            DB::table('buildings')->where('id', $data['building_id'])->where('barangay_id', $report->barangay_id)->exists(),
            422,
            'Choose a building from the assigned report.',
        );
        abort_unless(DB::table('damage_classes')->where('id', $data['verified_damage_class_id'])->exists(), 422, 'Choose a field damage class.');
        $this->workflow->saveVerification($request->user(), $row, $data);

        return response()->json(['data' => $this->detail($this->find($assessment))]);
    }

    public function verify(Request $request, int $assessment): JsonResponse
    {
        $row = $this->editable($request, $assessment);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $this->workflow->signAssessment($request->user(), $row);

        return response()->json(['data' => $this->detail($this->find($assessment))]);
    }

    public function approve(Request $request, int $assessment): JsonResponse
    {
        $row = $this->find($assessment);
        abort_unless($this->assessments->view($request->user(), $row), 403);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $this->workflow->approveAssessment($request->user(), $row);

        return response()->json(['data' => $this->detail($this->find($assessment))]);
    }

    public function submit(Request $request, int $assessment): JsonResponse
    {
        $row = $this->find($assessment);
        abort_unless($this->assessments->view($request->user(), $row), 403);
        if ($row->assessment_status === 'submitted') {
            abort(409, 'This assessment was already submitted.');
        }
        $this->workflow->submitAssessment($request->user(), $row, $request->input('updated_at'));

        return response()->json(['data' => $this->detail($this->find($assessment))]);
    }

    public function returnReport(Request $request, int $report): JsonResponse
    {
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        abort_unless($this->scope->assessorCanOpenReport($request->user(), (int) $row->id), 403);
        $data = $request->validate([
            'return_reason' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        if (in_array($row->report_status, ['signed', 'submitted'], true)) {
            $id = $this->workflow->copyReturnedSuccessor($request->user(), $row, $data['return_reason']);

            return response()->json(['data' => ['id' => $id, 'supersedes_id' => $row->id, 'status' => 'returned']]);
        }

        $id = $this->workflow->returnBarangayReport($request->user(), $row, $data['return_reason']);

        return response()->json(['data' => DB::table('barangay_reports')->where('id', $id)->first()]);
    }

    public function damageClasses(): JsonResponse
    {
        $rows = Schema::hasTable('damage_classes')
            ? DB::table('damage_classes')->orderBy('display_order')->get(['id', 'class_code', 'class_name'])
            : collect();

        return response()->json(['data' => $rows]);
    }

    private function editable(Request $request, int $assessment): object
    {
        $row = $this->find($assessment);
        abort_unless($this->assessments->update($request->user(), $row), 403);

        return $row;
    }

    private function find(int $id): object
    {
        $row = DB::table('field_assessments')->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function detail(object $row): array
    {
        $report = DB::table('barangay_reports')->where('id', $row->barangay_report_id)->first();
        $verifications = Schema::hasTable('building_verifications')
            ? DB::table('building_verifications')->where('field_assessment_id', $row->id)->get()
            : collect();

        $signatures = Schema::hasTable('report_signatures')
            ? DB::table('report_signatures')->where('field_assessment_id', $row->id)->get($this->signatureColumns())->all()
            : [];
        $sourceSignatures = $report && Schema::hasTable('report_signatures')
            ? DB::table('report_signatures')->where('barangay_report_id', $report->id)->where('signature_role', 'barangay_signer')->exists()
            : false;
        $incident = $report ? DB::table('disaster_incidents')->where('id', $report->disaster_incident_id)->first() : null;
        $disasterType = null;
        if ($incident && Schema::hasTable('disaster_types') && Schema::hasColumn('disaster_incidents', 'disaster_type_id')) {
            $disasterType = DB::table('disaster_types')->where('id', $incident->disaster_type_id)->value('name');
        }
        $barangay = $report ? DB::table('barangays')->where('id', $report->barangay_id)->first() : null;
        $assessor = DB::table('users')->where('id', $row->assigned_assessor_id)->first();
        $buildingLink = $report && Schema::hasTable('barangay_report_buildings')
            ? DB::table('barangay_report_buildings')->where('barangay_report_id', $report->id)->first()
            : null;
        $building = $buildingLink ? DB::table('buildings')->where('id', $buildingLink->building_id)->first() : null;
        $household = $building && Schema::hasTable('households')
            ? DB::table('households')->where('building_id', $building->id)->orderBy('id')->first()
            : null;
        $photos = $report && Schema::hasTable('incident_photos')
            ? DB::table('incident_photos')->where('barangay_report_id', $report->id)->get(['id', 'original_filename', 'mime_type'])
            : collect();
        $predictions = Schema::hasTable('building_damage_predictions') && $photos->isNotEmpty()
            ? DB::table('building_damage_predictions')->whereIn('incident_photo_id', $photos->pluck('id'))->get(['id', 'incident_photo_id', 'prediction_status', 'failure_reason'])
            : collect();
        $openFlags = Schema::hasTable('report_flags')
            ? DB::table('report_flags')->where('subject_type', 'field_assessment')->where('subject_id', $row->id)->where('status', 'open')->count()
            : 0;
        $successor = Schema::hasColumn('field_assessments', 'supersedes_id')
            ? DB::table('field_assessments')->where('supersedes_id', $row->id)->exists()
            : false;
        $user = auth()->user();
        $user?->loadMissing('role');
        $role = $user?->role?->code;
        $canSubmitAssessed = in_array((string) $row->assessment_status, ['draft', 'returned'], true)
            && ! in_array((string) ($row->workflow_stage ?? ''), ['pending_admin_verification', 'pending_city_assessor', 'forwarded_cdrrmo'], true)
            && $role === 'rdana'
            && (int) $row->assigned_assessor_id === (int) ($user?->id ?? 0);
        $canVerifyOfficer = $role === 'rdana_admin_officer'
            && (string) ($row->workflow_stage ?? '') === 'pending_admin_verification';
        $canForwardCity = $role === 'rdana_city_assessor'
            && (string) ($row->workflow_stage ?? '') === 'pending_city_assessor';
        $hasSigningAuthority = $canSubmitAssessed || $canVerifyOfficer || $canForwardCity;
        $verified = collect($signatures)->contains(fn ($signature) => $signature->signature_role === 'verified_by' && $signature->mechanism === 'authenticated_e_signature');
        $approved = collect($signatures)->contains(fn ($signature) => $signature->signature_role === 'approved_by' && $signature->mechanism === 'authenticated_e_signature');
        $verification = $verifications->first();
        $findingsPresent = trim((string) $row->findings) !== '';
        $damageSummary = $report ? $this->damageSummaryForReport((int) $report->id) : null;
        $damageReady = $damageSummary !== null && in_array($damageSummary['assessment_status'], ['draft', 'confirmed'], true);
        $checklist = [
            ['key' => 'fields', 'label' => 'Required fields complete', 'met' => $findingsPresent, 'detail' => $findingsPresent ? 'Field findings are saved.' : 'Record field findings before signing.'],
            ['key' => 'damage', 'label' => 'Building damage assessment recorded', 'met' => $damageReady, 'detail' => $damageReady ? 'Damage assessment data is saved.' : 'Complete the building damage assessment before signing.'],
            ['key' => 'evidence', 'label' => 'Required evidence attached', 'met' => $photos->isNotEmpty(), 'detail' => $photos->isNotEmpty() ? $photos->count().' photograph(s) on the source report.' : 'The source report has no photograph.'],
            ['key' => 'verification', 'label' => 'Required location and household verification complete', 'met' => $verification && $verification->location_verified && $verification->household_link_verified, 'detail' => 'Save location and household verification through the assessment workflow.'],
            ['key' => 'predecessor', 'label' => 'Required predecessor signatures present', 'met' => $sourceSignatures, 'detail' => $sourceSignatures ? 'The barangay signer has signed the source report.' : 'The source report has no barangay signature.'],
            ['key' => 'authority', 'label' => 'Current user has signing authority', 'met' => $hasSigningAuthority, 'detail' => $canSubmitAssessed
                ? 'This account can sign as Assessed by.'
                : ($canVerifyOfficer
                    ? 'This account can sign as Verified by.'
                    : ($canForwardCity ? 'This account can sign as Noted by (City Assessor).' : 'This account cannot sign the next required slot.'))],
            ['key' => 'flags', 'label' => 'No unresolved blocking flags', 'met' => $openFlags === 0, 'detail' => $openFlags === 0 ? 'No open flags.' : $openFlags.' open flag(s).'],
            ['key' => 'current', 'label' => 'Report version is current', 'met' => ! $successor, 'detail' => $successor ? 'A newer revision exists.' : 'This is the current version.'],
        ];

        $signaturePayload = $report
            ? $this->signatories->barangayReportViewerPayload($report, $this->lineage)
            : ['slots' => [], 'signed_count' => 0, 'lineage' => []];
        $lineageResolved = $report
            ? $this->lineage->forBarangayReport((int) $report->id)
            : [
                'resident_report_id' => null,
                'resident_report_reference' => null,
                'resident_reference' => 'Not recorded',
                'building_reference' => 'Not recorded',
                'household_reference' => 'Not recorded',
            ];

        $assessorName = $assessor
            ? trim(implode(' ', array_filter([$assessor->first_name, $assessor->middle_name, $assessor->last_name])))
            : null;

        return [
            ...$this->row($row),
            'kind' => 'field_assessment',
            'title' => 'Field Assessment Report',
            'findings' => $row->findings,
            'return_reason' => property_exists($row, 'return_reason') ? $row->return_reason : null,
            'can_edit' => in_array($row->assessment_status, ['draft', 'returned'], true)
                && $role === 'rdana'
                && (int) $row->assigned_assessor_id === (int) ($user?->id ?? 0),
            'can_return_assessment' => in_array($role, ['rdana_admin_officer', 'rdana_city_assessor'], true)
                && in_array((string) ($row->workflow_stage ?? ''), ['pending_admin_verification', 'pending_city_assessor'], true),
            'version' => 1 + ((property_exists($row, 'supersedes_id') && $row->supersedes_id) ? 1 : 0),
            'barangay_report' => $report ? [
                'id' => $report->id,
                'reference' => $report->report_reference,
                'status' => $report->report_status,
                'label' => $this->workflow->label($report->report_status),
                'description' => $report->report_description,
                'return_reason' => property_exists($report, 'return_reason') ? $report->return_reason : null,
                'barangay_id' => $report->barangay_id,
                'disaster_incident_id' => $report->disaster_incident_id,
                'signed' => $sourceSignatures,
            ] : null,
            'resident_report' => [
                'id' => $lineageResolved['resident_report_id'],
                'reference' => $lineageResolved['resident_report_reference'],
            ],
            'lineage' => $signaturePayload['lineage'] ?: $lineageResolved,
            'incident' => $incident ? [
                'id' => $incident->id,
                'reference' => $incident->incident_reference,
                'name' => $incident->incident_name,
                'disaster_type' => $disasterType,
            ] : null,
            'barangay' => $barangay ? ['id' => $barangay->id, 'name' => $barangay->barangay_name] : null,
            'assessor' => $assessor ? ['id' => $assessor->id, 'name' => $assessorName] : null,
            'building' => $building ? ['id' => $building->id, 'reference' => $building->building_reference] : null,
            'household' => $household ? ['id' => $household->id, 'reference' => $household->household_reference] : null,
            'resident' => [
                'reference' => $lineageResolved['resident_reference'] ?? 'Not recorded',
            ],
            'photos' => $photos,
            'predictions' => $predictions,
            'verifications' => $verifications,
            'signatures' => $signatures,
            'signature_slots' => $signaturePayload['slots'],
            'assessment_signature_slots' => $this->signatories->resolveParentSlots(
                'field_assessment_id',
                (int) $row->id,
                $this->signatories->fieldSlots($assessorName),
            ),
            'signature_signed_count' => $signaturePayload['signed_count'],
            'damage_summary' => $damageSummary,
            'verified' => $verified,
            'approved' => $approved,
            'checklist' => $checklist,
            'open_flags' => $openFlags,
            'flags' => ReportFlagController::forSubject('field_assessment', (int) $row->id),
            'attachment' => $this->attachmentFor($row->assessment_reference),
            'timeline' => Schema::hasTable('activity_events')
                ? DB::table('activity_events')->where('subject_type', 'field_assessment')->where('subject_id', $row->id)->orderBy('created_at')->get(['action_type', 'summary', 'created_at'])
                : [],
        ];
    }

    private function row(object $row): array
    {
        $stage = property_exists($row, 'workflow_stage') ? $row->workflow_stage : null;
        $labelSource = $stage ?: $row->assessment_status;

        return [
            'id' => $row->id,
            'reference' => $row->assessment_reference,
            'status' => $row->assessment_status,
            'workflow_stage' => $stage,
            'label' => $this->workflow->label($labelSource),
            'barangay_report_id' => $row->barangay_report_id,
            'assigned_assessor_id' => $row->assigned_assessor_id,
            'supersedes_id' => property_exists($row, 'supersedes_id') ? $row->supersedes_id : null,
            'updated_at' => $row->updated_at,
            'can_submit_assessed' => in_array((string) $row->assessment_status, ['draft', 'returned'], true)
                && ! in_array((string) ($row->workflow_stage ?? ''), ['pending_admin_verification', 'pending_city_assessor', 'forwarded_cdrrmo'], true),
            'can_verify_officer' => (string) ($row->workflow_stage ?? '') === 'pending_admin_verification',
            'can_forward_city' => (string) ($row->workflow_stage ?? '') === 'pending_city_assessor',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function damageSummaryForReport(int $barangayReportId): ?array
    {
        if (! Schema::hasTable('building_damage_cost_assessments')) {
            return null;
        }

        $query = DB::table('building_damage_cost_assessments as a')
            ->where('a.barangay_report_id', $barangayReportId)
            ->whereIn('a.assessment_status', ['draft', 'confirmed'])
            ->orderByDesc('a.id');

        $select = ['a.*'];
        if (Schema::hasTable('damage_classes') && Schema::hasColumn('building_damage_cost_assessments', 'confirmed_damage_class_id')) {
            $query->leftJoin('damage_classes as confirmed', 'confirmed.id', '=', 'a.confirmed_damage_class_id');
            $select[] = 'confirmed.class_code as confirmed_class_code';
            $select[] = 'confirmed.class_name as confirmed_class_name';
        }
        if (Schema::hasTable('damage_classes') && Schema::hasColumn('building_damage_cost_assessments', 'proposed_damage_class_id')) {
            $query->leftJoin('damage_classes as proposed', 'proposed.id', '=', 'a.proposed_damage_class_id');
            $select[] = 'proposed.class_code as proposed_class_code';
            $select[] = 'proposed.class_name as proposed_class_name';
        }
        $row = $query->first($select);
        if ($row === null) {
            return null;
        }

        $code = $row->confirmed_class_code ?? $row->proposed_class_code ?? null;
        $name = $row->confirmed_class_name ?? $row->proposed_class_name ?? null;

        return [
            'id' => (int) $row->id,
            'assessment_status' => (string) $row->assessment_status,
            'building_status' => $this->estimator->displayLabel($code, $name),
            'estimated_cost_php' => $row->estimated_cost_php !== null ? (float) $row->estimated_cost_php : null,
            'cost_status' => $row->cost_status ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function page($query, Request $request, callable $map): array
    {
        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }
        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())->map($map)->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    private function signatureColumns(): array
    {
        $columns = ['signature_role', 'signed_at', 'signer_user_id', 'mechanism'];
        if (Schema::hasColumn('report_signatures', 'signature_statement')) {
            $columns[] = 'signature_statement';
        }

        return $columns;
    }

    private function attachmentFor(string $reference): ?array
    {
        if (! Schema::hasTable('imported_report_documents')) {
            return null;
        }
        $row = DB::table('imported_report_documents')->where('import_key', $reference)->first();
        if ($row === null) {
            return null;
        }

        return [
            'import_key' => $row->import_key,
            'filename' => $row->filename,
            'download_path' => request()->segment(1).'/api/documents/'.$row->import_key,
        ];
    }
}
