<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `resident_vulnerabilities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `verification_status` VARCHAR(16) NOT NULL,
  `record_source` VARCHAR(191) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `resident_id` BIGINT UNSIGNED NOT NULL,
  `vulnerability_category_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`vulnerability_category_id`) REFERENCES `vulnerability_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`resident_id`, `vulnerability_category_id`),
  CHECK (verification_status IN ('unverified','verified','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('resident_vulnerabilities'); }
};
