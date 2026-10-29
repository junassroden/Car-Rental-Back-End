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

CREATE TABLE IF NOT EXISTS car_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    car_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY car_images_car_id_idx (car_id),
    CONSTRAINT car_images_car_fk
        FOREIGN KEY (car_id) REFERENCES cars(id)
        ON DELETE CASCADE ON UPDATE CASCADE
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

CREATE TABLE IF NOT EXISTS maintenance_data_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id INT UNSIGNED NOT NULL,
    vehicle_type VARCHAR(50) NULL,
    vehicle_make VARCHAR(100) NULL,
    vehicle_model VARCHAR(100) NULL,
    vehicle_year SMALLINT UNSIGNED NULL,
    engine_details VARCHAR(500) NULL,
    record_type ENUM(
        'vehicle_inspection', 'sensor_measurement', 'diagnostic_scan',
        'diagnostic_trouble_code', 'suspected_fault', 'mechanic_confirmed_fault',
        'repair_performed', 'component_replaced', 'follow_up_inspection',
        'preventive_maintenance'
    ) NOT NULL,
    recorded_at DATETIME NOT NULL,
    odometer_reading BIGINT UNSIGNED NULL,
    odometer_unit ENUM('km', 'mi') NULL,
    component VARCHAR(160) NULL,
    component_identifier VARCHAR(160) NULL,
    symptoms TEXT NULL,
    inspection_findings TEXT NULL,
    dtc_codes TEXT NULL,
    sensor_measurements TEXT NULL,
    suspected_cause TEXT NULL,
    confirmed_cause TEXT NULL,
    diagnostic_tests TEXT NULL,
    repair_action TEXT NULL,
    replaced_components TEXT NULL,
    repair_outcome TEXT NULL,
    technician_reference VARCHAR(160) NULL,
    source VARCHAR(160) NOT NULL,
    evidence_reference TEXT NULL,
    verification_status ENUM(
        'draft', 'awaiting_verification', 'verified', 'rejected', 'superseded'
    ) NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    superseded_by_id BIGINT UNSIGNED NULL,
    idempotency_key CHAR(36) NULL,
    idempotency_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY maintenance_data_idempotency_unique (idempotency_key),
    KEY maintenance_data_vehicle_time_idx (vehicle_id, recorded_at),
    KEY maintenance_data_type_status_idx (record_type, verification_status),
    KEY maintenance_data_component_idx (component),
    KEY maintenance_data_component_identifier_idx (component_identifier),
    KEY maintenance_data_status_time_idx (verification_status, recorded_at),
    CONSTRAINT maintenance_data_vehicle_fk FOREIGN KEY (vehicle_id)
        REFERENCES cars(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT maintenance_data_creator_fk FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT maintenance_data_verifier_fk FOREIGN KEY (verified_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT maintenance_data_superseded_fk FOREIGN KEY (superseded_by_id)
        REFERENCES maintenance_data_records(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_data_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    maintenance_record_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    event_type VARCHAR(32) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NULL,
    reason TEXT NULL,
    before_snapshot LONGTEXT NULL,
    after_snapshot LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY maintenance_audit_record_time_idx (maintenance_record_id, created_at),
    CONSTRAINT maintenance_audit_record_fk FOREIGN KEY (maintenance_record_id)
        REFERENCES maintenance_data_records(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT maintenance_audit_actor_fk FOREIGN KEY (actor_user_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
