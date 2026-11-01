<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `resident_impact_estimates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `estimated_buildings` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `estimated_households` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `estimated_residents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `unique_vulnerable_residents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `estimation_method` VARCHAR(32) NOT NULL,
  `criteria` JSON NOT NULL,
  `polygon_snapshot` MULTIPOLYGON NOT NULL SRID 4326,
  `spatial_rule` VARCHAR(24) NOT NULL,
  `exclusions` JSON NOT NULL,
  `record_snapshot_at` DATETIME(6) NOT NULL,
  `generated_at` DATETIME(6) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `assessment_area_id` BIGINT UNSIGNED NOT NULL,
  `disaster_incident_id` BIGINT UNSIGNED NOT NULL,
  `generated_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`assessment_area_id`) REFERENCES `assessment_areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (estimation_method IN ('area_population','verified_damaged_buildings'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('resident_impact_estimates'); }
};
