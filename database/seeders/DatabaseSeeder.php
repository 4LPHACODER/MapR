<?php

namespace Database\Seeders;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    private const ACCOUNT_NAMES = [
        'resident' => ['Mariel', 'Dela Cruz'],
        'bdrrm' => ['Joel', 'Sarmiento'],
        'rdana' => ['Leandro', 'Villanueva'],
        'cdrrmo_admin' => ['Rina', 'Mercado'],
        'barangay_secretary' => ['Liza', 'Abad'],
        'barangay_captain' => ['Ramon', 'Alcantara'],
        'rdana_admin_officer' => ['Teresa', 'Salcedo'],
        'rdana_city_assessor' => ['Vicente', 'Ramirez'],
    ];

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

            foreach (Role::query()->whereIn('code', array_keys(self::ACCOUNT_NAMES))->get() as $role) {
                [$firstName, $lastName] = self::ACCOUNT_NAMES[$role->code];
                User::withTrashed()->firstOrCreate(
                    ['email' => str_replace('_', '.', $role->code).'@mapr.test'],
                    [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
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
