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

    public static function email_verified(array $user)
    {
        return !empty($user['email_verified_at']);
    }

    public function mark_email_verified($id)
    {
        return $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where_null('email_verified_at')
            ->update(['email_verified_at' => date('Y-m-d H:i:s')]);
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
