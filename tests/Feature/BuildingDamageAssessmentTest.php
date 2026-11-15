<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\BuildingCostEstimator;
use App\Services\DamageInferenceClient;
use App\Services\ReportAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class BuildingDamageAssessmentTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        $this->createTables();
    }

    public function test_schedule_cost_calculation_and_pending_inputs(): void
    {
        $estimator = app(BuildingCostEstimator::class);

        $ok = $estimator->calculate([
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 100,
            'assessed_floor_area_m2' => 100,
            'damage_class_code' => 'totally_damaged',
        ]);
        $this->assertSame('calculated', $ok['cost_status']);
        $this->assertSame(1056000.00, $ok['estimated_cost_php']);
        $this->assertSame('schedule', $ok['rate_source']);
        $this->assertSame([], $ok['missing']);

        $zero = $estimator->calculate([
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 100,
            'assessed_floor_area_m2' => 0,
            'damage_class_code' => 'not_affected',
        ]);
        $this->assertSame('zero_confirmed', $zero['cost_status']);
        $this->assertSame(0.0, $zero['estimated_cost_php']);

        $pending = $estimator->calculate([
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'C',
            'total_floor_area_m2' => 100,
            'assessed_floor_area_m2' => 40,
            'damage_class_code' => 'partially_damaged',
        ]);
        $this->assertSame('pending_inputs', $pending['cost_status']);
        $this->assertNull($pending['estimated_cost_php']);
        $this->assertContains('unit_cost', $pending['missing']);
        $this->assertStringContainsString('Pending Required Assessment Data', $pending['message']);

        $this->assertSame('No Damage', $estimator->displayLabel('not_affected', 'Not affected'));
        $this->assertSame('Partially Damage', $estimator->displayLabel('partially_damaged', 'Partially damaged'));
        $this->assertSame('Totally Damage', $estimator->displayLabel('totally_damaged', 'Totally damaged'));
    }

    public function test_class_code_normalization_maps_supported_labels_only(): void
    {
        $client = app(DamageInferenceClient::class);
        $this->assertSame('not_affected', $client->normalizeClassCode('No Damage'));
        $this->assertSame('partially_damaged', $client->normalizeClassCode('partially_damaged'));
        $this->assertSame('totally_damaged', $client->normalizeClassCode('Totally Damage'));
        $this->assertNull($client->normalizeClassCode('minor'));
    }

    public function test_authorized_assessor_can_draft_confirm_and_see_pdf_section(): void
    {
        [$assessor, $reportId, $buildingId, $classPartial, $classTotal] = $this->seedEligibleReport();

        $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment')
            ->assertOk()
            ->assertJsonPath('data.report.id', $reportId)
            ->assertJsonPath('data.buildings.0.id', $buildingId);

        $calc = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/calculate', [
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 100,
            'assessed_floor_area_m2' => 100,
            'damage_class_code' => 'totally_damaged',
        ])->assertOk();
        $calc->assertJsonPath('data.estimated_cost_php', 1056000);

        $draft = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingId,
            'proposed_damage_class_id' => $classPartial,
            'confirmed_damage_class_id' => $classTotal,
            'classification_source' => 'ai_corrected',
            'correction_reason' => 'Field inspection shows total loss of the structure.',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 100,
            'assessed_floor_area_m2' => 100,
            'damage_class_code' => 'totally_damaged',
            'remarks' => 'Confirmed on site.',
        ])->assertCreated();

        $assessmentId = (int) $draft->json('data.id');
        $this->assertSame('draft', $draft->json('data.assessment_status'));
        $this->assertSame('Totally Damage', $draft->json('data.confirmed_label'));
        $this->assertSame(1056000, (int) $draft->json('data.estimated_cost_php'));

        $confirmed = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/'.$assessmentId.'/confirm')
            ->assertOk();
        $confirmed->assertJsonPath('data.assessment_status', 'confirmed');

        $this->assertSame(1, DB::table('building_verifications')->where('building_id', $buildingId)->count());
        $this->assertSame(1056000.00, (float) DB::table('building_verifications')->where('building_id', $buildingId)->value('estimated_repair_cost'));
        $this->assertSame($classTotal, (int) DB::table('building_verifications')->where('building_id', $buildingId)->value('verified_damage_class_id'));

        $preview = $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$reportId.'/preview')->assertOk();
        $html = $preview->getContent();
        $this->assertStringContainsString('Building Damage Assessment', $html);
        $this->assertStringContainsString('Building Status', $html);
        $this->assertStringContainsString('Estimated Cost Assessment', $html);
        $this->assertStringContainsString('Totally Damage', $html);
        $this->assertStringContainsString('1,056,000.00', $html);
        $photoPos = strpos($html, 'Photographic evidence');
        $statusPos = strpos($html, 'Building Status');
        $this->assertNotFalse($photoPos);
        $this->assertNotFalse($statusPos);
        $this->assertGreaterThan($photoPos, $statusPos);
    }

    public function test_manual_correction_preserves_ai_prediction_and_unauthorized_is_forbidden(): void
    {
        [$assessor, $reportId, $buildingId, $classPartial, $classTotal] = $this->seedEligibleReport();
        $outsider = $this->assessor([$this->barangay('166819999', 'Outsideville')], 'Outside Assessor');

        $predictionId = DB::table('building_damage_predictions')->insertGetId([
            'prediction_status' => 'succeeded',
            'incident_photo_id' => DB::table('incident_photos')->insertGetId([
                'file_path' => 'photos/demo.jpg',
                'original_filename' => 'demo.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 100,
                'file_sha256' => str_repeat('a', 64),
                'image_source' => 'bdrrm_upload',
                'barangay_report_id' => $reportId,
                'building_id' => $buildingId,
                'uploaded_by' => $assessor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'building_id' => $buildingId,
            'ai_model_id' => DB::table('ai_models')->insertGetId([
                'model_name' => 'MapR EfficientNetB0',
                'model_version' => 'test-1',
                'architecture' => 'EfficientNetB0',
                'optimizer' => 'AdamW',
                'model_file_reference' => 'models/test.onnx',
                'artifact_sha256' => str_repeat('b', 64),
                'deployment_status' => 'active',
                'deployed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'predicted_damage_class_id' => $classPartial,
            'confidence_score' => 0.81,
            'predicted_at' => now(),
            'requested_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draft = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingId,
            'ai_prediction_id' => $predictionId,
            'proposed_damage_class_id' => $classPartial,
            'confirmed_damage_class_id' => $classTotal,
            'classification_source' => 'ai_corrected',
            'correction_reason' => 'Roof and walls are collapsed.',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'III',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 50,
            'assessed_floor_area_m2' => 50,
            'damage_class_code' => 'totally_damaged',
        ])->assertCreated();

        $this->assertSame($predictionId, (int) $draft->json('data.ai_prediction_id'));
        $this->assertSame($classPartial, (int) DB::table('building_damage_predictions')->where('id', $predictionId)->value('predicted_damage_class_id'));
        $this->assertSame('ai_corrected', $draft->json('data.classification_source'));

        $this->actingAs($outsider)->getJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment')
            ->assertForbidden();
        $this->actingAs($outsider)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingId,
            'confirmed_damage_class_id' => $classTotal,
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 10,
            'assessed_floor_area_m2' => 10,
            'damage_class_code' => 'totally_damaged',
        ])->assertForbidden();
    }

    public function test_reconfirm_supersedes_previous_and_multi_building_stays_isolated(): void
    {
        [$assessor, $reportId, $buildingA, $classPartial, $classTotal] = $this->seedEligibleReport();
        $buildingB = DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-B',
            'building_type' => 'residential',
            'barangay_id' => DB::table('barangay_reports')->where('id', $reportId)->value('barangay_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('barangay_report_buildings')->insert([
            'observations' => 'Second building',
            'barangay_report_id' => $reportId,
            'building_id' => $buildingB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingA,
            'proposed_damage_class_id' => $classPartial,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'II',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 20,
            'damage_class_code' => 'partially_damaged',
        ])->assertCreated();
        $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/'.$first->json('data.id').'/confirm')
            ->assertOk();

        $second = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingA,
            'proposed_damage_class_id' => $classPartial,
            'confirmed_damage_class_id' => $classTotal,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'II',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 40,
            'damage_class_code' => 'totally_damaged',
        ])->assertCreated();
        $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/'.$second->json('data.id').'/confirm')
            ->assertOk();

        $this->assertSame('superseded', DB::table('building_damage_cost_assessments')->where('id', $first->json('data.id'))->value('assessment_status'));
        $this->assertSame('confirmed', DB::table('building_damage_cost_assessments')->where('id', $second->json('data.id'))->value('assessment_status'));

        $other = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingB,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'III',
            'construction_grade' => 'B',
            'total_floor_area_m2' => 30,
            'assessed_floor_area_m2' => 10,
            'damage_class_code' => 'partially_damaged',
        ])->assertCreated();
        $this->assertSame($buildingB, (int) $other->json('data.building_id'));
        $this->assertSame('confirmed', DB::table('building_damage_cost_assessments')->where('id', $second->json('data.id'))->value('assessment_status'));
    }

    public function test_classify_fails_closed_without_inference_service(): void
    {
        [$assessor, $reportId, $buildingId] = array_slice($this->seedEligibleReport(), 0, 3);
        DB::table('ai_models')->insert([
            'model_name' => 'MapR EfficientNetB0',
            'model_version' => 'test-1',
            'architecture' => 'EfficientNetB0',
            'optimizer' => 'AdamW',
            'model_file_reference' => 'models/test.onnx',
            'artifact_sha256' => str_repeat('c', 64),
            'deployment_status' => 'active',
            'deployed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('incident_photos')->insert([
            'file_path' => 'photos/missing.jpg',
            'original_filename' => 'missing.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'file_sha256' => str_repeat('d', 64),
            'image_source' => 'bdrrm_upload',
            'barangay_report_id' => $reportId,
            'building_id' => $buildingId,
            'uploaded_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/classify', [
            'building_id' => $buildingId,
        ])->assertOk();

        $this->assertSame('failed', $response->json('data.0.prediction_status'));
        $this->assertNotEmpty($response->json('data.0.failure_reason'));
        $this->assertNull($response->json('data.0.predicted_damage_class_id'));
    }

    public function test_report_scoped_photos_appear_without_building_tag_and_can_be_classified_after_link(): void
    {
        $mabua = $this->barangay('166819008', 'Mabua');
        $officer = $this->officer($mabua);
        $assessor = $this->assessor([$mabua]);
        $incident = $this->incident($mabua, 'River flood');
        $buildingId = DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-PHOTO',
            'building_type' => 'residential',
            'barangay_id' => $mabua,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportId = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-PHOTO-SCOPE',
            'report_description' => 'Flooded residence.',
            'report_status' => 'review',
            'submitted_at' => now(),
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $photoId = DB::table('incident_photos')->insertGetId([
            'file_path' => 'photos/untagged.jpg',
            'original_filename' => 'Damage_Building4.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 40,
            'file_sha256' => str_repeat('e', 64),
            'image_source' => 'resident_upload',
            'barangay_report_id' => $reportId,
            'building_id' => null,
            'uploaded_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('field_assessments')->insert([
            'assessment_reference' => 'FA-PHOTO-'.$reportId,
            'assessment_status' => 'draft',
            'workflow_stage' => 'assessor_draft',
            'barangay_report_id' => $reportId,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $show = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment')
            ->assertOk();
        $show->assertJsonPath('data.report.reference', 'BR-PHOTO-SCOPE');
        $show->assertJsonPath('data.photos.0.id', $photoId);
        $show->assertJsonPath('data.photos.0.building_id', null);
        $show->assertJsonPath('data.photos.0.url', '/rdana/api/reports/'.$reportId.'/photos/'.$photoId);
        $this->assertSame([], $show->json('data.buildings'));

        $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/classify', [
            'building_id' => $buildingId,
        ])->assertStatus(422);

        $linked = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/link-building', [
            'building_id' => $buildingId,
            'tag_untagged_photos' => true,
        ])->assertOk();
        $linked->assertJsonPath('data.buildings.0.id', $buildingId);
        $this->assertSame($buildingId, (int) DB::table('incident_photos')->where('id', $photoId)->value('building_id'));

        DB::table('ai_models')->insert([
            'model_name' => 'MapR EfficientNetB0',
            'model_version' => 'test-1',
            'architecture' => 'EfficientNetB0',
            'optimizer' => 'AdamW',
            'model_file_reference' => 'models/test.onnx',
            'artifact_sha256' => str_repeat('f', 64),
            'deployment_status' => 'active',
            'deployed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classify = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/classify', [
            'building_id' => $buildingId,
        ])->assertOk();
        $this->assertSame('failed', $classify->json('data.0.prediction_status'));
        $this->assertSame($photoId, (int) $classify->json('data.0.incident_photo_id'));
    }

    public function test_draft_building_status_appears_below_photos_and_reopen_does_not_duplicate(): void
    {
        [$assessor, $reportId, $buildingId, $classPartial] = $this->seedEligibleReport();

        $first = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingId,
            'proposed_damage_class_id' => $classPartial,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'C',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 20,
            'damage_class_code' => 'partially_damaged',
        ])->assertCreated();

        $this->assertSame('pending_inputs', $first->json('data.cost_status'));
        $assessmentId = (int) $first->json('data.id');

        $preview = $this->actingAs($assessor)->get('/rdana/reports/barangay/'.$reportId.'/preview')->assertOk();
        $html = $preview->getContent();
        $this->assertStringContainsString('Building Status', $html);
        $this->assertStringContainsString('Partially Damage', $html);
        $this->assertStringContainsString('Pending Required Assessment Data', $html);
        $this->assertTrue(strpos($html, 'Building Status') > strpos($html, 'Photographic evidence'));

        $again = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'id' => $assessmentId,
            'building_id' => $buildingId,
            'proposed_damage_class_id' => $classPartial,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 20,
            'damage_class_code' => 'partially_damaged',
        ])->assertOk();
        $this->assertSame($assessmentId, (int) $again->json('data.id'));
        $this->assertSame(1, DB::table('building_damage_cost_assessments')->where('barangay_report_id', $reportId)->where('assessment_status', 'draft')->count());

        $show = $this->actingAs($assessor)->getJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment')->assertOk();
        $show->assertJsonPath('data.current_assessments.0.id', $assessmentId);
        $show->assertJsonPath('data.current_assessments.0.confirmed_label', 'Partially Damage');
    }

    public function test_adjusted_cost_requires_reason_and_persists_calculated_separately(): void
    {
        [$assessor, $reportId, $buildingId, $classPartial] = $this->seedEligibleReport();

        $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingId,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 20,
            'damage_class_code' => 'partially_damaged',
            'estimated_cost_php' => 250000,
            'cost_is_adjusted' => true,
        ])->assertStatus(422);

        $saved = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingId,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'manual',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 20,
            'damage_class_code' => 'partially_damaged',
            'estimated_cost_php' => 250000,
            'cost_is_adjusted' => true,
            'cost_adjustment_reason' => 'Documented material costs exceed schedule estimate.',
        ])->assertCreated();

        $this->assertTrue((bool) $saved->json('data.cost_is_adjusted'));
        $this->assertSame(250000, (int) $saved->json('data.estimated_cost_php'));
        $this->assertSame(211200, (int) $saved->json('data.calculated_estimated_cost_php'));
        $this->assertSame('Documented material costs exceed schedule estimate.', $saved->json('data.cost_adjustment_reason'));

        $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/'.$saved->json('data.id').'/confirm')
            ->assertOk();
    }

    public function test_supervisor_revise_supersedes_confirmed_row_and_preserves_ai_fields(): void
    {
        [$assessor, $reportId, $buildingId, $classPartial, $classTotal] = $this->seedEligibleReport();
        $barangayId = (int) DB::table('barangay_reports')->where('id', $reportId)->value('barangay_id');
        $officer = $this->supervisor('rdana_admin_officer', $barangayId, 'Admin Officer');

        $predictionId = DB::table('building_damage_predictions')->insertGetId([
            'prediction_status' => 'succeeded',
            'incident_photo_id' => DB::table('incident_photos')->insertGetId([
                'file_path' => 'photos/revise.jpg',
                'original_filename' => 'revise.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 50,
                'file_sha256' => str_repeat('9', 64),
                'image_source' => 'bdrrm_upload',
                'barangay_report_id' => $reportId,
                'building_id' => $buildingId,
                'uploaded_by' => $assessor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'building_id' => $buildingId,
            'ai_model_id' => DB::table('ai_models')->insertGetId([
                'model_name' => 'MapR EfficientNetB0',
                'model_version' => 'test-1',
                'architecture' => 'EfficientNetB0',
                'optimizer' => 'AdamW',
                'model_file_reference' => 'models/test.onnx',
                'artifact_sha256' => str_repeat('8', 64),
                'deployment_status' => 'active',
                'deployed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'predicted_damage_class_id' => $classPartial,
            'confidence_score' => 0.77,
            'predicted_at' => now(),
            'requested_by' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draft = $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment', [
            'building_id' => $buildingId,
            'ai_prediction_id' => $predictionId,
            'proposed_damage_class_id' => $classPartial,
            'confirmed_damage_class_id' => $classPartial,
            'classification_source' => 'ai',
            'occupancy_key' => 'one_storey_residence',
            'construction_type' => 'V',
            'construction_grade' => 'A',
            'total_floor_area_m2' => 40,
            'assessed_floor_area_m2' => 20,
            'damage_class_code' => 'partially_damaged',
        ])->assertCreated();
        $assessmentId = (int) $draft->json('data.id');
        $this->actingAs($assessor)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/'.$assessmentId.'/confirm')
            ->assertOk();

        $fieldAssessmentId = (int) DB::table('field_assessments')->where('barangay_report_id', $reportId)->value('id');
        DB::table('field_assessments')->where('id', $fieldAssessmentId)->update([
            'workflow_stage' => 'pending_admin_verification',
            'updated_at' => now(),
        ]);
        DB::table('report_signatures')->insert([
            'signature_role' => 'assessed_by',
            'signer_user_id' => $assessor->id,
            'field_assessment_id' => $fieldAssessmentId,
            'signed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revised = $this->actingAs($officer)->postJson('/rdana/api/barangay-reports/'.$reportId.'/damage-assessment/revise', [
            'confirmed_damage_class_id' => $classTotal,
            'estimated_cost_php' => 900000,
            'correction_reason' => 'City inspection confirms total structural loss.',
        ])->assertOk();

        $this->assertSame('confirmed', $revised->json('data.assessment_status'));
        $this->assertSame('supervisor_corrected', $revised->json('data.classification_source'));
        $this->assertSame($predictionId, (int) $revised->json('data.ai_prediction_id'));
        $this->assertSame($classPartial, (int) $revised->json('data.proposed_damage_class_id'));
        $this->assertSame(1, DB::table('building_damage_cost_assessments')->where('id', $assessmentId)->where('assessment_status', 'superseded')->count());
        $this->assertSame(1, DB::table('building_damage_cost_assessments')->where('barangay_report_id', $reportId)->where('assessment_status', 'confirmed')->count());
        $this->assertSame('pending_admin_verification', DB::table('field_assessments')->where('id', $fieldAssessmentId)->value('workflow_stage'));
    }

    public function test_supervisor_can_return_field_assessment_to_assessor_queue(): void
    {
        [$assessor, $reportId] = array_slice($this->seedEligibleReport(), 0, 2);
        $barangayId = (int) DB::table('barangay_reports')->where('id', $reportId)->value('barangay_id');
        $officer = $this->supervisor('rdana_admin_officer', $barangayId, 'Return Officer');
        $fieldAssessmentId = (int) DB::table('field_assessments')->where('barangay_report_id', $reportId)->value('id');

        DB::table('field_assessments')->where('id', $fieldAssessmentId)->update([
            'workflow_stage' => 'pending_admin_verification',
            'assessment_status' => 'draft',
            'updated_at' => now(),
        ]);
        DB::table('report_signatures')->insert([
            'signature_role' => 'assessed_by',
            'signer_user_id' => $assessor->id,
            'field_assessment_id' => $fieldAssessmentId,
            'signed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)->postJson('/rdana/api/assessments/'.$fieldAssessmentId.'/return', [
            'return_reason' => 'Please recheck estimated cost against schedule rates.',
        ])->assertOk();

        $row = DB::table('field_assessments')->where('id', $fieldAssessmentId)->first();
        $this->assertSame('returned', $row->assessment_status);
        $this->assertSame('assessor_draft', $row->workflow_stage);
        $this->assertSame(0, DB::table('report_signatures')->where('field_assessment_id', $fieldAssessmentId)->count());
        $this->assertSame('draft', app(ReportAssignmentService::class)->assessmentBucket($reportId));
    }

    /**
     * @return array{0: User, 1: int, 2: int, 3: int, 4: int}
     */
    private function seedEligibleReport(): array
    {
        $mabua = $this->barangay('166819008', 'Mabua');
        $officer = $this->officer($mabua);
        $assessor = $this->assessor([$mabua]);
        $incident = $this->incident($mabua, 'River flood');
        $buildingId = DB::table('buildings')->insertGetId([
            'building_reference' => 'BLD-A',
            'building_type' => 'residential',
            'barangay_id' => $mabua,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportId = DB::table('barangay_reports')->insertGetId([
            'report_reference' => 'BR-DAMAGE-1',
            'report_description' => 'Flooded residence.',
            'report_status' => 'review',
            'submitted_at' => now(),
            'disaster_incident_id' => $incident,
            'barangay_id' => $mabua,
            'prepared_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('barangay_report_buildings')->insert([
            'observations' => 'Main house',
            'barangay_report_id' => $reportId,
            'building_id' => $buildingId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classTotal = DB::table('damage_classes')->insertGetId([
            'class_code' => 'totally_damaged',
            'class_name' => 'Totally damaged',
            'description' => 'Total',
            'display_order' => 1,
            'counts_as_damaged' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classPartial = DB::table('damage_classes')->insertGetId([
            'class_code' => 'partially_damaged',
            'class_name' => 'Partially damaged',
            'description' => 'Partial',
            'display_order' => 2,
            'counts_as_damaged' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('damage_classes')->insert([
            'class_code' => 'not_affected',
            'class_name' => 'Not affected',
            'description' => 'None',
            'display_order' => 3,
            'counts_as_damaged' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('field_assessments')->insert([
            'assessment_reference' => 'FA-DAMAGE-'.$reportId,
            'assessment_status' => 'draft',
            'workflow_stage' => 'assessor_draft',
            'barangay_report_id' => $reportId,
            'assigned_assessor_id' => $assessor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$assessor, $reportId, $buildingId, $classPartial, $classTotal];
    }

    private function createTables(): void
    {
        Schema::create('disaster_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('disaster_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_reference');
            $table->string('incident_name');
            $table->string('incident_status')->default('active');
            $table->dateTime('occurred_at')->nullable();
            $table->unsignedBigInteger('disaster_type_id')->nullable();
            $table->timestamps();
        });
        Schema::create('disaster_barangays', function (Blueprint $table) {
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_reference')->nullable();
            $table->string('building_type')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->timestamps();
        });
        Schema::create('barangay_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_reference');
            $table->text('report_description')->nullable();
            $table->string('report_status')->default('draft');
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('disaster_incident_id');
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamps();
        });
        Schema::create('barangay_report_buildings', function (Blueprint $table) {
            $table->id();
            $table->text('observations')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('building_id');
            $table->timestamps();
        });
        Schema::create('field_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_reference');
            $table->text('findings')->nullable();
            $table->string('assessment_status')->default('draft');
            $table->string('workflow_stage')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('assigned_assessor_id');
            $table->timestamps();
        });
        Schema::create('report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_reference')->nullable();
            $table->string('document_reference')->nullable();
            $table->char('document_hash', 64)->nullable();
            $table->string('mechanism')->nullable();
            $table->string('signature_role');
            $table->dateTime('signed_at')->nullable();
            $table->string('verification_status')->nullable();
            $table->unsignedBigInteger('signer_user_id')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->timestamps();
        });
        Schema::create('damage_classes', function (Blueprint $table) {
            $table->id();
            $table->string('class_code')->unique();
            $table->string('class_name');
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('counts_as_damaged')->default(false);
            $table->timestamps();
        });
        Schema::create('building_verifications', function (Blueprint $table) {
            $table->id();
            $table->boolean('location_verified')->default(false);
            $table->boolean('household_link_verified')->default(false);
            $table->text('verification_notes')->nullable();
            $table->dateTime('verified_at');
            $table->unsignedBigInteger('field_assessment_id');
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('damage_prediction_id')->nullable();
            $table->unsignedBigInteger('verified_damage_class_id');
            $table->unsignedBigInteger('verified_by');
            $table->decimal('estimated_repair_cost', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['field_assessment_id', 'building_id']);
        });
        Schema::create('building_damage_cost_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_report_id');
            $table->unsignedBigInteger('field_assessment_id')->nullable();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('ai_prediction_id')->nullable();
            $table->unsignedBigInteger('proposed_damage_class_id')->nullable();
            $table->unsignedBigInteger('confirmed_damage_class_id')->nullable();
            $table->string('classification_source', 32)->default('manual');
            $table->text('correction_reason')->nullable();
            $table->string('occupancy_key', 64)->nullable();
            $table->string('construction_type', 8)->nullable();
            $table->string('construction_grade', 8)->nullable();
            $table->decimal('total_floor_area_m2', 12, 4)->nullable();
            $table->decimal('assessed_floor_area_m2', 12, 4)->nullable();
            $table->decimal('unit_cost_php', 12, 2)->nullable();
            $table->string('rate_source', 32)->nullable();
            $table->text('rate_source_note')->nullable();
            $table->string('criteria_version', 64)->nullable();
            $table->decimal('estimated_cost_php', 14, 2)->nullable();
            $table->decimal('calculated_estimated_cost_php', 14, 2)->nullable();
            $table->boolean('cost_is_adjusted')->default(false);
            $table->text('cost_adjustment_reason')->nullable();
            $table->string('cost_status', 32)->default('pending_inputs');
            $table->string('assessment_status', 32)->default('draft');
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->unsignedBigInteger('assessed_by');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->json('breakdown_json')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_name');
            $table->string('model_version');
            $table->string('architecture');
            $table->string('optimizer');
            $table->string('model_file_reference');
            $table->string('artifact_sha256', 64);
            $table->string('deployment_status');
            $table->dateTime('deployed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('incident_photos', function (Blueprint $table) {
            $table->id();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('file_sha256', 64);
            $table->string('image_source');
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });
        Schema::create('building_damage_predictions', function (Blueprint $table) {
            $table->id();
            $table->decimal('confidence_score', 8, 4)->nullable();
            $table->unsignedInteger('inference_time_ms')->nullable();
            $table->string('prediction_status');
            $table->dateTime('predicted_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('incident_photo_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('ai_model_id');
            $table->unsignedBigInteger('predicted_damage_class_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->timestamps();
        });
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->timestamps();
        });
        Schema::create('imported_report_documents', function (Blueprint $table) {
            $table->id();
            $table->string('import_key')->nullable();
            $table->unsignedBigInteger('barangay_report_id')->nullable();
            $table->timestamps();
        });
    }

    private function barangay(string $code, string $name): int
    {
        return DB::table('barangays')->insertGetId([
            'barangay_code' => $code,
            'barangay_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function incident(int $barangayId, string $name): int
    {
        $type = DB::table('disaster_types')->insertGetId([
            'name' => 'Flood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::table('disaster_incidents')->insertGetId([
            'incident_reference' => 'INC-'.$barangayId.'-'.random_int(10, 99),
            'incident_name' => $name,
            'incident_status' => 'active',
            'occurred_at' => now(),
            'disaster_type_id' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('disaster_barangays')->insert([
            'disaster_incident_id' => $id,
            'barangay_id' => $barangayId,
        ]);

        return $id;
    }

    private function user(string $role, string $name, ?int $barangayId = null): User
    {
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => $role,
                'code' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        [$first, $rest] = array_pad(explode(' ', $name, 2), 2, 'User');

        return User::query()->create([
            'first_name' => $first,
            'last_name' => $rest,
            'email' => strtolower(str_replace(' ', '.', $name)).random_int(1, 999).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => $barangayId,
        ]);
    }

    private function officer(int $barangayId): User
    {
        $user = $this->user('bdrrm', 'Officer '.$barangayId, $barangayId);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $user->id,
            'barangay_id' => $barangayId,
            'assigned_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh('role');
    }

    private function assessor(array $barangayIds, string $name = 'Mark Merillo'): User
    {
        $user = $this->user('rdana', $name);
        foreach ($barangayIds as $barangayId) {
            DB::table('user_barangay_assignments')->insert([
                'assigned_at' => now(),
                'user_id' => $user->id,
                'barangay_id' => $barangayId,
                'assigned_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user->fresh('role');
    }

    private function supervisor(string $role, int $barangayId, string $name): User
    {
        $user = $this->user($role, $name, $barangayId);
        DB::table('user_barangay_assignments')->insert([
            'assigned_at' => now(),
            'user_id' => $user->id,
            'barangay_id' => $barangayId,
            'assigned_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh('role');
    }
}
