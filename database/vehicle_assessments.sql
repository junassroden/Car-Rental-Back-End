-- Vehicle assessments, vehicle profile fields and the Air Conditioning component.
-- Equivalent to migrations 013, 014 and 015 together; run one route or the other.
-- Additive and safe to re-run. Existing rows are preserved.

SET @add_column = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE cars ADD COLUMN acquisition_date DATE NULL AFTER current_mileage', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'acquisition_date'
);
PREPARE add_column FROM @add_column;
EXECUTE add_column;
DEALLOCATE PREPARE add_column;

SET @add_column = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE cars ADD COLUMN usage_frequency ENUM(''daily'',''several_per_week'',''weekly'',''occasional'') NULL AFTER acquisition_date', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'usage_frequency'
);
PREPARE add_column FROM @add_column;
EXECUTE add_column;
DEALLOCATE PREPARE add_column;

SET @add_column = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE cars ADD COLUMN previous_maintenance_date DATE NULL AFTER usage_frequency', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'previous_maintenance_date'
);
PREPARE add_column FROM @add_column;
EXECUTE add_column;
DEALLOCATE PREPARE add_column;

INSERT IGNORE INTO maintenance_component_types (component_key, display_name, body_types, sort_order)
VALUES ('air-conditioning', 'Air conditioning', 'sedan,suv,pickup,van,minivan', 35);

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

-- Predictive training support (migration 015).

SET @add_column = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE assessment_outcomes ADD COLUMN outcome_date DATE NULL AFTER repair_date', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_outcomes' AND COLUMN_NAME = 'outcome_date'
);
PREPARE add_column FROM @add_column;
EXECUTE add_column;
DEALLOCATE PREPARE add_column;

SET @add_column = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE assessment_ai_findings ADD COLUMN risk_level VARCHAR(20) NULL AFTER prediction_horizon', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_ai_findings' AND COLUMN_NAME = 'risk_level'
);
PREPARE add_column FROM @add_column;
EXECUTE add_column;
DEALLOCATE PREPARE add_column;

SET @add_column = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE assessment_ai_findings ADD COLUMN model_score DECIMAL(6,5) NULL AFTER risk_level', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_ai_findings' AND COLUMN_NAME = 'model_score'
);
PREPARE add_column FROM @add_column;
EXECUTE add_column;
DEALLOCATE PREPARE add_column;

SET @add_column = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE assessment_ai_findings ADD COLUMN prediction JSON NULL AFTER model_score', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_ai_findings' AND COLUMN_NAME = 'prediction'
);
PREPARE add_column FROM @add_column;
EXECUTE add_column;
DEALLOCATE PREPARE add_column;

UPDATE assessment_outcomes SET outcome_date = COALESCE(failure_date, repair_date) WHERE outcome_date IS NULL;

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
