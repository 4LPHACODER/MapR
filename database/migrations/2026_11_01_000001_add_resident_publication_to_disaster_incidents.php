<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('disaster_incidents')) {
            return;
        }

        Schema::table('disaster_incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('disaster_incidents', 'published_for_residents')) {
                $table->boolean('published_for_residents')->default(false);
            }
            if (! Schema::hasColumn('disaster_incidents', 'resident_coverage')) {
                $table->string('resident_coverage', 16)->nullable();
            }
            if (! Schema::hasColumn('disaster_incidents', 'published_at')) {
                $table->dateTime('published_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('disaster_incidents')) {
            return;
        }

        Schema::table('disaster_incidents', function (Blueprint $table) {
            foreach (['published_at', 'resident_coverage', 'published_for_residents'] as $column) {
                if (Schema::hasColumn('disaster_incidents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
