<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UserModel extends Model
{
    protected $table       = 'users';
    protected $primary_key = 'id';
    protected $fillable    = [
        'username', 'full_name', 'email', 'email_verified_at', 'password', 'has_password',
        'role', 'is_active', 'google_sub',
        'phone', 'address_line', 'city', 'province', 'postal_code', 'country',
        'driver_license_number', 'driver_license_expiry',
        'verification_consent_at', 'verification_photo_filename', 'updated_at',
    ];

    // created_at uses the column default and updated_at is set in update_profile().
    protected $timestamps = false;

    /**
     * One row per customer for the administrator chat: name, sign-up date, whether the driver's licence and the
     * verification photo are on file (never the licence number, the photo or any contact detail), and booking
     * counts and the verified amount paid. Read-only.
     */
    public function chat_customers()
    {
        return $this->db->raw(
            "SELECT users.id, users.full_name, users.username, users.created_at, users.is_active,
                    (users.driver_license_number IS NOT NULL AND users.driver_license_number <> '') AS has_licence,
                    (users.driver_license_expiry IS NOT NULL AND users.driver_license_expiry < CURDATE()) AS licence_expired,
                    (users.verification_photo_filename IS NOT NULL AND users.verification_photo_filename <> '') AS has_photo,
                    COUNT(reservations.id) AS reservations,
                    COALESCE(SUM(reservations.status = 'completed'), 0) AS completed,
                    COALESCE(SUM(reservations.status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(reservations.status = 'expired'), 0) AS expired,
                    COALESCE(SUM(reservations.status IN ('pending', 'confirmed')), 0) AS active,
                    COALESCE(SUM(paid.amount), 0) AS paid_total
             FROM users
             LEFT JOIN reservations ON reservations.user_id = users.id
             LEFT JOIN (
                 SELECT reservation_id, SUM(COALESCE(paid_amount, amount)) AS amount
                 FROM checkout_payments WHERE status = 'paid' GROUP BY reservation_id
             ) paid ON paid.reservation_id = reservations.id
             WHERE users.role = 'customer'
             GROUP BY users.id
             ORDER BY users.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The active user with this ID, or NULL. */
    public function find_active($id)
    {
        $user = $this->find((int) $id);

        return $user && (int) $user['is_active'] === 1 ? $user : null;
    }

    /** A user matched by email or username (for login), or NULL. */
    public function find_by_identity($identity)
    {
        return $this->db->table($this->table)
            ->grouped(static function ($query) use ($identity) {
                $query->where('email', $identity)
                      ->or_where('username', $identity);
            })
            ->limit(1)
            ->row_array();
    }

    /** The user with exactly this email address (not username), or NULL. */
    public function find_by_email($email)
    {
        return $this->db->table($this->table)
            ->where('email', strtolower(trim((string) $email)))
            ->limit(1)
            ->row_array();
    }

    /** Sets a password the owner chose (password reset, or first password for a Google account). */
    public function set_password($id, $password)
    {
        return $this->update((int) $id, [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'has_password' => 1,
        ]);
    }

    /** Signs the user out everywhere by deleting every saved refresh token. */
    public function revoke_sessions($id)
    {
        return $this->db->table('refresh_tokens')->where('user_id', (int) $id)->delete();
    }

    public function email_exists($email)
    {
        return (bool) $this->exists(['email' => $email]);
    }

    public function find_by_google_sub($google_sub)
    {
        return $this->db->table($this->table)
            ->where('google_sub', $google_sub)
            ->limit(1)
            ->row_array();
    }

    /**
     * Creates a customer with a unique username taken from the email address. Returns the new ID.
     * A Google sign-up passes $email_verified (Google confirmed it) and no usable password.
     */
    public function create_customer($full_name, $email, $password, $email_verified = false, $has_password = true)
    {
        $base     = trim((string) preg_replace('/[^a-z0-9._-]+/i', '-', strstr($email, '@', true)), '.-_');
        $base     = substr($base !== '' ? $base : 'driver', 0, 100);
        $username = $base;

        for ($suffix = 2; $this->exists(['username' => $username]); $suffix++) {
            $username = substr($base, 0, 100 - strlen('-' . $suffix)) . '-' . $suffix;
        }

        return $this->insert([
            'username'  => $username,
            'full_name' => $full_name,
            'email'     => $email,
            'password'  => password_hash($password, PASSWORD_DEFAULT),
            'role'      => 'customer',
            'is_active' => 1,
            'email_verified_at' => $email_verified ? date('Y-m-d H:i:s') : null,
            'has_password' => $has_password ? 1 : 0,
        ]);
    }

    /**
     * Saves the profile fields in $values (and the new photo filename, if any)
     * and returns the fresh user row.
     */
    public function update_profile($id, array $values, $photo_filename = null)
    {
        $now  = date('Y-m-d H:i:s');
        $data = $values + [
            'verification_consent_at' => $now,
            'updated_at'              => $now,
        ];

        if ($photo_filename !== null) {
            $data['verification_photo_filename'] = $photo_filename;
        }

        $this->update((int) $id, $data);

        return $this->find((int) $id);
    }
}
