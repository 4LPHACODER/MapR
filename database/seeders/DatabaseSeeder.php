<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call([
                RoleSeeder::class,
                BarangaySeeder::class,
                DisasterTypeSeeder::class,
                DamageClassSeeder::class,
                VulnerabilityCategorySeeder::class,
            ]);

            if (app()->environment(['local', 'testing'])) {
                $this->call(MaprDemoUserSeeder::class);
                $this->call(MapRWorkflowPersonnelSeeder::class);
            } else {
                $this->command?->warn('Skipped MaprDemoUserSeeder (runs only in local or testing).');
            }

            $this->command?->info('Load map, residents, and demo reports locally with: php artisan mapr:bootstrap-local-data');
        });
    }
}
