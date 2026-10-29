<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ProfileController extends Controller
{
    const FIELDS = [
        'full_name|Full name'                       => 'required|max_length[150]',
        'phone|Phone number'                        => 'required|min_length[7]|max_length[30]|pattern[tel]',
        'address_line|Street address'               => 'required|max_length[255]',
        'city|City or municipality'                 => 'required|max_length[100]',
        'province|Province or region'               => 'required|max_length[100]',
        'postal_code|Postal code'                   => 'required|max_length[20]',
        'country|Country'                           => 'required|max_length[100]',
        'driver_license_number|Driver’s licence number' => 'required|max_length[100]',
        'driver_license_expiry|Licence expiry date' => 'required|pattern[date_ymd]',
    ];

    // Column names from FIELDS ('phone|Phone number' => 'phone').
    const COLUMNS = [
        'full_name', 'phone', 'address_line', 'city', 'province',
        'postal_code', 'country', 'driver_license_number', 'driver_license_expiry',
    ];

    /** Signed-in customer row. */
    private $user;

    /** Private storage outside the public web root. */
    private $photoDirectory;

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->photoDirectory = ROOT_DIR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'user-verification';

        // Every endpoint here is for customers only.
        $this->user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$this->user || (int) $this->user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }
        if ($this->user['role'] === 'admin') {
            $this->response->send_json_error('Customer access is required.', 403);
            exit;
        }
    }

    public function show()
    {
        $this->response->send_json_success($this->profileData($this->user), 'Profile retrieved successfully.');
    }

    public function update()
    {
        $user  = $this->user;
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();

        // Form_validation reads $_POST, so a JSON body is placed there first.
        $_POST = is_array($input) ? $input : [];
        $this->call->library('form_validation');
        if (!$this->form_validation->validate(self::FIELDS)) {
            $errors = $this->form_validation->get_errors();
            $this->response->send_json_error($errors[0], 422, ['errors' => $errors]);
            return;
        }

        $values = [];
        foreach (self::COLUMNS as $column) {
            $values[$column] = trim((string) $this->request->post($column));
        }

        // Rejects impossible dates such as 2026-02-31.
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $values['driver_license_expiry']);
        if (!$expiry || $expiry->format('Y-m-d') !== $values['driver_license_expiry']) {
            $this->response->send_json_error('Enter a valid driver’s licence expiry date.', 422);
            return;
        }
        if ($values['driver_license_expiry'] < date('Y-m-d')) {
            $this->response->send_json_error('The driver’s licence must not be expired.', 422);
            return;
        }
        if ($this->request->post('verification_consent') !== 'yes') {
            $this->response->send_json_error('Confirm that the photo shows you holding your driver’s licence before saving.', 422);
            return;
        }

        $hasNewPhoto = $this->request->has_file('verification_photo');
        if (!$hasNewPhoto && !$this->hasVerificationPhoto($user)) {
            $this->response->send_json_error('Upload a photo of yourself holding your driver’s licence.', 422);
            return;
        }

        $filename = null;
        if ($hasNewPhoto) {
            $this->call->library('upload', $this->request->file('verification_photo'));
            $this->upload->set_dir($this->photoDirectory)
                ->max_size(5)
                ->allowed_extensions(['jpg', 'jpeg', 'png', 'webp'])
                ->allowed_mimes(['image/jpeg', 'image/png', 'image/webp'])
                ->is_image()
                ->encrypt_name();

            if (!$this->upload->do_upload()) {
                $this->response->send_json_error(
                    'The profile photo must be a valid JPEG, PNG, or WebP image no larger than 5 MB.',
                    422,
                    ['errors' => $this->upload->get_errors()]
                );
                return;
            }
            $filename = $this->upload->get_filename();
        }

        try {
            $updated = $this->UserModel->update_profile((int) $user['id'], $values, $filename);
        } catch (Exception $exception) {
            if ($filename !== null) {
                @unlink($this->photoDirectory . DIRECTORY_SEPARATOR . $filename);
            }
            error_log('Customer profile update failed: ' . $exception->getMessage());
            $this->response->send_json_error('The profile could not be saved. Please try again.', 500);
            return;
        }

        // The new photo is saved, so the one it replaced can go.
        if ($filename !== null && !empty($user['verification_photo_filename'])) {
            @unlink($this->photoDirectory . DIRECTORY_SEPARATOR . basename($user['verification_photo_filename']));
        }

        $this->response->send_json_success($this->profileData($updated), 'Profile saved successfully.');
    }

    public function verificationPhoto()
    {
        if (!$this->hasVerificationPhoto($this->user)) {
            $this->response->send_json_error('No profile verification photo was found.', 404);
            return;
        }

        $path = $this->photoDirectory . DIRECTORY_SEPARATOR . basename($this->user['verification_photo_filename']);

        $this->response->set_status_code(200);
        $this->response->content_type(mime_content_type($path), null);
        $this->response->add_header('Content-Length', filesize($path));
        $this->response->add_header('Cache-Control', 'private, no-store, max-age=0');
        $this->response->send_headers();
        readfile($path);
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private function profileData(array $user)
    {
        $profile = [
            'id'        => (int) $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'] ?? $user['username'],
            'email'     => $user['email'],
            'role'      => $user['role'],
        ];
        foreach (self::COLUMNS as $column) {
            $profile[$column] = $user[$column] ?? '';
        }

        $profile['has_verification_photo']     = $this->hasVerificationPhoto($user);
        $profile['verification_consent_given'] = !empty($user['verification_consent_at']);

        return $profile;
    }

    private function hasVerificationPhoto(array $user)
    {
        $filename = basename((string) ($user['verification_photo_filename'] ?? ''));
        return $filename !== '' && is_file($this->photoDirectory . DIRECTORY_SEPARATOR . $filename);
    }
}
