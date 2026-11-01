<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `building_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `location_verified` BOOLEAN NOT NULL DEFAULT 0,
  `household_link_verified` BOOLEAN NOT NULL DEFAULT 0,
  `verification_notes` TEXT NULL,
  `verified_at` DATETIME(6) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `field_assessment_id` BIGINT UNSIGNED NOT NULL,
  `building_id` BIGINT UNSIGNED NOT NULL,
  `damage_prediction_id` BIGINT UNSIGNED NULL,
  `verified_damage_class_id` BIGINT UNSIGNED NOT NULL,
  `verified_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`field_assessment_id`) REFERENCES `field_assessments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`damage_prediction_id`) REFERENCES `building_damage_predictions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`verified_damage_class_id`) REFERENCES `damage_classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`field_assessment_id`, `building_id`),
  FOREIGN KEY (damage_prediction_id, building_id) REFERENCES building_damage_predictions (id, building_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('building_verifications'); }
};
