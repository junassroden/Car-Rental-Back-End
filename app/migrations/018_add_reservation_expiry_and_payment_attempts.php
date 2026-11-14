<?php

/**
 * Unpaid reservations get an expiry time, and every checkout becomes its own payment attempt.
 *
 * - reservations.expires_at: when a pending (unpaid) reservation stops holding its dates.
 *   Existing pending rows get created_at + 60 minutes, so stale ones are released by the
 *   next `php lava reservations expire` run (and stop blocking availability at once).
 * - checkout_payments: the one-row-per-reservation unique key is dropped so a retry adds a
 *   new attempt instead of overwriting the previous invoice ID. A paid invoice can then
 *   always be matched, even after the customer opened a newer one.
 * - payment_webhook_events: every webhook received, for idempotency and as a payment trail.
 *
 * Non-destructive: no rows or columns are removed.
 */
class Add_reservation_expiry_and_payment_attempts
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->addColumnIfMissing('reservations', 'expires_at', 'DATETIME NULL AFTER status');
        $this->addIndexIfMissing('reservations', 'reservations_status_expires_idx', '(status, expires_at)');
        $this->_lava->db->raw(
            "UPDATE reservations SET expires_at = DATE_ADD(created_at, INTERVAL 60 MINUTE)
             WHERE status = 'pending' AND expires_at IS NULL"
        );

        $this->addColumnIfMissing('checkout_payments', 'provider', "VARCHAR(20) NOT NULL DEFAULT 'xendit' AFTER reservation_id");
        $this->addColumnIfMissing('checkout_payments', 'provider_invoice_id', 'VARCHAR(100) NULL AFTER checkout_session_id');
        $this->addColumnIfMissing('checkout_payments', 'currency', "CHAR(3) NOT NULL DEFAULT 'PHP' AFTER amount");
        $this->addColumnIfMissing('checkout_payments', 'expires_at', 'DATETIME NULL AFTER status');
        $this->addColumnIfMissing('checkout_payments', 'paid_amount', 'DECIMAL(10,2) NULL AFTER paid_at');
        $this->addColumnIfMissing('checkout_payments', 'review_status', 'VARCHAR(20) NULL');
        $this->addColumnIfMissing('checkout_payments', 'review_reason', 'VARCHAR(255) NULL');
        $this->addColumnIfMissing('checkout_payments', 'updated_at', 'DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
        $this->_lava->db->raw("UPDATE checkout_payments SET provider = 'paymongo' WHERE checkout_session_id LIKE 'cs\\_%'");
        $this->_lava->db->raw("UPDATE checkout_payments SET provider = 'manual' WHERE checkout_url = 'manual-gcash'");

        // The foreign key keeps using checkout_payments_reservation_idx (reservation_id, id).
        if ($this->indexExists('checkout_payments', 'checkout_payments_reservation_unique')) {
            $this->_lava->db->raw('ALTER TABLE checkout_payments DROP INDEX checkout_payments_reservation_unique');
        }
        $this->addIndexIfMissing('checkout_payments', 'checkout_payments_status_expires_idx', '(status, expires_at)');
        $this->addIndexIfMissing('checkout_payments', 'checkout_payments_review_idx', '(review_status)');

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS payment_webhook_events (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider VARCHAR(20) NOT NULL,
                event_hash CHAR(64) NOT NULL,
                external_id VARCHAR(100) NULL,
                event_status VARCHAR(30) NULL,
                payment_id INT UNSIGNED NULL,
                outcome VARCHAR(40) NOT NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY payment_webhook_events_hash_unique (provider, event_hash),
                KEY payment_webhook_events_payment_idx (payment_id),
                CONSTRAINT payment_webhook_events_payment_fk
                    FOREIGN KEY (payment_id) REFERENCES checkout_payments(id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        // Intentionally non-destructive: payment history and expiry times are kept.
    }

    private function addColumnIfMissing($table, $column, $definition)
    {
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchColumn();
        if (!(int) $exists) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function indexExists($table, $index)
    {
        return (bool) (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->fetchColumn();
    }

    private function addIndexIfMissing($table, $index, $columns)
    {
        if (!$this->indexExists($table, $index)) {
            $this->_lava->db->raw("ALTER TABLE {$table} ADD INDEX {$index} {$columns}");
        }
    }
}
