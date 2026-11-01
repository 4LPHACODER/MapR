<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `impact_estimate_buildings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `point_snapshot` POINT NOT NULL SRID 4326,
  `damage_class_code_snapshot` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `resident_impact_estimate_id` BIGINT UNSIGNED NOT NULL,
  `building_id` BIGINT UNSIGNED NOT NULL,
  `building_verification_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`resident_impact_estimate_id`) REFERENCES `resident_impact_estimates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_verification_id`) REFERENCES `building_verifications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`resident_impact_estimate_id`, `building_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('impact_estimate_buildings'); }
};
