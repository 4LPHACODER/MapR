<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE `building_location_corrections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `building_id` BIGINT UNSIGNED NOT NULL,
  `proposed_point` POINT NOT NULL SRID 4326,
  `original_point` POINT NOT NULL SRID 4326,
  `reason` TEXT NOT NULL,
  `source_reference` TEXT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `proposed_by` BIGINT UNSIGNED NOT NULL,
  `applied_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`proposed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (status IN ('pending','applied','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('building_location_corrections');
    }
};
