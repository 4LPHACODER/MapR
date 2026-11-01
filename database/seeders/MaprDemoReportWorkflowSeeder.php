<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MaprDemoReportWorkflowSeeder extends Seeder
{
    private const INCIDENT_REFERENCE = 'DEMO-INC-LOCAL-001';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('MaprDemoReportWorkflowSeeder is disabled in production.');

            return;
        }

        if (! Schema::hasTable('field_assessments') || ! Schema::hasTable('barangay_reports')) {
            return;
        }

        if (DB::table('field_assessments')->exists()) {
            $this->command?->info('Skipped demo report workflow (field assessments already exist).');

            return;
        }

        $preparedBy = (int) (DB::table('users')->where('email', 'lestertalan@mapr_admin.com')->value('id')
            ?: DB::table('users')->orderBy('id')->value('id'));

        $assessorId = (int) (DB::table('users')->where('email', 'rdana.demo@mapr.test')->value('id')
            ?: $preparedBy);

        if ($preparedBy === 0) {
            throw new RuntimeException('Demo users must exist before seeding report workflow. Run db:seed.');
        }

        $typeId = (int) DB::table('disaster_types')->orderBy('id')->value('id');
        if ($typeId === 0) {
            $this->call(DisasterTypeSeeder::class);
            $typeId = (int) DB::table('disaster_types')->orderBy('id')->value('id');
        }

        if ($typeId === 0) {
            throw new RuntimeException('No disaster type available for demo incident.');
        }

        $incidentId = (int) DB::table('disaster_incidents')
            ->where('incident_reference', self::INCIDENT_REFERENCE)
            ->value('id');

        if ($incidentId === 0) {
            $incidentId = (int) DB::table('disaster_incidents')->insertGetId([
                'disaster_type_id' => $typeId,
                'incident_reference' => self::INCIDENT_REFERENCE,
                'incident_name' => 'Demo flood event (local)',
                'description' => 'Seeded for local CDRRMO report list QA.',
                'occurred_at' => now()->subDays(7),
                'incident_status' => 'active',
                'created_by' => $preparedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $barangays = DB::table('barangays')->orderBy('id')->get();

        foreach ($barangays as $barangay) {
            $exists = DB::table('disaster_barangays')
                ->where('disaster_incident_id', $incidentId)
                ->where('barangay_id', $barangay->id)
                ->exists();

            if (! $exists) {
                DB::table('disaster_barangays')->insert([
                    'disaster_incident_id' => $incidentId,
                    'barangay_id' => $barangay->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $reportDefinitions = [
            ['reference' => 'BR-DEMO-LOCAL-001', 'barangay_index' => 0, 'status' => 'draft'],
            ['reference' => 'BR-DEMO-LOCAL-002', 'barangay_index' => 1, 'status' => 'review'],
        ];

        foreach ($reportDefinitions as $index => $definition) {
            $barangay = $barangays->get($definition['barangay_index']);
            if ($barangay === null) {
                continue;
            }

            $reportId = (int) DB::table('barangay_reports')
                ->where('report_reference', $definition['reference'])
                ->value('id');

            if ($reportId === 0) {
                $reportId = (int) DB::table('barangay_reports')->insertGetId([
                    'report_reference' => $definition['reference'],
                    'report_description' => 'Demo barangay report for local QA.',
                    'report_status' => $definition['status'],
                    'disaster_incident_id' => $incidentId,
                    'barangay_id' => $barangay->id,
                    'prepared_by' => $preparedBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $assessmentRef = 'FA-DEMO-LOCAL-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            if (! DB::table('field_assessments')->where('assessment_reference', $assessmentRef)->exists()) {
                DB::table('field_assessments')->insert([
                    'assessment_reference' => $assessmentRef,
                    'findings' => 'Demo field assessment (draft). Replace with real RDANA findings.',
                    'assessment_status' => 'draft',
                    'barangay_report_id' => $reportId,
                    'assigned_assessor_id' => $assessorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command?->info('Demo barangay reports and field assessments seeded for local QA.');
    }
}
