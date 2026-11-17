<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResidentReportSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('ResidentReportSeeder runs only in local or testing environments.');
        }

        DB::transaction(function () {
            $reviewerId = $this->staffId('bdrrm');
            $assessorId = $this->staffId('rdana');
            $adminId = $this->staffId('cdrrmo_admin');
            $resident = User::query()->where('email', 'resident.demo@mapr.test')->first();
            if ($resident === null) {
                $this->call(ResidentDemoSeeder::class);
                $resident = User::query()->where('email', ResidentDemoSeeder::EMAIL)->firstOrFail();
            }
            $this->call(DisasterTypeSeeder::class);

            if ($resident->role?->code !== 'resident' || $resident->barangay_id === null) {
                throw new RuntimeException('The demo resident must have the resident role and a barangay.');
            }

            $now = now();
            $incidentId = $this->insertOnce('disaster_incidents', 'incident_reference', 'DEMO-RESIDENT-INC-001', [
                'incident_name' => 'Demo flood for resident report tracking',
                'description' => 'Local sample data covering every resident report status.',
                'disaster_type_id' => DB::table('disaster_types')->where('name', 'Flood')->value('id')
                    ?? DB::table('disaster_types')->orderBy('id')->value('id'),
                'occurred_at' => $now->copy()->subDays(7),
                'incident_status' => 'active',
                'published_for_residents' => true,
                'resident_coverage' => 'barangays',
                'published_at' => $now->copy()->subDays(7),
                'created_by' => $adminId,
            ]);
            DB::table('disaster_barangays')->insertOrIgnore([
                'disaster_incident_id' => $incidentId,
                'barangay_id' => $resident->barangay_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The last three scenarios use linked reports to produce downstream tracking labels.
            foreach (['draft', 'submitted', 'under_review', 'returned', 'reviewed', 'assessor', 'cdrrmo', 'finalized'] as $scenario) {
                $reference = ($resident->email === ResidentDemoSeeder::EMAIL ? 'RR-DEMO-' : 'RR-DEMO-USER-'.$resident->id.'-').strtoupper($scenario);
                if (DB::table('resident_incident_reports')->where('report_reference', $reference)->exists()) {
                    continue;
                }

                $accepted = in_array($scenario, ['reviewed', 'assessor', 'cdrrmo', 'finalized'], true);
                $forwarded = in_array($scenario, ['assessor', 'cdrrmo', 'finalized'], true);
                $assessed = in_array($scenario, ['cdrrmo', 'finalized'], true);
                $barangayReportId = null;
                if ($accepted) {
                    $barangayReportId = $this->insertOnce('barangay_reports', 'report_reference', 'BR-'.$reference, [
                        'report_description' => 'Demo resident handoff: '.$scenario.'.',
                        'report_status' => $forwarded ? 'review' : 'draft',
                        'submitted_at' => $forwarded ? $now->copy()->subDays(3) : null,
                        'workflow_stage' => $forwarded ? 'handed_to_rdana' : 'pending_secretary',
                        'disaster_incident_id' => $incidentId,
                        'barangay_id' => $resident->barangay_id,
                        'prepared_by' => $reviewerId,
                    ]);
                }
                if ($forwarded) {
                    $assessmentId = $this->insertOnce('field_assessments', 'assessment_reference', 'FA-'.$reference, [
                        'findings' => 'Demo assessment of flood damage to the ground floor.',
                        'assessment_status' => $assessed ? 'submitted' : 'draft',
                        'submitted_at' => $assessed ? $now->copy()->subDays(2) : null,
                        'barangay_report_id' => $barangayReportId,
                        'assigned_assessor_id' => $assessorId,
                    ]);
                    if ($scenario === 'finalized') {
                        $consolidatedId = $this->insertOnce('consolidated_reports', 'report_reference', 'CR-'.$reference, [
                            'report_summary' => 'Demo finalized report for resident tracking. Sample data only.',
                            'report_status' => 'draft',
                            'disaster_incident_id' => $incidentId,
                            'prepared_by' => $adminId,
                        ]);
                        DB::table('consolidated_report_assessments')->insertOrIgnore([
                            'consolidated_report_id' => $consolidatedId,
                            'field_assessment_id' => $assessmentId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        DB::table('consolidated_reports')->where('id', $consolidatedId)
                            ->where('report_status', 'draft')->update([
                                'report_status' => 'finalized',
                                'finalized_at' => $now->copy()->subDay(),
                                'updated_at' => $now,
                            ]);
                    }
                }

                $reportId = $this->insertOnce('resident_incident_reports', 'report_reference', $reference, [
                    'incident_description' => 'Demo '.$scenario.' report: floodwater entered the ground floor and damaged household belongings.',
                    'reported_location' => 'Demo location, Barangay '.DB::table('barangays')->where('id', $resident->barangay_id)->value('barangay_name').', Tandag City',
                    'report_status' => $accepted ? 'reviewed' : $scenario,
                    'observed_at' => $now->copy()->subDays(7),
                    'submitted_at' => $scenario === 'draft' ? null : $now->copy()->subDays(6),
                    'reviewed_at' => $accepted ? $now->copy()->subDays(4) : null,
                    'reviewed_by' => $accepted ? $reviewerId : null,
                    'returned_at' => $scenario === 'returned' ? $now->copy()->subDays(5) : null,
                    'return_reason' => $scenario === 'returned' ? 'Please clarify the affected location and attach clearer photos of the damage.' : null,
                    'resident_user_id' => $resident->id,
                    'disaster_incident_id' => $incidentId,
                    'barangay_id' => $resident->barangay_id,
                    'barangay_report_id' => $barangayReportId,
                ]);
                if (DB::getDriverName() === 'mysql') {
                    DB::update("UPDATE resident_incident_reports SET reported_point = ST_GeomFromText(?, 4326, 'axis-order=long-lat') WHERE id = ?", ['POINT(126.20313 9.069381354)', $reportId]);
                } else {
                    DB::table('resident_incident_reports')->where('id', $reportId)->update(['reported_point' => '126.20313 9.069381354']);
                }
            }
            $this->command?->info('Resident report samples cover all five statuses and all six tracking labels for '.$resident->email.'.');
        });
    }

    private function staffId(string $role): int
    {
        $id = User::query()->where('account_status', 'active')
            ->whereHas('role', fn ($query) => $query->where('code', $role))
            ->orderBy('id')->value('id');
        if ($id === null) {
            throw new RuntimeException('An active '.$role.' account is required. Run db:seed first.');
        }

        return (int) $id;
    }

    private function insertOnce(string $table, string $column, string $reference, array $attributes): int
    {
        $existing = DB::table($table)->where($column, $reference)->value('id');

        return $existing !== null ? (int) $existing : (int) DB::table($table)->insertGetId([
            $column => $reference,
            ...$attributes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
