<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration { public function up(): void {
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_barangay_reports_update` BEFORE UPDATE ON `barangay_reports` FOR EACH ROW BEGIN
IF OLD.report_status IN ('signed','submitted','finalized') THEN
 IF NOT (OLD.report_status='signed' AND NEW.report_status IN ('submitted','finalized') AND (NEW.`id` <=> OLD.`id`) AND (NEW.`report_reference` <=> OLD.`report_reference`) AND (NEW.`report_description` <=> OLD.`report_description`) AND (NEW.`signed_at` <=> OLD.`signed_at`) AND (NEW.`created_at` <=> OLD.`created_at`) AND (NEW.`disaster_incident_id` <=> OLD.`disaster_incident_id`) AND (NEW.`barangay_id` <=> OLD.`barangay_id`) AND (NEW.`prepared_by` <=> OLD.`prepared_by`)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report is immutable'; END IF;
END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_barangay_reports_delete` BEFORE DELETE ON `barangay_reports` FOR EACH ROW BEGIN
IF OLD.report_status IN ('signed','submitted','finalized') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report is immutable';
END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_field_assessments_update` BEFORE UPDATE ON `field_assessments` FOR EACH ROW BEGIN
IF OLD.assessment_status IN ('signed','submitted','finalized') THEN
 IF NOT (OLD.assessment_status='signed' AND NEW.assessment_status IN ('submitted','finalized') AND (NEW.`id` <=> OLD.`id`) AND (NEW.`assessment_reference` <=> OLD.`assessment_reference`) AND (NEW.`findings` <=> OLD.`findings`) AND (NEW.`verified_at` <=> OLD.`verified_at`) AND (NEW.`signed_at` <=> OLD.`signed_at`) AND (NEW.`created_at` <=> OLD.`created_at`) AND (NEW.`barangay_report_id` <=> OLD.`barangay_report_id`) AND (NEW.`assigned_assessor_id` <=> OLD.`assigned_assessor_id`)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report is immutable'; END IF;
END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_field_assessments_delete` BEFORE DELETE ON `field_assessments` FOR EACH ROW BEGIN
IF OLD.assessment_status IN ('signed','submitted','finalized') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report is immutable';
END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_consolidated_reports_update` BEFORE UPDATE ON `consolidated_reports` FOR EACH ROW BEGIN
IF OLD.report_status IN ('signed','submitted','finalized') THEN
 IF NOT (OLD.report_status='signed' AND NEW.report_status IN ('submitted','finalized') AND (NEW.`id` <=> OLD.`id`) AND (NEW.`report_reference` <=> OLD.`report_reference`) AND (NEW.`report_summary` <=> OLD.`report_summary`) AND (NEW.`signed_at` <=> OLD.`signed_at`) AND (NEW.`created_at` <=> OLD.`created_at`) AND (NEW.`disaster_incident_id` <=> OLD.`disaster_incident_id`) AND (NEW.`prepared_by` <=> OLD.`prepared_by`) AND (NEW.`resident_impact_estimate_id` <=> OLD.`resident_impact_estimate_id`)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report is immutable'; END IF;
END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_consolidated_reports_delete` BEFORE DELETE ON `consolidated_reports` FOR EACH ROW BEGIN
IF OLD.report_status IN ('signed','submitted','finalized') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report is immutable';
END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_barangay_report_buildings_insert` BEFORE INSERT ON `barangay_report_buildings` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM barangay_reports WHERE id=NEW.barangay_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_barangay_report_buildings_update` BEFORE UPDATE ON `barangay_report_buildings` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM barangay_reports WHERE id=OLD.barangay_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
IF EXISTS (SELECT 1 FROM barangay_reports WHERE id=NEW.barangay_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_barangay_report_buildings_delete` BEFORE DELETE ON `barangay_report_buildings` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM barangay_reports WHERE id=OLD.barangay_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_building_verifications_insert` BEFORE INSERT ON `building_verifications` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM field_assessments WHERE id=NEW.field_assessment_id AND assessment_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_building_verifications_update` BEFORE UPDATE ON `building_verifications` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM field_assessments WHERE id=OLD.field_assessment_id AND assessment_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
IF EXISTS (SELECT 1 FROM field_assessments WHERE id=NEW.field_assessment_id AND assessment_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_building_verifications_delete` BEFORE DELETE ON `building_verifications` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM field_assessments WHERE id=OLD.field_assessment_id AND assessment_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_consolidated_report_assessments_insert` BEFORE INSERT ON `consolidated_report_assessments` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM consolidated_reports WHERE id=NEW.consolidated_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_consolidated_report_assessments_update` BEFORE UPDATE ON `consolidated_report_assessments` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM consolidated_reports WHERE id=OLD.consolidated_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
IF EXISTS (SELECT 1 FROM consolidated_reports WHERE id=NEW.consolidated_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `guard_consolidated_report_assessments_delete` BEFORE DELETE ON `consolidated_report_assessments` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM consolidated_reports WHERE id=OLD.consolidated_report_id AND report_status IN ('signed','submitted','finalized')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed report items are immutable'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_report_signatures_update` BEFORE UPDATE ON `report_signatures` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_report_signatures_delete` BEFORE DELETE ON `report_signatures` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_activity_logs_update` BEFORE UPDATE ON `activity_logs` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_activity_logs_delete` BEFORE DELETE ON `activity_logs` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_building_damage_predictions_update` BEFORE UPDATE ON `building_damage_predictions` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_building_damage_predictions_delete` BEFORE DELETE ON `building_damage_predictions` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_resident_impact_estimates_update` BEFORE UPDATE ON `resident_impact_estimates` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_resident_impact_estimates_delete` BEFORE DELETE ON `resident_impact_estimates` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_buildings_update` BEFORE UPDATE ON `impact_estimate_buildings` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_buildings_delete` BEFORE DELETE ON `impact_estimate_buildings` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_households_update` BEFORE UPDATE ON `impact_estimate_households` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_households_delete` BEFORE DELETE ON `impact_estimate_households` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_residents_update` BEFORE UPDATE ON `impact_estimate_residents` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_residents_delete` BEFORE DELETE ON `impact_estimate_residents` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_vulnerabilities_update` BEFORE UPDATE ON `impact_estimate_vulnerabilities` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_impact_estimate_vulnerabilities_delete` BEFORE DELETE ON `impact_estimate_vulnerabilities` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_resident_impact_vulnerability_summaries_update` BEFORE UPDATE ON `resident_impact_vulnerability_summaries` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `append_resident_impact_vulnerability_summaries_delete` BEFORE DELETE ON `resident_impact_vulnerability_summaries` FOR EACH ROW BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only assessment history';
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `photo_guard_update` BEFORE UPDATE ON `incident_photos` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM building_damage_predictions WHERE incident_photo_id=OLD.id) OR EXISTS (SELECT 1 FROM barangay_reports WHERE id=OLD.barangay_report_id AND report_status IN ('signed','submitted')) OR EXISTS (SELECT 1 FROM field_assessments WHERE id=OLD.field_assessment_id AND assessment_status IN ('signed','submitted')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Preserve assessment evidence'; END IF;
END
SQL);
DB::unprepared(<<<'SQL'
CREATE TRIGGER `photo_guard_delete` BEFORE DELETE ON `incident_photos` FOR EACH ROW BEGIN
IF EXISTS (SELECT 1 FROM building_damage_predictions WHERE incident_photo_id=OLD.id) OR EXISTS (SELECT 1 FROM barangay_reports WHERE id=OLD.barangay_report_id AND report_status IN ('signed','submitted')) OR EXISTS (SELECT 1 FROM field_assessments WHERE id=OLD.field_assessment_id AND assessment_status IN ('signed','submitted')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Preserve assessment evidence'; END IF;
END
SQL);
} public function down(): void {
DB::unprepared('DROP TRIGGER IF EXISTS `photo_guard_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `photo_guard_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_resident_impact_vulnerability_summaries_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_resident_impact_vulnerability_summaries_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_vulnerabilities_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_vulnerabilities_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_residents_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_residents_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_households_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_households_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_buildings_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_impact_estimate_buildings_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_resident_impact_estimates_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_resident_impact_estimates_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_building_damage_predictions_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_building_damage_predictions_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_activity_logs_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_activity_logs_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_report_signatures_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `append_report_signatures_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_consolidated_report_assessments_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_consolidated_report_assessments_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_consolidated_report_assessments_insert`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_building_verifications_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_building_verifications_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_building_verifications_insert`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_barangay_report_buildings_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_barangay_report_buildings_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_barangay_report_buildings_insert`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_consolidated_reports_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_consolidated_reports_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_field_assessments_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_field_assessments_update`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_barangay_reports_delete`');
DB::unprepared('DROP TRIGGER IF EXISTS `guard_barangay_reports_update`');
} };
