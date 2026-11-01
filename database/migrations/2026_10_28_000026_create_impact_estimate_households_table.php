<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `impact_estimate_households` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `resident_impact_estimate_id` BIGINT UNSIGNED NOT NULL,
  `household_id` BIGINT UNSIGNED NOT NULL,
  `building_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`resident_impact_estimate_id`) REFERENCES `resident_impact_estimates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`resident_impact_estimate_id`, `household_id`),
  FOREIGN KEY (resident_impact_estimate_id, building_id) REFERENCES impact_estimate_buildings (resident_impact_estimate_id, building_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('impact_estimate_households'); }
};
