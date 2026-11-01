<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_incident_reports')) {
            Schema::table('resident_incident_reports', function (Blueprint $table) {
                if (! Schema::hasColumn('resident_incident_reports', 'return_reason')) {
                    $table->text('return_reason')->nullable();
                }
                if (! Schema::hasColumn('resident_incident_reports', 'returned_at')) {
                    $table->dateTime('returned_at')->nullable();
                }
                if (! Schema::hasColumn('resident_incident_reports', 'observed_at')) {
                    $table->dateTime('observed_at')->nullable();
                }
                if (! Schema::hasColumn('resident_incident_reports', 'observed_at_approximate')) {
                    $table->boolean('observed_at_approximate')->default(false);
                }
                if (! Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
                    $table->unsignedBigInteger('barangay_report_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('building_verifications') && ! Schema::hasColumn('building_verifications', 'estimated_repair_cost')) {
            Schema::table('building_verifications', function (Blueprint $table) {
                $table->decimal('estimated_repair_cost', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('resident_incident_reports')) {
            Schema::table('resident_incident_reports', function (Blueprint $table) {
                foreach (['return_reason', 'returned_at', 'observed_at', 'observed_at_approximate', 'barangay_report_id'] as $column) {
                    if (Schema::hasColumn('resident_incident_reports', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('building_verifications') && Schema::hasColumn('building_verifications', 'estimated_repair_cost')) {
            Schema::table('building_verifications', function (Blueprint $table) {
                $table->dropColumn('estimated_repair_cost');
            });
        }
    }
};
