<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class RoleSeeder extends Seeder { public function run(): void {
 DB::table('roles')->updateOrInsert(['code'=>'resident'], ['name'=>'Resident','description'=>'Registered barangay resident','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'bdrrm'], ['name'=>'BDRRM Personnel','description'=>'Barangay disaster assessment personnel','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'rdana'], ['name'=>'RDANA Assessor','description'=>'Rapid damage assessment and needs analysis assessor','created_at'=>now(),'updated_at'=>now()]);
 DB::table('roles')->updateOrInsert(['code'=>'cdrrmo_admin'], ['name'=>'CDRRMO Administrator','description'=>'Designated city disaster management administrator','created_at'=>now(),'updated_at'=>now()]);
} }
