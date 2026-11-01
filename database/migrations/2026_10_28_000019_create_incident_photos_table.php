<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `incident_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `file_path` VARCHAR(512) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(64) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `file_sha256` CHAR(64) NOT NULL,
  `image_source` VARCHAR(191) NOT NULL,
  `captured_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `incident_report_id` BIGINT UNSIGNED NULL,
  `barangay_report_id` BIGINT UNSIGNED NULL,
  `field_assessment_id` BIGINT UNSIGNED NULL,
  `building_id` BIGINT UNSIGNED NULL,
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`incident_report_id`) REFERENCES `resident_incident_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_report_id`) REFERENCES `barangay_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`field_assessment_id`) REFERENCES `field_assessments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK ((incident_report_id IS NOT NULL) + (barangay_report_id IS NOT NULL) + (field_assessment_id IS NOT NULL) = 1),
  CHECK (mime_type IN ('image/jpeg','image/png','image/webp') AND file_size BETWEEN 1 AND 10485760)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('incident_photos'); }
};
