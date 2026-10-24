<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UserModel extends Model
{
    protected $table = 'users';

    public function __construct()
    {
        parent::__construct();
    }

    public function findByIdentity($identity)
    {
        return $this->db->table($this->table)
            ->grouped(static function ($query) use ($identity) {
                $query->where('email', $identity)
                    ->or_where('username', $identity);
            })
            ->limit(1)
            ->get();
    }

    public function findById($id)
    {
        return $this->db->table($this->table)
            ->where('id', (int) $id)
            ->limit(1)
            ->get();
    }

    public function emailExists($email)
    {
        return (bool) $this->db->table($this->table)
            ->select('id')
            ->where('email', $email)
            ->limit(1)
            ->get();
    }

    public function usernameExists($username)
    {
        return (bool) $this->db->table($this->table)
            ->select('id')
            ->where('username', $username)
            ->limit(1)
            ->get();
    }

    public function createCustomer($fullName, $email, $password)
    {
        $localPart = strstr($email, '@', true);
        $baseUsername = trim((string) preg_replace('/[^a-z0-9._-]+/i', '-', $localPart), '.-_');
        $baseUsername = substr($baseUsername !== '' ? $baseUsername : 'driver', 0, 100);
        $username = $baseUsername;
        for ($suffix = 2; $this->usernameExists($username); $suffix++) {
            $suffixText = '-' . $suffix;
            $username = substr($baseUsername, 0, 100 - strlen($suffixText)) . $suffixText;
        }

        return $this->db->table($this->table)->insert([
            'username' => $username,
            'full_name' => $fullName,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
            'is_active' => 1,
        ]);
    }

    public function updateProfile($id, array $values, $filename)
    {
        $data = [
            'full_name' => $values['full_name'],
            'phone' => $values['phone'],
            'address_line' => $values['address_line'],
            'city' => $values['city'],
            'province' => $values['province'],
            'postal_code' => $values['postal_code'],
            'country' => $values['country'],
            'driver_license_number' => $values['driver_license_number'],
            'driver_license_expiry' => $values['driver_license_expiry'],
            'verification_consent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($filename !== null) {
            $data['verification_photo_filename'] = $filename;
        }
        $this->db->table($this->table)
            ->where('id', (int) $id)
            ->update($data);

        return $this->findById($id);
    }

    public function activeRole($id)
    {
        return $this->db->table($this->table)
            ->select('role')
            ->where(['id' => (int) $id, 'is_active' => 1])
            ->limit(1)
            ->get();
    }
}
