<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            BarangaySeeder::class,
            DisasterTypeSeeder::class,
            DamageClassSeeder::class,
            VulnerabilityCategorySeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            $this->call(MaprDemoUserSeeder::class);
        } else {
            $this->command?->warn('Skipped MaprDemoUserSeeder (runs only in local or testing).');
        }

        $this->command?->info('Load map, residents, and demo reports locally with: php artisan mapr:bootstrap-local-data');
    }
}
