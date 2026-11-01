<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_signature_assets')) {
            Schema::create('user_signature_assets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('storage_path');
                $table->string('mime_type', 64);
                $table->unsignedInteger('byte_size');
                $table->unsignedInteger('width');
                $table->unsignedInteger('height');
                $table->char('sha256', 64);
                $table->boolean('is_current')->default(true);
                $table->timestamps();
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('report_signatures')) {
            Schema::table('report_signatures', function (Blueprint $table) {
                if (! Schema::hasColumn('report_signatures', 'user_signature_asset_id')) {
                    $table->unsignedBigInteger('user_signature_asset_id')->nullable();
                }
                if (! Schema::hasColumn('report_signatures', 'signature_statement')) {
                    $table->text('signature_statement')->nullable();
                }
            });
        }

        if (! Schema::hasTable('imported_report_documents')) {
            Schema::create('imported_report_documents', function (Blueprint $table) {
                $table->id();
                $table->string('import_key')->unique();
                $table->string('filename');
                $table->string('storage_path');
                $table->char('sha256', 64);
                $table->string('workflow_status', 32);
                $table->string('reviewing_office', 64);
                $table->unsignedBigInteger('barangay_id')->nullable();
                $table->unsignedBigInteger('resident_user_id')->nullable();
                $table->unsignedBigInteger('resident_incident_report_id')->nullable();
                $table->unsignedBigInteger('barangay_report_id')->nullable();
                $table->unsignedBigInteger('field_assessment_id')->nullable();
                $table->unsignedBigInteger('consolidated_report_id')->nullable();
                $table->string('source_resident_reference')->nullable();
                $table->string('source_building_reference')->nullable();
                $table->string('source_household_reference')->nullable();
                $table->unsignedBigInteger('resolved_resident_id')->nullable();
                $table->unsignedBigInteger('resolved_building_id')->nullable();
                $table->unsignedBigInteger('resolved_household_id')->nullable();
                $table->json('unresolved_identifiers')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_report_documents');
        Schema::dropIfExists('user_signature_assets');
    }
};
