<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `barangay_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `report_reference` VARCHAR(191) NOT NULL,
  `report_description` TEXT NULL,
  `report_status` VARCHAR(16) NOT NULL DEFAULT 'draft',
  `submitted_at` DATETIME(6) NULL,
  `signed_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `disaster_incident_id` BIGINT UNSIGNED NOT NULL,
  `barangay_id` BIGINT UNSIGNED NOT NULL,
  `prepared_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`report_reference`),
  CHECK (report_status IN ('draft','review','signed','submitted')),
  FOREIGN KEY (disaster_incident_id, barangay_id) REFERENCES disaster_barangays (disaster_incident_id, barangay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('barangay_reports'); }
};
