<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `user_barangay_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `assigned_at` DATETIME(6) NOT NULL,
  `revoked_at` DATETIME(6) NULL,
  `active_barangay_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN revoked_at IS NULL THEN barangay_id ELSE NULL END) STORED,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `barangay_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`user_id`, `active_barangay_id`),
  CHECK (revoked_at IS NULL OR revoked_at >= assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('user_barangay_assignments'); }
};
