<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Services\BuildingCostEstimator;
use App\Support\RoleHome;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use App\Support\SpatialSql;

class DemoDataSeeder extends Seeder
{
    private const INCIDENT_DESCRIPTION = 'Synthetic local training scenario.';

    private const RESIDENT_NAMES = [
        '166819002' => [['Celia', 'Bautista'], ['Myrna', 'Mateo'], ['Sofia', 'Padilla']],
        '166819005' => [['Janelle', 'Santos'], ['Agnes', 'Domingo'], ['Elisa', 'Reyes']],
        '166819008' => [['Noemi', 'Valdez'], ['Mila', 'Gonzales'], ['Nina', 'Rosales']],
        '166819022' => [['Hazel', 'Navarro'], ['Cora', 'Castillo'], ['Lara', 'Mendoza']],
    ];

    private const INACTIVE_RESIDENT_NAME = ['Grace', 'Manalo'];

    private array $files = [];

    private array $signatureAssets = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoDataSeeder runs only in local or testing environments.');
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('DemoDataSeeder requires the migrated MySQL spatial schema.');
        }

        $lock = 'mapr-demo-'.sha1(DB::connection()->getDatabaseName());
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->acquired !== 1) {
            throw new RuntimeException('Another demo seed is running.');
        }

        $this->files = [];
        $this->signatureAssets = [];
        try {
            DB::transaction(function () {
                if (DB::table('disaster_incidents')->where('description', self::INCIDENT_DESCRIPTION)
                    ->orWhere('incident_reference', 'TRAINING-FLOOD')->exists()) {
                    $this->command?->info('Training records are already seeded.');

                    return;
                }

                $this->call(DatabaseSeeder::class);
                $staff = $this->accounts();
                $models = $this->models();
                $areaCodes = array_column(config('mapr_reference.barangays'), 'barangay_code');
                $barangays = DB::table('barangays')->whereIn('barangay_code', $areaCodes)->orderBy('barangay_code')->get();

                $resident = User::query()->findOrFail($staff['resident']);
                if ($barangays->isEmpty()) {
                    throw new RuntimeException('At least one configured barangay is required.');
                }
                if ($resident->barangay_id === null) {
                    $resident->update(['barangay_id' => $barangays->first()->id]);
                }

                User::query()->create([
                    'first_name' => self::INACTIVE_RESIDENT_NAME[0],
                    'last_name' => self::INACTIVE_RESIDENT_NAME[1],
                    'email' => 'resident.inactive@mapr.test',
                    'role_id' => $resident->role_id,
                    'password' => 'password',
                    'account_status' => 'inactive',
                ]);

                $incidents = $this->incidents($staff['cdrrmo_admin']);
                foreach ($barangays as $index => $barangay) {
                    $owner = $this->barangayAccount($barangay->id, $barangay->barangay_code, $resident, $staff);
                    $registry = $this->registry($barangay, $index, $owner, $staff['cdrrmo_admin'], $incidents);
                    $this->reports($barangay->id, $barangay->barangay_code, $owner->id, $staff, $registry, $incidents, $models);
                }
                $this->runtimeFixtures();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($this->files);

            throw $exception;
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function accounts(): array
    {
        $accounts = [];
        foreach (array_keys(RoleHome::PREFIXES) as $role) {
            $user = User::query()->where('email', str_replace('_', '.', $role).'@mapr.test')
                ->where('account_status', 'active')
                ->whereHas('role', fn ($query) => $query->where('code', $role))
                ->first();
            if ($user === null) {
                throw new RuntimeException('The active training account for '.$role.' is required.');
            }
            $accounts[$role] = $user->id;
        }

        return $accounts;
    }

    private function barangayAccount(int $barangay, string $code, User $resident, array $staff): User
    {
        $admin = $staff['cdrrmo_admin'];
        foreach (Arr::except($staff, ['resident', 'cdrrmo_admin']) as $id) {
            $assignment = DB::table('user_barangay_assignments')->where('user_id', $id)
                ->where('barangay_id', $barangay)->whereNull('revoked_at');
            if (! $assignment->exists()) {
                $this->row('user_barangay_assignments', [
                    'user_id' => $id, 'barangay_id' => $barangay, 'assigned_by' => $admin, 'assigned_at' => now(),
                ]);
            }
        }
        if ((int) $resident->barangay_id === $barangay) {
            return $resident;
        }

        [$firstName, $lastName] = self::RESIDENT_NAMES[$code][0];

        return User::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'resident.'.$code.'@mapr.test',
            'role_id' => $resident->role_id,
            'barangay_id' => $barangay,
            'password' => 'password',
            'account_status' => 'active',
        ]);
    }

    private function incidents(int $admin): array
    {
        $type = DB::table('disaster_types')->where('name', 'Flood')->value('id');
        $incidents = [];
        foreach (['active' => 'FD0001', 'closed' => 'FD0002'] as $status => $suffix) {
            $active = $status === 'active';
            $incidents[$status] = $this->row('disaster_incidents', [
                'incident_reference' => 'INC-'.now()->year.'-'.$suffix,
                'incident_name' => $active ? 'Tandag Riverside Flooding' : 'Tandag Low-Lying Area Flooding',
                'description' => self::INCIDENT_DESCRIPTION,
                'occurred_at' => now()->subDays($active ? 5 : 15),
                'incident_status' => $status,
                'published_for_residents' => $active,
                'resident_coverage' => $active ? 'barangays' : null,
                'published_at' => $active ? now()->subDays(5) : null,
                'disaster_type_id' => $type,
                'created_by' => $admin,
            ]);
        }

        return $incidents;
    }

    private function registry(object $barangay, int $index, User $owner, int $admin, array $incidents): array
    {
        $code = $barangay->barangay_code;
        $lng = 126.169 + $index * 0.007;
        $lat = 9.072 + ($index % 2) * 0.007;
        $polygon = $this->square($lng, $lat, 0.002);
        DB::table('barangays')->where('id', $barangay->id)->whereNull('boundary_polygon')->update([
            'boundary_polygon' => $polygon,
            'boundary_source' => 'Synthetic training boundary',
        ]);
        foreach ($incidents as $incident) {
            $this->row('disaster_barangays', ['disaster_incident_id' => $incident, 'barangay_id' => $barangay->id]);
        }
        foreach (array_keys(config('mapr_reference.flood_classes')) as $offset => $class) {
            $this->row('flood_hazard_polygons', [
                'source_object_id' => 'TRAINING-'.$code.'-'.$class,
                'classification' => $class,
                'geometry' => $this->square($lng + $offset * 0.00035, $lat, 0.00015),
                'source' => 'Synthetic training hazard',
            ]);
        }

        $categories = DB::table('vulnerability_categories')->orderBy('id')->get(['id', 'category_name']);
        $sites = [];
        foreach (['verified', 'unverified', 'rejected'] as $offset => $status) {
            $point = $this->geometry(sprintf('POINT(%.7F %.7F)', $lng + $offset * 0.00035, $lat));
            $reference = $code.'-'.sprintf('%03d', $offset + 1);
            $address = 'Purok '.($offset + 2).', '.$barangay->barangay_name.', Tandag City';
            $building = $this->row('buildings', [
                'building_reference' => 'BLDG-'.$reference,
                'building_name' => 'Residence, Purok '.($offset + 2),
                'building_type' => 'Residential',
                'address_description' => $address,
                'geographic_point' => $point,
                'location_source' => 'Synthetic training location',
                'location_verification_status' => $status,
                'location_verified_by' => $status === 'verified' ? $admin : null,
                'location_verified_at' => $status === 'verified' ? now()->subDay() : null,
                'barangay_id' => $barangay->id,
            ]);
            $household = $this->row('households', [
                'household_reference' => 'HH-'.$reference,
                'household_status' => $status === 'rejected' ? 'inactive' : 'active',
                'record_source' => 'Training fixture',
                'source_household_size' => 1,
                'source_nuclear_families' => 1,
                'source_no_females' => 1,
                'source_no_males' => 0,
                'record_verified_at' => $status === 'verified' ? now()->subDay() : null,
                'building_id' => $building,
                'barangay_id' => $barangay->id,
            ]);
            $category = $categories[($index + $offset) % $categories->count()];
            $birthDate = match ($category->category_name) {
                'Senior citizen' => '1950-05-20',
                'Child under five' => now()->subYears(3)->toDateString(),
                default => '1995-05-20',
            };
            [$firstName, $lastName] = $offset === 0
                ? [$owner->first_name, $owner->last_name]
                : self::RESIDENT_NAMES[$code][$offset];
            $registryResident = $this->row('residents', [
                'registry_reference' => 'RES-'.$reference,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $birthDate,
                'sex' => 'female',
                'residency_status' => $status === 'rejected' ? 'inactive' : 'active',
                'record_source' => 'Training fixture',
                'record_verified_at' => $status === 'verified' ? now()->subDay() : null,
                'household_id' => $household,
            ]);

            $this->row('resident_vulnerabilities', [
                'resident_id' => $registryResident,
                'vulnerability_category_id' => $category->id,
                'verification_status' => $status,
                'record_source' => 'Training fixture',
            ]);
            $this->row('building_location_reviews', [
                'building_id' => $building,
                'previous_status' => $status === 'unverified' ? 'rejected' : 'unverified',
                'new_status' => $status,
                'basis_or_reason' => 'Synthetic training review.',
                'reviewed_by' => $admin,
                'reviewed_at' => now()->subDay(),
            ]);
            $correction = ['applied', 'pending', 'rejected'][$offset];
            $this->row('building_location_corrections', [
                'building_id' => $building,
                'proposed_point' => $this->geometry(sprintf('POINT(%.7F %.7F)', $lng + $offset * 0.00035, $lat + 0.00002)),
                'original_point' => $point,
                'reason' => 'Synthetic training correction.',
                'status' => $correction,
                'proposed_by' => $admin,
                'applied_at' => $correction === 'applied' ? now()->subDay() : null,
            ]);
            $this->inclusion($incidents['active'], $registryResident, $admin, $offset);
            $sites[] = [
                'building' => $building, 'household' => $household, 'resident' => $registryResident,
                'point' => $point, 'category' => $category->id, 'reference' => $reference, 'address' => $address,
            ];
        }

        if (! DB::table('account_resident_links')->where('user_id', $owner->id)->exists()) {
            $this->row('account_resident_links', [
                'user_id' => $owner->id,
                'resident_id' => $sites[0]['resident'],
                'verified_by' => $admin,
                'verified_at' => now()->subDay(),
                'authorization_reference' => 'TRAINING-LINK-'.$code,
            ]);
        }

        return ['site' => $sites[0], 'polygon' => $polygon];
    }

    private function inclusion(int $incident, int $resident, int $admin, int $offset): void
    {
        $removed = $offset === 2;
        $inclusion = $this->row('incident_resident_inclusions', [
            'disaster_incident_id' => $incident,
            'resident_id' => $resident,
            'inclusion_basis' => ['manual_review', 'selected_area', 'authorized_report'][$offset],
            'status' => $removed ? 'removed' : 'active',
            'added_by' => $admin,
            'removed_by' => $removed ? $admin : null,
            'removed_at' => $removed ? now() : null,
            'notes' => 'Synthetic training inclusion.',
        ]);
        $actions = match ($offset) {
            0 => ['added'],
            1 => ['added', 'removed', 'reinstated'],
            2 => ['added', 'removed'],
        };
        foreach ($actions as $action) {
            DB::table('incident_resident_inclusion_events')->insert([
                'inclusion_id' => $inclusion, 'action' => $action, 'user_id' => $admin, 'created_at' => now(),
            ]);
        }
    }

    private function models(): array
    {
        $states = ['inactive', 'retired'];
        // Register the active example only in an installation without a configured classifier.
        if (blank(config('mapr.damage_inference_url')) && ! DB::table('ai_models')->where('deployment_status', 'active')->exists()) {
            $states[] = 'active';
        }
        $models = [];
        foreach ($states as $status) {
            $manifest = $this->json(['name' => 'MapR training fixture', 'status' => $status, 'weights' => null]);
            $models[$status] = $this->row('ai_models', [
                'model_name' => 'MapR Training Fixture',
                'model_version' => 'fixture-'.$status,
                'model_file_reference' => $this->file("demo-data/models/{$status}.json", $manifest),
                'artifact_sha256' => hash('sha256', $manifest),
                'deployment_status' => $status,
                'deployed_at' => $status !== 'inactive' ? now()->subDays(15) : null,
            ]);
        }

        return $models;
    }

    private function reports(int $barangay, string $code, int $owner, array $staff, array $registry, array $incidents, array $models): void
    {
        $site = $registry['site'];
        $admin = $staff['cdrrmo_admin'];
        $bdrrm = $staff['bdrrm'];
        $assessor = $staff['rdana'];
        // Each row describes a complete snapshot of a different report.
        $scenarios = [
            'draft' => ['draft', null, null, null, null, null],
            'submitted' => ['submitted', null, null, null, null, null],
            'under-review' => ['under_review', null, null, null, null, null],
            'correction' => ['returned', null, null, null, null, null],
            'secretary' => ['reviewed', 'draft', 'pending_secretary', null, null, null],
            'captain' => ['reviewed', 'draft', 'pending_captain', null, null, null],
            'assessor' => ['reviewed', 'review', 'handed_to_rdana', 'draft', 'assessor_draft', null],
            'verification' => ['reviewed', 'review', 'handed_to_rdana', 'draft', 'pending_admin_verification', null],
            'city-assessor' => ['reviewed', 'review', 'handed_to_rdana', 'review', 'pending_city_assessor', null],
            'signed' => ['reviewed', 'signed', null, 'signed', null, null],
            'returned-assessment' => ['reviewed', 'review', 'handed_to_rdana', 'returned', 'assessor_draft', null],
            'returned-barangay' => ['reviewed', 'returned', null, null, null, null],
            'cdrrmo' => ['reviewed', 'submitted', 'handed_to_rdana', 'submitted', 'forwarded_cdrrmo', 'draft'],
            'consolidated-review' => ['reviewed', 'review', 'handed_to_rdana', 'submitted', 'forwarded_cdrrmo', 'review'],
            'consolidated-signed' => ['reviewed', 'review', 'handed_to_rdana', 'submitted', 'forwarded_cdrrmo', 'signed'],
            'finalized' => ['reviewed', 'review', 'handed_to_rdana', 'submitted', 'forwarded_cdrrmo', 'finalized'],
        ];
        $classes = DB::table('damage_classes')->pluck('id', 'class_code');
        $sequence = 0;
        foreach ($scenarios as $name => [$residentStatus, $barangayStatus, $barangayStage, $fieldStatus, $fieldStage, $finalStatus]) {
            $incident = $incidents[$name === 'finalized' ? 'closed' : 'active'];
            $observed = now()->subDays($name === 'finalized' ? 15 : 5);
            $reference = sprintf('%d-%s%03d', $observed->year, substr($code, -3), ++$sequence);
            $file = $name === 'finalized' ? 'storm' : 'flood';
            $barangayReport = $barangayStatus === null ? null : $this->row('barangay_reports', [
                'created_at' => $observed->copy()->addDays(2),
                'report_reference' => 'BR-'.$reference,
                'report_description' => 'Floodwater entered a ground-floor room and damaged the wall finishes.',
                'workflow_stage' => $barangayStage,
                'disaster_incident_id' => $incident,
                'barangay_id' => $barangay,
                'prepared_by' => $bdrrm,
                'return_reason' => $barangayStatus === 'returned' ? 'Add the observed water depth.' : null,
                'returned_at' => $barangayStatus === 'returned' ? now()->subDay() : null,
            ]);
            $residentReport = $this->row('resident_incident_reports', [
                'created_at' => $observed->copy()->addDay(),
                'report_reference' => 'RIR-'.$reference,
                'incident_description' => 'Floodwater reached the doorway and soaked the walls and belongings inside.',
                'reported_location' => $site['address'],
                'reported_point' => $site['point'],
                'report_status' => $residentStatus,
                'submitted_at' => $residentStatus === 'draft' ? null : $observed->copy()->addDay(),
                'reviewed_at' => $residentStatus === 'reviewed' ? $observed->copy()->addDays(2) : null,
                'reviewed_by' => $residentStatus === 'reviewed' ? $bdrrm : null,
                'returned_at' => $residentStatus === 'returned' ? $observed->copy()->addDays(2) : null,
                'return_reason' => $residentStatus === 'returned' ? 'Add a clearer photo and the time of observation.' : null,
                'observed_at' => $observed,
                'resident_user_id' => $owner,
                'disaster_incident_id' => $incident,
                'barangay_id' => $barangay,
                'building_id' => $site['building'],
                'barangay_report_id' => $barangayReport,
            ]);
            $this->photo('incident_report_id', $residentReport, $site['building'], $owner, $file, 'RIR-'.$reference);
            if ($residentStatus !== 'draft') {
                $this->signature('resident_incident_report_id', $residentReport, 'RIR-'.$reference, 'resident_signer', $owner, match ($name) {
                    'correction' => 'rejected',
                    'submitted' => 'pending',
                    default => 'verified',
                });
                if ($residentStatus !== 'submitted') {
                    $this->assign('resident_incident_report', $residentReport, $bdrrm, $staff['barangay_secretary'], 'bdrrm');
                }
                $this->history($owner, 'resident_report_submitted', 'resident_incident_report', $residentReport);
            }
            if ($barangayReport === null) {
                $this->flag('resident_incident_report', $residentReport, $bdrrm, $residentStatus);

                continue;
            }
            $this->signature('resident_incident_report_id', $residentReport, 'RIR-'.$reference, 'bdrrm_reviewer', $bdrrm);
            if ($name !== 'secretary' && $name !== 'returned-barangay') {
                $this->signature('barangay_report_id', $barangayReport, 'BR-'.$reference, 'barangay_secretary', $staff['barangay_secretary']);
            }
            if ($fieldStatus !== null) {
                $this->signature('barangay_report_id', $barangayReport, 'BR-'.$reference, 'barangay_signer', $staff['barangay_captain']);
                $this->assign('barangay_report', $barangayReport, $assessor, $staff['rdana_admin_officer'], 'rdana');
            }

            $photo = $this->photo('barangay_report_id', $barangayReport, $site['building'], $bdrrm, $file, 'BR-'.$reference);
            $predictionStatus = match ($name) {
                'secretary' => 'queued',
                'captain' => 'failed',
                default => 'succeeded',
            };
            $prediction = $this->row('building_damage_predictions', [
                'incident_photo_id' => $photo,
                'building_id' => $site['building'],
                'ai_model_id' => $models['active'] ?? $models['retired'],
                'predicted_damage_class_id' => $predictionStatus === 'succeeded' ? $classes['partially_damaged'] : null,
                'requested_by' => $bdrrm,
                'prediction_status' => $predictionStatus,
                'confidence_score' => $predictionStatus === 'succeeded' ? 0.87 : null,
                'inference_time_ms' => $predictionStatus === 'succeeded' ? 180 : null,
                'predicted_at' => $predictionStatus === 'succeeded' ? now()->subDay() : null,
                'failure_reason' => $predictionStatus === 'failed' ? 'Synthetic example of a low-quality source image.' : null,
            ]);
            $this->row('barangay_report_buildings', [
                'barangay_report_id' => $barangayReport,
                'building_id' => $site['building'],
                'damage_prediction_id' => $prediction,
                'observations' => 'Training observation: repair the interior finishes.',
            ]);
            DB::table('barangay_reports')->where('id', $barangayReport)->update([
                'report_status' => $barangayStatus,
                'submitted_at' => $fieldStatus !== null ? $observed->copy()->addDays(2) : null,
                'signed_at' => $fieldStatus !== null ? $observed->copy()->addDays(2) : null,
            ]);
            if ($fieldStatus === null) {
                continue;
            }

            $fieldReport = $this->row('field_assessments', [
                'created_at' => $observed->copy()->addDays(3),
                'assessment_reference' => 'FA-'.$reference,
                'findings' => 'Training assessment: verified the linked household and observed wall damage.',
                'workflow_stage' => $fieldStage,
                'barangay_report_id' => $barangayReport,
                'assigned_assessor_id' => $assessor,
                'returned_at' => $fieldStatus === 'returned' ? now()->subDay() : null,
                'return_reason' => $fieldStatus === 'returned' ? 'Confirm the extents of damaged floor area.' : null,
            ]);
            $this->photo('field_assessment_id', $fieldReport, $site['building'], $assessor, $file, 'FA-'.$reference);
            $damageCode = match ($name) {
                'consolidated-review' => 'not_affected',
                'finalized' => 'totally_damaged',
                default => 'partially_damaged',
            };
            $verification = $this->row('building_verifications', [
                'field_assessment_id' => $fieldReport,
                'building_id' => $site['building'],
                'damage_prediction_id' => $prediction,
                'verified_damage_class_id' => $classes[$damageCode],
                'verified_by' => $assessor,
                'verified_at' => now()->subDay(),
                'location_verified' => true,
                'household_link_verified' => true,
                'verification_notes' => 'Synthetic verification of the training building and household.',
            ]);
            $this->damageCost($barangayReport, $fieldReport, $verification, $site['building'], $prediction, $staff, $classes->all(), $damageCode, $name);
            if ($name !== 'assessor' && $fieldStatus !== 'returned') {
                $this->signature('field_assessment_id', $fieldReport, 'FA-'.$reference, 'assessed_by', $assessor);
            }
            if ($fieldStage === 'pending_city_assessor' || in_array($fieldStatus, ['signed', 'submitted'], true)) {
                $this->signature('field_assessment_id', $fieldReport, 'FA-'.$reference, 'verified_by', $staff['rdana_admin_officer']);
            }
            if ($fieldStatus === 'submitted') {
                $this->signature('field_assessment_id', $fieldReport, 'FA-'.$reference, 'approved_by', $staff['rdana_city_assessor']);
            }
            DB::table('field_assessments')->where('id', $fieldReport)->update([
                'assessment_status' => $fieldStatus,
                'submitted_at' => $fieldStatus === 'submitted' ? $observed->copy()->addDays(3) : null,
                'verified_at' => in_array($fieldStatus, ['review', 'signed', 'submitted'], true) ? $observed->copy()->addDays(3) : null,
                'signed_at' => in_array($fieldStatus, ['signed', 'submitted'], true) ? $observed->copy()->addDays(3) : null,
            ]);

            if ($finalStatus === null) {
                continue;
            }

            $estimate = $this->estimate($incident, $admin, $registry, $verification, $damageCode);
            $consolidated = $this->consolidated($finalStatus, $reference, $incident, $admin, $estimate, $fieldReport);
            if ($finalStatus === 'finalized') {
                foreach ([
                    'RIR' => ['resident_incident_report_id', $residentReport, 'reviewed', 'BDRRM'],
                    'BR' => ['barangay_report_id', $barangayReport, $barangayStatus, 'RDANA'],
                    'FA' => ['field_assessment_id', $fieldReport, $fieldStatus, 'CDRRMO'],
                    'CR' => ['consolidated_report_id', $consolidated, $finalStatus, 'CDRRMO'],
                ] as $prefix => [$parent, $id, $status, $office]) {
                    $this->document($prefix.'-'.$reference, $parent, $id, $status, $office, $barangay, $owner, $site);
                }
            }
        }
    }

    private function damageCost(int $report, int $field, int $verification, int $building, int $prediction, array $staff, array $classes, string $damageCode, string $scenario): void
    {
        $inputs = [
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'III',
            'construction_grade' => 'B',
            'total_floor_area_m2' => 60,
            'assessed_floor_area_m2' => match ($damageCode) {
                'not_affected' => 0,
                'totally_damaged' => 60,
                default => 12,
            },
        ];
        $draft = $scenario === 'assessor';
        $cost = app(BuildingCostEstimator::class)->calculate([
            ...($draft ? Arr::except($inputs, ['assessed_floor_area_m2']) : $inputs),
            'damage_class_code' => $damageCode,
        ]);
        $data = [
            ...$inputs,
            'barangay_report_id' => $report,
            'field_assessment_id' => $field,
            'building_id' => $building,
            'ai_prediction_id' => $prediction,
            'proposed_damage_class_id' => $classes['partially_damaged'],
            'confirmed_damage_class_id' => $draft ? null : $classes[$damageCode],
            'classification_source' => $damageCode === 'partially_damaged' ? 'ai_accepted' : 'ai_corrected',
            'correction_reason' => match ($damageCode) {
                'not_affected' => 'Training correction: water did not enter the building.',
                'totally_damaged' => 'Training correction: field evidence showed structural failure.',
                default => null,
            },
            'assessed_floor_area_m2' => $draft ? null : $inputs['assessed_floor_area_m2'],
            'unit_cost_php' => $cost['unit_cost_php'],
            'rate_source' => $cost['rate_source'],
            'criteria_version' => $cost['criteria_version'],
            'estimated_cost_php' => $cost['estimated_cost_php'],
            'calculated_estimated_cost_php' => $cost['estimated_cost_php'],
            'cost_status' => $cost['cost_status'],
            'assessment_status' => $draft ? 'draft' : 'confirmed',
            'assessed_by' => $staff['rdana'],
            'confirmed_by' => $draft ? null : $staff['rdana'],
            'confirmed_at' => $draft ? null : now()->subDay(),
            'breakdown_json' => $this->json($cost['breakdown']),
            'remarks' => 'Synthetic training estimate.',
        ];
        if ($scenario === 'consolidated-review') {
            $old = $this->row('building_damage_cost_assessments', [
                ...$data,
                'assessment_status' => 'superseded',
                'confirmed_damage_class_id' => $classes['partially_damaged'],
                'assessed_floor_area_m2' => 12,
                'estimated_cost_php' => 12 * $cost['unit_cost_php'],
                'calculated_estimated_cost_php' => 12 * $cost['unit_cost_php'],
                'cost_status' => 'calculated',
                'classification_source' => 'ai_accepted',
                'correction_reason' => null,
            ]);
            $data['supersedes_id'] = $old;
            $this->row('building_verification_corrections', [
                'building_verification_id' => $verification,
                'field_assessment_id' => $field,
                'building_id' => $building,
                'previous_damage_class_id' => $classes['partially_damaged'],
                'new_damage_class_id' => $classes[$damageCode],
                'note' => 'Training correction: the building was outside the flood extent.',
                'corrected_by' => $staff['rdana_admin_officer'],
                'corrected_at' => now()->subDay(),
            ]);
        }
        $this->row('building_damage_cost_assessments', $data);
    }

    private function estimate(int $incident, int $admin, array $registry, int $verification, string $damageCode): int
    {
        $site = $registry['site'];
        $method = $damageCode === 'not_affected' ? 'area_population' : 'verified_damaged_buildings';
        $rule = $method === 'area_population' ? 'strict_interior' : 'includes_boundary';
        $area = $this->row('assessment_areas', [
            'area_name' => 'Training estimate area',
            'polygon_geometry' => $registry['polygon'],
            'spatial_rule' => $rule,
            'selected_at' => now(),
            'disaster_incident_id' => $incident,
            'selected_by' => $admin,
        ]);
        $estimate = $this->row('resident_impact_estimates', [
            'estimated_buildings' => 1,
            'estimated_households' => 1,
            'estimated_residents' => 1,
            'unique_vulnerable_residents' => 1,
            'estimation_method' => $method,
            'criteria' => $this->json(['source' => 'Synthetic training snapshot']),
            'polygon_snapshot' => $registry['polygon'],
            'spatial_rule' => $rule,
            'exclusions' => $this->json(['unverified or inactive registry records']),
            'record_snapshot_at' => now(),
            'generated_at' => now(),
            'assessment_area_id' => $area,
            'disaster_incident_id' => $incident,
            'generated_by' => $admin,
        ]);
        $details = [
            'impact_estimate_buildings' => [
                'building_id' => $site['building'], 'point_snapshot' => $site['point'],
                'building_verification_id' => $verification, 'damage_class_code_snapshot' => $damageCode,
            ],
            'impact_estimate_households' => ['household_id' => $site['household'], 'building_id' => $site['building']],
            'impact_estimate_residents' => ['resident_id' => $site['resident'], 'household_id' => $site['household']],
            'impact_estimate_vulnerabilities' => ['resident_id' => $site['resident'], 'vulnerability_category_id' => $site['category']],
            'resident_impact_vulnerability_summaries' => ['vulnerability_category_id' => $site['category'], 'resident_count' => 1],
        ];
        foreach ($details as $table => $data) {
            $this->row($table, ['resident_impact_estimate_id' => $estimate, ...$data]);
        }

        return $estimate;
    }

    private function consolidated(string $status, string $reference, int $incident, int $admin, int $estimate, int $field): int
    {
        $report = $this->row('consolidated_reports', [
            'report_reference' => 'CR-'.$reference,
            'report_summary' => 'Training summary of flood damage and verified household impact.',
            'disaster_incident_id' => $incident,
            'resident_impact_estimate_id' => $estimate,
            'prepared_by' => $admin,
        ]);
        $this->row('consolidated_report_assessments', ['consolidated_report_id' => $report, 'field_assessment_id' => $field]);
        $signed = in_array($status, ['signed', 'finalized'], true);
        if ($status !== 'draft') {
            $this->signature('consolidated_report_id', $report, 'CR-'.$reference, 'noted_by', $admin);
        }
        if ($signed) {
            $this->signature('consolidated_report_id', $report, 'CR-'.$reference, 'approved_by', $admin);
        }
        DB::table('consolidated_reports')->where('id', $report)->update([
            'report_status' => $status,
            'signed_at' => $signed ? now()->subHour() : null,
            'finalized_at' => $status === 'finalized' ? now() : null,
        ]);
        $this->flag('consolidated_report', $report, $admin, $status);
        $this->history($admin, 'consolidated_report_'.$status, 'consolidated_report', $report);

        return $report;
    }

    private function document(string $reference, string $parent, int $id, string $status, string $office, int $barangay, int $owner, array $site): void
    {
        $title = e($reference);
        $pdf = Pdf::loadHTML("<h1>MapR Training Document</h1><p>{$title}</p><p>Synthetic local fixture. For practice only.</p>")->output();
        $filename = $reference.'.pdf';
        $this->row('imported_report_documents', [
            'import_key' => $reference,
            'filename' => $filename,
            'storage_path' => $this->file('demo-data/documents/'.$filename, $pdf),
            'sha256' => hash('sha256', $pdf),
            'workflow_status' => $status,
            'reviewing_office' => $office,
            'barangay_id' => $barangay,
            'resident_user_id' => $owner,
            $parent => $id,
            'source_resident_reference' => 'RES-'.$site['reference'],
            'source_building_reference' => 'BLDG-'.$site['reference'],
            'source_household_reference' => 'HH-'.$site['reference'],
            'resolved_resident_id' => $site['resident'],
            'resolved_building_id' => $site['building'],
            'resolved_household_id' => $site['household'],
            'unresolved_identifiers' => $this->json([]),
        ]);
    }

    private function photo(string $parent, int $id, int $building, int $uploader, string $kind, string $reference): int
    {
        $filename = $reference.'.jpg';
        $bytes = file_get_contents(__DIR__.'/media/'.$kind.'.jpg');

        return $this->row('incident_photos', [
            $parent => $id,
            'file_path' => $this->file('demo-data/photos/'.$filename, $bytes),
            'original_filename' => $filename,
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($bytes),
            'file_sha256' => hash('sha256', $bytes),
            'image_source' => 'FEMA / Wikimedia Commons. Illustrative training photo.',
            'building_id' => $building,
            'uploaded_by' => $uploader,
        ]);
    }

    private function signature(string $parent, int $id, string $document, string $role, int $signer, string $verificationStatus = 'verified'): void
    {
        if (! isset($this->signatureAssets[$signer])) {
            $image = imagecreatetruecolor(240, 60);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagestring($image, 5, 40, 22, 'TRAINING SAMPLE', imagecolorallocate($image, 30, 79, 137));
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();

            $this->signatureAssets[$signer] = $this->row('user_signature_assets', [
                'user_id' => $signer,
                'storage_path' => $this->file('demo-data/signatures/'.$signer.'.png', $bytes),
                'mime_type' => 'image/png',
                'byte_size' => strlen($bytes),
                'width' => 240,
                'height' => 60,
                'sha256' => hash('sha256', $bytes),
                'is_current' => false,
            ]);
        }

        $this->row('report_signatures', [
            $parent => $id,
            'signature_reference' => 'SIG-'.$document.'-'.$role,
            'document_reference' => $document,
            'document_hash' => hash('sha256', $document),
            'signature_role' => $role,
            'mechanism' => 'simulated_demo',
            'signed_at' => now()->subHour(),
            'verification_status' => $verificationStatus,
            'signer_user_id' => $signer,
            'user_signature_asset_id' => $this->signatureAssets[$signer],
            'signature_statement' => 'Simulated training endorsement. No person signed this document.',
        ]);
    }

    private function assign(string $subject, int $id, int $assignee, int $assigner, string $role): void
    {
        $this->row('report_assignments', [
            'subject_type' => $subject,
            'subject_id' => $id,
            'assignee_user_id' => $assignee,
            'assigned_by' => $assigner,
            'assignment_role' => $role,
            'remarks' => 'Training assignment.',
        ]);
    }

    private function flag(string $subject, int $id, int $user, string $status): void
    {
        if (! in_array($status, ['under_review', 'returned', 'review', 'finalized'], true)) {
            return;
        }
        $resolved = in_array($status, ['returned', 'finalized'], true);
        $this->row('report_flags', [
            'subject_type' => $subject,
            'subject_id' => $id,
            'category' => $subject === 'resident_incident_report' ? 'Photographs' : 'Evidence',
            'reason' => 'Training flag: check the linked evidence and reported location.',
            'status' => $resolved ? 'resolved' : 'open',
            'opened_by' => $user,
            'resolved_by' => $resolved ? $user : null,
            'resolved_at' => $resolved ? now() : null,
            'resolution' => $resolved ? 'Training review completed.' : null,
        ]);
    }

    private function history(int $user, string $action, string $subject, int $id): void
    {
        $this->row('activity_events', [
            'user_id' => $user,
            'action_type' => $action,
            'subject_type' => $subject,
            'subject_id' => $id,
            'summary' => 'Synthetic local training event.',
        ]);
        $this->row('activity_logs', [
            'user_id' => $user,
            'action' => $action,
            'module' => 'training',
            'record_type' => $subject,
            'record_id' => $id,
            'description' => 'Synthetic local training event.',
        ]);
    }

    private function runtimeFixtures(): void
    {
        $time = now()->timestamp;
        $expired = now()->subDay()->timestamp;
        $queue = 'mapr-training-fixtures';
        $uuid = 'e75a77ec-b325-4586-a5ba-ff38343c7210';
        $payload = $this->json(['uuid' => $uuid, 'displayName' => 'Inert MapR training fixture', 'job' => null, 'data' => []]);
        DB::table('sessions')->insert([
            'id' => sha1('mapr-training-expired-session'),
            'payload' => base64_encode(serialize([])),
            'last_activity' => $expired,
        ]);
        DB::table('cache')->insert([
            'key' => 'mapr-training-fixture', 'value' => serialize('Training example'), 'expiration' => $expired,
        ]);
        DB::table('cache_locks')->insert([
            'key' => 'mapr-training-lock', 'owner' => 'mapr-training-fixture', 'expiration' => $expired,
        ]);
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'available_at' => now()->addYears(10)->timestamp,
            'created_at' => $time,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => $payload,
            'exception' => 'Synthetic example, no job executed.',
        ]);
        DB::table('job_batches')->insert([
            'id' => '135fc24f-aae9-40c5-bf4c-c811d9dfc55d',
            'name' => 'MapR training batch',
            'total_jobs' => 1,
            'pending_jobs' => 0,
            'failed_jobs' => 1,
            'failed_job_ids' => $this->json([$uuid]),
            'created_at' => $time,
            'finished_at' => $time,
        ]);
    }

    private function row(string $table, array $data): int
    {
        return (int) DB::table($table)->insertGetId(['created_at' => now(), 'updated_at' => now(), ...$data]);
    }

    private function geometry(string $wkt): Expression
    {
        // Every WKT value comes from numeric coordinates generated in this seeder.
        return DB::raw(SpatialSql::geomFromTextExpr("'" . str_replace("'", "''", $wkt) . "'"));
    }

    private function square(float $x, float $y, float $radius): Expression
    {
        return $this->geometry(sprintf(
            'MULTIPOLYGON(((%.7F %.7F, %.7F %.7F, %.7F %.7F, %.7F %.7F, %.7F %.7F)))',
            $x - $radius, $y - $radius,
            $x + $radius, $y - $radius,
            $x + $radius, $y + $radius,
            $x - $radius, $y + $radius,
            $x - $radius, $y - $radius,
        ));
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function file(string $path, string $bytes): string
    {
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            // Leave existing uploads untouched if a database is being reseeded against the same volume.
            if ($disk->get($path) === $bytes) {
                return $path;
            }
            $path = dirname($path).'/'.str()->uuid().'-'.basename($path);
        }
        if ($bytes === '' || ! $disk->put($path, $bytes)) {
            throw new RuntimeException('Failed to store training fixture '.$path);
        }
        $this->files[] = $path;

        return $path;
    }
}
