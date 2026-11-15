<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MapRWorkflowPersonnelSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('MapRWorkflowPersonnelSeeder cannot run in production.');
        }
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('MapRWorkflowPersonnelSeeder runs only in local or testing environments.');
        }

        $password = config('mapr.demo_password');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set MAPR_DEMO_PASSWORD before seeding workflow personnel.');
        }

        DB::transaction(function () use ($password) {
            $this->seedPersonnel($password);
        });
    }

    private function seedPersonnel(string $password): void
    {
        $this->call(RoleSeeder::class);
        $roles = DB::table('roles')->pluck('id', 'code');
        $barangayId = DB::table('barangays')->where('barangay_code', '166819008')->value('id');
        if ($barangayId === null) {
            $this->command?->warn('Barangay Mabua (166819008) was not found. Personnel were not seeded.');

            return;
        }

        $actorId = (int) (User::query()->where('account_status', 'active')->orderBy('id')->value('id') ?? 0);

        foreach ([
            ['email' => 'jesnard.plaza@mapr.test', 'first_name' => 'Jesnard', 'middle_name' => 'D.', 'last_name' => 'Plaza', 'role' => 'bdrrm'],
            ['email' => 'peter.salomon@mapr.test', 'first_name' => 'Peter', 'last_name' => 'Salomon', 'role' => 'bdrrm'],
            ['email' => 'victor.valdez@mapr.test', 'first_name' => 'Victor', 'last_name' => 'Valdez', 'role' => 'bdrrm'],
            ['email' => 'leonida.lagunda@mapr.test', 'first_name' => 'Leonida', 'last_name' => 'Lagunda', 'role' => 'barangay_secretary'],
            ['email' => 'olver.ravelo@mapr.test', 'first_name' => 'Olver', 'last_name' => 'Ravelo', 'role' => 'rdana'],
            ['email' => 'james.tan@mapr.test', 'first_name' => 'James', 'last_name' => 'Tan', 'role' => 'rdana'],
            ['email' => 'orland.falcon@mapr.test', 'first_name' => 'Orland', 'last_name' => 'Falcon', 'role' => 'rdana'],
            ['email' => 'lovy.dedicatoria@mapr.test', 'first_name' => 'Lovy', 'middle_name' => 'R.', 'last_name' => 'Dedicatoria', 'role' => 'rdana_city_assessor', 'job_title' => 'Engr., Acting City Assessor'],
        ] as $account) {
            $user = $this->ensureUser($account, (int) $roles[$account['role']], (int) $barangayId, $password);
            if ($user === null) {
                continue;
            }
            $this->assignBarangay($user, (int) $barangayId, $actorId ?: $user->id);
        }

        $captain = User::query()->where('email', 'rocky.pimentel@mapr.test')->where('account_status', 'active')->first();
        if ($captain) {
            if ((int) $captain->role_id !== (int) $roles['barangay_captain']) {
                $captain->forceFill(['role_id' => $roles['barangay_captain']])->save();
            }
            $this->assignBarangay($captain, (int) $barangayId, $actorId ?: $captain->id);
        }

        $mark = $this->ensureUser([
            'email' => 'mark.merillo@mapr.test',
            'first_name' => 'Mark',
            'middle_name' => 'L.',
            'last_name' => 'Merillo',
            'job_title' => 'Administrative Officer III',
        ], (int) $roles['rdana_admin_officer'], (int) $barangayId, $password);
        if ($mark !== null) {
            $this->assignBarangay($mark, (int) $barangayId, $actorId ?: $mark->id);
        }

        foreach ([
            ['email' => 'lester.talan@mapr.test', 'first_name' => 'Lester Lito', 'middle_name' => 'C.', 'last_name' => 'Talan',
                'aliases' => ['lestertalan@mapr_admin.com', 'lestertalan@mapr.com', 'lester.talan@mapr.test']],
            ['email' => 'reyveen.geli@mapr.test', 'first_name' => 'Reyveen John', 'middle_name' => 'B.', 'last_name' => 'Geli',
                'aliases' => ['reyveen.geli@mapr.test']],
        ] as $account) {
            foreach ($account['aliases'] as $email) {
                if (User::withTrashed()->where('email', $email)->exists()) {
                    $account['email'] = $email;
                    break;
                }
            }
            $this->ensureUser($account, (int) $roles['cdrrmo_admin'], null, $password);
        }
    }

    private function ensureUser(array $account, int $roleId, ?int $barangayId, string $password): ?User
    {
        $existing = User::withTrashed()->where('email', $account['email'])->first();
        if ($existing) {
            if ($existing->trashed() || $existing->account_status !== 'active') {
                return null;
            }
            if ((int) $existing->role_id !== $roleId) {
                $existing->forceFill(['role_id' => $roleId])->save();
            }

            return $existing;
        }

        return User::query()->create([
            'first_name' => $account['first_name'],
            'middle_name' => $account['middle_name'] ?? null,
            'last_name' => $account['last_name'],
            'email' => $account['email'],
            'password' => $password,
            'account_status' => 'active',
            'email_verified_at' => now(),
            'job_title' => $account['job_title'] ?? null,
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ]);
    }

    private function assignBarangay(User $user, int $barangayId, int $assignedBy): void
    {
        if (! Schema::hasTable('user_barangay_assignments')) {
            return;
        }
        $active = DB::table('user_barangay_assignments')
            ->where('user_id', $user->id)
            ->where('barangay_id', $barangayId)
            ->whereNull('revoked_at')
            ->exists();
        if ($active) {
            return;
        }
        DB::table('user_barangay_assignments')->insert([
            'user_id' => $user->id,
            'barangay_id' => $barangayId,
            'assigned_by' => $assignedBy > 0 ? $assignedBy : $user->id,
            'assigned_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
