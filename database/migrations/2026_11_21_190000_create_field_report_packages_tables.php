<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_report_packages')) {
            Schema::create('field_report_packages', function (Blueprint $table) {
                $table->id();
                $table->string('package_reference', 191)->unique();
                $table->string('scope', 32);
                $table->unsignedBigInteger('barangay_id')->nullable();
                $table->string('package_status', 32)->default('generated');
                $table->string('destination', 32)->default('cdrrmo');
                $table->unsignedBigInteger('prepared_by');
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at', 6)->nullable();
                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->timestamp('acknowledged_at', 6)->nullable();
                $table->text('return_reason')->nullable();
                $table->json('meta')->nullable();
                $table->string('snapshot_hash', 64)->nullable();
                $table->timestamps();

                $table->index(['scope', 'package_status']);
                $table->index('barangay_id');
            });
        }

        if (! Schema::hasTable('field_report_package_items')) {
            Schema::create('field_report_package_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('field_report_package_id');
                $table->unsignedBigInteger('field_assessment_id');
                $table->string('assessment_reference', 191)->nullable();
                $table->timestamps();

                $table->unique(['field_report_package_id', 'field_assessment_id'], 'frpi_package_assessment_unique');
                $table->index('field_assessment_id');
            });
        }

        if (! Schema::hasTable('field_report_exports')) {
            Schema::create('field_report_exports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('field_report_package_id')->nullable();
                $table->string('scope', 32);
                $table->unsignedBigInteger('barangay_id')->nullable();
                $table->string('filename', 255);
                $table->string('disk_path', 500);
                $table->string('snapshot_hash', 64)->nullable();
                $table->json('assessment_ids')->nullable();
                $table->unsignedBigInteger('generated_by');
                $table->timestamp('generated_at', 6);
                $table->timestamps();

                $table->index(['scope', 'barangay_id']);
                $table->index('field_report_package_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_report_exports');
        Schema::dropIfExists('field_report_package_items');
        Schema::dropIfExists('field_report_packages');
    }
};
