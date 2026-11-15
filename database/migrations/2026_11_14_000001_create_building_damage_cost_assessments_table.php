<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('building_damage_cost_assessments')) {
            Schema::create('building_damage_cost_assessments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('barangay_report_id');
                $table->unsignedBigInteger('field_assessment_id')->nullable();
                $table->unsignedBigInteger('building_id');
                $table->unsignedBigInteger('ai_prediction_id')->nullable();
                $table->unsignedBigInteger('proposed_damage_class_id')->nullable();
                $table->unsignedBigInteger('confirmed_damage_class_id')->nullable();
                $table->string('classification_source', 32)->default('manual');
                $table->text('correction_reason')->nullable();
                $table->string('occupancy_key', 64)->nullable();
                $table->string('construction_type', 8)->nullable();
                $table->string('construction_grade', 8)->nullable();
                $table->decimal('total_floor_area_m2', 12, 4)->nullable();
                $table->decimal('assessed_floor_area_m2', 12, 4)->nullable();
                $table->decimal('unit_cost_php', 12, 2)->nullable();
                $table->string('rate_source', 32)->nullable();
                $table->text('rate_source_note')->nullable();
                $table->string('criteria_version', 64)->nullable();
                $table->decimal('estimated_cost_php', 14, 2)->nullable();
                $table->string('cost_status', 32)->default('pending_inputs');
                $table->string('assessment_status', 32)->default('draft');
                $table->unsignedBigInteger('supersedes_id')->nullable();
                $table->unsignedBigInteger('assessed_by');
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->text('remarks')->nullable();
                $table->json('breakdown_json')->nullable();
                $table->timestamps();

                // Short names: MySQL identifier limit is 64 characters.
                $table->index(['barangay_report_id', 'building_id'], 'bdca_report_building_idx');
                $table->index(['field_assessment_id', 'building_id'], 'bdca_field_building_idx');
                $table->index('assessment_status', 'bdca_status_idx');
            });

            return;
        }

        Schema::table('building_damage_cost_assessments', function (Blueprint $table) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = collect($sm->getIndexes('building_damage_cost_assessments'))
                ->pluck('name')
                ->all();

            if (! in_array('bdca_report_building_idx', $indexes, true)) {
                $table->index(['barangay_report_id', 'building_id'], 'bdca_report_building_idx');
            }
            if (! in_array('bdca_field_building_idx', $indexes, true)) {
                $table->index(['field_assessment_id', 'building_id'], 'bdca_field_building_idx');
            }
            if (! in_array('bdca_status_idx', $indexes, true)) {
                $table->index('assessment_status', 'bdca_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_damage_cost_assessments');
    }
};
