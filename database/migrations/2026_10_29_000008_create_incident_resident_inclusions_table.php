<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE `incident_resident_inclusions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `inclusion_basis` VARCHAR(32) NOT NULL,
  `reference` VARCHAR(191) NULL,
  `notes` TEXT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `removed_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `disaster_incident_id` BIGINT UNSIGNED NOT NULL,
  `resident_id` BIGINT UNSIGNED NOT NULL,
  `added_by` BIGINT UNSIGNED NOT NULL,
  `removed_by` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`removed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (`disaster_incident_id`, `resident_id`),
  CHECK (inclusion_basis IN ('manual_review','selected_area','authorized_report')),
  CHECK (status IN ('active','removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `incident_resident_inclusion_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `action` VARCHAR(16) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `inclusion_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`inclusion_id`) REFERENCES `incident_resident_inclusions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (action IN ('added','removed','reinstated'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_resident_inclusion_events');
        Schema::dropIfExists('incident_resident_inclusions');
    }
};
