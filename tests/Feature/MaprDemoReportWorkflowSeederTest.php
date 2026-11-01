<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Database\Seeders\MaprDemoReportWorkflowSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class MaprDemoReportWorkflowSeederTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createReportWorkflowSchema();
        $this->seedMinimalReferenceRows();
    }

    public function test_seeder_creates_demo_reports_and_is_idempotent(): void
    {
        $this->seed(MaprDemoReportWorkflowSeeder::class);

        $this->assertSame(2, DB::table('barangay_reports')->count());
        $this->assertSame(2, DB::table('field_assessments')->count());
        $this->assertSame(2, DB::table('disaster_barangays')->count());

        $this->seed(MaprDemoReportWorkflowSeeder::class);

        $this->assertSame(2, DB::table('field_assessments')->count());
    }

    private function createReportWorkflowSchema(): void
    {
        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disaster_type_id');
            $table->string('incident_reference')->unique();
            $table->string('incident_name');
            $table->text('description')->nullable();
            $table->dateTime('occurred_at');
            $table->string('incident_status')->default('active');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->timestamps();
            $table->unique(['disaster_incident_id', 'barangay_id']);
        });

        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference')->unique();
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });

        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference')->unique();
            $table->text('findings')->nullable();
            $table->string('assessment_status')->default('draft');
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamps();
        });
    }

    private function seedMinimalReferenceRows(): void
    {
        DB::table('barangays')->insert([
            ['barangay_code' => 'A', 'barangay_name' => 'Barangay A', 'created_at' => now(), 'updated_at' => now()],
            ['barangay_code' => 'B', 'barangay_name' => 'Barangay B', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('disaster_types')->insert([
            'name' => 'Flood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = Role::query()->firstOrCreate(
            ['code' => 'cdrrmo_admin'],
            ['name' => 'CDRRMO', 'description' => 'CDRRMO'],
        );

        $rdanaRole = Role::query()->firstOrCreate(
            ['code' => 'rdana'],
            ['name' => 'RDANA', 'description' => 'RDANA'],
        );

        User::query()->create([
            'first_name' => 'Lester',
            'last_name' => 'Talan',
            'email' => 'lestertalan@mapr_admin.com',
            'password' => 'secret',
            'account_status' => 'active',
            'role_id' => $role->id,
        ]);

        User::query()->create([
            'first_name' => 'Demo',
            'last_name' => 'RDANA',
            'email' => 'rdana.demo@mapr.test',
            'password' => 'secret',
            'account_status' => 'active',
            'role_id' => $rdanaRole->id,
        ]);
    }
}
