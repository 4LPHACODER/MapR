<?php

namespace Tests\Integration;

use App\Models\Auth\User;
use App\Services\ResidentReportWorkflow;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringStartsWith('mapr_demo_seed_test', DB::connection()->getDatabaseName());

        return [null];
    }

    public function test_populates_the_schema_with_connected_reusable_fixtures(): void
    {
        Storage::fake('local');
        $this->seed(DemoDataSeeder::class);

        $counts = [];
        foreach (Schema::getTables(DB::getDatabaseName()) as $table) {
            $counts[$table['name']] = DB::table($table['name'])->count();
        }
        $this->assertNotEmpty($counts);
        foreach ($counts as $table => $count) {
            $this->assertGreaterThan(0, $count, "{$table} is empty");
        }

        foreach ([
            'resident_incident_reports' => ['draft', 'submitted', 'under_review', 'reviewed', 'returned'],
            'barangay_reports' => ['draft', 'review', 'signed', 'submitted', 'returned'],
            'field_assessments' => ['draft', 'review', 'signed', 'submitted', 'returned'],
            'consolidated_reports' => ['draft', 'review', 'signed', 'finalized'],
        ] as $table => $states) {
            $column = $table === 'field_assessments' ? 'assessment_status' : 'report_status';
            $this->assertEqualsCanonicalizing($states, DB::table($table)->distinct()->pluck($column)->all());
        }

        $residentId = DB::table('users')->where('email', 'resident@mapr.test')->value('id');
        $this->assertDatabaseHas('users', ['id' => $residentId, 'first_name' => 'Mariel', 'last_name' => 'Dela Cruz']);
        $this->assertDatabaseHas('users', ['email' => 'barangay.captain@mapr.test', 'first_name' => 'Ramon', 'last_name' => 'Alcantara']);
        $linked = DB::table('account_resident_links')->where('user_id', $residentId)->value('resident_id');
        $this->assertDatabaseHas('residents', ['id' => $linked, 'first_name' => 'Mariel', 'last_name' => 'Dela Cruz']);
        $residentReports = DB::table('resident_incident_reports')->where('resident_user_id', $residentId)->get();
        $this->assertDatabaseHas('disaster_incidents', ['incident_name' => 'Tandag Riverside Flooding', 'incident_status' => 'active']);
        foreach ($residentReports as $report) {
            $this->assertMatchesRegularExpression('/^RIR-\d{4}-\d{6}$/', $report->report_reference);
            $this->assertStringContainsString('Tandag City', $report->reported_location);
        }
        $this->assertFalse(DB::table('report_signatures as signature')
            ->join('resident_incident_reports as report', 'report.id', '=', 'signature.resident_incident_report_id')
            ->whereColumn('signature.document_reference', '!=', 'report.report_reference')->exists());
        $workflow = app(ResidentReportWorkflow::class);
        $this->assertEqualsCanonicalizing([
            'Draft', 'Needs Correction', 'Pending BDRRM Review', 'Pending Assessor Review', 'Pending CDRRMO Review', 'Finalized',
        ], $residentReports->map(fn ($row) => $workflow->present($row)['label'])->unique()->values()->all());
        foreach ([
            ['users', 'account_status', ['active', 'inactive']],
            ['disaster_incidents', 'incident_status', ['active', 'closed']],
            ['building_damage_predictions', 'prediction_status', ['queued', 'succeeded', 'failed']],
            ['building_damage_cost_assessments', 'assessment_status', ['draft', 'confirmed', 'superseded']],
            ['building_damage_cost_assessments', 'cost_status', ['pending_inputs', 'calculated', 'zero_confirmed']],
            ['building_location_corrections', 'status', ['pending', 'applied', 'rejected']],
            ['report_flags', 'status', ['open', 'resolved']],
            ['report_signatures', 'verification_status', ['pending', 'verified', 'rejected']],
        ] as [$table, $column, $states]) {
            $this->assertEqualsCanonicalizing($states, DB::table($table)->distinct()->pluck($column)->all());
        }

        $this->assertEqualsCanonicalizing(
            array_column(config('mapr_reference.barangays'), 'barangay_code'),
            DB::table('barangay_reports')->join('barangays', 'barangays.id', '=', 'barangay_reports.barangay_id')
                ->distinct()->pluck('barangays.barangay_code')->all(),
        );

        $disk = Storage::disk('local');
        foreach (DB::table('incident_photos')->get() as $photo) {
            $this->assertMatchesRegularExpression('/^image\//', $photo->mime_type);
            $this->assertSame($photo->file_sha256, hash('sha256', $disk->get($photo->file_path)));
        }
        foreach (DB::table('imported_report_documents')->get() as $document) {
            $this->assertStringStartsWith('%PDF-', $disk->get($document->storage_path));
            $this->assertSame($document->sha256, hash('sha256', $disk->get($document->storage_path)));
        }
        foreach (DB::table('user_signature_assets')->get() as $signature) {
            $this->assertSame($signature->sha256, hash('sha256', $disk->get($signature->storage_path)));
        }

        $files = $disk->allFiles();
        DB::table('users')->where('id', $residentId)->update(['first_name' => 'Edited']);
        $this->seed(DemoDataSeeder::class);
        $this->assertDatabaseHas('users', ['id' => $residentId, 'first_name' => 'Edited']);

        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame($files, $disk->allFiles());
    }

    public function test_training_data_is_visible_in_role_workspaces_and_files_are_served(): void
    {
        Storage::fake('local');
        config(['session.driver' => 'array', 'cache.default' => 'array']);
        $this->seed(DemoDataSeeder::class);

        $resident = User::query()->where('email', 'resident@mapr.test')->firstOrFail();
        $report = DB::table('resident_incident_reports')->where('resident_user_id', $resident->id)->first();
        $photo = DB::table('incident_photos')->where('incident_report_id', $report->id)->first();
        $this->actingAs($resident)->getJson('/resident/api/reports')->assertOk()
            ->assertJsonFragment(['label' => 'Finalized']);
        $this->get('/resident/api/reports/'.$report->id.'/photos/'.$photo->id)
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $bdrrm = User::query()->where('email', 'bdrrm@mapr.test')->firstOrFail();
        $this->actingAs($bdrrm)->getJson('/bdrrm/api/submissions')->assertOk()
            ->assertJsonFragment(['resident_name' => $resident->display_name]);

        $assessor = User::query()->where('email', 'rdana@mapr.test')->firstOrFail();
        $field = DB::table('field_assessments')->where('assigned_assessor_id', $assessor->id)->first();
        $this->actingAs($assessor)->getJson('/rdana/api/assessments/'.$field->id)->assertOk()
            ->assertJsonPath('data.reference', $field->assessment_reference);

        $admin = User::query()->where('email', 'cdrrmo.admin@mapr.test')->firstOrFail();
        $this->actingAs($admin)->getJson('/cdrrmo/api/reports/consolidated?workflow_status=finalized')
            ->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/cdrrmo/api/map/boundaries')->assertOk()->assertJsonCount(4, 'features');
        $this->getJson('/cdrrmo/api/map/flood')->assertOk()->assertJsonCount(16, 'features');

        $document = DB::table('imported_report_documents')->first();
        $this->get('/cdrrmo/api/documents/'.$document->import_key.'/preview')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_production_cannot_create_training_records(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('local or testing');
        app(DemoDataSeeder::class)->run();
    }
}
