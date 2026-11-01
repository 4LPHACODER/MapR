-- Oracle MySQL 8.0.30+
-- Relationships are enforced through FOREIGN KEY constraints.
-- Barangays, damage labels, and vulnerability categories require approved data.

CREATE DATABASE IF NOT EXISTS mapr_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE mapr_db;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(32) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CHECK (code IN ('resident', 'bdrrm', 'rdana', 'cdrrmo_admin'))
) ENGINE=InnoDB;

CREATE TABLE barangays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barangay_code VARCHAR(32) NOT NULL UNIQUE,
    barangay_name VARCHAR(191) NOT NULL,
    city VARCHAR(191) NOT NULL,
    province VARCHAR(191) NOT NULL,
    region VARCHAR(191) NOT NULL,
    boundary_polygon MULTIPOLYGON NULL SRID 4326,
    boundary_source TEXT NULL,
    boundary_verified_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    barangay_id BIGINT UNSIGNED NULL,
    first_name VARCHAR(191) NOT NULL,
    middle_name VARCHAR(191) NULL,
    last_name VARCHAR(191) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    contact_number VARCHAR(32) NULL,
    account_status VARCHAR(16) NOT NULL DEFAULT 'inactive',
    email_verified_at DATETIME(6) NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at DATETIME(6) NULL,

    FOREIGN KEY (role_id)
        REFERENCES roles(id) ON DELETE RESTRICT,
    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id) ON DELETE RESTRICT,

    CHECK (account_status IN ('active', 'inactive'))
) ENGINE=InnoDB;

CREATE TABLE user_barangay_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    barangay_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,

    active_barangay_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN revoked_at IS NULL THEN barangay_id ELSE NULL END
        ) STORED,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (user_id, active_barangay_id),

    FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (revoked_at IS NULL OR revoked_at >= assigned_at)
) ENGINE=InnoDB;

CREATE TABLE buildings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barangay_id BIGINT UNSIGNED NOT NULL,
    building_reference VARCHAR(191) NOT NULL UNIQUE,
    building_name VARCHAR(191) NULL,
    building_type VARCHAR(64) NULL,
    address_description TEXT NULL,
    geographic_point POINT NULL SRID 4326,
    location_source TEXT NULL,
    location_verification_status VARCHAR(16) NOT NULL DEFAULT 'unverified',
    location_verified_by BIGINT UNSIGNED NULL,
    location_verified_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (id, barangay_id),

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id) ON DELETE RESTRICT,
    FOREIGN KEY (location_verified_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (
        location_verification_status IN ('unverified', 'verified', 'rejected')
    ),
    CHECK (
        location_verification_status <> 'verified'
        OR (
            geographic_point IS NOT NULL
            AND location_source IS NOT NULL
            AND location_verified_by IS NOT NULL
            AND location_verified_at IS NOT NULL
        )
    )
) ENGINE=InnoDB;

CREATE TABLE households (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    building_id BIGINT UNSIGNED NULL,
    barangay_id BIGINT UNSIGNED NOT NULL,
    household_reference VARCHAR(191) NOT NULL UNIQUE,
    household_status VARCHAR(16) NOT NULL DEFAULT 'active',
    record_source VARCHAR(191) NOT NULL,
    record_verified_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id) ON DELETE RESTRICT,
    FOREIGN KEY (building_id, barangay_id)
        REFERENCES buildings(id, barangay_id) ON DELETE RESTRICT,

    CHECK (household_status IN ('active', 'inactive'))
) ENGINE=InnoDB;

CREATE TABLE residents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    registry_reference VARCHAR(191) NOT NULL UNIQUE,
    first_name VARCHAR(191) NOT NULL,
    middle_name VARCHAR(191) NULL,
    last_name VARCHAR(191) NOT NULL,
    birth_date DATE NULL,
    sex VARCHAR(32) NULL,
    residency_status VARCHAR(16) NOT NULL DEFAULT 'active',
    record_source VARCHAR(191) NOT NULL,
    record_verified_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (household_id)
        REFERENCES households(id) ON DELETE RESTRICT,

    CHECK (residency_status IN ('active', 'inactive'))
) ENGINE=InnoDB;

