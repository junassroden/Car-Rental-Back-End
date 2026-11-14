<?php

/**
 * Email verification and single-use account tokens.
 *
 * - users.email_verified_at: NULL until the owner proves the address. Password sign-in is
 *   refused until then, and Google sign-in never links to an unverified account.
 *   Existing administrators and Google-linked users are marked verified (Google confirmed
 *   the address); existing password-only customers must verify at their next sign-in.
 * - users.has_password: 0 for accounts created by Google sign-in, whose stored hash is a
 *   random value nobody knows. Such accounts set a password through the reset flow.
 * - account_tokens: hashed, expiring, single-use tokens for email verification and password
 *   reset. Only the SHA-256 of a token is stored.
 */
class Add_email_verification
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->addColumnIfMissing('users', 'email_verified_at', 'DATETIME NULL AFTER email');
        $this->addColumnIfMissing('users', 'has_password', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER password');

        $this->_lava->db->raw(
            "UPDATE users SET email_verified_at = NOW()
             WHERE email_verified_at IS NULL AND (role = 'admin' OR google_sub IS NOT NULL)"
        );

        // Unverified accounts must verify at their next sign-in, so their saved sessions end now
        // instead of running on for up to the refresh-token lifetime (7 days).
        $this->_lava->db->raw(
            'DELETE refresh_tokens FROM refresh_tokens
             INNER JOIN users ON users.id = refresh_tokens.user_id
             WHERE users.email_verified_at IS NULL'
        );

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS account_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(30) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY account_tokens_hash_unique (token_hash),
                KEY account_tokens_user_purpose_idx (user_id, purpose),
                CONSTRAINT account_tokens_user_fk
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        // Intentionally non-destructive: verification state is kept.
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
}
