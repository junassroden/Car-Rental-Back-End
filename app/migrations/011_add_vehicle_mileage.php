<?php

class Add_vehicle_mileage
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
        $column = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, 'cars', 'current_mileage']
        )->fetchColumn();

        if (!(int) $column) {
            $this->_lava->db->raw(
                'ALTER TABLE cars ADD COLUMN current_mileage INT UNSIGNED NULL AFTER status'
            );
        }
    }

    public function down()
    {
        // Keep vehicle mileage data; this migration is intentionally non-destructive.
    }
}
