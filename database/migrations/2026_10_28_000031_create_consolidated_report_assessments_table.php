<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `consolidated_report_assessments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `consolidated_report_id` BIGINT UNSIGNED NOT NULL,
  `field_assessment_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`consolidated_report_id`) REFERENCES `consolidated_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`field_assessment_id`) REFERENCES `field_assessments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`consolidated_report_id`, `field_assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('consolidated_report_assessments'); }
};
