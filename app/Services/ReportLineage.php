<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ReportLineage
{
    /**
     * @return array{
     *     resident_report_id: ?int,
     *     resident_reference: string,
     *     resident_report_reference: ?string,
     *     building_reference: string,
     *     household_reference: string,
     *     reported_incident_type: ?string
     * }
     */
    public function forBarangayReport(int $barangayReportId): array
    {
        $empty = [
            'resident_report_id' => null,
            'resident_reference' => 'Not recorded',
            'resident_report_reference' => null,
            'building_reference' => 'Not recorded',
            'household_reference' => 'Not recorded',
            'reported_incident_type' => null,
        ];
        if (! Schema::hasTable('resident_incident_reports')) {
            return $empty;
        }

        $report = DB::table('resident_incident_reports')->where('barangay_report_id', $barangayReportId)->orderByDesc('id')->first();
        if ($report === null) {
            return $empty;
        }

        $registry = $this->registryForUser((int) $report->resident_user_id, isset($report->building_id) ? (int) $report->building_id : null);
        $buildingId = $registry['building_id'] ?: (isset($report->building_id) ? (int) $report->building_id : null);
        if (! $buildingId && Schema::hasTable('barangay_report_buildings')) {
            $buildingId = DB::table('barangay_report_buildings')->where('barangay_report_id', $barangayReportId)->orderBy('id')->value('building_id');
            $buildingId = $buildingId ? (int) $buildingId : null;
        }

        $buildingReference = $registry['building_reference'];
        $householdReference = $registry['household_reference'];
        if ($buildingReference === 'Not recorded' && $buildingId && Schema::hasTable('buildings')) {
            $reference = DB::table('buildings')->where('id', $buildingId)->value('building_reference');
            if (is_string($reference) && $reference !== '') {
                $buildingReference = $reference;
            }
        }
        if ($householdReference === 'Not recorded' && $buildingId && Schema::hasTable('households') && Schema::hasColumn('households', 'household_reference')) {
            $reference = DB::table('households')->where('building_id', $buildingId)->value('household_reference');
            if (is_string($reference) && $reference !== '') {
                $householdReference = $reference;
            }
        }

        return [
            'resident_report_id' => (int) $report->id,
            'resident_reference' => $registry['resident_reference'],
            'resident_report_reference' => $report->report_reference ?? null,
            'building_reference' => $buildingReference,
            'household_reference' => $householdReference,
            'reported_incident_type' => Schema::hasColumn('resident_incident_reports', 'reported_incident_type')
                ? ($report->reported_incident_type ?: null)
                : null,
        ];
    }

    /**
     * @return list<array{title: string, name: string, position: string, image: ?string, caption: string}>
     */
    public function sourceSignatureSlots(int $residentReportId): array
    {
        if (! Schema::hasTable('report_signatures')) {
            return [];
        }
        $rows = DB::table('report_signatures')
            ->where('resident_incident_report_id', $residentReportId)
            ->get();
        $slots = [
            ['role' => 'resident_signer', 'title' => 'Reported and declared by', 'position' => 'Reporting resident'],
            ['role' => 'bdrrm_reviewer', 'title' => 'Reviewed by', 'position' => 'BDRRM Personnel'],
        ];

        return array_map(function (array $slot) use ($rows) {
            $signed = $rows->first(fn ($row) => $row->signature_role === $slot['role']);
            $name = 'Awaiting signature';
            if ($signed && Schema::hasTable('users')) {
                $user = DB::table('users')->where('id', $signed->signer_user_id)->first();
                if ($user) {
                    $name = trim(implode(' ', array_filter([
                        (string) ($user->first_name ?? ''),
                        (string) ($user->last_name ?? ''),
                    ], fn ($part) => $part !== ''))) ?: 'Signed';
                }
            }

            return [
                'title' => $slot['title'],
                'name' => $signed ? $name : 'Awaiting signature',
                'position' => $slot['position'],
                'image' => $this->signatureImage($signed),
                'caption' => $signed && ! empty($signed->signed_at)
                    ? 'Signed '.Carbon::parse($signed->signed_at)->timezone('Asia/Manila')->format('d M Y')
                    : 'Awaiting signature',
            ];
        }, $slots);
    }

    /**
     * @return array{resident_reference: string, household_reference: string, building_reference: string, building_id: ?int}
     */
    private function registryForUser(int $userId, ?int $reportBuildingId): array
    {
        $empty = [
            'resident_reference' => 'Not recorded',
            'household_reference' => 'Not recorded',
            'building_reference' => 'Not recorded',
            'building_id' => $reportBuildingId,
        ];
        if (! Schema::hasTable('account_resident_links') || ! Schema::hasTable('residents')) {
            return $empty;
        }

        $query = DB::table('account_resident_links')
            ->join('residents', 'residents.id', '=', 'account_resident_links.resident_id')
            ->where('account_resident_links.user_id', $userId);
        $columns = ['residents.registry_reference'];
        if (Schema::hasTable('households')) {
            $query->leftJoin('households', 'households.id', '=', 'residents.household_id');
            if (Schema::hasColumn('households', 'household_reference')) {
                $columns[] = 'households.household_reference';
            }
            $columns[] = 'households.building_id';
        }
        $row = $query->first($columns);
        if ($row === null) {
            return $empty;
        }

        $buildingId = $reportBuildingId ?: (isset($row->building_id) ? (int) $row->building_id : null);
        $buildingReference = 'Not recorded';
        if ($buildingId && Schema::hasTable('buildings')) {
            $reference = DB::table('buildings')->where('id', $buildingId)->value('building_reference');
            if (is_string($reference) && $reference !== '') {
                $buildingReference = $reference;
            }
        }

        $resident = is_string($row->registry_reference ?? null) && $row->registry_reference !== ''
            ? $row->registry_reference
            : 'Not recorded';
        $household = is_string($row->household_reference ?? null) && $row->household_reference !== ''
            ? $row->household_reference
            : 'Not recorded';

        return [
            'resident_reference' => $resident,
            'household_reference' => $household,
            'building_reference' => $buildingReference,
            'building_id' => $buildingId,
        ];
    }

    private function signatureImage(?object $signed): ?string
    {
        if ($signed === null || ! Schema::hasTable('user_signature_assets') || empty($signed->user_signature_asset_id)) {
            return null;
        }
        $asset = DB::table('user_signature_assets')->where('id', $signed->user_signature_asset_id)->first();
        $path = is_object($asset) ? ($asset->storage_path ?? null) : null;
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $mime = is_object($asset) && is_string($asset->mime_type ?? null) && $asset->mime_type !== ''
            ? $asset->mime_type
            : 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('local')->get($path));
    }
}
