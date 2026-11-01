<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `barangays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `barangay_code` VARCHAR(32) NOT NULL,
  `barangay_name` VARCHAR(191) NOT NULL,
  `city` VARCHAR(191) NOT NULL,
  `province` VARCHAR(191) NOT NULL,
  `region` VARCHAR(191) NOT NULL,
  `boundary_polygon` MULTIPOLYGON NULL SRID 4326,
  `boundary_source` TEXT NULL,
  `boundary_verified_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE (`barangay_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('barangays'); }
};
