<?php

class Add_full_name_to_users
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $database = getenv('DB_NAME') ?: 'car_rental_db';
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, 'users', 'full_name']
        )->fetchColumn();
        if (!(int) $exists) {
            $this->_lava->db->raw('ALTER TABLE users ADD COLUMN full_name VARCHAR(150) NULL AFTER username');
        }
        $this->_lava->db->raw("UPDATE users SET full_name = username WHERE full_name IS NULL OR full_name = ''");
    }

    public function down()
    {
        // Intentionally non-destructive to preserve existing account data.
    }
}
