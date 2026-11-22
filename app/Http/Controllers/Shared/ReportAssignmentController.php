<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Services\AssignmentScope;
use App\Services\ReportAssignmentService;
use App\Services\ReportWorkflow;
use App\Services\SignatureConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportAssignmentController extends Controller
{
    public function __construct(
        private readonly ReportAssignmentService $assignments,
        private readonly ReportWorkflow $workflow,
        private readonly SignatureConfirmation $confirmation,
    ) {}

    public function personnel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'barangay_id' => ['nullable', 'integer'],
            'role' => ['required', 'in:bdrrm,rdana'],
            'summary' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('summary') && $data['role'] === 'rdana') {
            $user = $request->user();
            $user->loadMissing('role');
            if (! in_array($user->role?->code, ['rdana_admin_officer', 'rdana_city_assessor'], true)) {
                abort(403, 'You cannot manage assessor personnel.');
            }
            $scope = app(AssignmentScope::class);
            $summary = $this->assignments->assessorSummary($scope->activeBarangayIds($user->id), 'rdana');

            return response()->json(['data' => $summary['personnel'], 'summary' => $summary]);
        }

        if (empty($data['barangay_id'])) {
            abort(422, 'A barangay is required.');
        }

        return response()->json([
            'data' => $this->assignments->candidates((int) $data['barangay_id'], $data['role']),
        ]);
    }

    public function assignSubmission(Request $request, int $submission): JsonResponse
    {
        $data = $request->validate([
            'assignee_user_id' => ['required', 'integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'replaced_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->assignments->assign(
            $request->user(),
            'resident_incident_report',
            $submission,
            (int) $data['assignee_user_id'],
            'bdrrm',
            $data['remarks'] ?? null,
            $data['replaced_reason'] ?? null,
        );

        return response()->json(['message' => 'BDRRM personnel assigned.']);
    }

    public function assignSubmissions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'submission_ids' => ['required', 'array', 'min:1', 'max:100'],
            'submission_ids.*' => ['integer'],
            'assignee_user_id' => ['required', 'integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'replaced_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->assignments->assignMany(
            $request->user(),
            'resident_incident_report',
            $data['submission_ids'],
            (int) $data['assignee_user_id'],
            'bdrrm',
            $data['remarks'] ?? null,
            $data['replaced_reason'] ?? null,
        );

        return response()->json(['message' => 'BDRRM personnel assigned.']);
    }

    public function assignAssessor(Request $request, int $report): JsonResponse
    {
        $data = $request->validate([
            'assignee_user_id' => ['required', 'integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'replaced_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->assignments->assign(
            $request->user(),
            'barangay_report',
            $report,
            (int) $data['assignee_user_id'],
            'rdana',
            $data['remarks'] ?? null,
            $data['replaced_reason'] ?? null,
        );
        $this->startAssignedAssessment($report, (int) $data['assignee_user_id']);

        return response()->json(['message' => 'Assessor assigned.']);
    }

    public function assignAssessors(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignee_user_id' => ['required', 'integer'],
            'report_ids' => ['required', 'array', 'min:1', 'max:100'],
            'report_ids.*' => ['integer', 'distinct'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'replaced_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reportIds = array_values(array_unique(array_map('intval', $data['report_ids'])));
        $assigneeId = (int) $data['assignee_user_id'];
        $planned = $this->assignments->distributeBarangayReports(
            $request->user(),
            $reportIds,
            [['assignee_user_id' => $assigneeId, 'count' => count($reportIds)]],
            $data['remarks'] ?? null,
            $data['replaced_reason'] ?? null,
        );

        foreach ($planned as $row) {
            $this->startAssignedAssessment($row['report_id'], $row['assignee_user_id']);
        }

        return response()->json([
            'message' => 'Assessor assigned.',
            'assignments' => $planned,
        ]);
    }

    private function startAssignedAssessment(int $reportId, int $assigneeUserId): void
    {
        $report = DB::table('barangay_reports')->where('id', $reportId)->first();
        if ($report === null) {
            return;
        }
        $assessor = User::query()->find($assigneeUserId);
        if ($assessor === null) {
            return;
        }
        $this->workflow->startAssessment($assessor, $report);
    }

    public function endorse(Request $request, int $report): JsonResponse
    {
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        $request->validate([
            'confirmed' => ['accepted'],
            'use_saved_signature' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'file'],
        ]);
        if (! $request->hasFile('signature') && ! $request->boolean('use_saved_signature')) {
            abort(422, 'Draw a signature or choose your saved signature before checking.');
        }
        $this->workflow->endorseBySecretary($request->user(), $row, [
            'use_saved' => $request->boolean('use_saved_signature'),
            'file' => $request->file('signature'),
        ]);

        return response()->json(['message' => 'Report checked and signed for the punong barangay.']);
    }

    public function approve(Request $request, int $report): JsonResponse
    {
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        $request->validate([
            'confirmed' => ['accepted'],
            'use_saved_signature' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'file'],
        ]);
        if (! $request->hasFile('signature') && ! $request->boolean('use_saved_signature')) {
            abort(422, 'Draw a signature or choose your saved signature before endorsing.');
        }
        $this->workflow->approveByCaptain($request->user(), $row, [
            'use_saved' => $request->boolean('use_saved_signature'),
            'file' => $request->file('signature'),
        ]);

        return response()->json(['message' => 'Report endorsed and released to RDANA.']);
    }

    public function submitAssessment(Request $request, int $assessment): JsonResponse
    {
        $row = DB::table('field_assessments')->where('id', $assessment)->first();
        abort_if($row === null, 404);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $request->validate([
            'use_saved_signature' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'file'],
        ]);
        if (! $request->hasFile('signature') && ! $request->boolean('use_saved_signature')) {
            abort(422, 'Draw a signature or choose your saved signature before submitting.');
        }
        $this->workflow->submitAssessedDamage($request->user(), $row, [
            'use_saved' => $request->boolean('use_saved_signature'),
            'file' => $request->file('signature'),
        ]);

        return response()->json(['message' => 'Assessment submitted for verification.']);
    }

    public function verifyAssessment(Request $request, int $assessment): JsonResponse
    {
        $row = DB::table('field_assessments')->where('id', $assessment)->first();
        abort_if($row === null, 404);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $request->validate([
            'use_saved_signature' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'file'],
        ]);
        if (! $request->hasFile('signature') && ! $request->boolean('use_saved_signature')) {
            abort(422, 'Draw a signature or choose your saved signature before verifying.');
        }
        $this->workflow->verifyByAdminOfficer($request->user(), $row, [
            'use_saved' => $request->boolean('use_saved_signature'),
            'file' => $request->file('signature'),
        ]);

        return response()->json(['message' => 'Assessment verified.']);
    }

    public function forwardAssessment(Request $request, int $assessment): JsonResponse
    {
        $row = DB::table('field_assessments')->where('id', $assessment)->first();
        abort_if($row === null, 404);
        $this->confirmation->assertConfirmed($request->user(), $request);
        $request->validate([
            'use_saved_signature' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'file'],
        ]);
        if (! $request->hasFile('signature') && ! $request->boolean('use_saved_signature')) {
            abort(422, 'Draw a signature or choose your saved signature before reviewing.');
        }
        $this->workflow->approveAndForward($request->user(), $row, [
            'use_saved' => $request->boolean('use_saved_signature'),
            'file' => $request->file('signature'),
        ]);

        return response()->json(['message' => 'Assessment reviewed and marked ready for field consolidation.']);
    }

    public function returnAssessment(Request $request, int $assessment): JsonResponse
    {
        $row = DB::table('field_assessments')->where('id', $assessment)->first();
        abort_if($row === null, 404);
        $data = $request->validate([
            'return_reason' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        $this->workflow->returnFieldAssessment($request->user(), $row, $data['return_reason']);

        return response()->json(['message' => 'Assessment returned for correction.']);
    }
}
