-- Component-level maintenance for the 3D Predictive Maintenance page.
-- Idempotent and additive: it creates new tables only and never alters or
-- deletes existing vehicle, service-history, prediction or evidence data.
-- Equivalent to migration 009_create_component_maintenance.php; run one or
-- the other (safe to run in phpMyAdmin against car_rental_db).

-- Catalog of inspectable components. The key is the stable identifier the
-- React 3D rigs use to find the matching mesh / anchor. body_types lists the
-- 3D rig body types that physically model the component.
CREATE TABLE IF NOT EXISTS maintenance_component_types (
    component_key VARCHAR(40) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    body_types VARCHAR(120) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (component_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO maintenance_component_types (component_key, display_name, body_types, sort_order) VALUES
    ('engine',           'Engine',                'sedan,suv,pickup,van,minivan,motorcycle', 10),
    ('battery',          'Battery',               'sedan,suv,pickup,van,minivan,motorcycle', 20),
    ('cooling',          'Cooling system',        'sedan,suv,pickup,van,minivan',            30),
    ('transmission',     'Transmission',          'sedan,suv,pickup,van,minivan',            40),
    ('final-drive',      'Chain / final drive',   'motorcycle',                              45),
    ('brakes-front',     'Front brakes',          'sedan,suv,pickup,van,minivan,motorcycle', 50),
    ('brakes-rear',      'Rear brakes',           'sedan,suv,pickup,van,minivan,motorcycle', 60),
    ('tire-front-left',  'Front-left tire',       'sedan,suv,pickup,van,minivan',            70),
    ('tire-front-right', 'Front-right tire',      'sedan,suv,pickup,van,minivan',            71),
    ('tire-rear-left',   'Rear-left tire',        'sedan,suv,pickup,van,minivan',            72),
    ('tire-rear-right',  'Rear-right tire',       'sedan,suv,pickup,van,minivan',            73),
    ('tire-front',       'Front tire',            'motorcycle',                              74),
    ('tire-rear',        'Rear tire',             'motorcycle',                              75),
    ('steering',         'Steering system',       'sedan,suv,pickup,van,minivan',            78),
    ('suspension',       'Suspension',            'sedan,suv,pickup,van,minivan,motorcycle', 80),
    ('exhaust',          'Exhaust system',        'sedan,suv,pickup,van,minivan,motorcycle', 90),
    ('lighting',         'Lighting',              'sedan,suv,pickup,van,minivan,motorcycle', 100);

-- Append-only inspection / service history. Rows are never updated; a newer
-- inspection supersedes an older one for status purposes.
CREATE TABLE IF NOT EXISTS vehicle_component_inspections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id INT UNSIGNED NOT NULL,
    component_key VARCHAR(40) NOT NULL,
    inspected_on DATE NOT NULL,
    odometer_km INT UNSIGNED NULL,
    condition_rating ENUM('good', 'needs_attention', 'critical', 'not_assessed') NOT NULL,
    reported_symptoms TEXT NULL,
    dtc_codes VARCHAR(255) NULL,
    findings TEXT NULL,
    confirmed_fault VARCHAR(255) NULL,
    service_performed TINYINT(1) NOT NULL DEFAULT 0,
    service_date DATE NULL,
    work_performed TEXT NULL,
    parts_replaced TEXT NULL,
    recommended_action VARCHAR(255) NULL,
    next_due_date DATE NULL,
    next_due_km INT UNSIGNED NULL,
    technician VARCHAR(120) NULL,
    recorded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY component_inspection_lookup_idx (vehicle_id, component_key, inspected_on),
    CONSTRAINT component_inspection_vehicle_fk FOREIGN KEY (vehicle_id)
        REFERENCES cars(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT component_inspection_type_fk FOREIGN KEY (component_key)
        REFERENCES maintenance_component_types(component_key) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT component_inspection_user_fk FOREIGN KEY (recorded_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Administrator-configured service intervals. No rows are seeded: intervals
-- must come from the manufacturer schedule or fleet policy named in `basis`.
-- A rule targets either one vehicle (vehicle_id) or a whole body type.
CREATE TABLE IF NOT EXISTS maintenance_service_intervals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    component_key VARCHAR(40) NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    body_type VARCHAR(20) NULL,
    interval_days SMALLINT UNSIGNED NULL,
    interval_km INT UNSIGNED NULL,
    due_soon_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    due_soon_km INT UNSIGNED NOT NULL DEFAULT 1000,
    basis VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY service_interval_lookup_idx (component_key, vehicle_id, body_type),
    CONSTRAINT service_interval_type_fk FOREIGN KEY (component_key)
        REFERENCES maintenance_component_types(component_key) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT service_interval_vehicle_fk FOREIGN KEY (vehicle_id)
        REFERENCES cars(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT service_interval_user_fk FOREIGN KEY (updated_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
