<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class BootstrapLocalDataCommandTest extends TestCase
{
    use CreatesMaprAuthSchema;

    public function test_bootstrap_refuses_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $exitCode = Artisan::call('mapr:bootstrap-local-data');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('production', Artisan::output());
    }

    public function test_bootstrap_skip_import_prints_summary_in_testing(): void
    {
        $this->createMaprAuthSchema();

        \Illuminate\Support\Facades\DB::table('barangays')->insert([
            'barangay_code' => 'TEST',
            'barangay_name' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = \Illuminate\Support\Facades\DB::table('roles')->insertGetId([
            'name' => 'CDRRMO',
            'code' => 'cdrrmo_admin',
            'description' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('users')->insert([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'bootstrap.test@mapr.test',
            'password' => 'secret',
            'account_status' => 'active',
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('mapr:bootstrap-local-data', [
            '--skip-import' => true,
            '--skip-reports' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Local bootstrap summary', Artisan::output());
    }
}
