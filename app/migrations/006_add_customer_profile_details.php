<?php

class Add_customer_profile_details
{
    private $_lava;

    private const COLUMNS = [
        'phone' => 'VARCHAR(30) NULL',
        'address_line' => 'VARCHAR(255) NULL',
        'city' => 'VARCHAR(100) NULL',
        'province' => 'VARCHAR(100) NULL',
        'postal_code' => 'VARCHAR(20) NULL',
        'country' => 'VARCHAR(100) NULL',
        'driver_license_number' => 'VARCHAR(100) NULL',
        'driver_license_expiry' => 'DATE NULL',
        'verification_photo_filename' => 'VARCHAR(80) NULL',
        'verification_consent_at' => 'DATETIME NULL',
    ];

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $database = getenv('DB_NAME') ?: 'car_rental_db';
        foreach (self::COLUMNS as $column => $definition) {
            $exists = $this->_lava->db->raw(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$database, 'users', $column]
            )->fetchColumn();
            if (!(int) $exists) {
                $this->_lava->db->raw("ALTER TABLE users ADD COLUMN {$column} {$definition}");
            }
        }
    }

    public function down()
    {
        // Intentionally non-destructive to preserve customer profile data.
    }
}
