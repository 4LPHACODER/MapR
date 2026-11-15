<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->updateOrInsert(['code' => 'resident'], fn (bool $exists) => ['name' => 'Resident', 'description' => 'Registered barangay resident', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
        DB::table('roles')->updateOrInsert(['code' => 'bdrrm'], fn (bool $exists) => ['name' => 'BDRRM Personnel', 'description' => 'Barangay disaster assessment personnel', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
        DB::table('roles')->updateOrInsert(['code' => 'rdana'], fn (bool $exists) => ['name' => 'RDANA Assessor', 'description' => 'Rapid damage assessment and needs analysis assessor', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
        DB::table('roles')->updateOrInsert(['code' => 'cdrrmo_admin'], fn (bool $exists) => ['name' => 'CDRRMO Administrator', 'description' => 'Designated city disaster management administrator', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
        DB::table('roles')->updateOrInsert(['code' => 'barangay_secretary'], fn (bool $exists) => ['name' => 'Barangay Secretary', 'description' => 'Assigns BDRRM personnel and endorses reviewed reports', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
        DB::table('roles')->updateOrInsert(['code' => 'barangay_captain'], fn (bool $exists) => ['name' => 'Punong Barangay', 'description' => 'Approves barangay reports for RDANA', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
        DB::table('roles')->updateOrInsert(['code' => 'rdana_admin_officer'], fn (bool $exists) => ['name' => 'RDANA Administrative Officer III', 'description' => 'Assigns assessors and verifies field assessments', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
        DB::table('roles')->updateOrInsert(['code' => 'rdana_city_assessor'], fn (bool $exists) => ['name' => 'City Assessor', 'description' => 'Approves field assessments for CDRRMO', 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])]);
    }
}
