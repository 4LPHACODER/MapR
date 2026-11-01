<?php

namespace App\Console\Commands;

use App\Support\MapLocationEligibility;
use Database\Seeders\MaprDemoReportWorkflowSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BootstrapLocalDataCommand extends Command
{
    protected $signature = 'mapr:bootstrap-local-data
                            {--skip-import : Skip geospatial import}
                            {--skip-reports : Skip demo report workflow seeder}';

    protected $description = 'Load local CDRRMO demo data: seed reference data, import geospatial registry, verify sample map locations, and seed demo reports.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('mapr:bootstrap-local-data cannot run in production.');

            return self::FAILURE;
        }

        if ($this->needsReferenceData()) {
            $this->info('Seeding reference data (roles, barangays, demo users)…');
            if ($this->call('db:seed') !== self::SUCCESS) {
                $this->error('db:seed failed.');

                return self::FAILURE;
            }
        }

        if (! $this->option('skip-import')) {
            if (ImportGeospatialCommand::resolveHouseholdShapefileBase() === null) {
                $this->error('Household shapefile not found. Expected one of:');
                $this->line('  - geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022/CBMS2022HOUSEHOLD.{shp,dbf}');
                $this->line('  - geospatial_data/CBMS22/CBMS22.{shp,dbf}');

                return self::FAILURE;
            }

            $this->info('Importing geospatial data…');
            if ($this->call('mapr:import-geospatial') !== self::SUCCESS) {
                $this->error('mapr:import-geospatial failed.');

                return self::FAILURE;
            }
        }

        $this->info('Marking sample buildings as map-eligible (verified)…');
        if ($this->call('mapr:mark-sample-locations-verified') !== self::SUCCESS) {
            $this->error('mapr:mark-sample-locations-verified failed.');

            return self::FAILURE;
        }

        if (! $this->option('skip-reports')
            && Schema::hasTable('field_assessments')
            && DB::table('field_assessments')->count() === 0) {
            $this->info('Seeding demo report workflow…');
            $this->call('db:seed', ['--class' => MaprDemoReportWorkflowSeeder::class]);
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    private function needsReferenceData(): bool
    {
        if (! Schema::hasTable('barangays') || ! Schema::hasTable('users')) {
            return true;
        }

        return DB::table('barangays')->count() < 1 || DB::table('users')->count() < 1;
    }

    private function printSummary(): void
    {
        $buildings = Schema::hasTable('buildings') ? (int) DB::table('buildings')->count() : 0;
        $residents = Schema::hasTable('residents')
            ? (int) DB::table('residents')->where('residency_status', 'active')->count()
            : 0;
        $fieldAssessments = Schema::hasTable('field_assessments')
            ? (int) DB::table('field_assessments')->count()
            : 0;

        $eligible = 0;
        if (Schema::hasTable('households') && Schema::hasTable('buildings')) {
            $constraint = MapLocationEligibility::buildingSqlConstraint('buildings');
            $eligible = (int) DB::selectOne(
                "SELECT COUNT(DISTINCT households.id) AS aggregate
                 FROM households
                 JOIN buildings ON buildings.id = households.building_id
                 WHERE households.household_status = 'active' AND {$constraint}",
            )->aggregate;
        }

        $this->newLine();
        $this->info('Local bootstrap summary:');
        $this->line("  Buildings: {$buildings}");
        $this->line("  Active residents: {$residents}");
        $this->line("  Map-eligible household markers: {$eligible}");
        $this->line("  Field assessments: {$fieldAssessments}");
    }
}
