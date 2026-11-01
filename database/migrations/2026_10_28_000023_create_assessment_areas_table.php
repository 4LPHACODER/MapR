<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `assessment_areas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `area_name` VARCHAR(191) NOT NULL,
  `polygon_geometry` MULTIPOLYGON NOT NULL SRID 4326,
  `spatial_rule` VARCHAR(24) NOT NULL DEFAULT 'includes_boundary',
  `selected_at` DATETIME(6) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `disaster_incident_id` BIGINT UNSIGNED NOT NULL,
  `selected_by` BIGINT UNSIGNED NOT NULL,
  SPATIAL INDEX (polygon_geometry),
  FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`selected_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (spatial_rule IN ('includes_boundary','strict_interior'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('assessment_areas'); }
};
