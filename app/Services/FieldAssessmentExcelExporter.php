<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class FieldAssessmentExcelExporter
{
    public function __construct(
        private readonly FieldConsolidationService $consolidation,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{id:int,filename:string,disk_path:string,snapshot_hash:string,assessment_ids:list<int>}
     */
    public function generate(User $user, string $scope, ?int $barangayId = null, ?int $packageId = null): array
    {
        abort_unless($this->consolidation->canManage($user), 403, 'You cannot generate field assessment exports.');
        abort_unless(in_array($scope, ['barangay', 'consolidated'], true), 422, 'Invalid export scope.');
        if ($scope === 'barangay') {
            abort_if($barangayId === null, 422, 'Choose a barangay for this export.');
        }

        $assessments = $this->consolidation->eligibleAssessments($barangayId, false);
        $assessmentIds = $assessments->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $hash = hash('sha256', implode(',', $assessmentIds).'|'.$scope.'|'.($barangayId ?? 'all'));
        $barangayName = $scope === 'barangay'
            ? (collect($this->consolidation->resolveTargetBarangays())->firstWhere('id', $barangayId)['name'] ?? 'Barangay')
            : null;
        $filename = $scope === 'consolidated'
            ? 'MapR_Consolidated_Field_Assessment_Reports.xlsx'
            : 'MapR_'.Str::of((string) $barangayName)->replace([' ', '/'], '_').'_Field_Assessment.xlsx';

        $spreadsheet = new Spreadsheet();
        if ($scope === 'consolidated') {
            $this->buildConsolidatedWorkbook($spreadsheet, $user, $assessments, $hash);
        } else {
            $this->buildBarangayWorkbook($spreadsheet, $user, $assessments, (string) $barangayName, $hash);
        }

        $relative = 'field-exports/'.now()->format('Y/m').'/'.Str::uuid().'-'.$filename;
        Storage::disk('local')->makeDirectory(dirname($relative));
        $absolute = Storage::disk('local')->path($relative);
        (new Xlsx($spreadsheet))->save($absolute);
        $spreadsheet->disconnectWorksheets();

        $exportId = 0;
        if (Schema::hasTable('field_report_exports')) {
            $exportId = (int) DB::table('field_report_exports')->insertGetId([
                'field_report_package_id' => $packageId,
                'scope' => $scope,
                'barangay_id' => $barangayId,
                'filename' => $filename,
                'disk_path' => $relative,
                'snapshot_hash' => $hash,
                'assessment_ids' => json_encode($assessmentIds),
                'generated_by' => $user->id,
                'generated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->logger->log(
            $user,
            'field_assessment_export_generated',
            'Generated '.$filename.' covering '.count($assessmentIds).' assessment(s).',
            $packageId ? 'field_report_package' : 'field_assessment_export',
            $packageId ?: ($exportId ?: null),
        );

        return [
            'id' => $exportId,
            'filename' => $filename,
            'disk_path' => $relative,
            'snapshot_hash' => $hash,
            'assessment_ids' => $assessmentIds,
        ];
    }

    public function downloadPath(int $exportId): array
    {
        $export = DB::table('field_report_exports')->where('id', $exportId)->first();
        abort_if($export === null, 404);
        abort_unless(Storage::disk('local')->exists($export->disk_path), 404, 'The export file is no longer available.');

        return [
            'path' => Storage::disk('local')->path($export->disk_path),
            'filename' => $export->filename,
        ];
    }

    private function buildConsolidatedWorkbook(Spreadsheet $spreadsheet, User $user, $assessments, string $hash): void
    {
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Consolidated Summary');
        $reportIds = $assessments->pluck('barangay_report_id')->map(fn ($id) => (int) $id)->all();
        $damage = $this->consolidation->damageCounts($reportIds);
        $residents = $this->consolidation->residentSubmissionStats($reportIds);
        $this->writePairs($summary, [
            ['MapR Consolidated Field Assessment Reports'],
            ['Report Generation Date', now()->toDateTimeString()],
            ['Reporting Period', $this->periodLabel($assessments)],
            ['Total Reviewed Reports', $assessments->count()],
            ['Total Assessed Buildings', $damage['assessed_buildings']],
            ['Partially Damaged Buildings', $damage['partially_damaged']],
            ['Totally Damaged Buildings', $damage['totally_damaged']],
            ['Total Linked Resident Submissions', $residents['total']],
            ['Estimated Potentially Affected Residents', $residents['potentially_affected'] ?? 'Not Available'],
            ['Current Consolidation Status', $assessments->isEmpty() ? 'No eligible records' : 'Ready for consolidation'],
            ['Snapshot Hash', $hash],
        ]);
        $this->styleTitle($summary);

        $breakdown = $spreadsheet->createSheet();
        $breakdown->setTitle('Barangay Breakdown');
        $headers = ['Barangay Name', 'Report Count', 'Assessed Buildings', 'Partially Damaged', 'Totally Damaged', 'Resident Submissions', 'Potentially Affected', 'Last Assessment Date', 'Status'];
        $rows = [];
        foreach ($this->consolidation->resolveTargetBarangays() as $barangay) {
            $subset = $barangay['id'] ? $assessments->where('barangay_id', $barangay['id']) : collect();
            $subsetIds = $subset->pluck('barangay_report_id')->map(fn ($id) => (int) $id)->all();
            $d = $this->consolidation->damageCounts($subsetIds);
            $r = $this->consolidation->residentSubmissionStats($subsetIds);
            $rows[] = [
                $barangay['name'],
                $subset->count(),
                $d['assessed_buildings'],
                $d['partially_damaged'],
                $d['totally_damaged'],
                $r['total'],
                $r['potentially_affected'] ?? 'Not Available',
                optional($subset->sortByDesc('updated_at')->first())->updated_at ?? 'Not Recorded',
                $subset->isEmpty() ? 'No eligible records' : 'Ready for consolidation',
            ];
        }
        $this->writeTable($breakdown, $headers, $rows);

        $this->writeReportRegister($spreadsheet->createSheet(), $assessments);
        $this->writeResidentRegister($spreadsheet->createSheet(), $assessments);
        $this->writeHistorySheet($spreadsheet->createSheet(), $assessments);
        $info = $spreadsheet->createSheet();
        $info->setTitle('Export Information');
        $this->writePairs($info, [
            ['Export Information'],
            ['Export Identifier', $hash],
            ['Generated By', trim($user->first_name.' '.$user->last_name)],
            ['Generated At', now()->toDateTimeString()],
            ['Data Cutoff', now()->toDateTimeString()],
            ['Included Barangays', implode(', ', $this->consolidation->targetBarangayNames())],
            ['Included Report References', $assessments->pluck('assessment_reference')->implode(', ') ?: 'None'],
            ['Notes', 'Values marked Not Recorded or Not Available were absent in source records. Attachments are referenced, not embedded.'],
        ]);
        $this->styleTitle($info);
    }

    private function buildBarangayWorkbook(Spreadsheet $spreadsheet, User $user, $assessments, string $barangayName, string $hash): void
    {
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Barangay Summary');
        $reportIds = $assessments->pluck('barangay_report_id')->map(fn ($id) => (int) $id)->all();
        $damage = $this->consolidation->damageCounts($reportIds);
        $residents = $this->consolidation->residentSubmissionStats($reportIds);
        $this->writePairs($summary, [
            ['MapR Field Assessment — '.$barangayName],
            ['Barangay', $barangayName],
            ['Reporting Period', $this->periodLabel($assessments)],
            ['Total Reports', $assessments->count()],
            ['Assessed Buildings', $damage['assessed_buildings']],
            ['Partially Damaged Buildings', $damage['partially_damaged']],
            ['Totally Damaged Buildings', $damage['totally_damaged']],
            ['Linked Resident Submissions', $residents['total']],
            ['Consolidation Status', $assessments->isEmpty() ? 'No eligible records' : 'Ready for consolidation'],
            ['Snapshot Hash', $hash],
            ['Generated By', trim($user->first_name.' '.$user->last_name)],
            ['Generated At', now()->toDateTimeString()],
        ]);
        $this->styleTitle($summary);

        $reports = $spreadsheet->createSheet();
        $reports->setTitle('Barangay Reports');
        $this->writeTable($reports, [
            'Report Reference', 'Barangay Report', 'Incident', 'Incident Date', 'Assessor', 'Status', 'Verified By', 'Reviewed By', 'Updated',
        ], $assessments->map(function ($row) {
            $signatures = $this->consolidation->signatureNames((int) $row->id);

            return [
                $row->assessment_reference,
                $row->barangay_report_reference ?? 'Not Recorded',
                $row->incident_name ?? 'Not Recorded',
                $row->incident_date ?? 'Not Recorded',
                trim((string) ($row->assessor_name ?? '')) ?: 'Not Recorded',
                $row->workflow_stage ?: $row->assessment_status,
                $signatures['verified_by'] ?? 'Not Recorded',
                $signatures['approved_by'] ?? 'Not Recorded',
                $row->updated_at ?? 'Not Recorded',
            ];
        })->all());

        $this->writeResidentRegister($spreadsheet->createSheet(), $assessments, 'Individual Resident Submissions');
        $this->writeBuildingSheet($spreadsheet->createSheet(), $assessments);
        $this->writeHistorySheet($spreadsheet->createSheet(), $assessments, 'Report History');
    }

    private function writeReportRegister(Worksheet $sheet, $assessments): void
    {
        $sheet->setTitle('Individual Report Register');
        $rows = $assessments->map(function ($row) {
            $signatures = $this->consolidation->signatureNames((int) $row->id);

            return [
                $row->assessment_reference,
                $row->barangay_name ?? 'Not Recorded',
                $row->barangay_report_reference ?? 'Not Recorded',
                $row->incident_name ?? 'Not Recorded',
                $row->incident_date ?? 'Not Recorded',
                trim((string) ($row->assessor_name ?? '')) ?: 'Not Recorded',
                $row->workflow_stage ?: $row->assessment_status,
                $signatures['verified_by'] ?? 'Not Recorded',
                $signatures['verified_at'] ?? 'Not Recorded',
                $signatures['approved_by'] ?? 'Not Recorded',
                $signatures['approved_at'] ?? 'Not Recorded',
                $row->assessment_status,
            ];
        })->all();
        $this->writeTable($sheet, [
            'Report Reference', 'Barangay', 'Barangay Report', 'Incident Type', 'Incident Date', 'Assigned Assessor',
            'Assessment Status', 'Verified By', 'Verification Date', 'Reviewed By', 'Review Date', 'Report Status',
        ], $rows);
    }

    private function writeResidentRegister(Worksheet $sheet, $assessments, string $title = 'Resident Submission Register'): void
    {
        $sheet->setTitle(Str::limit($title, 31, ''));
        $headers = [
            'Resident Submission Reference', 'Related Barangay Report', 'Barangay', 'Submission Date', 'Incident Date',
            'Incident Type', 'Building Record Reference', 'Recorded Damage Information', 'Assessment Reference',
            'Verification Status', 'Review Status', 'Source Document Reference',
        ];
        $rows = [];
        if (Schema::hasTable('resident_incident_reports')) {
            $reportMap = $assessments->keyBy('barangay_report_id');
            $residents = DB::table('resident_incident_reports')
                ->whereIn('barangay_report_id', $assessments->pluck('barangay_report_id')->all() ?: [0])
                ->orderBy('id')
                ->get();
            foreach ($residents as $resident) {
                $assessment = $reportMap->get($resident->barangay_report_id);
                $signatures = $assessment ? $this->consolidation->signatureNames((int) $assessment->id) : [];
                $buildingRef = 'Not Recorded';
                if (Schema::hasTable('barangay_report_buildings') && Schema::hasTable('buildings')) {
                    $buildingRef = DB::table('barangay_report_buildings as brb')
                        ->join('buildings', 'buildings.id', '=', 'brb.building_id')
                        ->where('brb.barangay_report_id', $resident->barangay_report_id)
                        ->value('buildings.building_reference') ?: 'Not Recorded';
                }
                $rows[] = [
                    $resident->report_reference ?? 'Not Recorded',
                    $assessment->barangay_report_reference ?? 'Not Recorded',
                    $assessment->barangay_name ?? 'Not Recorded',
                    $resident->submitted_at ?? $resident->created_at ?? 'Not Recorded',
                    $assessment->incident_date ?? 'Not Recorded',
                    $assessment->incident_name ?? ($resident->reported_incident_type ?? 'Not Recorded'),
                    $buildingRef,
                    'Not Recorded',
                    $assessment->assessment_reference ?? 'Not Recorded',
                    $signatures['verified_by'] ? 'Verified' : 'Pending Verification',
                    $signatures['approved_by'] ? 'Reviewed' : 'Pending Verification',
                    isset($resident->id) ? '/bdrrm/submissions/'.$resident->id : 'Not Available',
                ];
            }
        }
        $this->writeTable($sheet, $headers, $rows);
    }

    private function writeBuildingSheet(Worksheet $sheet, $assessments): void
    {
        $sheet->setTitle('Building Assessment Details');
        $headers = [
            'Building Record Reference', 'Related Report Reference', 'Assessment Date', 'Observed Damage Classification',
            'Assessment Findings', 'Authorized Cost Estimate', 'Verification Information',
        ];
        $rows = [];
        if (Schema::hasTable('building_damage_cost_assessments')) {
            $query = DB::table('building_damage_cost_assessments as a')
                ->leftJoin('barangay_reports as br', 'br.id', '=', 'a.barangay_report_id');
            $select = [
                'br.report_reference',
                'a.updated_at',
                'a.estimated_cost_php',
                'a.assessment_status',
            ];
            if (Schema::hasTable('buildings')) {
                $query->leftJoin('buildings', 'buildings.id', '=', 'a.building_id');
                $select[] = 'buildings.building_reference';
            }
            if (Schema::hasTable('damage_classes')) {
                $query->leftJoin('damage_classes as dc', 'dc.id', '=', 'a.confirmed_damage_class_id');
                $select[] = 'dc.class_name';
            }
            $records = $query
                ->whereIn('a.barangay_report_id', $assessments->pluck('barangay_report_id')->all() ?: [0])
                ->whereIn('a.assessment_status', ['draft', 'confirmed'])
                ->get($select);
            foreach ($records as $row) {
                $rows[] = [
                    $row->building_reference ?? 'Not Recorded',
                    $row->report_reference ?? 'Not Recorded',
                    $row->updated_at ?? 'Not Recorded',
                    $row->class_name ?? 'Not Recorded',
                    'Not Recorded',
                    $row->estimated_cost_php !== null ? number_format((float) $row->estimated_cost_php, 2) : 'Not Available',
                    $row->assessment_status ?? 'Not Recorded',
                ];
            }
        }
        $this->writeTable($sheet, $headers, $rows);
    }

    private function writeHistorySheet(Worksheet $sheet, $assessments, string $title = 'Assessment and Submission History'): void
    {
        $sheet->setTitle(Str::limit($title, 31, ''));
        $headers = ['Report Reference', 'Activity Type', 'Previous Status', 'New Status', 'Performed By', 'Date and Time', 'Remarks'];
        $rows = [];
        if (Schema::hasTable('activity_events') && $assessments->isNotEmpty()) {
            $events = DB::table('activity_events as ae')
                ->leftJoin('users as u', 'u.id', '=', 'ae.user_id')
                ->where('ae.subject_type', 'field_assessment')
                ->whereIn('ae.subject_id', $assessments->pluck('id')->all())
                ->orderBy('ae.created_at')
                ->get([
                    'ae.subject_id',
                    'ae.action_type',
                    'ae.summary',
                    'ae.created_at',
                    DB::raw("trim(concat(coalesce(u.first_name,''), ' ', coalesce(u.last_name,''))) as actor_name"),
                ]);
            $refMap = $assessments->keyBy('id');
            foreach ($events as $event) {
                $rows[] = [
                    $refMap->get($event->subject_id)->assessment_reference ?? 'Not Recorded',
                    $event->action_type,
                    'Not Available',
                    'Not Available',
                    trim((string) $event->actor_name) ?: 'System',
                    $event->created_at,
                    $event->summary,
                ];
            }
        }
        $this->writeTable($sheet, $headers, $rows);
    }

    private function writePairs(Worksheet $sheet, array $pairs): void
    {
        $row = 1;
        foreach ($pairs as $pair) {
            if (count($pair) === 1) {
                $sheet->setCellValue("A{$row}", $pair[0]);
                $sheet->mergeCells("A{$row}:B{$row}");
            } else {
                $sheet->setCellValue("A{$row}", $pair[0]);
                $sheet->setCellValue("B{$row}", $this->cell($pair[1]));
            }
            $row++;
        }
        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(56);
    }

    private function writeTable(Worksheet $sheet, array $headers, array $rows): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }
        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 2], $this->cell($value));
            }
        }
        $endCol = chr(64 + max(1, count($headers)));
        $endRow = max(1, count($rows) + 1);
        $range = "A1:{$endCol}{$endRow}";
        $sheet->getStyle("A1:{$endCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
        ]);
        if (count($rows) > 0) {
            for ($i = 2; $i <= $endRow; $i++) {
                if ($i % 2 === 0) {
                    $sheet->getStyle("A{$i}:{$endCol}{$i}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F8FAFC');
                }
            }
            $sheet->setAutoFilter("A1:{$endCol}1");
        }
        $sheet->freezePane('A2');
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setWidth(18);
        }
    }

    private function styleTitle(Worksheet $sheet): void
    {
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1E3A8A');
    }

    private function cell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not Recorded';
        }

        return is_scalar($value) ? (string) $value : 'Not Available';
    }

    private function periodLabel($assessments): string
    {
        if ($assessments->isEmpty()) {
            return 'Not Available';
        }
        $dates = $assessments->pluck('updated_at')->filter()->sort()->values();
        if ($dates->isEmpty()) {
            return 'Not Available';
        }

        return $dates->first().' to '.$dates->last();
    }
}
