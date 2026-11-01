<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_signatures')) {
            return;
        }

        if (! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            Schema::table('report_signatures', function (Blueprint $table) {
                $table->unsignedBigInteger('resident_incident_report_id')->nullable();
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $foreign = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
            ['report_signatures', 'resident_incident_report_id', 'resident_incident_reports'],
        );
        if (! $foreign && Schema::hasTable('resident_incident_reports')) {
            DB::statement('ALTER TABLE report_signatures ADD CONSTRAINT report_signatures_resident_incident_report_id_foreign FOREIGN KEY (resident_incident_report_id) REFERENCES resident_incident_reports (id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        }

        $unique = DB::selectOne(
            'SELECT INDEX_NAME AS name FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['report_signatures', 'report_signatures_resident_incident_report_id_role_unique'],
        );
        if (! $unique && Schema::hasColumn('report_signatures', 'signature_role')) {
            DB::statement('ALTER TABLE report_signatures ADD UNIQUE report_signatures_resident_incident_report_id_role_unique (resident_incident_report_id, signature_role)');
        }

        $this->replaceParentCheck();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return;
        }

        DB::statement('ALTER TABLE report_signatures DROP INDEX report_signatures_resident_incident_report_id_role_unique');
        DB::statement('ALTER TABLE report_signatures DROP FOREIGN KEY report_signatures_resident_incident_report_id_foreign');
        Schema::table('report_signatures', function (Blueprint $table) {
            $table->dropColumn('resident_incident_report_id');
        });
    }

    private function replaceParentCheck(): void
    {
        $checks = DB::select(
            'SELECT cc.CONSTRAINT_NAME AS name
             FROM information_schema.CHECK_CONSTRAINTS cc
             INNER JOIN information_schema.TABLE_CONSTRAINTS tc
                ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
             WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?
               AND cc.CHECK_CLAUSE LIKE ? AND cc.CHECK_CLAUSE LIKE ? AND cc.CHECK_CLAUSE LIKE ?
               AND cc.CHECK_CLAUSE NOT LIKE ?',
            ['report_signatures', '%barangay_report_id%', '%field_assessment_id%', '%consolidated_report_id%', '%resident_incident_report_id%'],
        );

        foreach ($checks as $check) {
            DB::statement('ALTER TABLE report_signatures DROP CHECK `'.$check->name.'`');
        }

        $replacement = DB::selectOne(
            'SELECT cc.CONSTRAINT_NAME AS name
             FROM information_schema.CHECK_CONSTRAINTS cc
             INNER JOIN information_schema.TABLE_CONSTRAINTS tc
                ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
             WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND cc.CONSTRAINT_NAME = ?',
            ['report_signatures', 'report_signatures_one_parent'],
        );
        if ($replacement) {
            return;
        }

        DB::statement('ALTER TABLE report_signatures ADD CONSTRAINT report_signatures_one_parent CHECK ((barangay_report_id IS NOT NULL) + (field_assessment_id IS NOT NULL) + (consolidated_report_id IS NOT NULL) + (resident_incident_report_id IS NOT NULL) = 1)');
    }
};
