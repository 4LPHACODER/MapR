<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `report_signatures` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `signature_reference` VARCHAR(512) NOT NULL,
  `document_reference` VARCHAR(512) NOT NULL,
  `document_hash` CHAR(64) NOT NULL,
  `mechanism` VARCHAR(32) NOT NULL DEFAULT 'authenticated_e_signature',
  `signed_at` DATETIME(6) NOT NULL,
  `verification_status` VARCHAR(16) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `signer_user_id` BIGINT UNSIGNED NOT NULL,
  `barangay_report_id` BIGINT UNSIGNED NULL,
  `field_assessment_id` BIGINT UNSIGNED NULL,
  `consolidated_report_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`signer_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_report_id`) REFERENCES `barangay_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`barangay_report_id`),
  FOREIGN KEY (`field_assessment_id`) REFERENCES `field_assessments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`field_assessment_id`),
  FOREIGN KEY (`consolidated_report_id`) REFERENCES `consolidated_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`consolidated_report_id`),
  CHECK ((barangay_report_id IS NOT NULL) + (field_assessment_id IS NOT NULL) + (consolidated_report_id IS NOT NULL) = 1),
  CHECK (verification_status IN ('pending','verified','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('report_signatures'); }
};
