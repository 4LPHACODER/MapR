<?php

namespace App\Console\Commands;

use Database\Seeders\MapEligibleSampleSeeder;
use Illuminate\Console\Command;

class MarkSampleLocationsVerifiedCommand extends Command
{
    protected $signature = 'mapr:mark-sample-locations-verified';

    protected $description = 'Mark a small sample of buildings as map-eligible (local/testing only).';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => \Database\Seeders\MapEligibleSampleSeeder::class]);

        return self::SUCCESS;
    }
}
