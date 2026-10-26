<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiController extends Controller
{
    private const CAR_FIELDS = [
        'make',
        'model',
        'year',
        'category',
        'plate_number',
        'color',
        'description',
        'daily_rental_price',
        'security_deposit',
        'minimum_rental_duration',
        'transmission',
        'fuel_type',
        'seating_capacity',
        'air_conditioning',
        'gps_navigation',
        'additional_features',
        'status',
    ];

    public function before_action()
    {
        $this->carModel = $this->call->model('CarModel');
        $this->maintenanceModel = $this->call->model('MaintenanceModel');
        $this->userModel = $this->call->model('UserModel');
        $this->reservationModel = $this->call->model('ReservationModel');

        $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
        $allowedOrigins = array_values(array_unique(array_filter(array_map(static function ($value) {
            return rtrim(trim((string) $value), '/');
        }, preg_split('/\s*,\s*/', (string) (getenv('FRONTEND_ORIGIN') ?: 'http://localhost:5173,http://127.0.0.1:5173'))), static function ($value) {
            return $value !== '';
        })));
        if ($allowedOrigins === []) {
            $allowedOrigins = ['http://localhost:5173', 'http://127.0.0.1:5173'];
        }
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        } elseif ($origin !== '') {
            $this->response->send_json(['success' => false, 'message' => 'Origin is not allowed.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 600');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || $forwardedProto === 'https';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => $isHttps ? 'None' : 'Lax',
            ]);
            session_start();
        }
    }

    public function login()
    {
        $input = $this->input();
        $identity = trim((string) ($input['email'] ?? $input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($identity === '' || $password === '') {
            $this->response->send_json(['success' => false, 'message' => 'Email/username and password are required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $user = $this->userModel->findByIdentity($identity);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password'])) {
            $this->response->send_json(['success' => false, 'message' => 'Invalid login credentials.'], 401, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Login successful.', 'data' => $this->publicUser($user)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function register()
    {
        $input = $this->input();
        $fullName = is_string($input['full_name'] ?? null) ? trim($input['full_name']) : '';
        $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';

        if ($fullName === '' || strlen($fullName) > 150) {
            $this->response->send_json(['success' => false, 'message' => 'Full name is required and must be no longer than 150 characters.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->response->send_json(['success' => false, 'message' => 'Enter a valid email address.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->response->send_json(['success' => false, 'message' => 'Password must be between 8 and 72 characters.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if ($this->userModel->emailExists($email)) {
            $this->response->send_json(['success' => false, 'message' => 'An account with that email address already exists.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $this->userModel->createCustomer($fullName, $email, $password);
        } catch (Exception $exception) {
            if ($exception->getCode() === '23000') {
                $this->response->send_json(['success' => false, 'message' => 'An account could not be created because the email address or username is already in use.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            error_log('Account registration failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The account could not be created. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $this->response->send_json(['success' => true, 'message' => 'Account created successfully.'], 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function logout()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
        $this->response->send_json(['success' => true, 'message' => 'Logged out successfully.'], 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function me()
    {
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Authenticated user retrieved.', 'data' => $this->authenticatedUser()], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function profile()
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        $user = $this->authenticatedUserRecord();
        if ($this->isAdminRole($user['role'] ?? null)) {
            $this->response->send_json(['success' => false, 'message' => 'Customer profile access is required.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $this->response->send_json(array_filter(['success' => true, 'message' => 'Profile retrieved successfully.', 'data' => $this->profileData($user)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if ($method !== 'POST') {
            $this->response->send_json(['success' => false, 'message' => 'Only GET and POST requests are allowed.'], 405, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 6 * 1024 * 1024) {
            $this->response->send_json(['success' => false, 'message' => 'The profile photo must be no larger than 5 MB.'], 413, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $input = $this->input();
        $fields = [
            'full_name' => ['label' => 'Full name', 'max' => 150],
            'phone' => ['label' => 'Phone number', 'max' => 30],
            'address_line' => ['label' => 'Street address', 'max' => 255],
            'city' => ['label' => 'City or municipality', 'max' => 100],
            'province' => ['label' => 'Province or region', 'max' => 100],
            'postal_code' => ['label' => 'Postal code', 'max' => 20],
            'country' => ['label' => 'Country', 'max' => 100],
            'driver_license_number' => ['label' => 'Driver’s licence number', 'max' => 100],
            'driver_license_expiry' => ['label' => 'Licence expiry date', 'max' => 10],
        ];
        $values = [];
        foreach ($fields as $field => $rules) {
            $value = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            if ($value === '' || strlen($value) > $rules['max']) {
                $this->response->send_json(['success' => false, 'message' => $rules['label'] . ' is required and must be no longer than ' . $rules['max'] . ' characters.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $values[$field] = $value;
        }

        if (!preg_match('/^[+0-9().\-\s]{7,30}$/', $values['phone'])) {
            $this->response->send_json(['success' => false, 'message' => 'Enter a valid phone number.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $values['driver_license_expiry']);
        if (!$expiry || $expiry->format('Y-m-d') !== $values['driver_license_expiry']) {
            $this->response->send_json(['success' => false, 'message' => 'Enter a valid driver’s licence expiry date.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if ($expiry < new DateTimeImmutable('today')) {
            $this->response->send_json(['success' => false, 'message' => 'The driver’s licence must not be expired.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (($input['verification_consent'] ?? '') !== 'yes') {
            $this->response->send_json(['success' => false, 'message' => 'Confirm that the photo shows you holding your driver’s licence before saving.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $uploadedPhoto = $_FILES['verification_photo'] ?? null;
        $hasExistingPhoto = $this->hasVerificationPhoto($user);
        if (!$hasExistingPhoto && (!$uploadedPhoto || $uploadedPhoto['error'] !== UPLOAD_ERR_OK)) {
            $this->response->send_json(['success' => false, 'message' => 'Upload a photo of yourself holding your driver’s licence.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $newFilename = null;
        if ($uploadedPhoto && $uploadedPhoto['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedPhoto['error'] !== UPLOAD_ERR_OK || $uploadedPhoto['size'] > 5 * 1024 * 1024) {
                $this->response->send_json(['success' => false, 'message' => 'The profile photo must be a valid image no larger than 5 MB.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($uploadedPhoto['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime]) || @getimagesize($uploadedPhoto['tmp_name']) === false) {
                $this->response->send_json(['success' => false, 'message' => 'Only valid JPEG, PNG, and WebP profile photos are accepted.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $newFilename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        }

        $photoDirectory = $this->verificationPhotoDirectory();
        if (
            $newFilename !== null && !is_dir($photoDirectory)
            && !mkdir($photoDirectory, 0700, true) && !is_dir($photoDirectory)
        ) {
            error_log('Could not create the private profile photo directory.');
            $this->response->send_json(['success' => false, 'message' => 'The profile photo could not be saved. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if (
            $newFilename !== null && !move_uploaded_file(
                $uploadedPhoto['tmp_name'],
                $photoDirectory . DIRECTORY_SEPARATOR . $newFilename
            )
        ) {
            error_log('Could not save a private customer profile photo.');
            $this->response->send_json(['success' => false, 'message' => 'The profile photo could not be saved. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $updatedUser = $this->userModel->updateProfile((int) $user['id'], $values, $newFilename);
        } catch (Exception $exception) {
            if ($newFilename !== null) {
                $this->deleteVerificationPhoto($newFilename);
            }
            error_log('Customer profile update failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The profile could not be saved. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if ($newFilename !== null && !empty($user['verification_photo_filename'])) {
            $this->deleteVerificationPhoto($user['verification_photo_filename']);
        }
        if (!$updatedUser) {
            error_log('Updated customer profile could not be reloaded.');
            $this->response->send_json(['success' => false, 'message' => 'The profile was saved but could not be reloaded. Please refresh and try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Profile saved successfully.', 'data' => $this->profileData($updatedUser)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function verificationPhoto()
    {
        header('Cache-Control: private, no-store, max-age=0');
        $user = $this->authenticatedUserRecord();
        if ($this->isAdminRole($user['role'] ?? null)) {
            $this->response->send_json(['success' => false, 'message' => 'Customer profile access is required.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $filename = basename((string) ($user['verification_photo_filename'] ?? ''));
        $path = $filename !== ''
            ? $this->verificationPhotoDirectory() . DIRECTORY_SEPARATOR . $filename
            : '';
        if ($path === '' || !is_file($path)) {
            $this->response->send_json(['success' => false, 'message' => 'No profile verification photo was found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            error_log('Stored customer verification photo has an unsupported content type.');
            $this->response->send_json(['success' => false, 'message' => 'The profile verification photo cannot be displayed.'], 415, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function maintenanceRecords()
    {
        $this->requireAdmin();
        $this->maintenanceModel->ensureSchema();

        $vehicles = $this->maintenanceModel->getVehicleOverview();

        $records = $this->maintenanceModel->getVehicleRecords();

        $summary = [
            'total_vehicles' => count($vehicles),
            'approaching' => 0,
            'overdue' => 0,
            'insufficient' => 0,
        ];

        $warningWindow = new DateTimeImmutable('today');
        $warningWindow = $warningWindow->modify('+30 days');

        foreach ($vehicles as $vehicle) {
            $lastDate = $vehicle['last_maintenance_date'];
            $mileage = $vehicle['current_mileage'];
            if ($lastDate === null && $mileage === null) {
                $summary['insufficient']++;
                continue;
            }
            if ($lastDate !== null) {
                $threshold = new DateTimeImmutable($lastDate);
                $nextDue = $threshold->modify('+180 days');
                if ($mileage !== null && ($vehicle['last_maintenance_odometer'] !== null && ((int) $vehicle['last_maintenance_odometer'] + 5000) <= (int) $mileage)) {
                    $summary['overdue']++;
                } elseif ($nextDue <= $warningWindow) {
                    $summary['approaching']++;
                }
            }
        }

        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance records retrieved successfully.', 'data' => [
            'summary' => $summary,
            'vehicles' => $vehicles,
            'records' => $records,
        ]], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function maintenancePredictions()
    {
        $this->requireAdmin();
        $this->maintenanceModel->ensureSchema();

        $predictions = $this->maintenanceModel->getPredictions();
        $vehicles = $this->maintenanceModel->getVehicles();

        $summary = [
            'total_vehicles' => count($vehicles),
            'approaching' => 0,
            'overdue' => 0,
            'insufficient' => 0,
        ];
        foreach ($predictions as $prediction) {
            $level = strtolower((string) ($prediction['riskLevel'] ?? 'low'));
            if ($level === 'high' || $level === 'overdue') {
                $summary['overdue']++;
            } elseif ($level === 'medium' || $level === 'approaching') {
                $summary['approaching']++;
            } elseif ($level === 'insufficient') {
                $summary['insufficient']++;
            }
        }

        if (!$predictions) {
            $predictions = $this->maintenanceModel->generatePredictions();
            $summary = [
                'total_vehicles' => count($vehicles),
                'approaching' => 0,
                'overdue' => 0,
                'insufficient' => 0,
            ];
            foreach ($predictions as $prediction) {
                $level = strtolower((string) ($prediction['riskLevel'] ?? 'low'));
                if ($level === 'high' || $level === 'overdue') {
                    $summary['overdue']++;
                } elseif ($level === 'medium' || $level === 'approaching') {
                    $summary['approaching']++;
                } elseif ($level === 'insufficient') {
                    $summary['insufficient']++;
                }
            }
        }

        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance predictions retrieved successfully.', 'data' => [
            'summary' => $summary,
            'vehicles' => $vehicles,
            'predictions' => $predictions,
        ]], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function generateMaintenancePredictions()
    {
        $this->requireAdmin();
        $predictions = $this->maintenanceModel->generatePredictions();
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance predictions were recalculated successfully.', 'data' => $predictions], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function createMaintenanceRecord()
    {
        $this->requireAdmin();
        $input = $this->input();

        $required = ['vehicleID', 'maintenanceType'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
                $this->response->send_json(['success' => false, 'message' => 'Vehicle and maintenance type are required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }

        if (!$this->carModel->findCar((int) $input['vehicleID'])) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $record = $this->maintenanceModel->createRecord($input);
            $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance record saved successfully.', 'data' => $record], function ($value) { return $value !== null; }), 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json(['success' => false, 'message' => $exception->getMessage()], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } catch (Exception $exception) {
            error_log('Maintenance record creation failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The maintenance record could not be saved.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

    public function updateMaintenanceRecord($id)
    {
        $this->requireAdmin();

        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Maintenance record not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $input = $this->input();
        $result = $this->maintenanceModel->updateRecord((int) $id, $input);
        if ($result === null) {
            $this->response->send_json(['success' => false, 'message' => 'Maintenance record not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!$result['updated']) {
            $this->response->send_json(['success' => false, 'message' => 'No fields were provided to update.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance record updated successfully.', 'data' => $result['record']], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function updateVehicleMileage()
    {
        $this->requireAdmin();
        $input = $this->input();

        $vehicleId = (int) ($input['vehicleID'] ?? $input['car_id'] ?? 0);
        $mileage = isset($input['current_mileage']) ? (int) $input['current_mileage'] : (isset($input['mileage']) ? (int) $input['mileage'] : 0);
        if ($vehicleId < 1 || $mileage < 0) {
            $this->response->send_json(['success' => false, 'message' => 'A valid vehicle and mileage are required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!$this->carModel->findCar((int) $vehicleId)) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $updated = $this->maintenanceModel->updateMileage($vehicleId, $mileage);
            $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicle mileage updated successfully.', 'data' => $updated], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json(['success' => false, 'message' => $exception->getMessage()], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

    public function chat()
    {
        $this->handleChatRequest();
    }

    protected function handleChatRequest()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->send_json(['success' => false, 'message' => 'Only POST requests are allowed.'], 405, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $sessionUser = $this->authenticatedUserRecord();
        if (!isset($sessionUser['role']) || (!$this->isAdminRole($sessionUser['role']) && !$this->isCustomerRole($sessionUser['role']))) {
            $this->response->send_json(['success' => false, 'message' => 'Authenticated customer or administrator access is required.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 8192) {
            $this->response->send_json(['success' => false, 'message' => 'The chat message is too large.'], 413, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $input = $this->input();
        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || strlen($message) > 4000) {
            $this->response->send_json(['success' => false, 'message' => 'Enter a message of up to 4,000 characters.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $mode = $input['mode'] ?? 'rental';
        if (!is_string($mode) || !in_array($mode, ['rental', 'diagnostic'], true)) {
            $this->response->send_json(['success' => false, 'message' => 'Chat mode must be rental or diagnostic.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $conversationId = $input['conversation_id'] ?? null;
        if (
            $conversationId !== null && (!is_string($conversationId)
                || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $conversationId))
        ) {
            $this->response->send_json(['success' => false, 'message' => 'The chat conversation is invalid.'], 400, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        lava_instance()->config->load('api');
        $token = trim((string) config_item('chatbot_api_token'));
        $chatbotUrl = trim((string) config_item('chatbot_api_url'));
        if (
            $token === '' || strlen($token) < 32 || !filter_var($chatbotUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($chatbotUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            $this->response->send_json(['success' => false, 'message' => 'The chat service is not configured.'], 503, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!function_exists('curl_init')) {
            $this->response->send_json(['success' => false, 'message' => 'The chat service is unavailable right now.'], 503, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $user = $userId ? $this->userModel->activeRole($userId) : false;
        $isAdmin = $user && $user['role'] === 'admin';
        if ($mode === 'diagnostic' && !$isAdmin) {
            $this->response->send_json(['success' => false, 'message' => 'Administrator access is required for vehicle diagnostics.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if ($mode === 'diagnostic') {
            $vehicleId = filter_var($input['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
            $component = $input['component'] ?? null;
            if (
                $vehicleId === false || $vehicleId < 1 || !is_string($component)
                || trim($component) === '' || strlen($component) > 80
            ) {
                $this->response->send_json(['success' => false, 'message' => 'Select a valid vehicle and diagnostic component.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $vehicle = $this->carModel->findCar($vehicleId);
            if (!$vehicle) {
                $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $maintenanceRecords = array_slice($this->maintenanceModel->getVehicleRecords($vehicleId), 0, 10);
            $records = array_map(static function ($record) {
                return [
                    'type' => substr((string) ($record['maintenanceType'] ?? ''), 0, 80),
                    'date' => $record['maintenanceDate'] ?? null,
                    'status' => substr((string) ($record['maintenanceStatus'] ?? ''), 0, 30),
                    'odometer_km' => $record['odometerReading'] === null ? null : (int) $record['odometerReading'],
                    'next_service_date' => $record['nextMaintenanceDate'] ?? null,
                    'findings' => substr((string) ($record['description'] ?? ''), 0, 500),
                ];
            }, $maintenanceRecords);
            $predictions = $this->maintenanceModel->getPredictions($vehicleId);
            $prediction = $predictions ? $predictions[0] : null;
            $rentalContext = [
                'viewer_scope' => 'admin_diagnostic',
                'data_as_of' => date(DATE_ATOM),
                'diagnostic_context' => [
                    'vehicle' => [
                        'make' => (string) $vehicle['make'],
                        'model' => (string) $vehicle['model'],
                        'year' => (int) $vehicle['year'],
                        'plate_number' => $vehicle['plate_number'],
                        'color' => $vehicle['color'],
                        'fuel_type' => $vehicle['fuel_type'],
                        'transmission' => $vehicle['transmission'],
                        'seating_capacity' => (int) $vehicle['seating_capacity'],
                        'current_mileage_km' => isset($vehicle['current_mileage']) ? (int) $vehicle['current_mileage'] : null,
                        'status' => $vehicle['status'],
                    ],
                    'selected_component' => trim($component),
                    'maintenance_history' => $records,
                    'rule_based_estimate' => $prediction ? [
                        'status' => $prediction['riskLevel'],
                        'due_date' => $prediction['nextMaintenanceDate'],
                        'basis' => substr((string) $prediction['predictedIssue'], 0, 500),
                        'recommended_action' => substr((string) $prediction['recommendedAction'], 0, 500),
                    ] : null,
                    'measurement_policy' => 'No live sensor telemetry or component test readings are available. Do not infer condition, measurements, or faults.',
                ],
            ];
        } else {
            $carContext = $this->carModel->getChatContext($isAdmin);
            $vehicleCount = $carContext['vehicle_count'];
            $cars = array_map([$this, 'normalizeChatCar'], $carContext['cars']);
            $statusCounts = $carContext['status_counts'];
            $rentalContext = [
                'currency' => 'PHP',
                'data_as_of' => date(DATE_ATOM),
                'viewer_scope' => $isAdmin ? 'admin' : 'public',
                'vehicle_count' => $vehicleCount,
                'vehicle_list_truncated' => $vehicleCount > count($cars),
                'vehicles' => $cars,
                'vehicle_counts_by_status' => $isAdmin ? $statusCounts : null,
                'vehicle_status_field_available' => true,
                'vehicle_status_authorized' => $isAdmin,
                'maintenance_history_supported' => true,
                'mileage_and_service_interval_fields_available' => true,
                'booking_or_transaction_records_supported' => false,
            ];
        }

        $payload = json_encode([
            'message' => trim($message),
            'provider' => 'auto',
            'conversation_id' => $conversationId,
            'conversation_scope' => $isAdmin ? 'admin:' . $userId : 'public',
            'rental_context' => $rentalContext,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            error_log('Chat request could not be encoded: ' . json_last_error_msg());
            $this->response->send_json(['success' => false, 'message' => 'The chat request could not be completed.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $handle = curl_init($chatbotUrl);
        if ($handle === false) {
            $this->response->send_json(['success' => false, 'message' => 'The chat service is unavailable right now.'], 503, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Chat-Client-IP: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 75,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
        ]);
        $responseBody = curl_exec($handle);
        $responseStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if ($responseBody === false) {
            error_log('Chat service request failed: ' . $curlError);
            $this->response->send_json(['success' => false, 'message' => 'The chat service is unavailable right now.'], 503, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $result = json_decode($responseBody, true);
        if (
            $responseStatus < 200 || $responseStatus >= 300
            || !is_array($result) || empty($result['success']) || !is_string($result['message'] ?? null)
        ) {
            $detail = 'The chat service is unavailable right now.';
            if (is_array($result) && is_string($result['error']['message'] ?? null)) {
                $detail = $result['error']['message'];
            } elseif (is_string($result['message'] ?? null)) {
                $detail = $result['message'];
            } elseif ($curlError !== '') {
                $detail = 'The chat service is unavailable right now: ' . $curlError;
            }
            error_log('Chat service returned an unsuccessful response (HTTP ' . $responseStatus . '): ' . $detail);
            $this->response->send_json(['success' => false, 'message' => $detail], 503, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Chat response generated.', 'data' => [
            'message' => $result['message'],
            'conversation_id' => $result['conversation_id'] ?? null
        ]], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function normalizeChatCar(array $car)
    {
        $car['daily_rental_price'] = (float) $car['daily_rental_price'];
        $car['security_deposit'] = (float) $car['security_deposit'];
        $car['minimum_rental_duration'] = (int) $car['minimum_rental_duration'];
        $car['seating_capacity'] = (int) $car['seating_capacity'];
        $car['air_conditioning'] = (bool) $car['air_conditioning'];
        $car['gps_navigation'] = (bool) $car['gps_navigation'];
        $features = is_string($car['additional_features'])
            ? json_decode($car['additional_features'], true)
            : $car['additional_features'];
        $encodedFeatures = json_encode($features);
        $car['additional_features'] = $encodedFeatures !== false && strlen($encodedFeatures) > 1000
            ? 'Feature details omitted because they exceed the chat context limit.'
            : $features;
        $car['description'] = substr((string) ($car['description'] ?? ''), 0, 240);
        return $car;
    }

    public function reservations()
    {
        $user = $this->authenticatedUser();
        $reservations = $this->reservationModel->getForUser($user);
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservations retrieved successfully.', 'data' => $reservations], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function reservation($id)
    {
        $user = $this->authenticatedUser();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $reservation = $this->findReservation((int) $id);
        if (!$reservation || ($user['role'] !== 'admin' && (int) $reservation['user_id'] !== $user['id'])) {
            $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservation retrieved successfully.', 'data' => $reservation], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function createReservation()
    {
        $user = $this->authenticatedUser();
        if (!$this->isCustomerRole($user['role'])) {
            $this->response->send_json(['success' => false, 'message' => 'Only customer accounts can create reservations.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $input = $this->input();
        $carId = filter_var($input['car_id'] ?? null, FILTER_VALIDATE_INT);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? null;
        if (!$carId || $carId < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Select a valid vehicle.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!$this->isValidReservationDate($startDate) || !$this->isValidReservationDate($endDate)) {
            $this->response->send_json(['success' => false, 'message' => 'Enter valid rental dates.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        $today = new DateTimeImmutable('today');
        $rentalDays = (int) $start->diff($end)->format('%a');
        if ($start < $today) {
            $this->response->send_json(['success' => false, 'message' => 'The rental start date cannot be in the past.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if ($end <= $start || $rentalDays < 1 || $rentalDays > 65535) {
            $this->response->send_json(['success' => false, 'message' => 'The return date must be after the pickup date.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $result = $this->reservationModel->createReservation(
                $user['id'],
                $carId,
                $startDate,
                $endDate,
                $rentalDays
            );
        } catch (Exception $exception) {
            error_log('Reservation creation failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The reservation could not be created. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        switch ($result['status']) {
            case 'vehicle_not_found':
                $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'vehicle_unavailable':
                $this->response->send_json(['success' => false, 'message' => 'This vehicle is not currently available for reservations.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'duration_too_short':
                $this->response->send_json(['success' => false, 'message' => 'The selected dates do not meet this vehicle’s minimum rental duration.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'date_conflict':
                $this->response->send_json(['success' => false, 'message' => 'This vehicle is already reserved for part or all of those dates.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'amount_too_large':
                $this->response->send_json(['success' => false, 'message' => 'The selected rental period exceeds the maximum supported amount.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'start_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be started. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'commit_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be committed. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'created':
                break;
            default:
                error_log('Reservation creation returned an unexpected result.');
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be created. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
        }

        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservation created successfully and is awaiting confirmation.', 'data' => $this->findReservation($result['id'])], function ($value) { return $value !== null; }), 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function updateReservation($id)
    {
        $user = $this->authenticatedUser();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $input = $this->input();
        $status = $input['status'] ?? null;
        if (!is_string($status)) {
            $this->response->send_json(['success' => false, 'message' => 'A valid reservation status is required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $result = $this->reservationModel->updateStatus((int) $id, $user, $status);
        } catch (Exception $exception) {
            error_log('Reservation update failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The reservation could not be updated. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        switch ($result) {
            case 'not_found':
                $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'invalid_transition':
                $this->response->send_json(['success' => false, 'message' => 'That reservation status change is not allowed.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'customer_forbidden':
                $this->response->send_json(['success' => false, 'message' => 'You may only cancel your own pending or confirmed reservations.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'vehicle_unavailable':
                $this->response->send_json(['success' => false, 'message' => 'This vehicle is no longer available for those dates.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'start_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation update could not be started. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'commit_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation update could not be committed. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'updated':
                break;
            default:
                error_log('Reservation update returned an unexpected result.');
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be updated. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservation status updated successfully.', 'data' => $this->findReservation((int) $id)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    protected function findReservation($id)
    {
        return $this->reservationModel->findWithDetails($id);
    }

    private function isValidReservationDate($value)
    {
        if (!is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    public function cars()
    {
        $this->requireAdmin();
        $this->listCars(false);
    }

    public function availableCars()
    {
        $this->listCars(true);
    }

    public function car($id)
    {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $car = $this->carModel->findCar((int) $id);
        if (!$car) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if ($car['status'] !== 'available') {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $user = $userId ? $this->userModel->activeRole($userId) : false;
            if (!$user || $user['role'] !== 'admin') {
                $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicle retrieved successfully.', 'data' => $this->carModel->findWithImages((int) $id)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function createCar()
    {
        $this->requireAdmin();
        $values = $this->validateCar($this->input());
        $id = $this->carModel->create($values);
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicle created successfully.', 'data' => $this->carModel->findWithImages($id)], function ($value) { return $value !== null; }), 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function updateCar($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1 || !$this->carModel->findCar((int) $id)) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $values = $this->validateCar($this->input(), true, (int) $id);
        if (!$values) {
            $this->response->send_json(['success' => false, 'message' => 'No valid vehicle fields were provided.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->carModel->update((int) $id, $values);
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicle updated successfully.', 'data' => $this->carModel->findWithImages((int) $id)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function deleteCar($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!$this->carModel->findCar((int) $id)) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        try {
            $images = $this->carModel->delete((int) $id);
        } catch (Exception $exception) {
            error_log('Vehicle deletion failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'This vehicle cannot be deleted while it is referenced by other records.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        foreach ($images as $image) {
            $this->deleteImageFile($image['image_path']);
        }
        $this->response->send_json(['success' => true, 'message' => 'Vehicle deleted successfully.'], 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function uploadImages($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1 || !$this->carModel->findCar((int) $id)) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $files = $this->uploadedFiles();
        if (!$files) {
            $this->response->send_json(['success' => false, 'message' => 'Select at least one vehicle image.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $directory = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cars';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->response->send_json(['success' => false, 'message' => 'The vehicle image directory could not be created.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $saved = [];
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                $this->response->send_json(['success' => false, 'message' => 'Images must be valid uploads no larger than 5 MB each.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
                $this->response->send_json(['success' => false, 'message' => 'Only valid JPEG, PNG, and WebP images are accepted.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            $relativePath = '/uploads/cars/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
                $this->response->send_json(['success' => false, 'message' => 'The vehicle image could not be saved.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            $saved[] = $this->carModel->createImage((int) $id, $relativePath);
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicle images uploaded successfully.', 'data' => $saved], function ($value) { return $value !== null; }), 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function deleteImage($id, $imageId)
    {
        $this->requireAdmin();
        $image = $this->carModel->deleteImage((int) $imageId, (int) $id);
        if (!$image) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle image not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->deleteImageFile($image['image_path']);
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicle image deleted successfully.', 'data' => $this->carModel->findWithImages((int) $id)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    protected function listCars($availableOnly)
    {
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        if ($startDate !== null || $endDate !== null) {
            if (
                !$availableOnly || !$this->isValidReservationDate($startDate)
                || !$this->isValidReservationDate($endDate)
                || $endDate <= $startDate
                || $startDate < (new DateTimeImmutable('today'))->format('Y-m-d')
            ) {
                $this->response->send_json(['success' => false, 'message' => 'Provide a valid future pickup and return date range.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        $page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
        $perPage = min(48, max(1, filter_var($_GET['per_page'] ?? 12, FILTER_VALIDATE_INT) ?: 12));
        $filters = [];
        foreach (['category', 'make', 'status', 'search'] as $filter) {
            if (isset($_GET[$filter]) && is_scalar($_GET[$filter])) {
                $filters[$filter] = trim((string) $_GET[$filter]);
            }
        }
        if ($startDate !== null && $endDate !== null) {
            $filters['start_date'] = $startDate;
            $filters['end_date'] = $endDate;
        }

        $result = $this->carModel->read(
            $availableOnly,
            $filters,
            $page,
            $perPage,
            is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest'
        );
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicles retrieved successfully.', 'data' => $result['cars'], 'meta' => $result['meta']], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    protected function validateCar(array $input, $partial = false, $currentId = 0)
    {
        if (isset($input['daily_rate']) && !isset($input['daily_rental_price'])) {
            $input['daily_rental_price'] = $input['daily_rate'];
        }
        if (isset($input['minimum_rental_period']) && !isset($input['minimum_rental_duration'])) {
            $input['minimum_rental_duration'] = $input['minimum_rental_period'];
        }
        if (isset($input['features']) && !isset($input['additional_features'])) {
            $input['additional_features'] = $input['features'];
        }
        $values = [];
        foreach (self::CAR_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }
        foreach ($values as $field => $value) {
            if ($field !== 'additional_features' && $value !== null && !is_scalar($value)) {
                $this->response->send_json(['success' => false, 'message' => "The {$field} field has an invalid value."], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        if (!$partial) {
            foreach (['make', 'model', 'year', 'category', 'daily_rental_price'] as $field) {
                if (!isset($values[$field]) || trim((string) $values[$field]) === '') {
                    $this->response->send_json(['success' => false, 'message' => "The {$field} field is required."], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    exit;
                }
            }
            $values += [
                'plate_number' => null,
                'color' => null,
                'description' => null,
                'security_deposit' => 0,
                'minimum_rental_duration' => 1,
                'transmission' => 'Automatic',
                'fuel_type' => 'Gasoline',
                'seating_capacity' => 5,
                'air_conditioning' => 0,
                'gps_navigation' => 0,
                'additional_features' => '[]',
                'status' => 'available',
            ];
        }
        foreach (['plate_number', 'color', 'description'] as $field) {
            if (isset($values[$field]) && trim((string) $values[$field]) === '') {
                $values[$field] = null;
            }
        }
        if (!empty($values['plate_number'])) {
            if ($this->carModel->plateNumberExists($values['plate_number'], $currentId)) {
                $this->response->send_json(['success' => false, 'message' => 'That plate number is already assigned to another vehicle.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        foreach (['make' => 100, 'model' => 100, 'category' => 50, 'plate_number' => 40, 'color' => 50] as $field => $max) {
            if (isset($values[$field]) && (strlen((string) $values[$field]) > $max || trim((string) $values[$field]) === '')) {
                $this->response->send_json(['success' => false, 'message' => "The {$field} field is invalid."], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        if (isset($values['year']) && (!filter_var($values['year'], FILTER_VALIDATE_INT) || $values['year'] < 1900 || $values['year'] > (int) date('Y') + 1)) {
            $this->response->send_json(['success' => false, 'message' => 'Year must be a valid vehicle year.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        foreach (['daily_rental_price', 'security_deposit'] as $field) {
            if (isset($values[$field]) && (!is_numeric($values[$field]) || $values[$field] < 0)) {
                $this->response->send_json(['success' => false, 'message' => "{$field} must be a non-negative amount."], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        foreach (['minimum_rental_duration', 'seating_capacity'] as $field) {
            if (isset($values[$field]) && (!filter_var($values[$field], FILTER_VALIDATE_INT) || $values[$field] < 1)) {
                $this->response->send_json(['success' => false, 'message' => "{$field} must be a positive whole number."], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        foreach (['air_conditioning', 'gps_navigation'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = filter_var($values[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (isset($values['status']) && !in_array($values['status'], ['available', 'rented', 'maintenance', 'unavailable'], true)) {
            $this->response->send_json(['success' => false, 'message' => 'Status must be available, rented, maintenance, or unavailable.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (isset($values['additional_features'])) {
            $features = $values['additional_features'];
            if (is_array($features)) {
                $features = array_values(array_filter($features, 'is_string'));
                $values['additional_features'] = json_encode($features);
            } elseif (is_string($features)) {
                $decoded = json_decode($features, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (!is_array($decoded)) {
                        $this->response->send_json(['success' => false, 'message' => 'Additional features must be a list of text values.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                        exit;
                    }
                    $values['additional_features'] = json_encode(array_values(array_filter($decoded, 'is_string')));
                } else {
                    $features = array_values(array_filter(array_map('trim', explode(',', $features))));
                    $values['additional_features'] = json_encode($features);
                }
            } else {
                $this->response->send_json(['success' => false, 'message' => 'Additional features must be a list of text values.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        if (isset($values['description']) && strlen((string) $values['description']) > 5000) {
            $this->response->send_json(['success' => false, 'message' => 'Description must be 5,000 characters or fewer.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        return $values;
    }

    protected function uploadedFiles()
    {
        $input = $_FILES['images'] ?? $_FILES['image'] ?? null;
        if (!$input) {
            return [];
        }
        if (!is_array($input['name'])) {
            return [$input];
        }
        $files = [];
        foreach ($input['name'] as $index => $name) {
            if ($name !== '') {
                $files[] = [
                    'name' => $name,
                    'type' => $input['type'][$index],
                    'tmp_name' => $input['tmp_name'][$index],
                    'error' => $input['error'][$index],
                    'size' => $input['size'][$index],
                ];
            }
        }
        return $files;
    }

    protected function deleteImageFile($relativePath)
    {
        $filename = basename((string) $relativePath);
        $path = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cars' . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    protected function input()
    {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $body = file_get_contents('php://input');
            $input = json_decode($body, true);
            if (!is_array($input)) {
                $this->response->send_json(['success' => false, 'message' => 'Request body must contain valid JSON.'], 400, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            return $input;
        }
        return $_POST;
    }

    protected function authenticatedUser()
    {
        $user = $this->authenticatedUserRecord();
        return $this->publicUser($user);
    }

    protected function authenticatedUserRecord()
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if (!$id) {
            $this->response->send_json(['success' => false, 'message' => 'Authentication is required.'], 401, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $user = $this->userModel->findById($id);
        if (!$user || (int) $user['is_active'] !== 1) {
            unset($_SESSION['user_id']);
            $this->response->send_json(['success' => false, 'message' => 'Authentication is required.'], 401, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        return $user;
    }

    protected function profileData(array $user)
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'] ?? '',
            'email' => $user['email'],
            'phone' => $user['phone'] ?? '',
            'address_line' => $user['address_line'] ?? '',
            'city' => $user['city'] ?? '',
            'province' => $user['province'] ?? '',
            'postal_code' => $user['postal_code'] ?? '',
            'country' => $user['country'] ?? '',
            'driver_license_number' => $user['driver_license_number'] ?? '',
            'driver_license_expiry' => $user['driver_license_expiry'] ?? '',
            'has_verification_photo' => $this->hasVerificationPhoto($user),
            'verification_consent_given' => !empty($user['verification_consent_at']),
            'role' => $this->publicRole($user['role'] ?? null),
        ];
    }

    protected function verificationPhotoDirectory()
    {
        return ROOT_DIR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'user-verification';
    }

    protected function hasVerificationPhoto(array $user)
    {
        $filename = basename((string) ($user['verification_photo_filename'] ?? ''));
        return $filename !== '' && is_file(
            $this->verificationPhotoDirectory() . DIRECTORY_SEPARATOR . $filename
        );
    }

    protected function deleteVerificationPhoto($filename)
    {
        $safeFilename = basename((string) $filename);
        $path = $this->verificationPhotoDirectory() . DIRECTORY_SEPARATOR . $safeFilename;
        if ($safeFilename !== '' && is_file($path) && !unlink($path)) {
            error_log('Could not remove a replaced customer verification photo.');
        }
    }

    protected function publicUser(array $user)
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'] ?? $user['username'],
            'email' => $user['email'],
            'role' => $this->publicRole($user['role'] ?? null),
        ];
    }

    protected function publicRole($role)
    {
        $role = strtolower((string) ($role ?? ''));
        return $role === 'admin' ? 'admin' : 'customer';
    }

    protected function isAdminRole($role)
    {
        return strtolower((string) ($role ?? '')) === 'admin';
    }

    protected function isCustomerRole($role)
    {
        $role = strtolower((string) ($role ?? ''));
        return in_array($role, ['user', 'customer'], true);
    }

    protected function requireAdmin()
    {
        $user = $this->authenticatedUser();
        if ($user['role'] !== 'admin') {
            $this->response->send_json(['success' => false, 'message' => 'Administrator access is required.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

}
