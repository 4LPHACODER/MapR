<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `field_assessments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `assessment_reference` VARCHAR(191) NOT NULL,
  `findings` TEXT NULL,
  `assessment_status` VARCHAR(16) NOT NULL DEFAULT 'draft',
  `verified_at` DATETIME(6) NULL,
  `signed_at` DATETIME(6) NULL,
  `submitted_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `barangay_report_id` BIGINT UNSIGNED NOT NULL,
  `assigned_assessor_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`barangay_report_id`) REFERENCES `barangay_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`assigned_assessor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`assessment_reference`),
  CHECK (assessment_status IN ('draft','review','signed','submitted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('field_assessments'); }
};
