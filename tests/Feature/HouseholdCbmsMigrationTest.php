<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdCbmsMigrationTest extends TestCase
{
    public function test_cbms_columns_migration_is_idempotent(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_reference');
            $table->string('record_source');
            $table->unsignedInteger('source_household_size')->nullable();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_10_29_000009_add_cbms_source_fields_to_households_table.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('households', 'source_household_size'));
        $this->assertTrue(Schema::hasColumn('households', 'source_line_number'));
    }
}
