<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            if (! Schema::hasColumn('households', 'source_household_size')) {
                $table->unsignedInteger('source_household_size')->nullable()->after('record_source');
            }
            if (! Schema::hasColumn('households', 'source_nuclear_families')) {
                $table->unsignedInteger('source_nuclear_families')->nullable()->after('source_household_size');
            }
            if (! Schema::hasColumn('households', 'source_no_males')) {
                $table->unsignedInteger('source_no_males')->nullable()->after('source_nuclear_families');
            }
            if (! Schema::hasColumn('households', 'source_no_females')) {
                $table->unsignedInteger('source_no_females')->nullable()->after('source_no_males');
            }
            if (! Schema::hasColumn('households', 'source_line_number')) {
                $table->string('source_line_number', 32)->nullable()->after('source_no_females');
            }
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('households', 'source_household_size') ? 'source_household_size' : null,
                Schema::hasColumn('households', 'source_nuclear_families') ? 'source_nuclear_families' : null,
                Schema::hasColumn('households', 'source_no_males') ? 'source_no_males' : null,
                Schema::hasColumn('households', 'source_no_females') ? 'source_no_females' : null,
                Schema::hasColumn('households', 'source_line_number') ? 'source_line_number' : null,
            ]);
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