CREATE TABLE account_resident_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    resident_id BIGINT UNSIGNED NOT NULL UNIQUE,
    verified_by BIGINT UNSIGNED NOT NULL,
    verified_at DATETIME(6) NOT NULL,
    authorization_reference VARCHAR(191) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (resident_id)
        REFERENCES residents(id) ON DELETE RESTRICT,
    FOREIGN KEY (verified_by)
        REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE vulnerability_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(191) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE resident_vulnerabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_id BIGINT UNSIGNED NOT NULL,
    vulnerability_category_id BIGINT UNSIGNED NOT NULL,
    verification_status VARCHAR(16) NOT NULL,
    record_source VARCHAR(191) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (resident_id, vulnerability_category_id),

    FOREIGN KEY (resident_id)
        REFERENCES residents(id) ON DELETE RESTRICT,
    FOREIGN KEY (vulnerability_category_id)
        REFERENCES vulnerability_categories(id) ON DELETE RESTRICT,

    CHECK (verification_status IN ('unverified', 'verified', 'rejected'))
) ENGINE=InnoDB;

CREATE TABLE disaster_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL UNIQUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE disaster_incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    disaster_type_id BIGINT UNSIGNED NOT NULL,
    incident_reference VARCHAR(191) NOT NULL UNIQUE,
    incident_name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    occurred_at DATETIME(6) NOT NULL,
    incident_status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (disaster_type_id)
        REFERENCES disaster_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (incident_status IN ('active', 'closed'))
) ENGINE=InnoDB;

CREATE TABLE disaster_barangays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    disaster_incident_id BIGINT UNSIGNED NOT NULL,
    barangay_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (disaster_incident_id, barangay_id),

    FOREIGN KEY (disaster_incident_id)
        REFERENCES disaster_incidents(id) ON DELETE RESTRICT,
    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE resident_incident_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_reference VARCHAR(191) NOT NULL UNIQUE,
    resident_user_id BIGINT UNSIGNED NOT NULL,
    disaster_incident_id BIGINT UNSIGNED NOT NULL,
    barangay_id BIGINT UNSIGNED NOT NULL,
    building_id BIGINT UNSIGNED NULL,
    incident_description TEXT NULL,
    reported_location TEXT NULL,
    reported_point POINT NULL SRID 4326,
    report_status VARCHAR(20) NOT NULL DEFAULT 'draft',
    submitted_at DATETIME(6) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (resident_user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (reviewed_by)
        REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (disaster_incident_id, barangay_id)
        REFERENCES disaster_barangays(disaster_incident_id, barangay_id)
        ON DELETE RESTRICT,
    FOREIGN KEY (building_id, barangay_id)
        REFERENCES buildings(id, barangay_id) ON DELETE RESTRICT,

    CHECK (
        report_status IN (
            'draft', 'submitted', 'under_review', 'reviewed', 'returned'
        )
    )
) ENGINE=InnoDB;

CREATE TABLE ai_models (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_name VARCHAR(191) NOT NULL,
    model_version VARCHAR(64) NOT NULL,
    architecture VARCHAR(32) NOT NULL DEFAULT 'EfficientNetB0',
    optimizer VARCHAR(16) NOT NULL DEFAULT 'AdamW',
    model_file_reference VARCHAR(512) NOT NULL,
    artifact_sha256 CHAR(64) NOT NULL,
    deployment_status VARCHAR(16) NOT NULL,
    deployed_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (model_name, model_version),

    CHECK (architecture = 'EfficientNetB0' AND optimizer = 'AdamW'),
    CHECK (deployment_status IN ('inactive', 'active', 'retired'))
) ENGINE=InnoDB;

CREATE TABLE damage_classes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_code VARCHAR(64) NOT NULL UNIQUE,
    class_name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    display_order SMALLINT UNSIGNED NOT NULL,
    counts_as_damaged BOOLEAN NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    CHECK (counts_as_damaged IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE barangay_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_reference VARCHAR(191) NOT NULL UNIQUE,
    disaster_incident_id BIGINT UNSIGNED NOT NULL,
    barangay_id BIGINT UNSIGNED NOT NULL,
    prepared_by BIGINT UNSIGNED NOT NULL,
    report_description TEXT NULL,
    report_status VARCHAR(16) NOT NULL DEFAULT 'draft',
    submitted_at DATETIME(6) NULL,
    signed_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (prepared_by)
        REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (disaster_incident_id, barangay_id)
        REFERENCES disaster_barangays(disaster_incident_id, barangay_id)
        ON DELETE RESTRICT,

    CHECK (report_status IN ('draft', 'review', 'signed', 'submitted'))
) ENGINE=InnoDB;

