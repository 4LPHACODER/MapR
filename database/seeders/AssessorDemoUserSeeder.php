<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Services\AssessorStudyBarangayAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssessorDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('AssessorDemoUserSeeder runs only in local or testing environments.');
        }

        $password = config('mapr.assessor_demo_password');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set MAPR_ASSESSOR_DEMO_PASSWORD before seeding the assessor account.');
        }

        $this->call(RoleSeeder::class);
        $roleId = DB::table('roles')->where('code', 'rdana')->value('id');
        if ($roleId === null) {
            throw new RuntimeException('Role [rdana] was not found.');
        }

        $existing = User::withTrashed()->where('email', 'mark.merillo@mapr.test')->first();
        if ($existing) {
            $this->command?->info('Skipped existing mark.merillo@mapr.test');
        } else {
            User::query()->create([
                'first_name' => 'Mark',
                'middle_name' => 'L.',
                'last_name' => 'Merillo',
                'email' => 'mark.merillo@mapr.test',
                'password' => $password,
                'job_title' => 'Administrative Officer III',
                'account_status' => 'active',
                'email_verified_at' => now(),
                'role_id' => $roleId,
                'barangay_id' => null,
            ]);
        }

        app(AssessorStudyBarangayAssignment::class)->assignActiveAssessors();
    }
}
