<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `disaster_incidents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `incident_reference` VARCHAR(191) NOT NULL,
  `incident_name` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `incident_status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `disaster_type_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`disaster_type_id`) REFERENCES `disaster_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`incident_reference`),
  CHECK (incident_status IN ('active','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('disaster_incidents'); }
};
