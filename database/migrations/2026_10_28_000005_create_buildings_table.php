<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `buildings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `building_reference` VARCHAR(191) NOT NULL,
  `building_name` VARCHAR(191) NULL,
  `building_type` VARCHAR(64) NULL,
  `address_description` TEXT NULL,
  `geographic_point` POINT NULL SRID 4326,
  `location_source` TEXT NULL,
  `location_verification_status` VARCHAR(16) NOT NULL DEFAULT 'unverified',
  `location_verified_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `barangay_id` BIGINT UNSIGNED NOT NULL,
  `location_verified_by` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`location_verified_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`building_reference`),
  UNIQUE (`id`, `barangay_id`),
  CHECK (location_verification_status IN ('unverified','verified','rejected')),
  CHECK (location_verification_status <> 'verified' OR (geographic_point IS NOT NULL AND location_source IS NOT NULL AND location_verified_by IS NOT NULL AND location_verified_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('buildings'); }
};
