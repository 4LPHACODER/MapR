<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BarangaySeeder extends Seeder
{
    public function run(): void
    {
        $rows = config('mapr_reference.barangays', []);

        if ($rows === []) {
            throw new \RuntimeException('Approved barangays required in config/mapr_reference.php');
        }

        if (count($rows) !== 4) {
            throw new \RuntimeException('Exactly four approved barangays required');
        }

        foreach ($rows as $row) {
            $payload = [
                'barangay_name' => $row['barangay_name'],
                'city' => $row['city'],
                'province' => $row['province'],
                'region' => $row['region'],
                'updated_at' => now(),
            ];

            DB::table('barangays')->updateOrInsert(
                ['barangay_code' => $row['barangay_code']],
                [...$payload, 'created_at' => now()],
            );
        }
    }
}