CREATE TABLE field_assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_reference VARCHAR(191) NOT NULL UNIQUE,
    barangay_report_id BIGINT UNSIGNED NOT NULL,
    assigned_assessor_id BIGINT UNSIGNED NOT NULL,
    findings TEXT NULL,
    assessment_status VARCHAR(16) NOT NULL DEFAULT 'draft',
    verified_at DATETIME(6) NULL,
    signed_at DATETIME(6) NULL,
    submitted_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (barangay_report_id)
        REFERENCES barangay_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_assessor_id)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (assessment_status IN ('draft', 'review', 'signed', 'submitted'))
) ENGINE=InnoDB;

CREATE TABLE incident_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_report_id BIGINT UNSIGNED NULL,
    barangay_report_id BIGINT UNSIGNED NULL,
    field_assessment_id BIGINT UNSIGNED NULL,
    building_id BIGINT UNSIGNED NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(512) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    image_source VARCHAR(191) NOT NULL,
    captured_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (incident_report_id)
        REFERENCES resident_incident_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (barangay_report_id)
        REFERENCES barangay_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (field_assessment_id)
        REFERENCES field_assessments(id) ON DELETE RESTRICT,
    FOREIGN KEY (building_id)
        REFERENCES buildings(id) ON DELETE RESTRICT,
    FOREIGN KEY (uploaded_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (
        (incident_report_id IS NOT NULL)
        + (barangay_report_id IS NOT NULL)
        + (field_assessment_id IS NOT NULL) = 1
    ),
    CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')),
    CHECK (file_size BETWEEN 1 AND 10485760)
) ENGINE=InnoDB;

CREATE TABLE building_damage_predictions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_photo_id BIGINT UNSIGNED NOT NULL,
    building_id BIGINT UNSIGNED NULL,
    ai_model_id BIGINT UNSIGNED NOT NULL,
    predicted_damage_class_id BIGINT UNSIGNED NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    confidence_score DECIMAL(8,7) NULL,
    inference_time_ms DECIMAL(12,3) NULL,
    prediction_status VARCHAR(16) NOT NULL,
    predicted_at DATETIME(6) NULL,
    failure_reason TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (id, building_id),

    FOREIGN KEY (incident_photo_id)
        REFERENCES incident_photos(id) ON DELETE RESTRICT,
    FOREIGN KEY (building_id)
        REFERENCES buildings(id) ON DELETE RESTRICT,
    FOREIGN KEY (ai_model_id)
        REFERENCES ai_models(id) ON DELETE RESTRICT,
    FOREIGN KEY (predicted_damage_class_id)
        REFERENCES damage_classes(id) ON DELETE RESTRICT,
    FOREIGN KEY (requested_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (prediction_status IN ('queued', 'succeeded', 'failed')),
    CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 1),
    CHECK (inference_time_ms IS NULL OR inference_time_ms >= 0),
    CHECK (
        prediction_status <> 'succeeded'
        OR (
            predicted_damage_class_id IS NOT NULL
            AND predicted_at IS NOT NULL
        )
    )
) ENGINE=InnoDB;

