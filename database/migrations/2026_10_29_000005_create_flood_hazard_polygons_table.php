<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE `flood_hazard_polygons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `source_object_id` VARCHAR(64) NOT NULL,
  `classification` VARCHAR(32) NOT NULL,
  `geometry` MULTIPOLYGON NOT NULL SRID 4326,
  `source` TEXT NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE (`source_object_id`),
  SPATIAL INDEX (`geometry`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('flood_hazard_polygons');
    }
};
