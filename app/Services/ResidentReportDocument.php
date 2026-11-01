<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ResidentReportDocument
{
    public function __construct(private readonly ResidentReportWorkflow $reports) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(object $row): array
    {
        $presented = $this->reports->present($row, true);
        $barangay = Schema::hasTable('barangays')
            ? DB::table('barangays')->where('id', $row->barangay_id)->first()
            : null;
        $resident = Schema::hasTable('users')
            ? DB::table('users')->where('id', $row->resident_user_id)->first()
            : null;
        $signature = $presented['signature'] ?? null;
        $image = null;
        if (is_array($signature)) {
            $stored = DB::table('report_signatures')
                ->where('resident_incident_report_id', $row->id)
                ->where('signature_role', 'resident_signer')
                ->value('user_signature_asset_id');
            $image = $this->assetPath($stored);
        }

        return [
            'reference' => (string) $row->report_reference,
            'title' => 'Resident Disaster Incident Report',
            'draft' => $row->report_status === 'draft',
            'status' => (string) ($presented['label'] ?? $row->report_status),
            'barangay_name' => $barangay->barangay_name ?? 'Not recorded',
            'resident_name' => $this->personName($resident) ?: 'Not recorded',
            'logos' => $this->logos($barangay->barangay_code ?? null),
            'incident_name' => $presented['incident_name'] ?: 'Not recorded',
            'incident_type' => $presented['incident_type'] ?: 'Not recorded',
            'occurred_at' => $this->when($presented['occurred_at'] ?? null),
            'observed_at' => $this->when($presented['observed_at'] ?? null),
            'observed_approximate' => (bool) ($presented['observed_at_approximate'] ?? false),
            'reported_location' => $this->text($presented['reported_location'] ?? null),
            'description' => $this->text($presented['incident_description'] ?? null),
            'submitted_at' => $this->when($presented['submitted_at'] ?? null),
            'return_reason' => $this->text($row->report_status === 'returned' ? ($presented['return_reason'] ?? null) : null),
            'photos' => $this->photos((int) $row->id),
            'assessment' => $this->assessment($row),
            'signature' => [
                'name' => $this->personName($resident) ?: 'Not recorded',
                'statement' => is_array($signature) ? ($signature['statement'] ?: 'The location, description, and photographs are accurate.') : null,
                'caption' => is_array($signature) ? ($signature['caption'] ?? 'Signed with this submission.') : 'Signature not recorded.',
                'signed_at' => is_array($signature) ? $this->when($signature['signed_at'] ?? null) : null,
                'image' => $image,
                'original_submission' => (bool) (is_array($signature) ? ($signature['original_submission'] ?? false) : false),
            ],
            'endorsements' => $this->endorsements($row, $barangay->barangay_code ?? null),
            'font_regular' => '/usr/share/fonts/google-carlito-fonts/Carlito-Regular.ttf',
            'font_bold' => '/usr/share/fonts/google-carlito-fonts/Carlito-Bold.ttf',
        ];
    }

    public function render(object $row): PdfDocument
    {
        $pdf = Pdf::loadView('resident.incident-report', $this->payload($row))
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', true)
            ->setOption('chroot', [base_path(), storage_path(), '/usr/share/fonts'])
            ->setOption('defaultFont', 'MaprCarlito');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $metrics = $pdf->getDomPDF()->getFontMetrics();
        $font = $metrics->getFont('MaprCarlito') ?: $metrics->getFont('serif');
        $canvas->page_text(468, 812, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 9, [0, 0, 0]);

        return $pdf;
    }

    /**
     * @return list<array{path: string, alt: string}>
     */
    private function logos(?string $code): array
    {
        $files = [
            ['assets/CDRRMO_LOGO.jpg', 'CDRRMO'],
            ['assets/TANDAG_CITY_LOGO.webp', 'City of Tandag'],
        ];
        $barangayLogos = [
            '166819022' => 'BRGY_TELAJE_LOGO.jpg',
            '166819008' => 'BRGY_MABUA_LOGO.jpg',
            '166819005' => 'BRGY_BONGTUD_LOGO.jpg',
        ];
        if ($code !== null && isset($barangayLogos[$code])) {
            $files[] = ['assets/'.$barangayLogos[$code], 'Barangay logo'];
        }

        $logos = [];
        foreach ($files as [$relative, $alt]) {
            $path = base_path($relative);
            if (! is_file($path)) {
                continue;
            }
            if (str_ends_with(strtolower($path), '.webp')) {
                $converted = $this->webpToPng($path);
                if ($converted === null) {
                    continue;
                }
                $path = $converted;
            }
            $logos[] = ['path' => $path, 'alt' => $alt];
        }

        return $logos;
    }

    private function webpToPng(string $path): ?string
    {
        if (! function_exists('imagecreatefromwebp')) {
            return null;
        }
        $directory = storage_path('app/private/resident-report-cache');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }
        $target = $directory.'/'.md5($path).'.png';
        if (! is_file($target)) {
            $image = @imagecreatefromwebp($path);
            if ($image === false) {
                return null;
            }
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $written = imagepng($image, $target);
            unset($image);
            if ($written !== true) {
                return null;
            }
        }

        return $target;
    }

    /**
     * @return list<array{number: int, path: ?string, caption: string}>
     */
    private function photos(int $reportId): array
    {
        if (! Schema::hasTable('incident_photos')) {
            return [];
        }
        $photos = [];
        $rows = DB::table('incident_photos')->where('incident_report_id', $reportId)->orderBy('id')->get();
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

    /**
     * @return array{summary: string, finding: ?string}
     */
    private function assessment(object $row): array
    {
        $empty = ['summary' => 'Awaiting assessment.', 'finding' => null];
        $barangayId = $row->barangay_report_id ?? null;
        if (! $barangayId || ! Schema::hasTable('field_assessments')) {
            return $empty;
        }
        $assessment = DB::table('field_assessments')->where('barangay_report_id', $barangayId)->orderByDesc('id')->first();
        if ($assessment === null || ($assessment->assessment_status ?? 'draft') === 'draft') {
            return $empty;
        }
        $finding = trim((string) ($assessment->findings ?? ''));

        return [
            'summary' => 'Field assessment '.($assessment->assessment_reference ?? 'record').' is '.$assessment->assessment_status.'.',
            'finding' => $finding !== '' ? $finding : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function endorsements(object $row, ?string $barangayCode): array
    {
        $captain = is_string($barangayCode) ? config('mapr_reference.barangay_captains.'.$barangayCode) : null;
        $barangayReport = null;
        if (! empty($row->barangay_report_id) && Schema::hasTable('barangay_reports')) {
            $barangayReport = DB::table('barangay_reports')->where('id', $row->barangay_report_id)->first();
        }
        $assessment = null;
        if ($barangayReport && Schema::hasTable('field_assessments')) {
            $assessment = DB::table('field_assessments')->where('barangay_report_id', $barangayReport->id)->orderByDesc('id')->first();
        }
        $consolidated = null;
        if ($assessment && Schema::hasTable('consolidated_report_assessments') && Schema::hasTable('consolidated_reports')) {
            $consolidatedId = DB::table('consolidated_report_assessments')->where('field_assessment_id', $assessment->id)->value('consolidated_report_id');
            $consolidated = $consolidatedId ? DB::table('consolidated_reports')->where('id', $consolidatedId)->first() : null;
        }
        $verifier = config('mapr_reference.assessor_verifier');
        $approver = config('mapr_reference.assessor_approver');
        $noted = config('mapr_reference.signatories.noted_by');
        $approved = config('mapr_reference.signatories.approved_by');

        return [
            $this->slot('Barangay endorsement', is_string($captain) && $captain !== '' ? $captain : 'Assigned barangay captain', 'Punong Barangay', $barangayReport ? 'barangay_report_id' : null, $barangayReport->id ?? null, 'barangay_signer', 'Barangay report', $barangayReport->report_reference ?? null),
            $this->slot('Verified by', (string) ($verifier['name'] ?? 'Assigned verifier'), (string) ($verifier['title'] ?? 'Assessor'), $assessment ? 'field_assessment_id' : null, $assessment->id ?? null, 'verified_by', 'Field assessment', $assessment->assessment_reference ?? null),
            $this->slot('Approved by', (string) ($approver['name'] ?? 'Assigned approver'), (string) ($approver['title'] ?? 'Acting City Assessor'), $assessment ? 'field_assessment_id' : null, $assessment->id ?? null, 'approved_by', 'Field assessment', $assessment->assessment_reference ?? null),
            $this->slot('Noted by', (string) ($noted['name'] ?? 'Assigned officer'), trim((string) (($noted['title'] ?? '').' '.($noted['office'] ?? ''))), $consolidated ? 'consolidated_report_id' : null, $consolidated->id ?? null, 'noted_by', 'Consolidated report', $consolidated->report_reference ?? null),
            $this->slot('Approved by', (string) ($approved['name'] ?? 'Assigned officer'), (string) ($approved['title'] ?? 'CDRRMO'), $consolidated ? 'consolidated_report_id' : null, $consolidated->id ?? null, 'approved_by', 'Consolidated report', $consolidated->report_reference ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(string $title, string $pendingName, string $position, ?string $column, ?int $id, string $role, string $documentLabel, ?string $documentReference): array
    {
        $signed = null;
        if ($column !== null && $id !== null && Schema::hasTable('report_signatures')) {
            $signed = DB::table('report_signatures')
                ->where($column, $id)
                ->where('signature_role', $role)
                ->where('mechanism', 'authenticated_e_signature')
                ->first();
        }
        $name = $pendingName;
        $image = null;
        $caption = 'Pending endorsement';
        $document = null;
        if ($signed) {
            $signer = Schema::hasTable('users') ? DB::table('users')->where('id', $signed->signer_user_id)->first() : null;
            $recorded = $this->personName($signer);
            if ($recorded !== '') {
                $name = $recorded;
            }
            $image = $this->assetPath($signed->user_signature_asset_id ?? null);
            $caption = 'Signed '.$this->when($signed->signed_at);
            if (is_string($documentReference) && $documentReference !== '') {
                $document = $documentLabel.' '.$documentReference;
            }
        }

        return [
            'title' => $title,
            'name' => $name,
            'position' => $position,
            'caption' => $caption,
            'image' => $image,
            'document' => $document,
            'signed' => $signed !== null,
        ];
    }

    private function assetPath(mixed $assetId): ?string
    {
        if (! $assetId || ! Schema::hasTable('user_signature_assets')) {
            return null;
        }
        $path = DB::table('user_signature_assets')->where('id', $assetId)->value('storage_path');
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }

    private function personName(?object $user): string
    {
        if ($user === null) {
            return '';
        }

        return trim(implode(' ', array_filter([
            $user->first_name ?? null,
            $user->middle_name ?? null,
            $user->last_name ?? null,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
    }

    private function when(mixed $value): string
    {
        if ($value === null || $value === '') {
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
