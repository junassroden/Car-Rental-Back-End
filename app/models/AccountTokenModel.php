<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Single-use, expiring tokens for email verification and password reset (account_tokens).
 * The plain token is only ever sent to the user; the table stores its SHA-256 hash.
 */
class AccountTokenModel extends Model
{
    protected $table = 'account_tokens';
    protected $primary_key = 'id';
    protected $fillable = ['user_id', 'purpose', 'token_hash', 'expires_at', 'used_at'];
    protected $timestamps = false;

    const PURPOSES = [
        'verify_email' => 86400,   // 24 hours
        'password_reset' => 3600,  // 1 hour
    ];

    /**
     * Issues a new token and invalidates any earlier unused token of the same purpose,
     * so only the most recent link works. Returns the plain token.
     */
    public function issue($user_id, $purpose)
    {
        if (!isset(self::PURPOSES[$purpose])) {
            throw new InvalidArgumentException('Unknown account token purpose.');
        }
        $now = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(32));

        $this->db->table($this->table)
            ->where('user_id', (int) $user_id)
            ->where('purpose', $purpose)
            ->where_null('used_at')
            ->update(['used_at' => $now]);

        $this->insert([
            'user_id' => (int) $user_id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::PURPOSES[$purpose]),
        ]);

        return $token;
    }

    /**
     * Uses a token once. Returns the user ID it belongs to, or NULL when the token is unknown,
     * expired, already used, or for another purpose. The row is locked so two concurrent
     * requests cannot both use it. Must run inside the caller's transaction.
     */
    public function consume($token, $purpose)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->db->table($this->table)
            ->where('token_hash', hash('sha256', $token))
            ->for_update()
            ->row_array();
        if (
            !$row
            || $row['purpose'] !== $purpose
            || $row['used_at'] !== null
            || strtotime($row['expires_at']) <= time()
        ) {
            return null;
        }
        $this->db->table($this->table)
            ->where('id', (int) $row['id'])
            ->update(['used_at' => date('Y-m-d H:i:s')]);

        return (int) $row['user_id'];
    }
}
