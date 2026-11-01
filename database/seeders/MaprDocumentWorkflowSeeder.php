<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MaprDocumentWorkflowSeeder extends Seeder
{
    private const SIMULATED = 'This signing event was simulated for a local demonstration. It does not record that an official signed this document.';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('MaprDocumentWorkflowSeeder runs only in local or testing environments.');
        }

        $password = config('mapr.demo_password');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set MAPR_DEMO_PASSWORD before seeding document workflow accounts.');
        }

        $this->placeSourceFiles();
        $this->call(RoleSeeder::class);

        $mark = User::withTrashed()->where('email', 'mark.merillo@mapr.test')->first();
        $lester = User::withTrashed()->where('email', 'lestertalan@mapr_admin.com')->first()
            ?? User::withTrashed()->where('email', 'lestertalan@mapr.com')->first();
        if ($mark === null) {
            throw new RuntimeException('Seed mark.merillo@mapr.test with AssessorDemoUserSeeder before the document workflow.');
        }
        if ($lester === null) {
            throw new RuntimeException('lestertalan@mapr_admin.com must already exist. This seeder does not create or reset that account.');
        }

        $accounts = $this->accounts($password, $lester);
        $this->importDocuments($accounts, $mark, $lester);
    }

    private function accounts(string $password, User $lester): array
    {
        $roles = DB::table('roles')->pluck('id', 'code');
        $captains = [
            'luis' => ['email' => 'luis.maquiling@mapr.test', 'first_name' => 'Hon. Luis', 'middle_name' => 'F.', 'last_name' => 'Maquiling', 'code' => '166819002'],
            'melanie' => ['email' => 'melanie.guno@mapr.test', 'first_name' => 'Hon. Melanie', 'middle_name' => 'Joy Momo', 'last_name' => 'Guno', 'code' => '166819022'],
            'rocky' => ['email' => 'rocky.pimentel@mapr.test', 'first_name' => 'Hon. Rocky', 'middle_name' => 'Roger M.', 'last_name' => 'Pimentel', 'code' => '166819008'],
            'charisse' => ['email' => 'charisse.pineda@mapr.test', 'first_name' => 'Hon. Charisse', 'middle_name' => 'Valentine P.', 'last_name' => 'Pineda', 'code' => '166819005'],
        ];

        $accounts = [];
        foreach ($captains as $key => $captain) {
            $user = $this->ensureUser($captain['email'], [
                'first_name' => $captain['first_name'],
                'middle_name' => $captain['middle_name'],
                'last_name' => $captain['last_name'],
                'job_title' => 'Barangay Captain',
                'role_id' => $roles['bdrrm'] ?? null,
            ], $password);
            $barangayId = $this->barangayId($captain['code']);
            if ($barangayId) {
                $this->assign($user, $barangayId, $lester->id);
            }
            $accounts[$key] = $user;
        }

        $accounts['lovy'] = $this->ensureUser('lovy.dedicatoria@mapr.test', [
            'first_name' => 'Engr. Lovy',
            'middle_name' => 'R.',
            'last_name' => 'Dedicatoria',
            'job_title' => 'LAOO II',
            'role_id' => $roles['rdana'] ?? null,
        ], $password);
        $accounts['reyveen'] = $this->ensureUser('reyveen.geli@mapr.test', [
            'first_name' => 'Reyveen',
            'middle_name' => 'John B.',
            'last_name' => 'Geli',
            'job_title' => 'Head, City Disaster Risk Reduction and Management Office (CDRRMO)',
            'role_id' => $roles['cdrrmo_admin'] ?? null,
        ], $password);

        $reporters = [
            'resident01' => ['JONALITO', 'ESTRADA', 'ABAA'],
            'resident02' => ['EDUARDO', 'CUBERO', 'ABARICO'],
            'resident03' => ['ROEL', 'ESCALERA', 'ABAD'],
            'resident04' => ['EDWARD', '-', 'ABARICO'],
        ];
        foreach ($reporters as $key => [$first, $middle, $last]) {
            $accounts[$key] = $this->ensureUser($key.'@mapr.test', [
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'job_title' => null,
                'role_id' => $roles['resident'] ?? null,
            ], $password);
        }

        return $accounts;
    }

    private function importDocuments(array $accounts, User $mark, User $lester): void
    {
        foreach (['imported_report_documents', 'disaster_incidents', 'disaster_barangays', 'resident_incident_reports', 'barangay_reports', 'field_assessments', 'consolidated_reports', 'report_signatures'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Table ['.$table.'] is required before importing the demo documents.');
            }
        }

        if (! Schema::hasColumn('report_signatures', 'signature_statement')) {
            throw new RuntimeException('Run the signature asset migration before seeding documents.');
        }

        $incidentId = $this->incidentId($lester);
        $documents = $this->documents();
        foreach ($documents as $document) {
            $barangayId = $this->barangayId($document['barangay_code']);
            if ($barangayId && $incidentId) {
                $this->linkIncident($incidentId, $barangayId);
            }
            $resolved = $this->resolveReferences($document, $barangayId);
            $this->storeCopy($document);
            if (DB::table('imported_report_documents')->where('import_key', $document['reference'])->exists()) {
                continue;
            }

            $ids = $this->workflowRows($document, $accounts, $mark, $lester, $incidentId, $barangayId, $resolved);
            DB::table('imported_report_documents')->insert([
                'import_key' => $document['reference'],
                'filename' => $document['filename'],
                'storage_path' => $this->storagePath($document['reference']),
                'sha256' => $document['sha256'],
                'workflow_status' => $document['status'],
                'reviewing_office' => $document['office'],
                'barangay_id' => $barangayId,
                'resident_user_id' => $accounts[$document['resident']]->id,
                'resident_incident_report_id' => $ids['resident_report'] ?? null,
                'barangay_report_id' => $ids['barangay_report'] ?? null,
                'field_assessment_id' => $ids['field_assessment'] ?? null,
                'consolidated_report_id' => $ids['consolidated'] ?? null,
                'source_resident_reference' => $document['resident_reference'],
                'source_building_reference' => $document['building_reference'],
                'source_household_reference' => $document['household_reference'],
                'resolved_resident_id' => $resolved['resident'],
                'resolved_building_id' => $resolved['building'],
                'resolved_household_id' => $resolved['household'],
                'unresolved_identifiers' => json_encode($resolved['unresolved']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function workflowRows(array $document, array $accounts, User $mark, User $lester, ?int $incidentId, ?int $barangayId, array $resolved): array
    {
        if ($incidentId === null || $barangayId === null) {
            return [];
        }

        $now = now();
        if ($document['kind'] === 'resident') {
            return ['resident_report' => DB::table('resident_incident_reports')->insertGetId([
                'report_reference' => $document['reference'],
                'incident_description' => 'For verification',
                'reported_location' => 'for verification',
                'report_status' => 'submitted',
                'submitted_at' => $now,
                'resident_user_id' => $accounts[$document['resident']]->id,
                'disaster_incident_id' => $incidentId,
                'barangay_id' => $barangayId,
                'building_id' => $resolved['building'],
                'created_at' => $now,
                'updated_at' => $now,
            ])];
        }

        if ($document['kind'] === 'barangay') {
            $reportId = DB::table('barangay_reports')->insertGetId([
                'report_reference' => $document['reference'],
                'report_description' => 'For verification',
                'report_status' => 'review',
                'disaster_incident_id' => $incidentId,
                'barangay_id' => $barangayId,
                'prepared_by' => $accounts[$document['captain']]->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->linkReportBuilding($reportId, $resolved['building']);
            $assessmentId = DB::table('field_assessments')->insertGetId([
                'assessment_reference' => $document['reference'].'-FA',
                'findings' => null,
                'assessment_status' => 'draft',
                'barangay_report_id' => $reportId,
                'assigned_assessor_id' => $mark->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['barangay_report' => $reportId, 'field_assessment' => $assessmentId];
        }

        if ($document['kind'] === 'field') {
            $reference = $document['reference'].'-BR';
            $reportId = DB::table('barangay_reports')->where('report_reference', $reference)->value('id');
            if ($reportId === null) {
                $reportId = DB::table('barangay_reports')->insertGetId([
                    'report_reference' => $reference,
                    'report_description' => 'For verification',
                    'report_status' => 'draft',
                    'disaster_incident_id' => $incidentId,
                    'barangay_id' => $barangayId,
                    'prepared_by' => $accounts[$document['captain']]->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->linkReportBuilding((int) $reportId, $resolved['building']);
                DB::table('barangay_reports')->where('id', $reportId)->update([
                    'report_status' => 'submitted',
                    'submitted_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $assessmentId = DB::table('field_assessments')->where('assessment_reference', $document['reference'])->value('id');
            if ($assessmentId === null) {
                $assessmentId = DB::table('field_assessments')->insertGetId([
                    'assessment_reference' => $document['reference'],
                    'findings' => 'For verification',
                    'assessment_status' => 'submitted',
                    'submitted_at' => $now,
                    'barangay_report_id' => $reportId,
                    'assigned_assessor_id' => $mark->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return ['barangay_report' => (int) $reportId, 'field_assessment' => (int) $assessmentId];
        }

        $consolidatedId = DB::table('consolidated_reports')->insertGetId([
            'report_reference' => $document['reference'],
            'report_summary' => 'Demo provenance. The source form still says every review stage is for verification. This row is not a genuine approval.',
            'report_status' => 'finalized',
            'finalized_at' => $now,
            'disaster_incident_id' => $incidentId,
            'prepared_by' => $lester->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('report_signatures')->insert([
            'signature_reference' => (string) Str::uuid(),
            'document_reference' => $document['reference'],
            'document_hash' => $document['sha256'],
            'mechanism' => 'simulated_demo',
            'signature_role' => 'approved_by',
            'signed_at' => $now,
            'verification_status' => 'pending',
            'signature_statement' => self::SIMULATED,
            'consolidated_report_id' => $consolidatedId,
            'signer_user_id' => $lester->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['consolidated' => $consolidatedId];
    }

    private function documents(): array
    {
        return [
            [
                'kind' => 'resident',
                'filename' => '01_MapR_Resident_Report_Pending_BDRRM_Review.docx',
                'sha256' => 'f618ba7b22361157ac455d543a6677fc58651cf06d7f1b1644b09af5eea4f6bd',
                'legacy' => '01_Pending_BDRRM.docx',
                'reference' => 'MAPR-20261029-BOL-001',
                'barangay_code' => '166819002',
                'resident' => 'resident01',
                'captain' => 'luis',
                'status' => 'submitted',
                'office' => 'BDRRM',
                'resident_reference' => 'CBMS22-RES-632-01',
                'building_reference' => 'CBMS22-BLDG-632',
                'household_reference' => 'CBMS22-HH-632',
            ],
            [
                'kind' => 'barangay',
                'filename' => '02_MapR_Barangay_Report_Pending_Assessor_Review.docx',
                'sha256' => 'ca2f6a2094ce27ab6c3d93d2222c85b7c1c930458be623bc296a74cbc860e5aa',
                'legacy' => '02_Pending_Assessor.docx',
                'reference' => 'MAPR-20261029-TEL-002',
                'barangay_code' => '166819022',
                'resident' => 'resident02',
                'captain' => 'melanie',
                'status' => 'review',
                'office' => 'Assessor',
                'resident_reference' => 'CBMS22-RES-13612-01',
                'building_reference' => 'CBMS22-BLDG-13612',
                'household_reference' => 'CBMS22-HH-13612',
            ],
            [
                'kind' => 'field',
                'filename' => '03_MapR_Field_Assessment_Report_Pending_CDRRMO_Review.docx',
                'sha256' => '9e16750c017c3201dd727ff2c70ae2239eda34ea80469b9129195465232e1db0',
                'legacy' => '03_Pending_CDRRMO.docx',
                'reference' => 'MAPR-20261029-MAB-003',
                'barangay_code' => '166819008',
                'resident' => 'resident03',
                'captain' => 'rocky',
                'status' => 'submitted',
                'office' => 'CDRRMO',
                'resident_reference' => 'CBMS22-RES-6268-01',
                'building_reference' => 'CBMS22-BLDG-6268',
                'household_reference' => 'CBMS22-HH-6268',
            ],
            [
                'kind' => 'consolidated',
                'filename' => '04_MapR_Consolidated_Assessment_Report_Finalized.docx',
                'sha256' => '758218d797a82f6eb5cc4ae4255acad33f39c5bd849f5b21753692f96081acdd',
                'legacy' => '04_Finalized_Demo.docx',
                'reference' => 'MAPR-20261029-BON-004',
                'barangay_code' => '166819005',
                'resident' => 'resident04',
                'captain' => 'charisse',
                'status' => 'finalized',
                'office' => 'CDRRMO',
                'resident_reference' => 'CBMS22-RES-2798-01',
                'building_reference' => 'CBMS22-BLDG-2798',
                'household_reference' => 'CBMS22-HH-2798',
            ],
        ];
    }

    private function placeSourceFiles(): void
    {
        foreach ($this->documents() as $document) {
            $directory = base_path('assets');
            $target = $directory.'/'.$document['filename'];
            $legacy = $directory.'/'.$document['legacy'];
            $source = is_file($target) ? $target : $legacy;
            if (! is_file($source)) {
                throw new RuntimeException('Missing source document '.$document['filename']);
            }
            $hash = hash_file('sha256', $source);
            if ($hash !== $document['sha256']) {
                throw new RuntimeException('Checksum mismatch for '.$document['filename']);
            }
            if (! is_file($target)) {
                rename($source, $target);
            }
        }
    }

    private function storeCopy(array $document): void
    {
        $path = $this->storagePath($document['reference']);
        if (Storage::disk('local')->exists($path)) {
            return;
        }
        $bytes = file_get_contents(base_path('assets/'.$document['filename']));
        Storage::disk('local')->put($path, $bytes);
    }

    private function storagePath(string $reference): string
    {
        return 'imported-reports/'.$reference.'.docx';
    }

    private function incidentId(User $lester): ?int
    {
        if (! Schema::hasTable('disaster_types') || ! Schema::hasTable('disaster_incidents')) {
            return null;
        }
        $reference = 'DEMO-MAPR-20261029-FOR-VERIFICATION';
        $existing = DB::table('disaster_incidents')->where('incident_reference', $reference)->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $typeId = DB::table('disaster_types')->where('name', 'Flood')->value('id')
            ?? DB::table('disaster_types')->orderBy('id')->value('id');
        if ($typeId === null) {
            return null;
        }

        return (int) DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => $reference,
            'incident_name' => 'For verification',
            'description' => 'DEMO- provenance. The recorded incident text in the imported documents is For verification.',
            'occurred_at' => Carbon::parse('2026-10-29 08:00:00', 'Asia/Manila')->utc(),
            'incident_status' => 'active',
            'disaster_type_id' => $typeId,
            'created_by' => $lester->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkIncident(int $incidentId, int $barangayId): void
    {
        $exists = DB::table('disaster_barangays')
            ->where('disaster_incident_id', $incidentId)
            ->where('barangay_id', $barangayId)
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('disaster_barangays')->insert([
            'disaster_incident_id' => $incidentId,
            'barangay_id' => $barangayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveReferences(array $document, ?int $barangayId): array
    {
        $unresolved = [];
        if ($barangayId === null) {
            $unresolved[] = 'barangay:'.$document['barangay_code'];
        }
        $resident = $this->residentId($document['resident_reference'], $barangayId);
        $building = $this->referenceId('buildings', 'building_reference', $document['building_reference'], $barangayId);
        $household = $this->referenceId('households', 'household_reference', $document['household_reference'], $barangayId);
        if ($resident === null) {
            $unresolved[] = 'resident:'.$document['resident_reference'];
        }
        if ($building === null) {
            $unresolved[] = 'building:'.$document['building_reference'];
        }
        if ($household === null) {
            $unresolved[] = 'household:'.$document['household_reference'];
        }

        return compact('resident', 'building', 'household', 'unresolved');
    }

    private function residentId(string $reference, ?int $barangayId): ?int
    {
        if (! Schema::hasTable('residents') || ! Schema::hasTable('households')) {
            return null;
        }
        $id = DB::table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->where('residents.registry_reference', $reference)
            ->when($barangayId, fn ($query) => $query->where('households.barangay_id', $barangayId))
            ->value('residents.id');

        return $id === null ? null : (int) $id;
    }

    private function referenceId(string $table, string $column, string $reference, ?int $barangayId): ?int
    {
        if ($barangayId === null || ! Schema::hasTable($table)) {
            return null;
        }
        $id = DB::table($table)->where($column, $reference)->where('barangay_id', $barangayId)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function linkReportBuilding(int $reportId, ?int $buildingId): void
    {
        if ($buildingId === null || ! Schema::hasTable('barangay_report_buildings')) {
            return;
        }
        DB::table('barangay_report_buildings')->insert([
            'barangay_report_id' => $reportId,
            'building_id' => $buildingId,
            'observations' => 'For verification',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function barangayId(string $code): ?int
    {
        if (! Schema::hasTable('barangays')) {
            return null;
        }
        $id = DB::table('barangays')->where('barangay_code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function ensureUser(string $email, array $attributes, string $password): User
    {
        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }
        if ($attributes['role_id'] === null) {
            throw new RuntimeException('Role missing for '.$email);
        }

        return User::query()->create($attributes + [
            'email' => $email,
            'password' => $password,
            'account_status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function assign(User $user, int $barangayId, int $assignedBy): void
    {
        $exists = DB::table('user_barangay_assignments')
            ->where('user_id', $user->id)
            ->where('barangay_id', $barangayId)
            ->whereNull('revoked_at')
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $user->id,
            'barangay_id' => $barangayId,
            'assigned_by' => $assignedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
