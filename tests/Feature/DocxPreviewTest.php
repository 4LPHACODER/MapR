<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\DocxPreviewConverter;
use App\Services\ImportedDocumentLinkRepair;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class DocxPreviewTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->seed(RoleSeeder::class);
        Schema::create('imported_report_documents', function (Blueprint $table) {
            $table->id();
            $table->string('import_key')->unique();
            $table->string('filename');
            $table->string('storage_path');
            $table->char('sha256', 64);
            $table->string('workflow_status', 32);
            $table->string('reviewing_office', 64);
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->string('source_building_reference')->nullable();
            $table->unsignedBigInteger('resolved_building_id')->nullable();
            $table->json('unresolved_identifiers')->nullable();
            $table->timestamps();
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference')->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete('imported-reports/TEST-PREVIEW.docx');
        parent::tearDown();
    }

    public function test_original_preview_is_authorized_and_does_not_change_the_source(): void
    {
        if (! is_executable('/usr/bin/soffice')) {
            $this->markTestSkipped('LibreOffice is not installed.');
        }

        $source = base_path('assets/01_MapR_Resident_Report_Pending_BDRRM_Review.docx');
        $before = hash_file('sha256', $source);
        Storage::disk('local')->put('imported-reports/TEST-PREVIEW.docx', file_get_contents($source));
        DB::table('imported_report_documents')->insert([
            'import_key' => 'TEST-PREVIEW',
            'filename' => '01_MapR_Resident_Report_Pending_BDRRM_Review.docx',
            'storage_path' => 'imported-reports/TEST-PREVIEW.docx',
            'sha256' => $before,
            'workflow_status' => 'submitted',
            'reviewing_office' => 'BDRRM',
            'source_building_reference' => 'CBMS22-BLDG-632',
            'unresolved_identifiers' => json_encode(['building:CBMS22-BLDG-632']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resident = $this->user('resident');
        $this->actingAs($resident)->get('/cdrrmo/api/documents/TEST-PREVIEW/preview')->assertForbidden();

        $admin = $this->user('cdrrmo_admin');
        $response = $this->actingAs($admin)->get('/cdrrmo/api/documents/TEST-PREVIEW/preview');
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
        $this->assertSame($before, hash_file('sha256', Storage::disk('local')->path('imported-reports/TEST-PREVIEW.docx')));
        $this->assertSame($before, hash_file('sha256', $source));
    }

    public function test_failed_conversion_does_not_return_the_generated_report(): void
    {
        Storage::disk('local')->put('imported-reports/TEST-PREVIEW.docx', 'not a real document');
        DB::table('imported_report_documents')->insert([
            'import_key' => 'TEST-PREVIEW',
            'filename' => 'missing.docx',
            'storage_path' => 'imported-reports/TEST-PREVIEW.docx',
            'sha256' => hash('sha256', 'not a real document'),
            'workflow_status' => 'draft',
            'reviewing_office' => 'BDRRM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->app->instance(DocxPreviewConverter::class, new DocxPreviewConverter('/no/such/soffice'));

        $response = $this->actingAs($this->user('cdrrmo_admin'))
            ->getJson('/cdrrmo/api/documents/TEST-PREVIEW/preview');

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'failed');
        $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
    }

    public function test_imported_documents_convert_to_multipage_a4_pdfs(): void
    {
        if (! is_executable('/usr/bin/soffice')) {
            $this->markTestSkipped('LibreOffice is not installed.');
        }

        $converter = new DocxPreviewConverter();
        $files = glob(base_path('assets/0*_MapR_*.docx')) ?: [];
        $this->assertCount(4, $files);
        foreach ($files as $path) {
            $before = hash_file('sha256', $path);
            $result = $converter->convert($path);
            $this->assertTrue($result['ok'], $result['error'] ?? basename($path));
            $this->assertSame($before, hash_file('sha256', $path));
            $pdf = file_get_contents($result['path']);
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertSame(1, preg_match('/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+(59[0-9.]+)\s+(84[0-9.]+)\s*\]/', $pdf));
            $this->assertGreaterThan(0, preg_match_all('/\/Subtype\s*\/Image/', $pdf));
        }
    }

    public function test_exact_reference_repair_links_one_building_and_is_idempotent(): void
    {
        DB::table('buildings')->insert([
            'building_reference' => 'CBMS22-BLDG-632',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('imported_report_documents')->insert([
            'import_key' => 'TEST-LINK',
            'filename' => 'link.docx',
            'storage_path' => 'imported-reports/missing.docx',
            'sha256' => str_repeat('a', 64),
            'workflow_status' => 'submitted',
            'reviewing_office' => 'BDRRM',
            'source_building_reference' => 'CBMS22-BLDG-632',
            'unresolved_identifiers' => json_encode(['building:CBMS22-BLDG-632']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $repair = new ImportedDocumentLinkRepair();
        $first = $repair->repair();
        $second = $repair->repair();

        $this->assertSame(1, $first['linked']);
        $this->assertSame(0, $second['linked']);
        $this->assertSame(1, (int) DB::table('imported_report_documents')->where('import_key', 'TEST-LINK')->value('resolved_building_id'));
        $this->assertSame([], json_decode(DB::table('imported_report_documents')->where('import_key', 'TEST-LINK')->value('unresolved_identifiers'), true));
    }

    private function user(string $code): User
    {
        return User::query()->create([
            'first_name' => 'Test',
            'last_name' => $code,
            'email' => $code.'.'.uniqid().'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => DB::table('roles')->where('code', $code)->value('id'),
        ]);
    }
}
