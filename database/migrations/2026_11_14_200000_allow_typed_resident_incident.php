<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->relax('resident_incident_reports', [
            'resident_incident_reports_ibfk_2',
            'resident_incident_reports_ibfk_6',
        ], [
            'ALTER TABLE `resident_incident_reports` ADD CONSTRAINT `resident_incident_reports_ibfk_2` FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
            'ALTER TABLE `resident_incident_reports` ADD CONSTRAINT `resident_incident_reports_ibfk_6` FOREIGN KEY (`disaster_incident_id`, `barangay_id`) REFERENCES `disaster_barangays` (`disaster_incident_id`, `barangay_id`) ON DELETE RESTRICT',
        ]);
        $this->relax('barangay_reports', [
            'barangay_reports_ibfk_1',
            'barangay_reports_ibfk_4',
        ], [
            'ALTER TABLE `barangay_reports` ADD CONSTRAINT `barangay_reports_ibfk_1` FOREIGN KEY (`disaster_incident_id`) REFERENCES `disaster_incidents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
            'ALTER TABLE `barangay_reports` ADD CONSTRAINT `barangay_reports_ibfk_4` FOREIGN KEY (`disaster_incident_id`, `barangay_id`) REFERENCES `disaster_barangays` (`disaster_incident_id`, `barangay_id`) ON DELETE RESTRICT',
        ]);
    }

    public function down(): void
    {
        // Typed incidents may already be stored without an official incident id.
    }

    /**
     * @param  list<string>  $constraints
     * @param  list<string>  $restore
     */
    private function relax(string $table, array $constraints, array $restore): void
    {
        $nullable = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'disaster_incident_id']
        );
        if ($nullable === null || $nullable->IS_NULLABLE === 'YES') {
            return;
        }

        foreach ($constraints as $name) {
            DB::statement('ALTER TABLE `'.$table.'` DROP FOREIGN KEY `'.$name.'`');
        }
        DB::statement('ALTER TABLE `'.$table.'` MODIFY `disaster_incident_id` BIGINT UNSIGNED NULL');
        foreach ($restore as $sql) {
            DB::statement($sql);
        }
    }
};
