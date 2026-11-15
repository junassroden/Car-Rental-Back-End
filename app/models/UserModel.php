<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UserModel extends Model
{
    protected $table       = 'users';
    protected $primary_key = 'id';
    protected $fillable    = [
        'username', 'full_name', 'email', 'password', 'role', 'is_active',
        'google_sub',
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

    /** Creates a customer with a unique username taken from the email address. Returns the new ID. */
    public function create_customer($full_name, $email, $password)
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
