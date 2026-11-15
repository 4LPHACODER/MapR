<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AuditBarangaySignatureContinuityCommand extends Command
{
    protected $signature = 'mapr:audit-barangay-signatures {--fix : Apply safe RIR↔BR link repairs when unambiguous (default is dry-run only)}';

    protected $description = 'Audit barangay report signature lineage and missing assets without fabricating signatures.';

    public function handle(): int
    {
        if (! Schema::hasTable('barangay_reports') || ! Schema::hasTable('report_signatures')) {
            $this->error('Required signature tables are not available.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('fix');
        $missingLineage = 0;
        $missingResidentSig = 0;
        $missingBdrrmSig = 0;
        $missingAssets = 0;
        $linked = 0;

        $reports = DB::table('barangay_reports')
            ->whereIn('report_status', ['review', 'signed', 'submitted'])
            ->orderBy('id')
            ->get(['id', 'report_reference', 'barangay_id', 'report_status', 'workflow_stage']);

        foreach ($reports as $report) {
            $hasCaptain = $this->hasRole('barangay_report_id', (int) $report->id, 'barangay_signer');
            $hasSecretary = $this->hasRole('barangay_report_id', (int) $report->id, 'barangay_secretary');
            if (! $hasCaptain && ! $hasSecretary) {
                continue;
            }

            $rir = Schema::hasTable('resident_incident_reports')
                && Schema::hasColumn('resident_incident_reports', 'barangay_report_id')
                ? DB::table('resident_incident_reports')->where('barangay_report_id', $report->id)->orderByDesc('id')->first()
                : null;

            if ($rir === null) {
                $missingLineage++;
                $candidate = $this->unambiguousResidentCandidate((int) $report->id, (int) $report->barangay_id);
                if ($candidate) {
                    $this->warn(($apply ? 'Linking' : 'Would link').' '.$report->report_reference.' ← '.$candidate->report_reference);
                    if ($apply) {
                        DB::table('resident_incident_reports')->where('id', $candidate->id)->update([
                            'barangay_report_id' => $report->id,
                            'updated_at' => now(),
                        ]);
                        $linked++;
                        $rir = DB::table('resident_incident_reports')->where('id', $candidate->id)->first();
                    }
                } else {
                    $this->line('Missing RIR link: '.$report->report_reference.' (id '.$report->id.')');
                }
            }

            if ($rir) {
                if (! $this->hasRole('resident_incident_report_id', (int) $rir->id, 'resident_signer')) {
                    $missingResidentSig++;
                    $this->line('Missing resident signature for '.$report->report_reference.' / '.$rir->report_reference);
                }
                if (! $this->hasRole('resident_incident_report_id', (int) $rir->id, 'bdrrm_reviewer')) {
                    $missingBdrrmSig++;
                    $this->line('Missing BDRRM signature for '.$report->report_reference.' / '.$rir->report_reference);
                }
            }

            $missingAssets += $this->countMissingAssets((int) $report->id, $rir?->id);
        }

        $this->newLine();
        $this->info('Audit complete.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Reports missing RIR link', $missingLineage],
                ['Safe links '.($apply ? 'applied' : 'available (dry-run)'), $linked],
                ['Missing resident signatures', $missingResidentSig],
                ['Missing BDRRM signatures', $missingBdrrmSig],
                ['Signature rows with missing assets', $missingAssets],
            ],
        );
        $this->comment('No signature images or completed endorsements were fabricated.');

        return self::SUCCESS;
    }

    private function hasRole(string $column, int $id, string $role): bool
    {
        if (! Schema::hasColumn('report_signatures', $column)) {
            return false;
        }

        return DB::table('report_signatures')
            ->where($column, $id)
            ->where('signature_role', $role)
            ->where('mechanism', 'authenticated_e_signature')
            ->exists();
    }

    private function unambiguousResidentCandidate(int $barangayReportId, int $barangayId): ?object
    {
        if (! Schema::hasTable('resident_incident_reports') || ! Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
            return null;
        }

        $candidates = DB::table('resident_incident_reports')
            ->where('barangay_id', $barangayId)
            ->where('report_status', 'reviewed')
            ->whereNull('barangay_report_id')
            ->orderBy('id')
            ->get();

        if ($candidates->count() !== 1) {
            return null;
        }

        // Only auto-link when captain signature exists and exactly one reviewed orphan matches the barangay.
        if (! $this->hasRole('barangay_report_id', $barangayReportId, 'barangay_signer')) {
            return null;
        }

        return $candidates->first();
    }

    private function countMissingAssets(int $barangayReportId, ?int $residentReportId): int
    {
        $query = DB::table('report_signatures')->where(function ($inner) use ($barangayReportId, $residentReportId) {
            $inner->where('barangay_report_id', $barangayReportId);
            if ($residentReportId && Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
                $inner->orWhere('resident_incident_report_id', $residentReportId);
            }
        })->whereNotNull('user_signature_asset_id');

        $missing = 0;
        foreach ($query->get(['id', 'user_signature_asset_id', 'signature_role']) as $row) {
            $path = Schema::hasTable('user_signature_assets')
                ? DB::table('user_signature_assets')->where('id', $row->user_signature_asset_id)->value('storage_path')
                : null;
            if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
                $missing++;
                $this->line('Missing asset for signature #'.$row->id.' ('.$row->signature_role.') on BR '.$barangayReportId);
            }
        }

        return $missing;
    }
}
