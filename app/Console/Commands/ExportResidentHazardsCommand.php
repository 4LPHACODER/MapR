<?php

namespace App\Console\Commands;

use App\Services\ResidentHazardExporter;
use Illuminate\Console\Command;

class ExportResidentHazardsCommand extends Command
{
    protected $signature = 'mapr:export-resident-hazards';

    protected $description = 'Convert verified WGS84 hazard shapefiles into cached Resident GeoJSON.';

    public function handle(ResidentHazardExporter $exporter): int
    {
        $catalog = $exporter->export();
        foreach ($catalog['layers'] as $layer) {
            $this->info($layer['name'].': '.count($layer['legend']).' legend entries');
        }
        $this->info('Resident hazard layers written to storage/app/private/resident-hazards.');

        return self::SUCCESS;
    }
}
