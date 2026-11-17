<?php

class Add_agreement_email_tracking
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
            [$database, 'reservations', 'agreement_email_sent_at']
        )->fetchColumn();

        if (!(int) $exists) {
            $this->_lava->db->raw('ALTER TABLE reservations ADD COLUMN agreement_email_sent_at DATETIME NULL');
        }
    }

    public function down()
    {
        // Intentionally non-destructive to preserve reservation notification history.
    }
}
