<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE `building_location_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `building_id` BIGINT UNSIGNED NOT NULL,
  `previous_status` VARCHAR(16) NOT NULL,
  `new_status` VARCHAR(16) NOT NULL,
  `basis_or_reason` TEXT NOT NULL,
  `reviewed_by` BIGINT UNSIGNED NOT NULL,
  `reviewed_at` DATETIME(6) NOT NULL,
  `selection_id` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (previous_status IN ('unverified','verified','rejected')),
  CHECK (new_status IN ('unverified','verified','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('building_location_reviews');
    }
};
