<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_flags')) {
            return;
        }

        Schema::create('report_flags', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('category', 80);
            $table->text('reason');
            $table->string('status', 16)->default('open');
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_flags');
    }
};
