<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `resident_incident_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `report_reference` VARCHAR(191) NOT NULL,
  `incident_description` TEXT NULL,
  `reported_location` TEXT NULL,
  `reported_point` POINT NULL SRID 4326,
  `report_status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `submitted_at` DATETIME(6) NULL,
  `reviewed_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `resident_user_id` BIGINT UNSIGNED NOT NULL,
  `disaster_incident_id` BIGINT UNSIGNED NOT NULL,
  `barangay_id` BIGINT UNSIGNED NOT NULL,
  `building_id` BIGINT UNSIGNED NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`resident_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`report_reference`),
  CHECK (report_status IN ('draft','submitted','under_review','reviewed','returned')),
  FOREIGN KEY (disaster_incident_id, barangay_id) REFERENCES disaster_barangays (disaster_incident_id, barangay_id) ON DELETE RESTRICT,
  FOREIGN KEY (building_id, barangay_id) REFERENCES buildings (id, barangay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('resident_incident_reports'); }
};
