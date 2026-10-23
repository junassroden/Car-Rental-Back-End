<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiController extends Controller
{
    private const CAR_FIELDS = [
        'make', 'model', 'year', 'category', 'plate_number', 'color',
        'description', 'daily_rental_price', 'security_deposit',
        'minimum_rental_duration', 'transmission', 'fuel_type',
        'seating_capacity', 'air_conditioning', 'gps_navigation',
        'additional_features', 'status',
    ];

    public function before_action()
    {
        $this->carModel = $this->call->model('CarModel');

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigin = getenv('FRONTEND_ORIGIN') ?: 'http://127.0.0.1:5173';
        if ($origin === $allowedOrigin) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        } elseif ($origin !== '') {
            $this->respond(403, false, 'Origin is not allowed.');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Access-Control-Max-Age: 600');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Strict',
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
            $this->respond(422, false, 'Email/username and password are required.');
        }

        $user = $this->db()->raw(
            'SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1',
            [$identity, $identity]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password'])) {
            $this->respond(401, false, 'Invalid login credentials.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $this->respond(200, true, 'Login successful.', $this->publicUser($user));
    }

    public function register()
    {
        $input = $this->input();
        $fullName = is_string($input['full_name'] ?? null) ? trim($input['full_name']) : '';
        $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';

        if ($fullName === '' || strlen($fullName) > 150) {
            $this->respond(422, false, 'Full name is required and must be no longer than 150 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->respond(422, false, 'Enter a valid email address.');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->respond(422, false, 'Password must be between 8 and 72 characters.');
        }

        $db = $this->db();
        $existingEmail = $db->raw(
            'SELECT id FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existingEmail) {
            $this->respond(409, false, 'An account with that email address already exists.');
        }

        $localPart = strstr($email, '@', true);
        $baseUsername = trim((string) preg_replace('/[^a-z0-9._-]+/i', '-', $localPart), '.-_');
        $baseUsername = substr($baseUsername !== '' ? $baseUsername : 'driver', 0, 100);
        $username = $baseUsername;
        for ($suffix = 2; ; $suffix++) {
            $existingUsername = $db->raw(
                'SELECT id FROM users WHERE username = ? LIMIT 1',
                [$username]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$existingUsername) {
                break;
            }
            $suffixText = '-' . $suffix;
            $username = substr($baseUsername, 0, 100 - strlen($suffixText)) . $suffixText;
        }

        try {
            $db->raw(
                'INSERT INTO users (username, full_name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                [$username, $fullName, $email, password_hash($password, PASSWORD_DEFAULT), 'user', 1]
            );
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $this->respond(409, false, 'An account could not be created because the email address or username is already in use.');
            }
            error_log('Account registration failed: ' . $exception->getMessage());
            $this->respond(500, false, 'The account could not be created. Please try again.');
        }

        $this->respond(201, true, 'Account created successfully.');
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
        $this->respond(200, true, 'Logged out successfully.');
    }

    public function me()
    {
        $this->respond(200, true, 'Authenticated user retrieved.', $this->authenticatedUser());
    }

    public function profile()
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        $user = $this->authenticatedUserRecord();
        if ($user['role'] === 'admin') {
            $this->respond(403, false, 'Customer profile access is required.');
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $this->respond(200, true, 'Profile retrieved successfully.', $this->profileData($user));
        }
        if ($method !== 'POST') {
            $this->respond(405, false, 'Only GET and POST requests are allowed.');
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 6 * 1024 * 1024) {
            $this->respond(413, false, 'The profile photo must be no larger than 5 MB.');
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
                $this->respond(422, false, $rules['label'] . ' is required and must be no longer than ' . $rules['max'] . ' characters.');
            }
            $values[$field] = $value;
        }

        if (!preg_match('/^[+0-9().\-\s]{7,30}$/', $values['phone'])) {
            $this->respond(422, false, 'Enter a valid phone number.');
        }
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $values['driver_license_expiry']);
        if (!$expiry || $expiry->format('Y-m-d') !== $values['driver_license_expiry']) {
            $this->respond(422, false, 'Enter a valid driver’s licence expiry date.');
        }
        if ($expiry < new DateTimeImmutable('today')) {
            $this->respond(422, false, 'The driver’s licence must not be expired.');
        }
        if (($input['verification_consent'] ?? '') !== 'yes') {
            $this->respond(422, false, 'Confirm that the photo shows you holding your driver’s licence before saving.');
        }

        $uploadedPhoto = $_FILES['verification_photo'] ?? null;
        $hasExistingPhoto = $this->hasVerificationPhoto($user);
        if (!$hasExistingPhoto && (!$uploadedPhoto || $uploadedPhoto['error'] !== UPLOAD_ERR_OK)) {
            $this->respond(422, false, 'Upload a photo of yourself holding your driver’s licence.');
        }

        $newFilename = null;
        if ($uploadedPhoto && $uploadedPhoto['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedPhoto['error'] !== UPLOAD_ERR_OK || $uploadedPhoto['size'] > 5 * 1024 * 1024) {
                $this->respond(422, false, 'The profile photo must be a valid image no larger than 5 MB.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($uploadedPhoto['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime]) || @getimagesize($uploadedPhoto['tmp_name']) === false) {
                $this->respond(422, false, 'Only valid JPEG, PNG, and WebP profile photos are accepted.');
            }
            $newFilename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        }

        $photoDirectory = $this->verificationPhotoDirectory();
        if ($newFilename !== null && !is_dir($photoDirectory)
            && !mkdir($photoDirectory, 0700, true) && !is_dir($photoDirectory)) {
            error_log('Could not create the private profile photo directory.');
            $this->respond(500, false, 'The profile photo could not be saved. Please try again.');
        }

        if ($newFilename !== null && !move_uploaded_file(
            $uploadedPhoto['tmp_name'],
            $photoDirectory . DIRECTORY_SEPARATOR . $newFilename
        )) {
            error_log('Could not save a private customer profile photo.');
            $this->respond(500, false, 'The profile photo could not be saved. Please try again.');
        }

        try {
            $this->db()->raw(
                'UPDATE users SET full_name = ?, phone = ?, address_line = ?, city = ?, province = ?, postal_code = ?, country = ?, driver_license_number = ?, driver_license_expiry = ?, verification_photo_filename = COALESCE(?, verification_photo_filename), verification_consent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [
                    $values['full_name'],
                    $values['phone'],
                    $values['address_line'],
                    $values['city'],
                    $values['province'],
                    $values['postal_code'],
                    $values['country'],
                    $values['driver_license_number'],
                    $values['driver_license_expiry'],
                    $newFilename,
                    (int) $user['id'],
                ]
            );
        } catch (PDOException $exception) {
            if ($newFilename !== null) {
                $this->deleteVerificationPhoto($newFilename);
            }
            error_log('Customer profile update failed: ' . $exception->getMessage());
            $this->respond(500, false, 'The profile could not be saved. Please try again.');
        }

        if ($newFilename !== null && !empty($user['verification_photo_filename'])) {
            $this->deleteVerificationPhoto($user['verification_photo_filename']);
        }
        $updatedUser = $this->db()->raw(
            'SELECT * FROM users WHERE id = ? LIMIT 1',
            [(int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$updatedUser) {
            error_log('Updated customer profile could not be reloaded.');
            $this->respond(500, false, 'The profile was saved but could not be reloaded. Please refresh and try again.');
        }
        $this->respond(200, true, 'Profile saved successfully.', $this->profileData($updatedUser));
    }

    public function verificationPhoto()
    {
        header('Cache-Control: private, no-store, max-age=0');
        $user = $this->authenticatedUserRecord();
        if ($user['role'] === 'admin') {
            $this->respond(403, false, 'Customer profile access is required.');
        }
        $filename = basename((string) ($user['verification_photo_filename'] ?? ''));
        $path = $filename !== ''
            ? $this->verificationPhotoDirectory() . DIRECTORY_SEPARATOR . $filename
            : '';
        if ($path === '' || !is_file($path)) {
            $this->respond(404, false, 'No profile verification photo was found.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            error_log('Stored customer verification photo has an unsupported content type.');
            $this->respond(415, false, 'The profile verification photo cannot be displayed.');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function chat()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond(405, false, 'Only POST requests are allowed.');
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 8192) {
            $this->respond(413, false, 'The chat message is too large.');
        }

        $input = $this->input();
        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || strlen($message) > 4000) {
            $this->respond(422, false, 'Enter a message of up to 4,000 characters.');
        }
        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && (!is_string($conversationId)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $conversationId))) {
            $this->respond(400, false, 'The chat conversation is invalid.');
        }

        lava_instance()->config->load('api');
        $token = trim((string) config_item('chatbot_api_token'));
        $chatbotUrl = trim((string) config_item('chatbot_api_url'));
        if ($token === '' || strlen($token) < 32 || !filter_var($chatbotUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($chatbotUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $this->respond(503, false, 'The chat service is not configured.');
        }
        if (!function_exists('curl_init')) {
            $this->respond(503, false, 'The chat service is unavailable right now.');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $user = $userId ? $this->db()->raw(
            'SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC) : false;
        $isAdmin = $user && $user['role'] === 'admin';
        $carFilter = $isAdmin ? '' : " WHERE status = 'available'";
        $vehicleCount = (int) $this->db()->raw(
            "SELECT COUNT(*) FROM cars{$carFilter}"
        )->fetchColumn();
        $cars = $this->db()->raw(
            "SELECT make, model, year, category, daily_rental_price, security_deposit,
                    minimum_rental_duration, transmission, fuel_type, seating_capacity,
                    air_conditioning, gps_navigation, additional_features, description, status
             FROM cars{$carFilter} ORDER BY make ASC, model ASC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
        $cars = array_map([$this, 'normalizeChatCar'], $cars);
        $statusCounts = [];
        if ($isAdmin) {
            $statusRows = $this->db()->raw(
                'SELECT status, COUNT(*) AS vehicle_count FROM cars GROUP BY status ORDER BY status'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($statusRows as $row) {
                $statusCounts[$row['status']] = (int) $row['vehicle_count'];
            }
        }

        $payload = json_encode([
            'message' => trim($message),
            'provider' => 'auto',
            'conversation_id' => $conversationId,
            'conversation_scope' => $isAdmin ? 'admin:' . $userId : 'public',
            'rental_context' => [
                'currency' => 'PHP',
                'data_as_of' => date(DATE_ATOM),
                'viewer_scope' => $isAdmin ? 'admin' : 'public',
                'vehicle_count' => $vehicleCount,
                'vehicle_list_truncated' => $vehicleCount > count($cars),
                'vehicles' => $cars,
                'vehicle_counts_by_status' => $isAdmin ? $statusCounts : null,
                'vehicle_status_field_available' => true,
                'vehicle_status_authorized' => $isAdmin,
                'maintenance_history_supported' => false,
                'mileage_and_service_interval_fields_available' => false,
                'booking_or_transaction_records_supported' => false,
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            error_log('Chat request could not be encoded: ' . json_last_error_msg());
            $this->respond(500, false, 'The chat request could not be completed.');
        }

        $handle = curl_init($chatbotUrl);
        if ($handle === false) {
            $this->respond(503, false, 'The chat service is unavailable right now.');
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
            $this->respond(503, false, 'The chat service is unavailable right now.');
        }

        $result = json_decode($responseBody, true);
        if ($responseStatus < 200 || $responseStatus >= 300
            || !is_array($result) || empty($result['success']) || !is_string($result['message'] ?? null)) {
            $detail = 'The chat service is unavailable right now.';
            if (is_array($result) && is_string($result['error']['message'] ?? null)) {
                $detail = $result['error']['message'];
            } elseif (is_string($result['message'] ?? null)) {
                $detail = $result['message'];
            } elseif ($curlError !== '') {
                $detail = 'The chat service is unavailable right now: ' . $curlError;
            }
            error_log('Chat service returned an unsuccessful response (HTTP ' . $responseStatus . '): ' . $detail);
            $this->respond(503, false, $detail);
        }
        $this->respond(200, true, 'Chat response generated.', [
            'message' => $result['message'],
            'conversation_id' => $result['conversation_id'] ?? null
        ]);
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
        $params = [];
        $where = '';
        if ($user['role'] !== 'admin') {
            $where = ' WHERE r.user_id = ?';
            $params[] = $user['id'];
        }
        $reservations = $this->db()->raw(
            "SELECT r.*, c.make, c.model, c.year, c.category, c.plate_number, c.primary_image,
                    u.full_name, u.username, u.email
             FROM reservations r
             INNER JOIN cars c ON c.id = r.car_id
             INNER JOIN users u ON u.id = r.user_id{$where}
             ORDER BY r.created_at DESC, r.id DESC",
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(200, true, 'Reservations retrieved successfully.', $reservations);
    }

    public function reservation($id)
    {
        $user = $this->authenticatedUser();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->respond(404, false, 'Reservation not found.');
        }
        $reservation = $this->findReservation((int) $id);
        if (!$reservation || ($user['role'] !== 'admin' && (int) $reservation['user_id'] !== $user['id'])) {
            $this->respond(404, false, 'Reservation not found.');
        }
        $this->respond(200, true, 'Reservation retrieved successfully.', $reservation);
    }

    public function createReservation()
    {
        $user = $this->authenticatedUser();
        if ($user['role'] !== 'customer') {
            $this->respond(403, false, 'Only customer accounts can create reservations.');
        }

        $input = $this->input();
        $carId = filter_var($input['car_id'] ?? null, FILTER_VALIDATE_INT);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? null;
        if (!$carId || $carId < 1) {
            $this->respond(422, false, 'Select a valid vehicle.');
        }
        if (!$this->isValidReservationDate($startDate) || !$this->isValidReservationDate($endDate)) {
            $this->respond(422, false, 'Enter valid rental dates.');
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        $today = new DateTimeImmutable('today');
        $rentalDays = (int) $start->diff($end)->format('%a');
        if ($start < $today) {
            $this->respond(422, false, 'The rental start date cannot be in the past.');
        }
        if ($end <= $start || $rentalDays < 1 || $rentalDays > 65535) {
            $this->respond(422, false, 'The return date must be after the pickup date.');
        }

        $db = $this->db();
        $transactionStarted = false;
        try {
            if (!$db->transaction()) {
                $this->respond(500, false, 'The reservation could not be started. Please try again.');
            }
            $transactionStarted = true;
            $car = $db->raw(
                'SELECT id, daily_rental_price, minimum_rental_duration, status
                 FROM cars WHERE id = ? FOR UPDATE',
                [$carId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$car) {
                $transactionStarted = false;
                $db->roll_back();
                $this->respond(404, false, 'Vehicle not found.');
            }
            if ($car['status'] !== 'available') {
                $transactionStarted = false;
                $db->roll_back();
                $this->respond(409, false, 'This vehicle is not currently available for reservations.');
            }
            if ($rentalDays < (int) $car['minimum_rental_duration']) {
                $transactionStarted = false;
                $db->roll_back();
                $this->respond(422, false, 'The selected dates do not meet this vehicle’s minimum rental duration.');
            }

            $conflict = $db->raw(
                "SELECT id FROM reservations
                 WHERE car_id = ? AND status IN ('pending', 'confirmed')
                   AND start_date < ? AND end_date > ?
                 LIMIT 1",
                [$carId, $endDate, $startDate]
            )->fetch(PDO::FETCH_ASSOC);
            if ($conflict) {
                $transactionStarted = false;
                $db->roll_back();
                $this->respond(409, false, 'This vehicle is already reserved for part or all of those dates.');
            }

            $dailyRate = round((float) $car['daily_rental_price'], 2);
            $totalAmount = round($dailyRate * $rentalDays, 2);
            if (!is_finite($totalAmount) || $totalAmount > 99999999.99) {
                $transactionStarted = false;
                $db->roll_back();
                $this->respond(422, false, 'The selected rental period exceeds the maximum supported amount.');
            }

            $db->raw(
                "INSERT INTO reservations
                    (user_id, car_id, start_date, end_date, rental_days, daily_rate, total_amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
                [$user['id'], $carId, $startDate, $endDate, $rentalDays, number_format($dailyRate, 2, '.', ''), number_format($totalAmount, 2, '.', '')]
            );
            $reservationId = (int) $db->last_id();
            $transactionStarted = false;
            if (!$db->commit()) {
                $this->respond(500, false, 'The reservation could not be committed. Please try again.');
            }
        } catch (PDOException $exception) {
            if ($transactionStarted) {
                $transactionStarted = false;
                $db->roll_back();
            }
            error_log('Reservation creation failed: ' . $exception->getMessage());
            $this->respond(500, false, 'The reservation could not be created. Please try again.');
        }

        $this->respond(201, true, 'Reservation created successfully and is awaiting confirmation.', $this->findReservation($reservationId));
    }

    public function updateReservation($id)
    {
        $user = $this->authenticatedUser();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->respond(404, false, 'Reservation not found.');
        }
        $input = $this->input();
        $status = $input['status'] ?? null;
        if (!is_string($status)) {
            $this->respond(422, false, 'A valid reservation status is required.');
        }

        $isAdmin = $user['role'] === 'admin';
        $db = $this->db();
        $transactionStarted = false;
        try {
            if (!$db->transaction()) {
                $this->respond(500, false, 'The reservation update could not be started. Please try again.');
            }
            $transactionStarted = true;
            $reservation = $db->raw(
                'SELECT * FROM reservations WHERE id = ? FOR UPDATE',
                [(int) $id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$reservation || (!$isAdmin && (int) $reservation['user_id'] !== $user['id'])) {
                $transactionStarted = false;
                $db->roll_back();
                $this->respond(404, false, 'Reservation not found.');
            }
            if ($isAdmin) {
                $transitions = [
                    'pending' => ['confirmed', 'rejected', 'cancelled'],
                    'confirmed' => ['cancelled', 'completed'],
                ];
                if (!in_array($status, $transitions[$reservation['status']] ?? [], true)) {
                    $transactionStarted = false;
                    $db->roll_back();
                    $this->respond(409, false, 'That reservation status change is not allowed.');
                }
            } elseif ($status !== 'cancelled' || !in_array($reservation['status'], ['pending', 'confirmed'], true)) {
                $transactionStarted = false;
                $db->roll_back();
                $this->respond(403, false, 'You may only cancel your own pending or confirmed reservations.');
            }

            if ($isAdmin && $status === 'confirmed') {
                $car = $db->raw(
                    'SELECT status FROM cars WHERE id = ? FOR UPDATE',
                    [(int) $reservation['car_id']]
                )->fetch(PDO::FETCH_ASSOC);
                $conflict = $db->raw(
                    "SELECT id FROM reservations
                     WHERE car_id = ? AND id <> ? AND status IN ('pending', 'confirmed')
                       AND start_date < ? AND end_date > ?
                     LIMIT 1",
                    [(int) $reservation['car_id'], (int) $id, $reservation['end_date'], $reservation['start_date']]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$car || $car['status'] !== 'available' || $conflict) {
                    $transactionStarted = false;
                    $db->roll_back();
                    $this->respond(409, false, 'This vehicle is no longer available for those dates.');
                }
            }
            $db->raw(
                'UPDATE reservations SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$status, (int) $id]
            );
            $transactionStarted = false;
            if (!$db->commit()) {
                $this->respond(500, false, 'The reservation update could not be committed. Please try again.');
            }
        } catch (PDOException $exception) {
            if ($transactionStarted) {
                $transactionStarted = false;
                $db->roll_back();
            }
            error_log('Reservation update failed: ' . $exception->getMessage());
            $this->respond(500, false, 'The reservation could not be updated. Please try again.');
        }
        $this->respond(200, true, 'Reservation status updated successfully.', $this->findReservation((int) $id));
    }

    private function findReservation($id)
    {
        return $this->db()->raw(
            'SELECT r.*, c.make, c.model, c.year, c.category, c.plate_number, c.primary_image,
                    u.full_name, u.username, u.email
             FROM reservations r
             INNER JOIN cars c ON c.id = r.car_id
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.id = ? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
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
            $this->respond(404, false, 'Vehicle not found.');
        }
        $car = $this->carModel->findCar((int) $id);
        if (!$car) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        if ($car['status'] !== 'available') {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $user = $userId ? $this->db()->raw(
                'SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
                [$userId]
            )->fetch(PDO::FETCH_ASSOC) : false;
            if (!$user || $user['role'] !== 'admin') {
                $this->respond(404, false, 'Vehicle not found.');
            }
        }
        $this->respond(200, true, 'Vehicle retrieved successfully.', $this->carModel->findWithImages((int) $id));
    }

    public function createCar()
    {
        $this->requireAdmin();
        $values = $this->validateCar($this->input());
        $id = $this->carModel->create($values);
        $this->respond(201, true, 'Vehicle created successfully.', $this->carModel->findWithImages($id));
    }

    public function updateCar($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1 || !$this->carModel->findCar((int) $id)) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        $values = $this->validateCar($this->input(), true, (int) $id);
        if (!$values) {
            $this->respond(422, false, 'No valid vehicle fields were provided.');
        }
        $this->carModel->update((int) $id, $values);
        $this->respond(200, true, 'Vehicle updated successfully.', $this->carModel->findWithImages((int) $id));
    }

    public function deleteCar($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        if (!$this->carModel->findCar((int) $id)) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        try {
            $images = $this->carModel->delete((int) $id);
        } catch (PDOException $exception) {
            error_log('Vehicle deletion failed: ' . $exception->getMessage());
            $this->respond(409, false, 'This vehicle cannot be deleted while it is referenced by other records.');
        }
        foreach ($images as $image) {
            $this->deleteImageFile($image['image_path']);
        }
        $this->respond(200, true, 'Vehicle deleted successfully.');
    }

    public function uploadImages($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1 || !$this->carModel->findCar((int) $id)) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        $files = $this->uploadedFiles();
        if (!$files) {
            $this->respond(422, false, 'Select at least one vehicle image.');
        }

        $directory = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cars';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->respond(500, false, 'The vehicle image directory could not be created.');
        }
        $saved = [];
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                $this->respond(422, false, 'Images must be valid uploads no larger than 5 MB each.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
                $this->respond(422, false, 'Only valid JPEG, PNG, and WebP images are accepted.');
            }
            $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            $relativePath = '/uploads/cars/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
                $this->respond(500, false, 'The vehicle image could not be saved.');
            }
            $saved[] = $this->carModel->createImage((int) $id, $relativePath);
        }
        $this->respond(201, true, 'Vehicle images uploaded successfully.', $saved);
    }

    public function deleteImage($id, $imageId)
    {
        $this->requireAdmin();
        $image = $this->carModel->deleteImage((int) $imageId, (int) $id);
        if (!$image) {
            $this->respond(404, false, 'Vehicle image not found.');
        }
        $this->deleteImageFile($image['image_path']);
        $this->respond(200, true, 'Vehicle image deleted successfully.', $this->carModel->findWithImages((int) $id));
    }

    private function listCars($availableOnly)
    {
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        if ($startDate !== null || $endDate !== null) {
            if (!$availableOnly || !$this->isValidReservationDate($startDate)
                || !$this->isValidReservationDate($endDate)
                || $endDate <= $startDate
                || $startDate < (new DateTimeImmutable('today'))->format('Y-m-d')) {
                $this->respond(422, false, 'Provide a valid future pickup and return date range.');
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
        $this->respond(
            200,
            true,
            'Vehicles retrieved successfully.',
            $result['cars'],
            $result['meta']
        );
    }

    private function validateCar(array $input, $partial = false, $currentId = 0)
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
                $this->respond(422, false, "The {$field} field has an invalid value.");
            }
        }
        if (!$partial) {
            foreach (['make', 'model', 'year', 'category', 'daily_rental_price'] as $field) {
                if (!isset($values[$field]) || trim((string) $values[$field]) === '') {
                    $this->respond(422, false, "The {$field} field is required.");
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
                $this->respond(409, false, 'That plate number is already assigned to another vehicle.');
            }
        }
        foreach (['make' => 100, 'model' => 100, 'category' => 50, 'plate_number' => 40, 'color' => 50] as $field => $max) {
            if (isset($values[$field]) && (strlen((string) $values[$field]) > $max || trim((string) $values[$field]) === '')) {
                $this->respond(422, false, "The {$field} field is invalid.");
            }
        }
        if (isset($values['year']) && (!filter_var($values['year'], FILTER_VALIDATE_INT) || $values['year'] < 1900 || $values['year'] > (int) date('Y') + 1)) {
            $this->respond(422, false, 'Year must be a valid vehicle year.');
        }
        foreach (['daily_rental_price', 'security_deposit'] as $field) {
            if (isset($values[$field]) && (!is_numeric($values[$field]) || $values[$field] < 0)) {
                $this->respond(422, false, "{$field} must be a non-negative amount.");
            }
        }
        foreach (['minimum_rental_duration', 'seating_capacity'] as $field) {
            if (isset($values[$field]) && (!filter_var($values[$field], FILTER_VALIDATE_INT) || $values[$field] < 1)) {
                $this->respond(422, false, "{$field} must be a positive whole number.");
            }
        }
        foreach (['air_conditioning', 'gps_navigation'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = filter_var($values[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (isset($values['status']) && !in_array($values['status'], ['available', 'rented', 'maintenance', 'unavailable'], true)) {
            $this->respond(422, false, 'Status must be available, rented, maintenance, or unavailable.');
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
                        $this->respond(422, false, 'Additional features must be a list of text values.');
                    }
                    $values['additional_features'] = json_encode(array_values(array_filter($decoded, 'is_string')));
                } else {
                    $features = array_values(array_filter(array_map('trim', explode(',', $features))));
                    $values['additional_features'] = json_encode($features);
                }
            } else {
                $this->respond(422, false, 'Additional features must be a list of text values.');
            }
        }
        if (isset($values['description']) && strlen((string) $values['description']) > 5000) {
            $this->respond(422, false, 'Description must be 5,000 characters or fewer.');
        }
        return $values;
    }

    private function uploadedFiles()
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

    private function deleteImageFile($relativePath)
    {
        $filename = basename((string) $relativePath);
        $path = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cars' . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function db()
    {
        return $this->call->database();
    }

    private function input()
    {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $body = file_get_contents('php://input');
            $input = json_decode($body, true);
            if (!is_array($input)) {
                $this->respond(400, false, 'Request body must contain valid JSON.');
            }
            return $input;
        }
        return $_POST;
    }

    private function authenticatedUser()
    {
        $user = $this->authenticatedUserRecord();
        return $this->publicUser($user);
    }

    private function authenticatedUserRecord()
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if (!$id) {
            $this->respond(401, false, 'Authentication is required.');
        }
        $user = $this->db()->raw(
            'SELECT * FROM users WHERE id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int) $user['is_active'] !== 1) {
            unset($_SESSION['user_id']);
            $this->respond(401, false, 'Authentication is required.');
        }
        return $user;
    }

    private function profileData(array $user)
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
            'role' => $user['role'] === 'admin' ? 'admin' : 'customer',
        ];
    }

    private function verificationPhotoDirectory()
    {
        return ROOT_DIR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'user-verification';
    }

    private function hasVerificationPhoto(array $user)
    {
        $filename = basename((string) ($user['verification_photo_filename'] ?? ''));
        return $filename !== '' && is_file(
            $this->verificationPhotoDirectory() . DIRECTORY_SEPARATOR . $filename
        );
    }

    private function deleteVerificationPhoto($filename)
    {
        $safeFilename = basename((string) $filename);
        $path = $this->verificationPhotoDirectory() . DIRECTORY_SEPARATOR . $safeFilename;
        if ($safeFilename !== '' && is_file($path) && !unlink($path)) {
            error_log('Could not remove a replaced customer verification photo.');
        }
    }

    private function publicUser(array $user)
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'] ?? $user['username'],
            'email' => $user['email'],
            'role' => $user['role'] === 'admin' ? 'admin' : 'customer',
        ];
    }

    private function requireAdmin()
    {
        $user = $this->authenticatedUser();
        if ($user['role'] !== 'admin') {
            $this->respond(403, false, 'Administrator access is required.');
        }
    }

    private function respond($status, $success, $message, $data = null, $meta = null)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $response = ['success' => $success, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        if ($meta !== null) {
            $response['meta'] = $meta;
        }
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
