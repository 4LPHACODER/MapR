<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `disaster_barangays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `disaster_incident_id` BIGINT UNSIGNED NOT NULL,
  `barangay_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`disaster_incident_id`, `barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('disaster_barangays'); }
};
