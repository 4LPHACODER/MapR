<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `impact_estimate_vulnerabilities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `resident_impact_estimate_id` BIGINT UNSIGNED NOT NULL,
  `resident_id` BIGINT UNSIGNED NOT NULL,
  `vulnerability_category_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`resident_impact_estimate_id`) REFERENCES `resident_impact_estimates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`vulnerability_category_id`) REFERENCES `vulnerability_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`resident_impact_estimate_id`, `resident_id`, `vulnerability_category_id`),
  FOREIGN KEY (resident_impact_estimate_id, resident_id) REFERENCES impact_estimate_residents (resident_impact_estimate_id, resident_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('impact_estimate_vulnerabilities'); }
};
