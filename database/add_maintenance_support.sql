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
