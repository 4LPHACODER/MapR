<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `households` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `household_reference` VARCHAR(191) NOT NULL,
  `household_status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `record_source` VARCHAR(191) NOT NULL,
  `record_verified_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `building_id` BIGINT UNSIGNED NULL,
  `barangay_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`household_reference`),
  FOREIGN KEY (building_id, barangay_id) REFERENCES buildings (id, barangay_id) ON DELETE RESTRICT,
  CHECK (household_status IN ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('households'); }
};
