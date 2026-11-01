<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendReport('barangay_reports', 'report_status', ['draft', 'review', 'signed', 'submitted', 'returned']);
        $this->extendReport('field_assessments', 'assessment_status', ['draft', 'review', 'signed', 'submitted', 'returned']);

        if (Schema::hasTable('report_signatures') && ! Schema::hasColumn('report_signatures', 'signature_role')) {
            Schema::table('report_signatures', function (Blueprint $table) {
                $table->string('signature_role', 32)->default('barangay_signer');
            });
        }

        $this->replaceSingleSignatureUniques();

        if (! Schema::hasTable('building_verification_corrections')) {
            Schema::create('building_verification_corrections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('building_verification_id')->nullable();
                $table->unsignedBigInteger('field_assessment_id');
                $table->unsignedBigInteger('building_id');
                $table->unsignedBigInteger('previous_damage_class_id')->nullable();
                $table->unsignedBigInteger('new_damage_class_id')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('corrected_by');
                $table->dateTime('corrected_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('building_verification_corrections');
    }

    private function extendReport(string $table, string $statusColumn, array $allowed): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'return_reason')) {
                $blueprint->text('return_reason')->nullable();
            }
            if (! Schema::hasColumn($table, 'returned_at')) {
                $blueprint->dateTime('returned_at')->nullable();
            }
            if (! Schema::hasColumn($table, 'supersedes_id')) {
                $blueprint->unsignedBigInteger('supersedes_id')->nullable()->index();
            }
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $checks = DB::select(
            'SELECT cc.CONSTRAINT_NAME AS name
             FROM information_schema.CHECK_CONSTRAINTS cc
             INNER JOIN information_schema.TABLE_CONSTRAINTS tc
                ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
             WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND cc.CHECK_CLAUSE LIKE ?',
            [$table, '%'.$statusColumn.'%'],
        );

        foreach ($checks as $check) {
            DB::statement('ALTER TABLE `'.$table.'` DROP CHECK `'.$check->name.'`');
        }

        $list = implode(',', array_map(fn (string $status) => "'".$status."'", $allowed));
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT {$table}_{$statusColumn}_values CHECK ({$statusColumn} IN ({$list}))");
    }

    private function replaceSingleSignatureUniques(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['barangay_report_id' => 'barangay_reports', 'field_assessment_id' => 'field_assessments', 'consolidated_report_id' => 'consolidated_reports'] as $column => $parent) {
            $uniqueName = "report_signatures_{$column}_role_unique";
            $already = DB::selectOne(
                'SELECT INDEX_NAME AS name FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                ['report_signatures', $uniqueName],
            );
            if ($already) {
                continue;
            }

            $foreignKeys = DB::select(
                'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                ['report_signatures', $column],
            );
            foreach ($foreignKeys as $foreignKey) {
                DB::statement('ALTER TABLE report_signatures DROP FOREIGN KEY `'.$foreignKey->name.'`');
            }

            $indexes = DB::select(
                'SELECT INDEX_NAME AS name FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> ?
                 GROUP BY INDEX_NAME
                 HAVING COUNT(*) = 1 AND SUM(COLUMN_NAME = ?) = 1',
                ['report_signatures', 'PRIMARY', $column],
            );
            foreach ($indexes as $index) {
                DB::statement('ALTER TABLE report_signatures DROP INDEX `'.$index->name.'`');
            }

            DB::statement("ALTER TABLE report_signatures ADD UNIQUE {$uniqueName} ({$column}, signature_role)");
            DB::statement("ALTER TABLE report_signatures ADD CONSTRAINT report_signatures_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES {$parent} (id) ON DELETE RESTRICT ON UPDATE RESTRICT");
        }
    }
};
