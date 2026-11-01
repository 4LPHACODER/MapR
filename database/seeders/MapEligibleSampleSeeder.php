<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MapEligibleSampleSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('MapEligibleSampleSeeder is disabled in production.');

            return;
        }

        if (! Schema::hasTable('buildings') || ! Schema::hasTable('users')) {
            return;
        }

        $reviewerId = (int) DB::table('users')->orderBy('id')->value('id');
        if ($reviewerId === 0) {
            return;
        }

        $marked = 0;
        $perBarangay = 10;
        $barangayIds = DB::table('buildings')
            ->whereNotNull('geographic_point')
            ->distinct()
            ->orderBy('barangay_id')
            ->pluck('barangay_id');

        foreach ($barangayIds as $barangayId) {
            $ids = DB::table('buildings')
                ->where('barangay_id', $barangayId)
                ->where('location_verification_status', 'unverified')
                ->whereNotNull('geographic_point')
                ->orderBy('id')
                ->limit($perBarangay)
                ->pluck('id');

            foreach ($ids as $buildingId) {
                $source = DB::table('buildings')->where('id', $buildingId)->value('location_source')
                    ?: 'mapr:sample-eligible-seeder';

                DB::table('buildings')->where('id', $buildingId)->update([
                    'location_verification_status' => 'verified',
                    'location_verified_by' => $reviewerId,
                    'location_verified_at' => now(),
                    'location_source' => $source,
                    'updated_at' => now(),
                ]);
                $marked++;
            }
        }

        $this->command?->info("Marked {$marked} study-area buildings as map-eligible (verified) for local QA.");
    }
}
