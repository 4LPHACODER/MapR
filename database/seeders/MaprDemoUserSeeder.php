<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class MaprDemoUserSeeder extends Seeder
{
    private const LESTER_OLD_EMAIL = 'lester.talan.demo@mapr.test';

    private const LESTER_NEW_EMAIL = 'lestertalan@mapr_admin.com';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('MaprDemoUserSeeder runs only in local or testing environments.');
        }

        $password = config('mapr.demo_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set MAPR_DEMO_PASSWORD before seeding demo accounts.');
        }

        $this->call(RoleSeeder::class);
        $roles = DB::table('roles')->pluck('id', 'code');

        if (! isset($roles['cdrrmo_admin'])) {
            throw new RuntimeException('Role [cdrrmo_admin] was not found.');
        }

        $this->migrateLesterDemoAccount((int) $roles['cdrrmo_admin'], $password);

        $accounts = [
            ['email' => 'admin.demo@mapr.test', 'first_name' => 'Demo', 'last_name' => 'CDRRMO Administrator', 'role' => 'cdrrmo_admin', 'assign' => false],
            ['email' => self::LESTER_NEW_EMAIL, 'first_name' => 'Lester Lito', 'middle_name' => 'C', 'last_name' => 'Talan', 'role' => 'cdrrmo_admin', 'assign' => false],
            ['email' => 'resident.demo@mapr.test', 'first_name' => 'Demo', 'last_name' => 'Resident', 'role' => 'resident', 'assign' => false],
            ['email' => 'bdrrm.demo@mapr.test', 'first_name' => 'Demo', 'last_name' => 'BDRRM Personnel', 'role' => 'bdrrm', 'assign' => true],
            ['email' => 'rdana.demo@mapr.test', 'first_name' => 'Demo', 'last_name' => 'RDANA Assessor', 'role' => 'rdana', 'assign' => true],
        ];

        $barangay = DB::table('barangays')->orderBy('id')->first();
        $created = [];

        foreach ($accounts as $account) {
            $existing = User::withTrashed()->where('email', $account['email'])->first();
            if ($existing) {
                $created[$account['email']] = $existing;
                $this->command?->info('Skipped existing '.$account['email']);
                continue;
            }

            $created[$account['email']] = User::query()->create([
                'first_name' => $account['first_name'],
                'middle_name' => $account['middle_name'] ?? null,
                'last_name' => $account['last_name'],
                'email' => $account['email'],
                'password' => $password,
                'account_status' => 'active',
                'email_verified_at' => now(),
                'role_id' => $roles[$account['role']],
                'barangay_id' => $account['role'] === 'cdrrmo_admin' ? null : $barangay?->id,
            ]);
        }

        if ($barangay === null) {
            return;
        }

        $admin = $created['admin.demo@mapr.test'] ?? User::query()->where('email', 'admin.demo@mapr.test')->first();
        if ($admin === null) {
            return;
        }

        foreach ($accounts as $account) {
            if (! $account['assign']) {
                continue;
            }
            $user = $created[$account['email']] ?? null;
            if ($user === null || ! $user->wasRecentlyCreated) {
                continue;
            }
            DB::table('user_barangay_assignments')->insert([
                'user_id' => $user->id,
                'barangay_id' => $barangay->id,
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function migrateLesterDemoAccount(int $cdrrmoRoleId, string $password): void
    {
        DB::transaction(function () use ($cdrrmoRoleId, $password) {
            $old = User::withTrashed()->where('email', self::LESTER_OLD_EMAIL)->first();
            $new = User::withTrashed()->where('email', self::LESTER_NEW_EMAIL)->first();

            if ($old !== null && $new !== null && (int) $old->id !== (int) $new->id) {
                throw new RuntimeException('Both Lester demo emails exist. Resolve manually before seeding.');
            }

            if ($old !== null && $new === null) {
                if ((int) $old->role_id !== $cdrrmoRoleId) {
                    throw new RuntimeException('The legacy Lester demo account is not a CDRRMO administrator.');
                }

                $old->forceFill([
                    'email' => self::LESTER_NEW_EMAIL,
                    'password' => Hash::make($password),
                    'first_name' => 'Lester Lito',
                    'middle_name' => 'C',
                    'last_name' => 'Talan',
                    'account_status' => 'active',
                ])->save();
            }
        });
    }
}
