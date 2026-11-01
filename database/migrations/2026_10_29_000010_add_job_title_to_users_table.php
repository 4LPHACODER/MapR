<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'job_title')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'job_title')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
    }
};
