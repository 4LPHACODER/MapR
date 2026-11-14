<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND CONSTRAINT_TYPE = 'CHECK'"
        );
        foreach ($existing as $constraint) {
            DB::statement('ALTER TABLE `roles` DROP CHECK `'.$constraint->CONSTRAINT_NAME.'`');
        }

        DB::statement(<<<'SQL'
ALTER TABLE `roles`
  ADD CONSTRAINT roles_code_chk CHECK (code IN (
    'resident',
    'bdrrm',
    'rdana',
    'cdrrmo_admin',
    'barangay_secretary',
    'barangay_captain',
    'rdana_admin_officer',
    'rdana_city_assessor'
  ))
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `roles` DROP CHECK `roles_code_chk`');
        DB::statement(<<<'SQL'
ALTER TABLE `roles`
  ADD CONSTRAINT roles_chk_1 CHECK (code IN ('resident','bdrrm','rdana','cdrrmo_admin'))
SQL);
    }
};
