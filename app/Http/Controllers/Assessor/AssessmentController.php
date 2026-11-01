<?php

namespace App\Http\Controllers\Assessor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shared\ReportFlagController;
use App\Policies\BarangayReportPolicy;
use App\Policies\FieldAssessmentPolicy;
use App\Services\AssignmentScope;
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
        private readonly FieldAssessmentPolicy $assessments,
        private readonly BarangayReportPolicy $reports,
        private readonly SignatureConfirmation $confirmation,
    ) {}

    public function barangayReports(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $this->scope->ids($request);
        $query = DB::table('barangay_reports')->orderBy('updated_at');
        $query->where(function ($inner) use ($user, $ids) {
            if ($ids !== []) {
                $inner->whereIn('barangay_id', $ids);
            }
            $inner->orWhereIn('id', function ($assigned) use ($user) {
                $assigned->select('barangay_report_id')->from('field_assessments')->where('assigned_assessor_id', $user->id);
            });
        });
        if ($request->filled('status')) {
            $query->where('report_status', $request->string('status'));
        }
        if ($request->filled('incident_id')) {
            $query->where('disaster_incident_id', $request->integer('incident_id'));
        }
        if ($request->filled('search')) {
            $query->where('report_reference', 'like', '%'.$request->string('search').'%');
        }

        return response()->json($this->page($query, $request, fn ($row) => [
            'id' => $row->id,
            'reference' => $row->report_reference,
            'status' => $row->report_status,
            'label' => $this->workflow->label($row->report_status),
            'barangay_id' => $row->barangay_id,
            'updated_at' => $row->updated_at,
            'return_reason' => $row->return_reason ?? null,
        ]));
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
        $canVerify = $user && (int) $row->assigned_assessor_id === (int) $user->id
            && strcasecmp((string) $user->email, (string) config('mapr.assessor_verifier_email', 'mark.merillo@mapr.test')) === 0;
        $approverEmail = (string) config('mapr.assessor_approver_email');
        $approverName = (string) config('mapr_reference.assessor_approver.name');
        $canApprove = $user && (int) $row->assigned_assessor_id !== (int) $user->id && (
            strcasecmp((string) $user->email, 'lovy.dedicatoria@mapr.test') === 0
            || ($approverEmail !== '' && strcasecmp((string) $user->email, $approverEmail) === 0)
            || ($approverName !== '' && strcasecmp(trim((string) $user->display_name), trim($approverName)) === 0)
        );
        $verified = collect($signatures)->contains(fn ($signature) => $signature->signature_role === 'verified_by' && $signature->mechanism === 'authenticated_e_signature');
        $approved = collect($signatures)->contains(fn ($signature) => $signature->signature_role === 'approved_by' && $signature->mechanism === 'authenticated_e_signature');
        $verification = $verifications->first();
        $findingsPresent = trim((string) $row->findings) !== '';
        $checklist = [
            ['key' => 'fields', 'label' => 'Required fields complete', 'met' => $findingsPresent, 'detail' => $findingsPresent ? 'Field findings are saved.' : 'Record field findings before signing.'],
            ['key' => 'evidence', 'label' => 'Required evidence attached', 'met' => $photos->isNotEmpty(), 'detail' => $photos->isNotEmpty() ? $photos->count().' photograph(s) on the source report.' : 'The source report has no photograph.'],
            ['key' => 'verification', 'label' => 'Required location and household verification complete', 'met' => $verification && $verification->location_verified && $verification->household_link_verified, 'detail' => 'Save location and household verification through the assessment workflow.'],
            ['key' => 'predecessor', 'label' => 'Required predecessor signatures present', 'met' => $sourceSignatures, 'detail' => $sourceSignatures ? 'The barangay signer has signed the source report.' : 'The source report has no barangay signature.'],
            ['key' => 'authority', 'label' => 'Current user has signing authority', 'met' => $canVerify || $canApprove, 'detail' => $canVerify ? 'This account can sign as verified by.' : ($canApprove ? 'This account can sign as approved by.' : 'This account cannot sign either assessment slot.')],
            ['key' => 'flags', 'label' => 'No unresolved blocking flags', 'met' => $openFlags === 0, 'detail' => $openFlags === 0 ? 'No open flags.' : $openFlags.' open flag(s).'],
            ['key' => 'current', 'label' => 'Report version is current', 'met' => ! $successor, 'detail' => $successor ? 'A newer revision exists.' : 'This is the current version.'],
        ];

        return [
            ...$this->row($row),
            'kind' => 'field_assessment',
            'title' => 'Field Assessment Report',
            'findings' => $row->findings,
            'return_reason' => property_exists($row, 'return_reason') ? $row->return_reason : null,
            'can_edit' => in_array($row->assessment_status, ['draft', 'returned'], true),
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
            'incident' => $incident ? [
                'id' => $incident->id,
                'reference' => $incident->incident_reference,
                'name' => $incident->incident_name,
                'disaster_type' => $disasterType,
            ] : null,
            'barangay' => $barangay ? ['id' => $barangay->id, 'name' => $barangay->barangay_name] : null,
            'assessor' => $assessor ? ['id' => $assessor->id, 'name' => trim($assessor->first_name.' '.($assessor->middle_name ? $assessor->middle_name.' ' : '').$assessor->last_name)] : null,
            'building' => $building ? ['id' => $building->id, 'reference' => $building->building_reference] : null,
            'household' => $household ? ['id' => $household->id, 'reference' => $household->household_reference] : null,
            'photos' => $photos,
            'predictions' => $predictions,
            'verifications' => $verifications,
            'signatures' => $signatures,
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
        return [
            'id' => $row->id,
            'reference' => $row->assessment_reference,
            'status' => $row->assessment_status,
            'label' => $this->workflow->label($row->assessment_status),
            'barangay_report_id' => $row->barangay_report_id,
            'assigned_assessor_id' => $row->assigned_assessor_id,
            'supersedes_id' => property_exists($row, 'supersedes_id') ? $row->supersedes_id : null,
            'updated_at' => $row->updated_at,
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
