<?php

namespace Database\Seeders;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Support\RoleHome;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedRoles();

            foreach ([
                'barangays' => 'barangay_code',
                'disaster_types' => 'name',
                'damage_classes' => 'class_code',
                'vulnerability_categories' => 'category_name',
            ] as $table => $key) {
                foreach (config('mapr_reference.'.$table) as $row) {
                    DB::table($table)->updateOrInsert(
                        [$key => $row[$key]],
                        fn (bool $exists) => $exists && $table === 'damage_classes' ? [] : [
                            ...Arr::except($row, [$key, 'source_name', 'source_id']),
                            'updated_at' => now(),
                            ...($exists ? [] : ['created_at' => now()]),
                        ],
                    );
                }
            }

            if (! app()->environment(['local', 'testing'])) {
                return;
            }

            foreach (Role::query()->whereIn('code', array_keys(RoleHome::PREFIXES))->get() as $role) {
                $name = Str::of($role->name);
                User::withTrashed()->firstOrCreate(
                    ['email' => str_replace('_', '.', $role->code).'@mapr.test'],
                    [
                        'first_name' => $name->contains(' ') ? (string) $name->beforeLast(' ') : $role->name,
                        'last_name' => $name->contains(' ') ? (string) $name->afterLast(' ') : '',
                        'password' => 'password',
                        'account_status' => 'active',
                        'role_id' => $role->id,
                    ],
                );
            }
        });
    }

    private function seedRoles(): void
    {
        foreach ([
            'resident' => 'Resident',
            'bdrrm' => 'BDRRM Personnel',
            'rdana' => 'RDANA Assessor',
            'cdrrmo_admin' => 'CDRRMO Administrator',
            'barangay_secretary' => 'Barangay Secretary',
            'barangay_captain' => 'Punong Barangay',
            'rdana_admin_officer' => 'RDANA Administrative Officer III',
            'rdana_city_assessor' => 'City Assessor',
        ] as $code => $name) {
            Role::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
