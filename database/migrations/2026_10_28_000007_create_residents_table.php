<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `residents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `registry_reference` VARCHAR(191) NOT NULL,
  `first_name` VARCHAR(191) NOT NULL,
  `middle_name` VARCHAR(191) NULL,
  `last_name` VARCHAR(191) NOT NULL,
  `birth_date` DATE NULL,
  `sex` VARCHAR(32) NULL,
  `residency_status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `record_source` VARCHAR(191) NOT NULL,
  `record_verified_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `household_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`registry_reference`),
  CHECK (residency_status IN ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('residents'); }
};