CREATE TABLE barangay_report_buildings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barangay_report_id BIGINT UNSIGNED NOT NULL,
    building_id BIGINT UNSIGNED NOT NULL,
    damage_prediction_id BIGINT UNSIGNED NULL,
    observations TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (barangay_report_id, building_id),

    FOREIGN KEY (barangay_report_id)
        REFERENCES barangay_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (building_id)
        REFERENCES buildings(id) ON DELETE RESTRICT,
    FOREIGN KEY (damage_prediction_id, building_id)
        REFERENCES building_damage_predictions(id, building_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE building_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_assessment_id BIGINT UNSIGNED NOT NULL,
    building_id BIGINT UNSIGNED NOT NULL,
    damage_prediction_id BIGINT UNSIGNED NULL,
    verified_damage_class_id BIGINT UNSIGNED NOT NULL,
    location_verified BOOLEAN NOT NULL DEFAULT 0,
    household_link_verified BOOLEAN NOT NULL DEFAULT 0,
    verification_notes TEXT NULL,
    verified_by BIGINT UNSIGNED NOT NULL,
    verified_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (field_assessment_id, building_id),
    UNIQUE (id, building_id),

    FOREIGN KEY (field_assessment_id)
        REFERENCES field_assessments(id) ON DELETE RESTRICT,
    FOREIGN KEY (building_id)
        REFERENCES buildings(id) ON DELETE RESTRICT,
    FOREIGN KEY (damage_prediction_id, building_id)
        REFERENCES building_damage_predictions(id, building_id)
        ON DELETE RESTRICT,
    FOREIGN KEY (verified_damage_class_id)
        REFERENCES damage_classes(id) ON DELETE RESTRICT,
    FOREIGN KEY (verified_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (location_verified IN (0, 1)),
    CHECK (household_link_verified IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE assessment_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    disaster_incident_id BIGINT UNSIGNED NOT NULL,
    selected_by BIGINT UNSIGNED NOT NULL,
    area_name VARCHAR(191) NOT NULL,
    polygon_geometry MULTIPOLYGON NOT NULL SRID 4326,
    spatial_rule VARCHAR(24) NOT NULL DEFAULT 'includes_boundary',
    selected_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (id, disaster_incident_id),
    SPATIAL INDEX (polygon_geometry),

    FOREIGN KEY (disaster_incident_id)
        REFERENCES disaster_incidents(id) ON DELETE RESTRICT,
    FOREIGN KEY (selected_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (spatial_rule IN ('includes_boundary', 'strict_interior'))
) ENGINE=InnoDB;

CREATE TABLE resident_impact_estimates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_area_id BIGINT UNSIGNED NOT NULL,
    disaster_incident_id BIGINT UNSIGNED NOT NULL,
    generated_by BIGINT UNSIGNED NOT NULL,
    estimated_buildings BIGINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_households BIGINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_residents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unique_vulnerable_residents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    estimation_method VARCHAR(32) NOT NULL,
    criteria JSON NOT NULL,
    polygon_snapshot MULTIPOLYGON NOT NULL SRID 4326,
    spatial_rule VARCHAR(24) NOT NULL,
    exclusions JSON NOT NULL,
    record_snapshot_at DATETIME(6) NOT NULL,
    generated_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (id, disaster_incident_id),

    FOREIGN KEY (assessment_area_id, disaster_incident_id)
        REFERENCES assessment_areas(id, disaster_incident_id)
        ON DELETE RESTRICT,
    FOREIGN KEY (generated_by)
        REFERENCES users(id) ON DELETE RESTRICT,

    CHECK (
        estimation_method IN (
            'area_population', 'verified_damaged_buildings'
        )
    ),
    CHECK (spatial_rule IN ('includes_boundary', 'strict_interior'))
) ENGINE=InnoDB;

CREATE TABLE impact_estimate_buildings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_impact_estimate_id BIGINT UNSIGNED NOT NULL,
    building_id BIGINT UNSIGNED NOT NULL,
    building_verification_id BIGINT UNSIGNED NULL,
    point_snapshot POINT NOT NULL SRID 4326,
    damage_class_code_snapshot VARCHAR(64) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (resident_impact_estimate_id, building_id),

    FOREIGN KEY (resident_impact_estimate_id)
        REFERENCES resident_impact_estimates(id) ON DELETE RESTRICT,
    FOREIGN KEY (building_id)
        REFERENCES buildings(id) ON DELETE RESTRICT,
    FOREIGN KEY (building_verification_id, building_id)
        REFERENCES building_verifications(id, building_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE impact_estimate_households (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_impact_estimate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    building_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (resident_impact_estimate_id, household_id),

    FOREIGN KEY (household_id)
        REFERENCES households(id) ON DELETE RESTRICT,
    FOREIGN KEY (resident_impact_estimate_id, building_id)
        REFERENCES impact_estimate_buildings(
            resident_impact_estimate_id, building_id
        ) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE impact_estimate_residents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_impact_estimate_id BIGINT UNSIGNED NOT NULL,
    resident_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (resident_impact_estimate_id, resident_id),

    FOREIGN KEY (resident_id)
        REFERENCES residents(id) ON DELETE RESTRICT,
    FOREIGN KEY (resident_impact_estimate_id, household_id)
        REFERENCES impact_estimate_households(
            resident_impact_estimate_id, household_id
        ) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE impact_estimate_vulnerabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_impact_estimate_id BIGINT UNSIGNED NOT NULL,
    resident_id BIGINT UNSIGNED NOT NULL,
    vulnerability_category_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (
        resident_impact_estimate_id,
        resident_id,
        vulnerability_category_id
    ),

    FOREIGN KEY (resident_impact_estimate_id, resident_id)
        REFERENCES impact_estimate_residents(
            resident_impact_estimate_id, resident_id
        ) ON DELETE RESTRICT,
    FOREIGN KEY (vulnerability_category_id)
        REFERENCES vulnerability_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE resident_impact_vulnerability_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_impact_estimate_id BIGINT UNSIGNED NOT NULL,
    vulnerability_category_id BIGINT UNSIGNED NOT NULL,
    resident_count BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (resident_impact_estimate_id, vulnerability_category_id),

    FOREIGN KEY (resident_impact_estimate_id)
        REFERENCES resident_impact_estimates(id) ON DELETE RESTRICT,
    FOREIGN KEY (vulnerability_category_id)
        REFERENCES vulnerability_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE consolidated_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_reference VARCHAR(191) NOT NULL UNIQUE,
    disaster_incident_id BIGINT UNSIGNED NOT NULL,
    prepared_by BIGINT UNSIGNED NOT NULL,
    resident_impact_estimate_id BIGINT UNSIGNED NULL,
    report_summary TEXT NULL,
    report_status VARCHAR(16) NOT NULL DEFAULT 'draft',
    signed_at DATETIME(6) NULL,
    finalized_at DATETIME(6) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (disaster_incident_id)
        REFERENCES disaster_incidents(id) ON DELETE RESTRICT,
    FOREIGN KEY (prepared_by)
        REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (resident_impact_estimate_id, disaster_incident_id)
        REFERENCES resident_impact_estimates(id, disaster_incident_id)
        ON DELETE RESTRICT,

    CHECK (report_status IN ('draft', 'review', 'signed', 'finalized'))
) ENGINE=InnoDB;

CREATE TABLE consolidated_report_assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consolidated_report_id BIGINT UNSIGNED NOT NULL,
    field_assessment_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (consolidated_report_id, field_assessment_id),

    FOREIGN KEY (consolidated_report_id)
        REFERENCES consolidated_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (field_assessment_id)
        REFERENCES field_assessments(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE report_signatures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signer_user_id BIGINT UNSIGNED NOT NULL,
    barangay_report_id BIGINT UNSIGNED NULL,
    field_assessment_id BIGINT UNSIGNED NULL,
    consolidated_report_id BIGINT UNSIGNED NULL,
    signature_reference VARCHAR(512) NOT NULL,
    document_reference VARCHAR(512) NOT NULL,
    document_hash CHAR(64) NOT NULL,
    mechanism VARCHAR(32) NOT NULL DEFAULT 'authenticated_e_signature',
    signed_at DATETIME(6) NOT NULL,
    verification_status VARCHAR(16) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE (barangay_report_id),
    UNIQUE (field_assessment_id),
    UNIQUE (consolidated_report_id),

    FOREIGN KEY (signer_user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (barangay_report_id)
        REFERENCES barangay_reports(id) ON DELETE RESTRICT,
    FOREIGN KEY (field_assessment_id)
        REFERENCES field_assessments(id) ON DELETE RESTRICT,
    FOREIGN KEY (consolidated_report_id)
        REFERENCES consolidated_reports(id) ON DELETE RESTRICT,

    CHECK (
        (barangay_report_id IS NOT NULL)
        + (field_assessment_id IS NOT NULL)
        + (consolidated_report_id IS NOT NULL) = 1
    ),
    CHECK (verification_status IN ('pending', 'verified', 'rejected'))
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(64) NOT NULL,
    record_type VARCHAR(100) NULL,
    record_id BIGINT UNSIGNED NULL,
    description VARCHAR(512) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_activity_record (record_type, record_id),
    INDEX idx_activity_created_at (created_at),

    FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO roles (
    name,
    code,
    description,
    created_at,
    updated_at
)
VALUES
(
    'Resident',
    'resident',
    'Registered barangay resident',
    NOW(),
    NOW()
),
(
    'BDRRM Personnel',
    'bdrrm',
    'Barangay disaster assessment personnel',
    NOW(),
    NOW()
),
(
    'RDANA Assessor',
    'rdana',
    'Rapid damage assessment and needs analysis assessor',
    NOW(),
    NOW()
),
(
    'CDRRMO Administrator',
    'cdrrmo_admin',
    'Designated city disaster management administrator',
    NOW(),
    NOW()
);