-- Adds vehicle colours with per-colour stock. Same change as app/migrations/011_add_car_color_variants.php.
-- Back up the database first, then run this once against an existing DriveRent database.
-- Existing cars and reservations are not changed. Cars with no colours keep working as one unit.

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

ALTER TABLE car_images
    ADD COLUMN variant_id INT UNSIGNED NULL,
    ADD CONSTRAINT car_images_variant_fk
        FOREIGN KEY (variant_id) REFERENCES car_color_variants(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE reservations
    ADD COLUMN variant_id INT UNSIGNED NULL,
    ADD CONSTRAINT reservations_variant_fk
        FOREIGN KEY (variant_id) REFERENCES car_color_variants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Records the change so the app's migration runner does not repeat it.
INSERT IGNORE INTO migrations (migration) VALUES (11);
