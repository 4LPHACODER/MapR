<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_incident_reports') && ! Schema::hasColumn('resident_incident_reports', 'reported_incident_type')) {
            Schema::table('resident_incident_reports', function (Blueprint $table) {
                $table->string('reported_incident_type', 120)->nullable()->after('disaster_incident_id');
            });
        }

        if (Schema::hasTable('barangay_reports') && ! Schema::hasColumn('barangay_reports', 'workflow_stage')) {
            Schema::table('barangay_reports', function (Blueprint $table) {
                $table->string('workflow_stage', 40)->nullable()->after('report_status');
            });
        }

        if (Schema::hasTable('field_assessments') && ! Schema::hasColumn('field_assessments', 'workflow_stage')) {
            Schema::table('field_assessments', function (Blueprint $table) {
                $table->string('workflow_stage', 40)->nullable()->after('assessment_status');
            });
        }

        if (! Schema::hasTable('report_assignments')) {
            Schema::create('report_assignments', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 40);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('assignee_user_id')->constrained('users');
                $table->foreignId('assigned_by')->constrained('users');
                $table->string('assignment_role', 20);
                $table->string('remarks', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('replaced_reason', 500)->nullable();
                $table->timestamps();
                $table->index(['subject_type', 'subject_id', 'assignment_role', 'is_active'], 'report_assignments_active_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_assignments');
        foreach (['barangay_reports', 'field_assessments'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'workflow_stage')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('workflow_stage');
                });
            }
        }
        if (Schema::hasTable('resident_incident_reports') && Schema::hasColumn('resident_incident_reports', 'reported_incident_type')) {
            Schema::table('resident_incident_reports', function (Blueprint $table) {
                $table->dropColumn('reported_incident_type');
            });
        }
    }
};
