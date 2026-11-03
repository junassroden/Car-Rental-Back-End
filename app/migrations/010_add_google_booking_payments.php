<?php

class Add_google_booking_payments
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

        $this->addColumnIfMissing($database, 'users', 'google_sub', 'VARCHAR(255) NULL');
        $googleIndex = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, 'users', 'users_google_sub_unique']
        )->fetchColumn();
        if (!(int) $googleIndex) {
            $this->_lava->db->raw('ALTER TABLE users ADD UNIQUE KEY users_google_sub_unique (google_sub)');
        }

        $this->addColumnIfMissing($database, 'reservations', 'special_duration_request', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($database, 'reservations', 'contract_text', 'MEDIUMTEXT NULL');
        $this->addColumnIfMissing($database, 'reservations', 'contract_version', "VARCHAR(20) NULL");
        $this->addColumnIfMissing($database, 'reservations', 'contract_accepted_at', 'DATETIME NULL');
        $this->addColumnIfMissing($database, 'reservations', 'payment_required', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($database, 'reservations', 'checked_out_at', 'DATETIME NULL');
        $this->addColumnIfMissing($database, 'reservations', 'returned_at', 'DATETIME NULL');

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS checkout_payments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reservation_id INT UNSIGNED NOT NULL,
                checkout_session_id VARCHAR(100) NOT NULL,
                checkout_url VARCHAR(500) NOT NULL,
                reference_number VARCHAR(80) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                webhook_event_hash CHAR(64) NULL,
                paid_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY checkout_payments_reservation_unique (reservation_id),
                UNIQUE KEY checkout_payments_session_unique (checkout_session_id),
                UNIQUE KEY checkout_payments_event_hash_unique (webhook_event_hash),
                KEY checkout_payments_reservation_idx (reservation_id, id),
                CONSTRAINT checkout_payments_reservation_fk
                    FOREIGN KEY (reservation_id) REFERENCES reservations(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function addColumnIfMissing($database, $table, $column, $definition)
    {
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column]
        )->fetchColumn();

        if (!(int) $exists) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    public function down()
    {
        // Intentionally non-destructive to preserve OAuth identities and booking records.
    }
}
