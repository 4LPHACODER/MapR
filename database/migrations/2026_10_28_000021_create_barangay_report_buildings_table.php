<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `barangay_report_buildings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `observations` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `barangay_report_id` BIGINT UNSIGNED NOT NULL,
  `building_id` BIGINT UNSIGNED NOT NULL,
  `damage_prediction_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`barangay_report_id`) REFERENCES `barangay_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`damage_prediction_id`) REFERENCES `building_damage_predictions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`barangay_report_id`, `building_id`),
  FOREIGN KEY (damage_prediction_id, building_id) REFERENCES building_damage_predictions (id, building_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('barangay_report_buildings'); }
};
