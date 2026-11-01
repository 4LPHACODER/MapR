<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesMaprAuthSchema
{
    protected function createMaprAuthSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('contact_number')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('account_status')->default('inactive');
            $table->string('job_title')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('barangay_id')->nullable();
        });

        Schema::create('user_barangay_assignments', function (Blueprint $table) {
            $table->id();
            $table->dateTime('assigned_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('assigned_by');
        });
    }
}
