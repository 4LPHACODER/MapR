<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Policies\BarangayReportPolicy;
use App\Policies\FieldAssessmentPolicy;
use App\Services\BuildingCostEstimator;
use App\Services\ReportLineage;
use App\Services\ReportSignatoryCatalog;
use App\Services\ReportWorkflow;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PreviewController extends Controller
{
    public function __construct(
        private readonly BarangayReportPolicy $reports,
        private readonly ReportWorkflow $workflow,
        private readonly ReportLineage $lineage,
        private readonly ReportSignatoryCatalog $signatories,
    ) {}

    public function barangay(Request $request, int $report): View|Response
    {
        $row = DB::table('barangay_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        abort_unless($this->reports->view($request->user(), $row), 403);
        $barangay = DB::table('barangays')->where('id', $row->barangay_id)->first();
        $incident = Schema::hasTable('disaster_incidents')
            ? DB::table('disaster_incidents')->where('id', $row->disaster_incident_id)->first()
            : null;
        $resolved = $this->resolvedRegistry('barangay_report_id', (int) $row->id, (string) $row->report_reference);
        $building = $resolved['building'];
        $household = $resolved['household'];
        $resident = $resolved['resident'];
        $incidentName = $this->incidentCaption($incident->incident_name ?? null, $resolved['reported_incident_type'] ?? null);
        $request->user()?->loadMissing('role');
        $isAssessor = in_array($request->user()?->role?->code, ['rdana', 'rdana_admin_officer', 'rdana_city_assessor'], true);
        $statusLabel = $isAssessor && $row->report_status === 'review'
            ? 'Barangay Report'
            : $this->workflow->label($row->report_status);

        return $this->render($request, [
            'kind' => 'barangay',
            'reference' => $row->report_reference,
            'prepared_at' => $this->prepared($row->updated_at ?? null),
            'office_line' => 'Barangay '.($barangay->barangay_name ?? 'Not recorded'),
            'title' => 'Building Damage and Resident Impact Report',
            'logos' => $this->logos('barangay', $barangay->barangay_code ?? null),
            'back_url' => $isAssessor ? '/rdana/reports/barangay' : null,
            'back_label' => $isAssessor ? 'Back to Barangay Reports' : null,
            'rows' => [
                ['label' => 'Incident', 'value' => $incidentName],
                ['label' => 'Barangay', 'value' => $barangay->barangay_name ?? 'Not recorded'],
                ['label' => 'Resident reference', 'value' => $resident],
                ['label' => 'Building reference', 'value' => $building],
                ['label' => 'Household reference', 'value' => $household],
                ['label' => 'Reported observation', 'value' => $this->text($row->report_description ?? null)],
                ['label' => 'Workflow status', 'value' => $statusLabel],
            ],
            'impact' => $this->impact($building, $household, $resident),
            'photos' => $this->barangayPhotos((int) $row->id),
            'damage_assessments' => $this->currentDamageAssessments((int) $row->id),
            'signatures' => array_merge(
                $resolved['resident_report_id'] ? $this->lineage->sourceSignatureSlots((int) $resolved['resident_report_id']) : [],
                $this->signatories->resolveParentSlots(
                    'barangay_report_id',
                    (int) $row->id,
                    $this->signatories->barangaySlots($barangay->barangay_code ?? null),
                ),
                $this->signatories->barangayForwardEndorsementSlots($row),
            ),
        ]);
    }

    public function assessment(Request $request, int $assessment): View|Response
    {
        $row = DB::table('field_assessments')->where('id', $assessment)->first();
        abort_if($row === null, 404);
        abort_unless(app(FieldAssessmentPolicy::class)->view($request->user(), $row), 403);
        $report = DB::table('barangay_reports')->where('id', $row->barangay_report_id)->first();
        $barangay = $report ? DB::table('barangays')->where('id', $report->barangay_id)->first() : null;
        $incident = $report && Schema::hasTable('disaster_incidents')
            ? DB::table('disaster_incidents')->where('id', $report->disaster_incident_id)->first()
            : null;
        $disasterType = null;
        if ($incident && Schema::hasTable('disaster_types') && ! empty($incident->disaster_type_id)) {
            $disasterType = DB::table('disaster_types')->where('id', $incident->disaster_type_id)->value('name');
        }
        $lineage = $report
            ? $this->lineage->forBarangayReport((int) $report->id)
            : [
                'resident_report_id' => null,
                'resident_report_reference' => null,
                'resident_reference' => 'Not recorded',
                'building_reference' => 'Not recorded',
                'household_reference' => 'Not recorded',
                'reported_incident_type' => null,
            ];
        $resolved = $report
            ? $this->resolvedRegistry('barangay_report_id', (int) $report->id, (string) $report->report_reference)
            : ['resident' => 'Not recorded', 'building' => 'Not recorded', 'household' => 'Not recorded', 'resident_report_id' => null, 'reported_incident_type' => null];
        $building = $resolved['building'];
        $household = $resolved['household'];
        $resident = $resolved['resident'];
        $incidentName = $this->incidentCaption($incident->incident_name ?? null, $resolved['reported_incident_type'] ?? null);
        $assessorName = $this->assessorName($row) ?: 'Not recorded';
        $workflowLabel = $this->workflow->label(
            property_exists($row, 'workflow_stage') && $row->workflow_stage
                ? $row->workflow_stage
                : $row->assessment_status
        );
        $residentReport = null;
        if (! empty($lineage['resident_report_id']) && Schema::hasTable('resident_incident_reports')) {
            $residentReport = DB::table('resident_incident_reports')->where('id', $lineage['resident_report_id'])->first();
        }
        $request->user()?->loadMissing('role');
        $isAssessor = in_array($request->user()?->role?->code, ['rdana', 'rdana_admin_officer', 'rdana_city_assessor'], true);

        return $this->render($request, [
            'kind' => 'field',
            'reference' => $row->assessment_reference,
            'prepared_at' => $this->prepared($row->updated_at ?? null),
            'office_line' => "City Assessor's Office",
            'title' => 'Field Assessment Report',
            'subtitle' => 'Building Damage and Resident Impact Assessment',
            'logos' => $this->logos('field', $barangay->barangay_code ?? null),
            'back_url' => $isAssessor ? '/rdana/reports/field' : null,
            'back_label' => $isAssessor ? 'Back to Field Assessment Reports' : null,
            'rows' => [
                ['label' => 'Field Assessment Reference', 'value' => $row->assessment_reference],
                ['label' => 'Source Barangay Report Reference', 'value' => $report->report_reference ?? 'Not recorded'],
                ['label' => 'Source Resident Report Reference', 'value' => $lineage['resident_report_reference'] ?: 'Not recorded'],
                ['label' => 'Incident', 'value' => $incidentName],
                ['label' => 'Disaster Type', 'value' => $this->text($disasterType)],
                ['label' => 'Barangay', 'value' => $barangay->barangay_name ?? 'Not recorded'],
                ['label' => 'Assigned RDANA Assessor', 'value' => $assessorName],
                ['label' => 'Date Prepared', 'value' => $this->prepared($row->updated_at ?? null)],
                ['label' => 'Assessment Status', 'value' => $workflowLabel],
                ['label' => 'Reporting Resident', 'value' => $resident],
                ['label' => 'Resident Registry Reference', 'value' => $resident],
                ['label' => 'Household Reference', 'value' => $household],
                ['label' => 'Building Reference', 'value' => $building],
                ['label' => 'Original Incident Description', 'value' => $this->text($residentReport->incident_description ?? null)],
                ['label' => 'Barangay Findings', 'value' => $this->text($report->report_description ?? null)],
                ['label' => 'Assessor Findings', 'value' => $this->text($row->findings ?? null)],
            ],
            'verification' => $this->assessmentVerification((int) $row->id),
            'impact' => $this->impact($building, $household, $resident),
            'photos' => $report ? $this->barangayPhotos((int) $report->id) : [],
            'damage_assessments' => $report ? $this->currentDamageAssessments((int) $report->id) : null,
            'signatures' => array_merge(
                $resolved['resident_report_id'] ? $this->lineage->sourceSignatureSlots((int) $resolved['resident_report_id']) : [],
                $report ? $this->signatories->resolveParentSlots(
                    'barangay_report_id',
                    (int) $report->id,
                    $this->signatories->barangaySlots($barangay->barangay_code ?? null),
                ) : [],
                $report ? $this->signatories->barangayForwardEndorsementSlots($report) : [],
            ),
        ]);
    }

    public function consolidated(Request $request, int $report): View|Response
    {
        abort_unless($request->user()?->role?->code === 'cdrrmo_admin', 403);
        $row = DB::table('consolidated_reports')->where('id', $report)->first();
        abort_if($row === null, 404);
        $incident = Schema::hasTable('disaster_incidents')
            ? DB::table('disaster_incidents')->where('id', $row->disaster_incident_id)->first()
            : null;

        return $this->render($request, [
            'kind' => 'consolidated',
            'reference' => $row->report_reference,
            'prepared_at' => $this->prepared($row->updated_at ?? null),
            'office_line' => 'City Disaster Risk Reduction and Management Office',
            'title' => 'Consolidated Assessment Report',
            'logos' => $this->logos('consolidated', null),
            'rows' => [
                ['label' => 'Incident', 'value' => $incident->incident_name ?? 'Not recorded'],
                ['label' => 'Report summary', 'value' => $this->text($row->report_summary ?? null)],
                ['label' => 'Workflow status', 'value' => $this->workflow->label($row->report_status)],
            ],
            'impact' => [
                'buildings' => 'Not recorded',
                'households' => 'Not recorded',
                'residents' => 'Not recorded',
                'validation' => 'Not a field verification',
            ],
            'signatures' => $this->signatories->resolveParentSlots(
                'consolidated_report_id',
                (int) $row->id,
                $this->signatories->consolidatedSlots(),
            ),
        ]);
    }

    private function render(Request $request, array $payload): View|Response
    {
        $payload['font_regular'] = '/usr/share/fonts/google-carlito-fonts/Carlito-Regular.ttf';
        $payload['font_bold'] = '/usr/share/fonts/google-carlito-fonts/Carlito-Bold.ttf';
        $payload['damage_assessments'] = $payload['damage_assessments'] ?? null;
        $payload['photos'] = $payload['photos'] ?? [];
        if ($request->query('format') === 'pdf') {
            unset($payload['back_url'], $payload['back_label']);
            $pdf = Pdf::loadView('shared.official-document', $payload)
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('chroot', [base_path(), storage_path(), '/usr/share/fonts'])
                ->setOption('defaultFont', 'MaprCarlito');
            $name = $payload['reference'].'.pdf';
            if ($request->query('inline') === '1') {
                return $pdf->stream($name);
            }

            return $pdf->download($name);
        }

        return view('shared.official-document', $payload);
    }

    /**
     * @return list<array{number: int, path: ?string, caption: string}>
     */
    private function barangayPhotos(int $reportId): array
    {
        if (! Schema::hasTable('incident_photos') || ! Schema::hasColumn('incident_photos', 'barangay_report_id')) {
            return [];
        }

        $photos = [];
        $rows = DB::table('incident_photos')
            ->where('barangay_report_id', $reportId)
            ->orderBy('id')
            ->get();
        $number = 1;
        foreach ($rows as $photo) {
            $path = null;
            if (is_string($photo->file_path) && $photo->file_path !== '' && Storage::disk('local')->exists($photo->file_path)) {
                $path = Storage::disk('local')->path($photo->file_path);
            }
            $photos[] = [
                'number' => $number,
                'path' => $path,
                'caption' => 'Photo '.$number,
            ];
            $number++;
        }

        return $photos;
    }

    private function logos(string $kind, ?string $barangayCode): array
    {
        $logos = [
            ['path' => base_path('assets/TANDAG_CITY_LOGO.webp'), 'alt' => 'City of Tandag'],
        ];
        if ($kind === 'field') {
            $logos[] = ['path' => base_path('assets/ASSESSORS_OFFICE_LOGO.jpg'), 'alt' => "City Assessor's Office"];
        }
        $logos[] = ['path' => base_path('assets/CDRRMO_LOGO.jpg'), 'alt' => 'CDRRMO'];
        $barangayLogos = [
            '166819022' => 'BRGY_TELAJE_LOGO.jpg',
            '166819008' => 'BRGY_MABUA_LOGO.jpg',
            '166819005' => 'BRGY_BONGTUD_LOGO.jpg',
            '166819015' => 'BRGY_AWASIAN_LOGO.jpg',
        ];
        if (in_array($kind, ['barangay', 'field'], true) && isset($barangayLogos[$barangayCode])) {
            $logos[] = ['path' => base_path('assets/'.$barangayLogos[$barangayCode]), 'alt' => 'Barangay logo'];
        }

        return array_values(array_filter($logos, fn (array $logo) => is_file($logo['path'])));
    }

    /**
     * @return list<array{label: string, value: string}>|null
     */
    private function assessmentVerification(int $fieldAssessmentId): ?array
    {
        if (! Schema::hasTable('building_verifications')) {
            return null;
        }

        $verification = DB::table('building_verifications as bv')
            ->leftJoin('users', 'users.id', '=', 'bv.verified_by')
            ->where('bv.field_assessment_id', $fieldAssessmentId)
            ->orderByDesc('bv.id')
            ->first([
                'bv.*',
                'users.first_name',
                'users.last_name',
            ]);

        if ($verification === null) {
            return [
                ['label' => 'Location Checked', 'value' => 'Not recorded'],
                ['label' => 'Household Link Checked', 'value' => 'Not recorded'],
                ['label' => 'Building Link Verification', 'value' => 'Not recorded'],
                ['label' => 'Location Remarks', 'value' => 'Not recorded'],
                ['label' => 'Verification Date', 'value' => 'Not recorded'],
                ['label' => 'Verifying Assessor', 'value' => 'Not recorded'],
            ];
        }

        $verifier = trim(implode(' ', array_filter([
            (string) ($verification->first_name ?? ''),
            (string) ($verification->last_name ?? ''),
        ], fn ($part) => $part !== ''))) ?: 'Not recorded';

        return [
            ['label' => 'Location Checked', 'value' => $verification->location_verified ? 'Yes' : 'No'],
            ['label' => 'Household Link Checked', 'value' => $verification->household_link_verified ? 'Yes' : 'No'],
            ['label' => 'Building Link Verification', 'value' => $verification->building_id ? 'Linked' : 'Not linked'],
            ['label' => 'Location Remarks', 'value' => $this->text($verification->verification_notes ?? null)],
            ['label' => 'Verification Date', 'value' => $this->prepared($verification->verified_at ?? $verification->updated_at ?? null)],
            ['label' => 'Verifying Assessor', 'value' => $verifier],
        ];
    }

    /**
     * @return array{resident: string, building: string, household: string, resident_report_id: ?int, reported_incident_type: ?string}
     */
    private function resolvedRegistry(string $column, int $id, string $reference): array
    {
        $lineage = $column === 'barangay_report_id'
            ? $this->lineage->forBarangayReport($id)
            : [
                'resident_report_id' => null,
                'resident_reference' => 'Not recorded',
                'building_reference' => 'Not recorded',
                'household_reference' => 'Not recorded',
                'reported_incident_type' => null,
            ];
        $import = $this->importFor($column, $id, $reference);
        $importedBuilding = $this->linkedReference('buildings', 'building_reference', $import->resolved_building_id ?? null, $import->source_building_reference ?? null);
        $importedHousehold = $this->linkedReference('households', 'household_reference', $import->resolved_household_id ?? null, $import->source_household_reference ?? null);
        $importedResident = $this->linkedReference('residents', 'registry_reference', $import->resolved_resident_id ?? null, $import->source_resident_reference ?? null);

        return [
            'resident' => $lineage['resident_reference'] !== 'Not recorded' ? $lineage['resident_reference'] : $importedResident,
            'building' => $lineage['building_reference'] !== 'Not recorded' ? $lineage['building_reference'] : $importedBuilding,
            'household' => $lineage['household_reference'] !== 'Not recorded' ? $lineage['household_reference'] : $importedHousehold,
            'resident_report_id' => $lineage['resident_report_id'],
            'reported_incident_type' => $lineage['reported_incident_type'],
        ];
    }

    private function incidentCaption(?string $official, ?string $custom): string
    {
        $official = is_string($official) ? trim($official) : '';
        $custom = is_string($custom) ? trim($custom) : '';
        if ($official !== '' && $custom !== '') {
            return $official.' — '.$custom;
        }
        if ($custom !== '') {
            return $custom;
        }

        return $official !== '' ? $official : 'Not recorded';
    }

    private function importFor(string $column, int $id, string $reference): ?object
    {
        if (! Schema::hasTable('imported_report_documents')) {
            return null;
        }
        $query = DB::table('imported_report_documents')->where('import_key', $reference);
        if (Schema::hasColumn('imported_report_documents', $column)) {
            $query->orWhere($column, $id);
        }

        return $query->first();
    }

    private function linkedReference(string $table, string $column, mixed $id, ?string $source): string
    {
        if ($id && Schema::hasTable($table)) {
            $value = DB::table($table)->where('id', $id)->value($column);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        if (is_string($source) && trim($source) !== '') {
            return trim($source).' (stated in the original document, not linked)';
        }

        return 'Not recorded';
    }

    private function impact(string $building, string $household, string $resident): array
    {
        return [
            'buildings' => $building === 'Not recorded' ? 'Not recorded' : 'Stated reference only',
            'households' => $household === 'Not recorded' ? 'Not recorded' : 'Stated reference only',
            'residents' => $resident === 'Not recorded' ? 'Not recorded' : 'Stated reference only',
            'validation' => 'Not field-verified from the generated report',
        ];
    }

    private function signatureSlots(string $column, int $id, array $slots): array
    {
        $rows = Schema::hasTable('report_signatures')
            ? DB::table('report_signatures')->where($column, $id)->get()
            : collect();

        return array_map(function (array $slot) use ($rows) {
            $signed = $rows->first(fn ($row) => $row->signature_role === $slot['role'] && $row->mechanism === 'authenticated_e_signature');
            $name = $slot['name'];
            if ($signed && Schema::hasTable('users')) {
                $signer = DB::table('users')->where('id', $signed->signer_user_id)->first();
                if ($signer) {
                    $signedName = trim(implode(' ', array_filter([
                        (string) ($signer->first_name ?? ''),
                        (string) ($signer->last_name ?? ''),
                    ], fn ($part) => $part !== '')));
                    if ($signedName !== '') {
                        $name = $signedName;
                    }
                }
            }
            $image = null;
            $assetId = $signed && property_exists($signed, 'user_signature_asset_id') ? $signed->user_signature_asset_id : null;
            if ($signed && Schema::hasTable('user_signature_assets') && $assetId) {
                $path = DB::table('user_signature_assets')->where('id', $assetId)->value('storage_path');
                if (is_string($path) && Storage::disk('local')->exists($path)) {
                    $image = Storage::disk('local')->path($path);
                }
            }

            return [
                'title' => $slot['title'],
                'name' => $name,
                'position' => $slot['position'],
                'image' => $image,
                'caption' => $signed ? 'Signed '.Carbon::parse($signed->signed_at)->timezone('Asia/Manila')->format('d M Y') : 'Awaiting signature',
            ];
        }, $slots);
    }

    private function assessorName(object $assessment): ?string
    {
        if (! Schema::hasTable('users') || empty($assessment->assigned_assessor_id)) {
            return null;
        }
        $user = DB::table('users')->where('id', $assessment->assigned_assessor_id)->first();
        if ($user === null) {
            return null;
        }
        $name = trim(implode(' ', array_filter([
            (string) ($user->first_name ?? ''),
            (string) ($user->last_name ?? ''),
        ], fn ($part) => $part !== '')));

        return $name !== '' ? $name : null;
    }

    private function prepared(mixed $value): string
    {
        if (! $value) {
            return 'Not recorded';
        }

        return Carbon::parse($value)->timezone('Asia/Manila')->format('d F Y');
    }

    private function text(mixed $value): string
    {
        $text = trim((string) $value);

        return $text === '' ? 'Not recorded' : $text;
    }

    /**
     * Current damage assessments for the official document (confirmed preferred, else draft).
     *
     * @return array{buildings: list<array<string, string>>, aggregate: string, incomplete: list<string>}|null
     */
    private function currentDamageAssessments(int $reportId): ?array
    {
        if (! Schema::hasTable('building_damage_cost_assessments') || ! Schema::hasTable('damage_classes')) {
            return null;
        }

        $query = DB::table('building_damage_cost_assessments as a')
            ->leftJoin('buildings', 'buildings.id', '=', 'a.building_id')
            ->leftJoin('users as assessor', 'assessor.id', '=', 'a.assessed_by')
            ->leftJoin('users as confirmer', 'confirmer.id', '=', 'a.confirmed_by')
            ->where('a.barangay_report_id', $reportId)
            ->whereIn('a.assessment_status', ['draft', 'confirmed'])
            ->orderByDesc('a.id');
        $select = [
            'a.*',
            'buildings.building_reference',
            'assessor.first_name as assessed_by_first_name',
            'assessor.last_name as assessed_by_last_name',
            'confirmer.first_name as confirmed_by_first_name',
            'confirmer.last_name as confirmed_by_last_name',
        ];
        if (Schema::hasColumn('building_damage_cost_assessments', 'proposed_damage_class_id')) {
            $query->leftJoin('damage_classes as proposed', 'proposed.id', '=', 'a.proposed_damage_class_id');
            $select[] = 'proposed.class_code as proposed_class_code';
            $select[] = 'proposed.class_name as proposed_class_name';
        }
        if (Schema::hasColumn('building_damage_cost_assessments', 'confirmed_damage_class_id')) {
            $query->leftJoin('damage_classes as confirmed', 'confirmed.id', '=', 'a.confirmed_damage_class_id');
            $select[] = 'confirmed.class_code as confirmed_class_code';
            $select[] = 'confirmed.class_name as confirmed_class_name';
        }
        $rows = $query->get($select);

        if ($rows->isEmpty()) {
            return null;
        }

        $estimator = app(BuildingCostEstimator::class);
        $byBuilding = [];
        foreach ($rows as $row) {
            $buildingKey = (int) $row->building_id;
            if (isset($byBuilding[$buildingKey])) {
                continue;
            }
            if ($row->assessment_status === 'confirmed') {
                $byBuilding[$buildingKey] = $row;

                continue;
            }
            $confirmed = $rows->first(fn ($candidate) => (int) $candidate->building_id === $buildingKey && $candidate->assessment_status === 'confirmed');
            $byBuilding[$buildingKey] = $confirmed ?: $row;
        }

        $buildings = [];
        $total = 0.0;
        $incomplete = [];

        foreach ($byBuilding as $row) {
            $statusCode = $row->confirmed_class_code ?: $row->proposed_class_code;
            $statusName = $row->confirmed_class_name ?: $row->proposed_class_name;
            $buildingStatus = $estimator->displayLabel($statusCode, $statusName ?: null);
            if ($buildingStatus === '' || $buildingStatus === 'Awaiting Classification') {
                $buildingStatus = $statusName ?: 'Awaiting Classification';
            }
            $costLabel = in_array($row->cost_status, ['calculated', 'zero_confirmed'], true) && $row->estimated_cost_php !== null
                ? 'PHP '.number_format((float) $row->estimated_cost_php, 2, '.', ',')
                : 'Pending Required Assessment Data';

            if (in_array($row->cost_status, ['calculated', 'zero_confirmed'], true) && $row->estimated_cost_php !== null) {
                $total += (float) $row->estimated_cost_php;
            } else {
                $incomplete[] = $row->building_reference ?: ('Building #'.$row->building_id);
            }

            $assessorName = trim(implode(' ', array_filter([
                (string) ($row->confirmed_by_first_name ?: $row->assessed_by_first_name ?? ''),
                (string) ($row->confirmed_by_last_name ?: $row->assessed_by_last_name ?? ''),
            ], fn ($part) => $part !== ''))) ?: 'Not recorded';

            $source = (string) ($row->classification_source ?? 'manual');
            $sourceLabel = match ($source) {
                'ai' => 'EfficientNetB0 prediction',
                'ai_corrected' => 'EfficientNetB0 prediction with manual correction',
                'supervisor_corrected' => 'Supervisor correction',
                default => 'Manual classification',
            };

            $buildings[] = [
                'building_reference' => $row->building_reference ?: ('Building #'.$row->building_id),
                'building_status' => $buildingStatus,
                'estimated_cost' => $costLabel,
                'assessment_status' => (string) $row->assessment_status,
                'criteria_version' => $this->text($row->criteria_version ?? null),
                'assessor' => $assessorName,
                'assessed_at' => $this->prepared($row->confirmed_at ?? $row->updated_at ?? null),
                'remarks' => $this->text($row->remarks ?? null),
                'classification_source' => $sourceLabel,
                'occupancy_key' => $this->text($row->occupancy_key ?? null),
                'construction_type' => $this->text($row->construction_type ?? null),
                'construction_grade' => $this->text($row->construction_grade ?? null),
                'total_floor_area_m2' => isset($row->total_floor_area_m2) && $row->total_floor_area_m2 !== null
                    ? number_format((float) $row->total_floor_area_m2, 2, '.', ',').' m²'
                    : 'Not recorded',
                'assessed_floor_area_m2' => isset($row->assessed_floor_area_m2) && $row->assessed_floor_area_m2 !== null
                    ? number_format((float) $row->assessed_floor_area_m2, 2, '.', ',').' m²'
                    : 'Not recorded',
                'unit_cost_php' => isset($row->unit_cost_php) && $row->unit_cost_php !== null
                    ? 'PHP '.number_format((float) $row->unit_cost_php, 2, '.', ',')
                    : 'Not recorded',
            ];
        }

        return [
            'buildings' => $buildings,
            'aggregate' => $incomplete === []
                ? 'PHP '.number_format($total, 2, '.', ',')
                : 'Partial aggregate only — incomplete building estimates remain',
            'incomplete' => $incomplete,
        ];
    }
}
