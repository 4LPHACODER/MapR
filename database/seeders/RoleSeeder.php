<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class RoleSeeder extends Seeder { public function run(): void {
 DB::table('roles')->updateOrInsert(['code'=>'resident'], ['name'=>'Resident','description'=>'Registered barangay resident','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'bdrrm'], ['name'=>'BDRRM Personnel','description'=>'Barangay disaster assessment personnel','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'rdana'], ['name'=>'RDANA Assessor','description'=>'Rapid damage assessment and needs analysis assessor','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'cdrrmo_admin'], ['name'=>'CDRRMO Administrator','description'=>'Designated city disaster management administrator','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'barangay_secretary'], ['name'=>'Barangay Secretary','description'=>'Assigns BDRRM personnel and endorses reviewed reports','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'barangay_captain'], ['name'=>'Punong Barangay','description'=>'Approves barangay reports for RDANA','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'rdana_admin_officer'], ['name'=>'RDANA Administrative Officer III','description'=>'Assigns assessors and verifies field assessments','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'rdana_city_assessor'], ['name'=>'City Assessor','description'=>'Approves field assessments for CDRRMO','created_at'=>now(),'updated_at'=>now()]);
} }
