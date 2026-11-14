<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ReportSignatoryCatalog
{
    /**
     * @return list<array{role: string, title: string, name: string, position: string, column: ?string}>
     */
    public function barangaySlots(?string $barangayCode = null): array
    {
        $captain = is_string($barangayCode) ? config('mapr_reference.barangay_captains.'.$barangayCode) : null;

        return [
            [
                'role' => 'barangay_secretary',
                'title' => 'Checked by',
                'name' => 'Barangay Secretary',
                'position' => 'Barangay Secretary',
                'column' => 'barangay_report_id',
            ],
            [
                'role' => 'barangay_signer',
                'title' => 'Endorsed by',
                'name' => is_string($captain) && $captain !== '' ? $captain : 'Assigned barangay captain',
                'position' => 'Barangay Captain',
                'column' => 'barangay_report_id',
            ],
        ];
    }

    /**
     * @return list<array{role: string, title: string, name: string, position: string, column: ?string}>
     */
    public function fieldSlots(?string $assessorName = null): array
    {
        $verifier = config('mapr_reference.assessor_verifier');
        $approver = config('mapr_reference.assessor_approver');

        return [
            [
                'role' => 'assessed_by',
                'title' => 'Assessed by',
                'name' => $assessorName ?: 'Assigned RDANA assessor',
                'position' => 'RDANA Assessor',
                'column' => 'field_assessment_id',
            ],
            [
                'role' => 'verified_by',
                'title' => 'Verified by',
                'name' => (string) ($verifier['name'] ?? 'Administrative Officer III'),
                'position' => (string) ($verifier['title'] ?? 'RDANA Administrative Officer III'),
                'column' => 'field_assessment_id',
            ],
            [
                'role' => 'approved_by',
                'title' => 'Noted by',
                'name' => (string) ($approver['name'] ?? 'City Assessor'),
                'position' => (string) ($approver['title'] ?? 'LAOO II Acting City Assessor'),
                'column' => 'field_assessment_id',
            ],
        ];
    }

    /**
     * @return list<array{role: string, title: string, name: string, position: string, column: ?string}>
     */
    public function consolidatedSlots(): array
    {
        $noted = config('mapr_reference.signatories.noted_by');
        $approved = config('mapr_reference.signatories.approved_by');

        return [
            [
                'role' => 'noted_by',
                'title' => 'Noted by',
                'name' => (string) ($noted['name'] ?? 'Assigned officer'),
                'position' => trim((string) (($noted['title'] ?? '').' '.($noted['office'] ?? ''))),
                'column' => 'consolidated_report_id',
            ],
            [
                'role' => 'approved_by',
                'title' => 'Approved by',
                'name' => (string) ($approved['name'] ?? 'Assigned officer'),
                'position' => (string) ($approved['title'] ?? 'Head, CDRRMO'),
                'column' => 'consolidated_report_id',
            ],
        ];
    }

    /**
     * Resident PDF office endorsements after the separate Reporting Resident declaration.
     *
     * @return list<array<string, mixed>>
     */
    public function residentOfficeEndorsements(object $row, ?string $barangayCode = null): array
    {
        $barangayReport = null;
        if (! empty($row->barangay_report_id) && Schema::hasTable('barangay_reports')) {
            $barangayReport = DB::table('barangay_reports')->where('id', $row->barangay_report_id)->first();
        }
        $assessment = null;
        if ($barangayReport && Schema::hasTable('field_assessments')) {
            $assessment = DB::table('field_assessments')->where('barangay_report_id', $barangayReport->id)->orderByDesc('id')->first();
        }
        $assessorName = null;
        if ($assessment && Schema::hasTable('users') && ! empty($assessment->assigned_assessor_id)) {
            $assessor = DB::table('users')->where('id', $assessment->assigned_assessor_id)->first();
            $assessorName = $this->personName($assessor) ?: null;
        }
        $consolidated = null;
        if ($assessment && Schema::hasTable('consolidated_report_assessments') && Schema::hasTable('consolidated_reports')) {
            $consolidatedId = DB::table('consolidated_report_assessments')->where('field_assessment_id', $assessment->id)->value('consolidated_report_id');
            $consolidated = $consolidatedId ? DB::table('consolidated_reports')->where('id', $consolidatedId)->first() : null;
        }

        $slots = [
            $this->bdrrmSlot($row),
        ];
        foreach ($this->barangaySlots($barangayCode) as $slot) {
            $slots[] = $this->resolveSlot(
                $slot,
                $barangayReport ? (int) $barangayReport->id : null,
                'Barangay report',
                $barangayReport->report_reference ?? null,
            );
        }
        foreach ($this->fieldSlots($assessorName) as $slot) {
            $slots[] = $this->resolveSlot(
                $slot,
                $assessment ? (int) $assessment->id : null,
                'Field assessment',
                $assessment->assessment_reference ?? null,
            );
        }
        foreach ($this->consolidatedSlots() as $slot) {
            $slots[] = $this->resolveSlot(
                $slot,
                $consolidated ? (int) $consolidated->id : null,
                'Consolidated report',
                $consolidated->report_reference ?? null,
            );
        }

        return $slots;
    }

    /**
     * Ordered signature slots for BDRRM/RDANA barangay report viewers (lineage + barangay office).
     *
     * @return array{
     *   slots: list<array<string, mixed>>,
     *   lineage: array{resident_report_id: ?int, resident_reference: string, household_reference: string, building_reference: string},
     *   signed_count: int
     * }
     */
    public function barangayReportViewerPayload(object $barangayReport, ?ReportLineage $lineage = null): array
    {
        $lineage ??= app(ReportLineage::class);
        $resolved = $lineage->forBarangayReport((int) $barangayReport->id);
        $barangayCode = null;
        if (Schema::hasTable('barangays')) {
            $barangayCode = DB::table('barangays')->where('id', $barangayReport->barangay_id)->value('barangay_code');
        }

        $slots = [];
        if (! empty($resolved['resident_report_id'])) {
            $residentId = (int) $resolved['resident_report_id'];
            $slots[] = $this->resolveSlot([
                'role' => 'resident_signer',
                'title' => 'Reported and declared by',
                'name' => 'Reporting Resident',
                'position' => 'Reporting Resident',
                'column' => 'resident_incident_report_id',
            ], $residentId, null, null);
            $slots[] = $this->resolveSlot([
                'role' => 'bdrrm_reviewer',
                'title' => 'BDRRM Review',
                'name' => 'BDRRM Personnel',
                'position' => 'BDRRM Personnel',
                'column' => 'resident_incident_report_id',
            ], $residentId, null, null);
        } else {
            $slots[] = $this->pendingSlot('resident_signer', 'Reported and declared by', 'Reporting Resident');
            $slots[] = $this->pendingSlot('bdrrm_reviewer', 'BDRRM Review', 'BDRRM Personnel');
        }

        foreach ($this->barangaySlots(is_string($barangayCode) ? $barangayCode : null) as $slot) {
            $slots[] = $this->resolveSlot($slot, (int) $barangayReport->id, null, null);
        }

        foreach ($this->barangayForwardEndorsementSlots($barangayReport) as $slot) {
            $slots[] = $slot;
        }

        $uiSlots = array_map(fn (array $slot) => [
            'role' => $slot['role'],
            'label' => $this->sidebarLabel($slot['role'], $slot['title'] ?? null),
            'title' => $slot['title'],
            'name' => $this->viewerSlotName($slot),
            'position' => $slot['position'],
            'signed' => (bool) $slot['signed'],
            'status' => $slot['signed'] ? 'Signed' : 'Pending',
            'caption' => $slot['caption'],
            'signed_at' => $slot['signed'] ? ($this->extractSignedAt($slot['caption']) ?? null) : null,
            'image' => $slot['image'] ?? null,
        ], $slots);

        return [
            'slots' => $uiSlots,
            'lineage' => [
                'resident_report_id' => $resolved['resident_report_id'] ?? null,
                'resident_report_reference' => $resolved['resident_report_reference'] ?? null,
                'resident_reference' => $resolved['resident_reference'] ?? 'Not recorded',
                'household_reference' => $resolved['household_reference'] ?? 'Not recorded',
                'building_reference' => $resolved['building_reference'] ?? 'Not recorded',
            ],
            'signed_count' => count(array_filter($uiSlots, fn (array $slot) => $slot['signed'])),
        ];
    }

    /**
     * Pending/resolved RDANA + CDRRMO endorsement slots for a barangay report.
     * Always returned (even before assessment/consolidated parents exist); never fabricates signatures.
     *
     * @return list<array<string, mixed>>
     */
    public function barangayForwardEndorsementSlots(object $barangayReport): array
    {
        $assessment = Schema::hasTable('field_assessments')
            ? DB::table('field_assessments')->where('barangay_report_id', $barangayReport->id)->orderByDesc('id')->first()
            : null;
        $assessorName = null;
        if ($assessment && Schema::hasTable('users') && ! empty($assessment->assigned_assessor_id)) {
            $assessorName = $this->personName(DB::table('users')->where('id', $assessment->assigned_assessor_id)->first()) ?: null;
        }

        $consolidated = null;
        if ($assessment && Schema::hasTable('consolidated_report_assessments') && Schema::hasTable('consolidated_reports')) {
            $consolidatedId = DB::table('consolidated_report_assessments')
                ->where('field_assessment_id', $assessment->id)
                ->value('consolidated_report_id');
            $consolidated = $consolidatedId
                ? DB::table('consolidated_reports')->where('id', $consolidatedId)->first()
                : null;
        }

        $slots = [];
        foreach ($this->fieldSlots($assessorName) as $slot) {
            if ($slot['role'] === 'assessed_by' && $assessorName === null) {
                $slot['name'] = '';
            }
            $slots[] = $this->resolveSlot(
                $slot,
                $assessment ? (int) $assessment->id : null,
                $assessment ? 'Field assessment' : null,
                $assessment->assessment_reference ?? null,
            );
        }
        foreach ($this->consolidatedSlots() as $slot) {
            $slots[] = $this->resolveSlot(
                $slot,
                $consolidated ? (int) $consolidated->id : null,
                $consolidated ? 'Consolidated report' : null,
                $consolidated->report_reference ?? null,
            );
        }

        return $slots;
    }

    /**
     * Preview-friendly signature cells for a parent document.
     *
     * @param  list<array{role: string, title: string, name: string, position: string, column?: ?string}>  $slots
     * @return list<array<string, mixed>>
     */
    public function resolveParentSlots(string $column, int $id, array $slots): array
    {
        return array_map(fn (array $slot) => $this->resolveSlot([
            ...$slot,
            'column' => $slot['column'] ?? $column,
        ], $id, null, null), $slots);
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingSlot(string $role, string $title, string $position): array
    {
        return [
            'title' => $title,
            'name' => $position,
            'position' => $position,
            'caption' => 'Pending endorsement',
            'image' => null,
            'document' => null,
            'signed' => false,
            'role' => $role,
        ];
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function viewerSlotName(array $slot): ?string
    {
        $name = is_string($slot['name'] ?? null) ? trim($slot['name']) : '';
        if ($name === '') {
            return null;
        }
        if (! empty($slot['signed'])) {
            return $name;
        }
        if (($slot['role'] ?? null) === 'assessed_by' && $name === 'Assigned RDANA assessor') {
            return null;
        }

        return $name;
    }

    private function sidebarLabel(string $role, ?string $title = null): string
    {
        if ($role === 'approved_by' && $title === 'Approved by') {
            return 'CDRRMO Head';
        }
        if ($role === 'approved_by' && $title === 'Noted by') {
            return 'City Assessor';
        }

        return match ($role) {
            'resident_signer' => 'Resident',
            'bdrrm_reviewer' => 'BDRRM Personnel',
            'barangay_secretary' => 'Barangay Secretary',
            'barangay_signer' => 'Barangay Captain',
            'assessed_by' => 'RDANA Assessor',
            'verified_by' => 'RDANA Administrative Officer',
            'approved_by' => 'City Assessor',
            'noted_by' => 'CDRRMO Notation',
            default => str_replace('_', ' ', ucfirst($role)),
        };
    }

    private function extractSignedAt(string $caption): ?string
    {
        if (! str_starts_with($caption, 'Signed ')) {
            return null;
        }

        return trim(substr($caption, 7)) ?: null;
    }

    /**
     * @param  array{role: string, title: string, name: string, position: string, column?: ?string}  $slot
     * @return array<string, mixed>
     */
    public function resolveSlot(array $slot, ?int $id, ?string $documentLabel, ?string $documentReference): array
    {
        $column = $slot['column'] ?? null;
        $signed = null;
        if ($column !== null && $id !== null && Schema::hasTable('report_signatures')) {
            $signed = DB::table('report_signatures')
                ->where($column, $id)
                ->where('signature_role', $slot['role'])
                ->where('mechanism', 'authenticated_e_signature')
                ->first();
        }
        $name = $slot['name'];
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
            if (is_string($documentReference) && $documentReference !== '' && is_string($documentLabel)) {
                $document = $documentLabel.' '.$documentReference;
            }
        }

        return [
            'title' => $slot['title'],
            'name' => $name,
            'position' => $slot['position'],
            'caption' => $caption,
            'image' => $image,
            'document' => $document,
            'signed' => $signed !== null,
            'role' => $slot['role'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bdrrmSlot(object $row): array
    {
        $signed = null;
        if (Schema::hasTable('report_signatures') && Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            $signed = DB::table('report_signatures')
                ->where('resident_incident_report_id', $row->id)
                ->where('signature_role', 'bdrrm_reviewer')
                ->where('mechanism', 'authenticated_e_signature')
                ->first();
        }
        if ($signed === null) {
            return [
                'title' => 'BDRRM Review',
                'name' => '',
                'position' => 'BDRRM Officer',
                'caption' => 'Pending endorsement',
                'image' => null,
                'document' => null,
                'signed' => false,
                'role' => 'bdrrm_reviewer',
            ];
        }
        $signer = Schema::hasTable('users') ? DB::table('users')->where('id', $signed->signer_user_id)->first() : null;

        return [
            'title' => 'BDRRM Review',
            'name' => $this->personName($signer),
            'position' => 'BDRRM Officer',
            'caption' => 'Signed '.$this->when($signed->signed_at),
            'image' => $this->assetPath($signed->user_signature_asset_id ?? null),
            'document' => null,
            'signed' => true,
            'role' => 'bdrrm_reviewer',
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

        return 'data:'.(DB::table('user_signature_assets')->where('id', $assetId)->value('mime_type') ?: 'image/png')
            .';base64,'.base64_encode((string) Storage::disk('local')->get($path));
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
        ], fn ($part) => is_string($part) && $part !== '')));
    }

    private function when(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Carbon::parse($value)->timezone(config('app.timezone'))->format('j F Y');
    }
}
