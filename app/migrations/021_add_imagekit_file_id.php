<?php

class Add_imagekit_file_id
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
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, 'car_images', 'imagekit_file_id']
        )->fetchColumn();

        if (!(int) $exists) {
            $this->_lava->db->raw('ALTER TABLE car_images ADD COLUMN imagekit_file_id VARCHAR(100) NULL');
        }
    }

    public function down()
    {
        // Intentionally non-destructive so an accidental rollback cannot break image cleanup.
    }
}
