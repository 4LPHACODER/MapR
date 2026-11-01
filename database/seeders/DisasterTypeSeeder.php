<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DisasterTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = config('mapr_reference.disaster_types', []);

        if ($rows === []) {
            throw new \RuntimeException('Approved disaster_types required in config/mapr_reference.php');
        }

        foreach ($rows as $row) {
            DB::table('disaster_types')->updateOrInsert(
                ['name' => $row['name']],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
