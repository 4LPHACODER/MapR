<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DamageClassSeeder extends Seeder
{
    public function run(): void
    {
        $rows = config('mapr_reference.damage_classes', []);

        if ($rows === []) {
            throw new \RuntimeException('Approved damage_classes required in config/mapr_reference.php');
        }

        foreach ($rows as $row) {
            $exists = DB::table('damage_classes')->where('class_code', $row['class_code'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('damage_classes')->insert([
                'class_code' => $row['class_code'],
                'class_name' => $row['class_name'],
                'description' => $row['description'] ?? null,
                'display_order' => $row['display_order'],
                'counts_as_damaged' => (bool) $row['counts_as_damaged'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
