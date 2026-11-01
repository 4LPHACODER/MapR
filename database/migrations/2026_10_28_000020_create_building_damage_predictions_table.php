<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `building_damage_predictions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `confidence_score` DECIMAL(8,7) NULL,
  `inference_time_ms` DECIMAL(12,3) NULL,
  `prediction_status` VARCHAR(16) NOT NULL,
  `predicted_at` DATETIME(6) NULL,
  `failure_reason` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `incident_photo_id` BIGINT UNSIGNED NOT NULL,
  `building_id` BIGINT UNSIGNED NULL,
  `ai_model_id` BIGINT UNSIGNED NOT NULL,
  `predicted_damage_class_id` BIGINT UNSIGNED NULL,
  `requested_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`incident_photo_id`) REFERENCES `incident_photos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`ai_model_id`) REFERENCES `ai_models` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`predicted_damage_class_id`) REFERENCES `damage_classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`id`, `building_id`),
  CHECK (prediction_status IN ('queued','succeeded','failed')),
  CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 1),
  CHECK (inference_time_ms IS NULL OR inference_time_ms >= 0),
  CHECK (prediction_status <> 'succeeded' OR (predicted_damage_class_id IS NOT NULL AND predicted_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('building_damage_predictions'); }
};
