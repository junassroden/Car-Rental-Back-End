<?php

/**
 * Lets one vehicle listing offer several colours, each with its own stock count.
 * Photos and reservations can point at a colour. Vehicles with no colours keep
 * working as a single unit, so existing data does not need to change.
 */
class Add_car_color_variants
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $database = getenv('DB_NAME') ?: 'CaRen';

        $this->_lava->db->raw("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->addVariantColumn($database, 'car_images', 'car_images_variant_fk', 'SET NULL');
        $this->addVariantColumn($database, 'reservations', 'reservations_variant_fk', 'RESTRICT');
    }

    public function down()
    {
        // Kept intentionally non-destructive so a rollback cannot erase stock or booking data.
    }

    private function addVariantColumn($database, $table, $constraint, $onDelete)
    {
        $column = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, 'variant_id']
        )->fetchColumn();
        if (!(int) $column) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN variant_id INT UNSIGNED NULL");
        }

        $foreignKey = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$database, $table, $constraint]
        )->fetchColumn();
        if (!(int) $foreignKey) {
            $this->_lava->db->raw(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint}
                 FOREIGN KEY (variant_id) REFERENCES car_color_variants(id)
                 ON DELETE {$onDelete} ON UPDATE CASCADE"
            );
        }
    }
}
