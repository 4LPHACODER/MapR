<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `action` VARCHAR(100) NOT NULL,
  `module` VARCHAR(64) NOT NULL,
  `record_type` VARCHAR(100) NULL,
  `record_id` BIGINT UNSIGNED NULL,
  `description` VARCHAR(512) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(512) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `user_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('activity_logs'); }
};
