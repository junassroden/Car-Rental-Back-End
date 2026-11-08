<?php

/**
 * Creates the maintenance log, tblvehicle_maintenance, which MaintenanceModel and
 * VehicleAssessmentModel read. It predates the migrations and was only created by
 * database/schema.sql and database/add_maintenance_support.sql, so a database built
 * from migrations alone did not have it and every assessment analysis failed.
 *
 * Same definition as schema.sql. IF NOT EXISTS leaves an existing table and its rows untouched.
 */
class Create_vehicle_maintenance_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        // Keep the maintenance log; this migration is intentionally non-destructive.
    }
}
