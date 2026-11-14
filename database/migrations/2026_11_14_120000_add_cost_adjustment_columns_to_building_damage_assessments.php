<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('building_damage_cost_assessments')) {
            return;
        }

        Schema::table('building_damage_cost_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('building_damage_cost_assessments', 'calculated_estimated_cost_php')) {
                $table->decimal('calculated_estimated_cost_php', 14, 2)->nullable()->after('estimated_cost_php');
            }
            if (! Schema::hasColumn('building_damage_cost_assessments', 'cost_is_adjusted')) {
                $table->boolean('cost_is_adjusted')->default(false)->after('calculated_estimated_cost_php');
            }
            if (! Schema::hasColumn('building_damage_cost_assessments', 'cost_adjustment_reason')) {
                $table->text('cost_adjustment_reason')->nullable()->after('cost_is_adjusted');
            }
        });

        if (Schema::hasColumn('building_damage_cost_assessments', 'calculated_estimated_cost_php')) {
            DB::table('building_damage_cost_assessments')
                ->whereNull('calculated_estimated_cost_php')
                ->whereNotNull('estimated_cost_php')
                ->update([
                    'calculated_estimated_cost_php' => DB::raw('estimated_cost_php'),
                    'cost_is_adjusted' => false,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('building_damage_cost_assessments')) {
            return;
        }

        Schema::table('building_damage_cost_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('building_damage_cost_assessments', 'cost_adjustment_reason')) {
                $table->dropColumn('cost_adjustment_reason');
            }
            if (Schema::hasColumn('building_damage_cost_assessments', 'cost_is_adjusted')) {
                $table->dropColumn('cost_is_adjusted');
            }
            if (Schema::hasColumn('building_damage_cost_assessments', 'calculated_estimated_cost_php')) {
                $table->dropColumn('calculated_estimated_cost_php');
            }
        });
    }
};
