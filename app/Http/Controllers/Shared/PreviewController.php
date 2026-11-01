<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Policies\BarangayReportPolicy;
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
        $import = $this->importFor('barangay_report_id', (int) $row->id, (string) $row->report_reference);
        $building = $this->linkedReference('buildings', 'building_reference', $import->resolved_building_id ?? null, $import->source_building_reference ?? null);
        $household = $this->linkedReference('households', 'household_reference', $import->resolved_household_id ?? null, $import->source_household_reference ?? null);
        $resident = $this->linkedReference('residents', 'registry_reference', $import->resolved_resident_id ?? null, $import->source_resident_reference ?? null);

        return $this->render($request, [
            'kind' => 'barangay',
            'reference' => $row->report_reference,
            'prepared_at' => $this->prepared($row->updated_at ?? null),
            'office_line' => 'Barangay '.($barangay->barangay_name ?? 'Not recorded'),
            'title' => 'Building Damage and Resident Impact Report',
            'logos' => $this->logos('barangay', $barangay->barangay_code ?? null),
            'rows' => [
                ['label' => 'Incident', 'value' => $incident->incident_name ?? 'Not recorded'],
                ['label' => 'Barangay', 'value' => $barangay->barangay_name ?? 'Not recorded'],
                ['label' => 'Resident reference', 'value' => $resident],
                ['label' => 'Building reference', 'value' => $building],
                ['label' => 'Household reference', 'value' => $household],
                ['label' => 'Reported observation', 'value' => $this->text($row->report_description ?? null)],
                ['label' => 'Workflow status', 'value' => $this->workflow->label($row->report_status)],
            ],
            'impact' => $this->impact($building, $household, $resident),
            'signatures' => $this->signatureSlots('barangay_report_id', (int) $row->id, [
                ['role' => 'barangay_signer', 'title' => 'Signed by', 'name' => $this->captain($barangay->barangay_code ?? null), 'position' => 'Punong Barangay'],
            ]),
        ]);
    }

    public function assessment(Request $request, int $assessment): View|Response
    {
        $row = DB::table('field_assessments')->where('id', $assessment)->first();
        abort_if($row === null, 404);
        abort_unless(app(\App\Policies\FieldAssessmentPolicy::class)->view($request->user(), $row), 403);
        $report = DB::table('barangay_reports')->where('id', $row->barangay_report_id)->first();
        $barangay = $report ? DB::table('barangays')->where('id', $report->barangay_id)->first() : null;
        $incident = $report && Schema::hasTable('disaster_incidents')
            ? DB::table('disaster_incidents')->where('id', $report->disaster_incident_id)->first()
            : null;
        $import = $this->importFor('field_assessment_id', (int) $row->id, (string) $row->assessment_reference);
        $building = $this->linkedReference('buildings', 'building_reference', $import->resolved_building_id ?? null, $import->source_building_reference ?? null);
        $household = $this->linkedReference('households', 'household_reference', $import->resolved_household_id ?? null, $import->source_household_reference ?? null);
        $resident = $this->linkedReference('residents', 'registry_reference', $import->resolved_resident_id ?? null, $import->source_resident_reference ?? null);

        return $this->render($request, [
            'kind' => 'field',
            'reference' => $row->assessment_reference,
            'prepared_at' => $this->prepared($row->updated_at ?? null),
            'office_line' => "City Assessor's Office",
            'title' => 'Building Damage and Resident Impact Report',
            'logos' => $this->logos('field', null),
            'rows' => [
                ['label' => 'Incident', 'value' => $incident->incident_name ?? 'Not recorded'],
                ['label' => 'Barangay', 'value' => $barangay->barangay_name ?? 'Not recorded'],
                ['label' => 'Source report', 'value' => $report->report_reference ?? 'Not recorded'],
                ['label' => 'Resident reference', 'value' => $resident],
                ['label' => 'Building reference', 'value' => $building],
                ['label' => 'Household reference', 'value' => $household],
                ['label' => 'Assessor finding', 'value' => $this->text($row->findings ?? null)],
                ['label' => 'Workflow status', 'value' => $this->workflow->label($row->assessment_status)],
            ],
            'impact' => $this->impact($building, $household, $resident),
            'signatures' => $this->signatureSlots('field_assessment_id', (int) $row->id, [
                ['role' => 'verified_by', 'title' => 'Verified by', 'name' => 'Mark L. Merillo', 'position' => 'Administrative Officer III'],
                ['role' => 'approved_by', 'title' => 'Approved by', 'name' => 'Engr. Lovy R. Dedicatoria, LAOO II', 'position' => 'Acting City Assessor'],
            ]),
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
            'signatures' => $this->signatureSlots('consolidated_report_id', (int) $row->id, [
                ['role' => 'noted_by', 'title' => 'Noted by', 'name' => 'Lester Lito C. Talan, LPT, MST Sci (CAR)', 'position' => 'LDRRMO II, Head, Research & Planning Div.'],
                ['role' => 'approved_by', 'title' => 'Approved by', 'name' => 'Reyveen John B. Geli, RN, DPA', 'position' => 'Head, CDRRMO'],
            ]),
        ]);
    }

    private function render(Request $request, array $payload): View|Response
    {
        $payload['font_regular'] = '/usr/share/fonts/google-carlito-fonts/Carlito-Regular.ttf';
        $payload['font_bold'] = '/usr/share/fonts/google-carlito-fonts/Carlito-Bold.ttf';
        if ($request->query('format') === 'pdf') {
            $pdf = Pdf::loadView('shared.official-document', $payload)
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('chroot', [base_path(), '/usr/share/fonts'])
                ->setOption('defaultFont', 'MaprCarlito');
            $name = $payload['reference'].'.pdf';
            if ($request->query('inline') === '1') {
                return $pdf->stream($name);
            }

            return $pdf->download($name);
        }

        return view('shared.official-document', $payload);
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
        if ($kind === 'barangay' && isset($barangayLogos[$barangayCode])) {
            $logos[] = ['path' => base_path('assets/'.$barangayLogos[$barangayCode]), 'alt' => 'Barangay logo'];
        }

        return array_values(array_filter($logos, fn (array $logo) => is_file($logo['path'])));
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
                'name' => $slot['name'],
                'position' => $slot['position'],
                'image' => $image,
                'caption' => $signed ? 'Signed '.Carbon::parse($signed->signed_at)->timezone('Asia/Manila')->format('d M Y') : 'Awaiting signature',
            ];
        }, $slots);
    }

    private function captain(?string $code): string
    {
        $name = $code ? config('mapr_reference.barangay_captains.'.$code) : null;

        return is_string($name) && $name !== '' ? $name : 'Assigned barangay captain';
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
}
