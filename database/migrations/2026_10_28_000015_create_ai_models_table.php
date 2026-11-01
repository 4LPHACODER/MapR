<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE TABLE `ai_models` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `model_name` VARCHAR(191) NOT NULL,
  `model_version` VARCHAR(64) NOT NULL,
  `architecture` VARCHAR(32) NOT NULL DEFAULT 'EfficientNetB0',
  `optimizer` VARCHAR(16) NOT NULL DEFAULT 'AdamW',
  `model_file_reference` VARCHAR(512) NOT NULL,
  `artifact_sha256` CHAR(64) NOT NULL,
  `deployment_status` VARCHAR(16) NOT NULL,
  `deployed_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE (`model_name`, `model_version`),
  CHECK (architecture = 'EfficientNetB0' AND optimizer = 'AdamW'),
  CHECK (deployment_status IN ('inactive','active','retired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL); }
 public function down(): void { Schema::dropIfExists('ai_models'); }
};
