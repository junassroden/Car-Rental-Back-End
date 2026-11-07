CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration INT NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY migration_unique (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    full_name VARCHAR(150) NULL,
    email VARCHAR(255) NOT NULL,
    google_sub VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    address_line VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(100) NULL,
    driver_license_number VARCHAR(100) NULL,
    driver_license_expiry DATE NULL,
    verification_photo_filename VARCHAR(80) NULL,
    verification_consent_at DATETIME NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'customer') NOT NULL DEFAULT 'customer',
    is_active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY username_unique (username),
    UNIQUE KEY email_unique (email),
    UNIQUE KEY users_google_sub_unique (google_sub),
    KEY email_idx (email),
    KEY role_idx (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    jti TEXT NOT NULL,
    PRIMARY KEY (id),
    KEY user_id_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cars (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    make VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    plate_number VARCHAR(40) NULL,
    color VARCHAR(50) NULL,
    description TEXT NULL,
    daily_rental_price DECIMAL(10,2) NOT NULL,
    security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
    minimum_rental_duration SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    transmission VARCHAR(40) NOT NULL DEFAULT 'Automatic',
    fuel_type VARCHAR(40) NOT NULL DEFAULT 'Gasoline',
    seating_capacity TINYINT UNSIGNED NOT NULL DEFAULT 5,
    air_conditioning TINYINT(1) NOT NULL DEFAULT 0,
    gps_navigation TINYINT(1) NOT NULL DEFAULT 0,
    additional_features JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    primary_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY cars_plate_number_unique (plate_number),
    KEY cars_status_idx (status),
    KEY cars_category_idx (category),
    KEY cars_created_at_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS car_color_variants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    car_id INT UNSIGNED NOT NULL,
    color VARCHAR(50) NOT NULL,
    stock SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY car_color_variants_car_color_unique (car_id, color),
    CONSTRAINT car_color_variants_car_fk
        FOREIGN KEY (car_id) REFERENCES cars(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS car_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    car_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    variant_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY car_images_car_id_idx (car_id),
    CONSTRAINT car_images_car_fk
        FOREIGN KEY (car_id) REFERENCES cars(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT car_images_variant_fk
        FOREIGN KEY (variant_id) REFERENCES car_color_variants(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    car_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    rental_days SMALLINT UNSIGNED NOT NULL,
    daily_rate DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    special_duration_request TINYINT(1) NOT NULL DEFAULT 0,
    contract_text MEDIUMTEXT NULL,
    contract_version VARCHAR(20) NULL,
    contract_accepted_at DATETIME NULL,
    payment_required TINYINT(1) NOT NULL DEFAULT 0,
    checked_out_at DATETIME NULL,
    returned_at DATETIME NULL,
    variant_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY reservations_user_created_idx (user_id, created_at),
    KEY reservations_car_period_status_idx (car_id, start_date, end_date, status),
    CONSTRAINT reservations_user_fk
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT reservations_car_fk
        FOREIGN KEY (car_id) REFERENCES cars(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT reservations_variant_fk
        FOREIGN KEY (variant_id) REFERENCES car_color_variants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkout_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    checkout_session_id VARCHAR(100) NOT NULL,
    checkout_url VARCHAR(500) NOT NULL,
    reference_number VARCHAR(80) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    webhook_event_hash CHAR(64) NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY checkout_payments_reservation_unique (reservation_id),
    UNIQUE KEY checkout_payments_session_unique (checkout_session_id),
    UNIQUE KEY checkout_payments_event_hash_unique (webhook_event_hash),
    KEY checkout_payments_reservation_idx (reservation_id, id),
    CONSTRAINT checkout_payments_reservation_fk
        FOREIGN KEY (reservation_id) REFERENCES reservations(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cars
    ADD COLUMN IF NOT EXISTS current_mileage INT UNSIGNED NULL AFTER status;

CREATE TABLE IF NOT EXISTS tblvehicle_maintenance (
    maintenanceID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicleID INT UNSIGNED NOT NULL,
    maintenanceType VARCHAR(80) NOT NULL,
    description TEXT NULL,
    maintenanceDate DATE NULL,
    cost DECIMAL(10,2) NULL DEFAULT 0,
    maintenanceStatus VARCHAR(30) NOT NULL DEFAULT 'completed',
    nextMaintenanceDate DATE NULL,
    odometerReading INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (maintenanceID),
    KEY maintenance_vehicle_date_idx (vehicleID, maintenanceDate),
    CONSTRAINT tblvehicle_maintenance_car_fk
        FOREIGN KEY (vehicleID) REFERENCES cars(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblai_maintenance_predictions (
    predictionID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicleID INT UNSIGNED NOT NULL,
    predictedFor DATE NOT NULL,
    predictedMaintenanceType VARCHAR(80) NOT NULL,
    predictedIssue VARCHAR(255) NOT NULL,
    riskLevel VARCHAR(20) NOT NULL DEFAULT 'medium',
    recommendedAction TEXT NULL,
    predictionDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nextMaintenanceDate DATE NULL,
    confidenceScore DECIMAL(5,2) NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (predictionID),
    KEY maintenance_prediction_vehicle_idx (vehicleID, predictedFor),
    CONSTRAINT tblai_maintenance_predictions_car_fk
        FOREIGN KEY (vehicleID) REFERENCES cars(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicle assessments (migration 013). The cars profile columns and the air-conditioning
-- component row are added by database/vehicle_assessments.sql.
CREATE TABLE IF NOT EXISTS vehicle_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id INT UNSIGNED NOT NULL,
    idempotency_key CHAR(36) NULL,
    assessed_on DATE NOT NULL,
    odometer_km INT UNSIGNED NULL,
    previous_odometer_km INT UNSIGNED NULL,
    odometer_flag ENUM('none','missing','decrease') NOT NULL DEFAULT 'none',
    vehicle_snapshot JSON NOT NULL,
    assessor_id INT UNSIGNED NULL,
    assessor_name VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    catalog_version VARCHAR(40) NOT NULL,
    overall_screening ENUM('critical','attention','monitor','no_concerns_recorded','insufficient_data','not_assessed') NOT NULL,
    safety_warning_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    review_status ENUM('submitted','reviewed') NOT NULL DEFAULT 'submitted',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    ai_status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    ai_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ai_claimed_at DATETIME NULL,
    ai_last_error VARCHAR(255) NULL,
    next_assessment_due DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY vehicle_assessment_idempotency_unique (idempotency_key),
    KEY vehicle_assessment_lookup_idx (vehicle_id, assessed_on),
    KEY vehicle_assessment_due_idx (next_assessment_due),
    KEY vehicle_assessment_ai_idx (ai_status),
    CONSTRAINT vehicle_assessment_vehicle_fk FOREIGN KEY (vehicle_id) REFERENCES cars(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT vehicle_assessment_assessor_fk FOREIGN KEY (assessor_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT vehicle_assessment_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_components (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    category_key VARCHAR(40) NOT NULL,
    rating ENUM('excellent','good','fair','poor','critical','not_applicable','unknown','not_assessed') NOT NULL,
    rating_score TINYINT UNSIGNED NULL,
    answers JSON NOT NULL,
    symptoms JSON NOT NULL,
    measurements JSON NOT NULL,
    fault_codes JSON NOT NULL,
    last_maintenance_date DATE NULL,
    notes TEXT NULL,
    screening_status ENUM('critical','attention','monitor','no_concerns_recorded','insufficient_data','not_assessed','not_applicable') NOT NULL,
    data_sufficiency ENUM('sufficient','partial','insufficient','not_assessed','not_applicable') NOT NULL,
    missing_questions JSON NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY assessment_component_unique (assessment_id, category_key),
    KEY assessment_component_rating_idx (category_key, rating),
    CONSTRAINT assessment_component_assessment_fk FOREIGN KEY (assessment_id) REFERENCES vehicle_assessments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_findings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    category_key VARCHAR(40) NULL,
    rule_id VARCHAR(80) NOT NULL,
    source VARCHAR(40) NOT NULL,
    severity ENUM('critical','warning','advisory') NOT NULL,
    is_safety TINYINT(1) NOT NULL DEFAULT 0,
    observation VARCHAR(500) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    recommended_action VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY assessment_finding_lookup_idx (assessment_id, category_key),
    KEY assessment_finding_safety_idx (is_safety, severity),
    CONSTRAINT assessment_finding_assessment_fk FOREIGN KEY (assessment_id) REFERENCES vehicle_assessments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_ai_analyses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    attempt SMALLINT UNSIGNED NOT NULL,
    status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    processing_status VARCHAR(40) NULL,
    catalog_version VARCHAR(40) NULL,
    predictive_status VARCHAR(40) NULL,
    response_json JSON NULL,
    error_message VARCHAR(255) NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY assessment_ai_attempt_unique (assessment_id, attempt),
    CONSTRAINT assessment_ai_assessment_fk FOREIGN KEY (assessment_id) REFERENCES vehicle_assessments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_ai_findings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    analysis_id BIGINT UNSIGNED NOT NULL,
    assessment_id BIGINT UNSIGNED NOT NULL,
    category_key VARCHAR(40) NOT NULL,
    screening_status VARCHAR(40) NOT NULL,
    explanation TEXT NULL,
    recommended_action VARCHAR(500) NULL,
    data_sufficiency VARCHAR(20) NULL,
    identified_symptoms JSON NULL,
    identified_faults JSON NULL,
    diagnostic_guidance JSON NULL,
    evidence_used JSON NULL,
    ai_model VARCHAR(120) NULL,
    ai_model_version VARCHAR(120) NULL,
    confidence DECIMAL(5,4) NULL,
    prediction_horizon VARCHAR(60) NULL,
    predictive_status VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY assessment_ai_finding_unique (analysis_id, category_key),
    KEY assessment_ai_finding_lookup_idx (assessment_id, category_key),
    CONSTRAINT assessment_ai_finding_analysis_fk FOREIGN KEY (analysis_id) REFERENCES assessment_ai_analyses(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT assessment_ai_finding_assessment_fk FOREIGN KEY (assessment_id) REFERENCES vehicle_assessments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    category_key VARCHAR(40) NOT NULL,
    filename VARCHAR(80) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY assessment_evidence_lookup_idx (assessment_id, category_key),
    CONSTRAINT assessment_evidence_assessment_fk FOREIGN KEY (assessment_id) REFERENCES vehicle_assessments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT assessment_evidence_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_outcomes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    category_key VARCHAR(40) NOT NULL,
    component_key VARCHAR(40) NULL,
    finding_id BIGINT UNSIGNED NULL,
    original_recommendation VARCHAR(500) NOT NULL,
    recommended_at DATETIME NOT NULL,
    decision ENUM('completed','deferred','rejected') NOT NULL,
    mechanic_confirmed ENUM('confirmed','not_confirmed','not_checked') NOT NULL DEFAULT 'not_checked',
    result ENUM('pending','confirmed_failure','successful_repair','no_fault_found') NOT NULL DEFAULT 'pending',
    maintenance_performed TEXT NULL,
    parts_replaced TEXT NULL,
    failure_date DATE NULL,
    repair_date DATE NULL,
    mileage_km INT UNSIGNED NULL,
    mechanic_name VARCHAR(150) NULL,
    notes TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    verification_status ENUM('unverified','verified','rejected') NOT NULL DEFAULT 'unverified',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY assessment_outcome_lookup_idx (assessment_id, category_key),
    KEY assessment_outcome_vehicle_idx (vehicle_id, category_key),
    KEY assessment_outcome_training_idx (verification_status, result, category_key),
    CONSTRAINT assessment_outcome_assessment_fk FOREIGN KEY (assessment_id) REFERENCES vehicle_assessments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT assessment_outcome_vehicle_fk FOREIGN KEY (vehicle_id) REFERENCES cars(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT assessment_outcome_finding_fk FOREIGN KEY (finding_id) REFERENCES assessment_findings(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT assessment_outcome_recorder_fk FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT assessment_outcome_verifier_fk FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Training run history (migration 015).
CREATE TABLE IF NOT EXISTS model_training_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_key VARCHAR(40) NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    dataset_rows INT UNSIGNED NOT NULL DEFAULT 0,
    dataset_vehicles INT UNSIGNED NOT NULL DEFAULT 0,
    model_version VARCHAR(160) NULL,
    evaluation_status VARCHAR(40) NULL,
    error_message VARCHAR(255) NULL,
    started_by INT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY model_training_run_lookup_idx (category_key, started_at),
    CONSTRAINT model_training_run_user_fk FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
