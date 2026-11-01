<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(191) NOT NULL,
  `middle_name` VARCHAR(191) NULL,
  `last_name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `contact_number` VARCHAR(32) NULL,
  `account_status` VARCHAR(16) NOT NULL DEFAULT 'inactive',
  `email_verified_at` DATETIME(6) NULL,
  `remember_token` VARCHAR(100) NULL,
  `deleted_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `barangay_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`email`),
  CHECK (account_status IN ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('users'); }
};
