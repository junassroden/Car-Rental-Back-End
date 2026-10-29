<?php

class Create_maintenance_data_records
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->_lava->db->raw("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        // Keep evidence and audit history; this migration is intentionally non-destructive.
    }
}
