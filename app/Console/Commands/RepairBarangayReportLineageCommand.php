<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairBarangayReportLineageCommand extends Command
{
    protected $signature = 'mapr:repair-barangay-report-lineage {--dry-run : Report matches without writing}';

    protected $description = 'Link a barangay report to the resident household building when that link is missing and unambiguous.';

    public function handle(): int
    {
        if (! Schema::hasTable('barangay_reports') || ! Schema::hasTable('resident_incident_reports') || ! Schema::hasTable('barangay_report_buildings')) {
            $this->error('Required report tables are not available.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $linked = 0;
        $skipped = 0;
        $reports = DB::table('barangay_reports')->orderBy('id')->get(['id', 'barangay_id', 'report_reference']);

        foreach ($reports as $report) {
            $already = DB::table('barangay_report_buildings')->where('barangay_report_id', $report->id)->exists();
            if ($already) {
                $skipped++;

                continue;
            }

            $residentReport = DB::table('resident_incident_reports')->where('barangay_report_id', $report->id)->orderByDesc('id')->first();
            if ($residentReport === null) {
                $skipped++;

                continue;
            }

            $buildingId = null;
            if (Schema::hasColumn('resident_incident_reports', 'building_id') && ! empty($residentReport->building_id)) {
                $buildingId = (int) $residentReport->building_id;
            } elseif (Schema::hasTable('account_resident_links') && Schema::hasTable('residents') && Schema::hasTable('households')) {
                $homes = DB::table('account_resident_links')
                    ->join('residents', 'residents.id', '=', 'account_resident_links.resident_id')
                    ->join('households', 'households.id', '=', 'residents.household_id')
                    ->where('account_resident_links.user_id', $residentReport->resident_user_id)
                    ->whereNotNull('households.building_id')
                    ->pluck('households.building_id')
                    ->unique()
                    ->values();
                if ($homes->count() === 1) {
                    $buildingId = (int) $homes->first();
                }
            }

            if (! $buildingId || ! Schema::hasTable('buildings')) {
                $skipped++;

                continue;
            }

            $building = DB::table('buildings')->where('id', $buildingId)->first(['id', 'barangay_id', 'building_reference']);
            if ($building === null || (int) $building->barangay_id !== (int) $report->barangay_id) {
                $skipped++;

                continue;
            }

            $this->line(($dryRun ? 'Would link ' : 'Linked ').$report->report_reference.' to '.($building->building_reference ?? $building->id));
            if (! $dryRun) {
                DB::table('barangay_report_buildings')->insert([
                    'barangay_report_id' => $report->id,
                    'building_id' => $building->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $linked++;
        }

        $this->info(($dryRun ? 'Would link ' : 'Linked ').$linked.' report(s). Skipped '.$skipped.'.');

        return self::SUCCESS;
    }
}
