<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sessions') && ! Schema::hasColumn('sessions', 'user_agent')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->text('user_agent')->nullable()->after('ip_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sessions', 'user_agent')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->dropColumn('user_agent');
            });
        }
    }
};
