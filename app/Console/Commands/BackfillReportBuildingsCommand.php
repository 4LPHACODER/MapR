<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BackfillReportBuildingsCommand extends Command
{
    protected $signature = 'mapr:backfill-report-buildings {--dry-run : List links without writing}';

    protected $description = 'Link unambiguous resident-handoff barangay reports to the reporter household building when exactly one verified building exists.';

    public function handle(): int
    {
        if (! Schema::hasTable('barangay_reports')
            || ! Schema::hasTable('barangay_report_buildings')
            || ! Schema::hasTable('resident_incident_reports')
            || ! Schema::hasTable('account_resident_links')
            || ! Schema::hasTable('households')
            || ! Schema::hasTable('buildings')) {
            $this->warn('Required tables are missing.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $scanned = 0;
        $linked = 0;
        $skipped = 0;

        $reports = DB::table('barangay_reports as br')
            ->join('resident_incident_reports as rir', 'rir.barangay_report_id', '=', 'br.id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('barangay_report_buildings as brb')
                    ->whereColumn('brb.barangay_report_id', 'br.id');
            })
            ->orderBy('br.id')
            ->get([
                'br.id as barangay_report_id',
                'br.report_reference',
                'br.barangay_id',
                'rir.id as resident_report_id',
                'rir.resident_user_id',
                'rir.building_id as resident_building_id',
            ]);

        foreach ($reports as $report) {
            $scanned++;
            $buildingId = $report->resident_building_id ? (int) $report->resident_building_id : null;
            if (! $buildingId) {
                $candidates = DB::table('account_resident_links')
                    ->join('residents', 'residents.id', '=', 'account_resident_links.resident_id')
                    ->join('households', 'households.id', '=', 'residents.household_id')
                    ->where('account_resident_links.user_id', $report->resident_user_id)
                    ->where('households.barangay_id', $report->barangay_id)
                    ->whereNotNull('households.building_id')
                    ->distinct()
                    ->pluck('households.building_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
                if (count($candidates) !== 1) {
                    $skipped++;
                    $this->line("Skip {$report->report_reference}: unambiguous building not found (".count($candidates).' candidates).');

                    continue;
                }
                $buildingId = $candidates[0];
            }

            $valid = DB::table('buildings')
                ->where('id', $buildingId)
                ->where('barangay_id', $report->barangay_id)
                ->exists();
            if (! $valid) {
                $skipped++;
                $this->line("Skip {$report->report_reference}: building {$buildingId} not in report barangay.");

                continue;
            }

            if ($dryRun) {
                $this->info("[dry-run] Would link {$report->report_reference} → building {$buildingId}");
                $linked++;

                continue;
            }

            DB::transaction(function () use ($report, $buildingId) {
                DB::table('barangay_report_buildings')->insert([
                    'observations' => null,
                    'barangay_report_id' => $report->barangay_report_id,
                    'building_id' => $buildingId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (Schema::hasColumn('resident_incident_reports', 'building_id') && empty($report->resident_building_id)) {
                    DB::table('resident_incident_reports')->where('id', $report->resident_report_id)->update([
                        'building_id' => $buildingId,
                        'updated_at' => now(),
                    ]);
                }
                if (Schema::hasTable('incident_photos')) {
                    DB::table('incident_photos')
                        ->where('barangay_report_id', $report->barangay_report_id)
                        ->whereNull('building_id')
                        ->update([
                            'building_id' => $buildingId,
                            'updated_at' => now(),
                        ]);
                }
            });

            Log::info('mapr.backfill_report_buildings', [
                'barangay_report_id' => $report->barangay_report_id,
                'report_reference' => $report->report_reference,
                'building_id' => $buildingId,
            ]);
            $this->info("Linked {$report->report_reference} → building {$buildingId}");
            $linked++;
        }

        $this->info("Scanned: {$scanned}. Linked: {$linked}. Skipped: {$skipped}.".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
