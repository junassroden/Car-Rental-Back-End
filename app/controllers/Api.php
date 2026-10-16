<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api extends Controller
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
        $car = $this->db()->raw(
            'SELECT * FROM cars WHERE id = ? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
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
        $this->respond(200, true, 'Vehicle retrieved successfully.', $this->withImages($car));
    }

    public function createCar()
    {
        $this->requireAdmin();
        $values = $this->validateCar($this->input());
        $columns = implode(', ', array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->db()->raw(
            "INSERT INTO cars ({$columns}) VALUES ({$placeholders})",
            array_values($values)
        );
        $id = (int) $this->db()->last_id();
        $this->respond(201, true, 'Vehicle created successfully.', $this->getCar($id));
    }

    public function updateCar($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1 || !$this->getCar((int) $id)) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        $values = $this->validateCar($this->input(), true, (int) $id);
        if (!$values) {
            $this->respond(422, false, 'No valid vehicle fields were provided.');
        }
        $assignments = implode(', ', array_map(
            static fn($field) => "{$field} = ?",
            array_keys($values)
        ));
        $params = array_values($values);
        $params[] = (int) $id;
        $this->db()->raw(
            "UPDATE cars SET {$assignments}, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            $params
        );
        $this->respond(200, true, 'Vehicle updated successfully.', $this->getCar((int) $id));
    }

    public function deleteCar($id)
    {
        $this->requireAdmin();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        $images = $this->images((int) $id);
        if (!$this->getCar((int) $id)) {
            $this->respond(404, false, 'Vehicle not found.');
        }
        try {
            $this->db()->raw('DELETE FROM car_images WHERE car_id = ?', [(int) $id]);
            $this->db()->raw('DELETE FROM cars WHERE id = ?', [(int) $id]);
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
        if (!ctype_digit((string) $id) || (int) $id < 1 || !$this->getCar((int) $id)) {
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
        $db = $this->db();
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
            $isPrimary = empty($this->images((int) $id)) && empty($saved) ? 1 : 0;
            $db->raw(
                'INSERT INTO car_images (car_id, image_path, is_primary) VALUES (?, ?, ?)',
                [(int) $id, $relativePath, $isPrimary]
            );
            if ($isPrimary) {
                $db->raw('UPDATE cars SET primary_image = ? WHERE id = ?', [$relativePath, (int) $id]);
            }
            $saved[] = [
                'id' => (int) $db->last_id(),
                'image_path' => $relativePath,
                'is_primary' => $isPrimary,
            ];
        }
        $this->respond(201, true, 'Vehicle images uploaded successfully.', $saved);
    }

    public function deleteImage($id, $imageId)
    {
        $this->requireAdmin();
        $image = $this->db()->raw(
            'SELECT id, image_path, is_primary FROM car_images WHERE id = ? AND car_id = ?',
            [(int) $imageId, (int) $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$image) {
            $this->respond(404, false, 'Vehicle image not found.');
        }
        $this->db()->raw('DELETE FROM car_images WHERE id = ?', [(int) $imageId]);
        $this->deleteImageFile($image['image_path']);
        if ((int) $image['is_primary'] === 1) {
            $next = $this->db()->raw(
                'SELECT id, image_path FROM car_images WHERE car_id = ? ORDER BY id ASC LIMIT 1',
                [(int) $id]
            )->fetch(PDO::FETCH_ASSOC);
            if ($next) {
                $this->db()->raw('UPDATE car_images SET is_primary = 1 WHERE id = ?', [(int) $next['id']]);
            }
            $this->db()->raw('UPDATE cars SET primary_image = ? WHERE id = ?', [$next['image_path'] ?? null, (int) $id]);
        }
        $this->respond(200, true, 'Vehicle image deleted successfully.', $this->getCar((int) $id));
    }

    private function listCars($availableOnly)
    {
        $filters = [];
        $params = [];
        if ($availableOnly) {
            $filters[] = "status = 'available'";
        }
        foreach (['category', 'make', 'status'] as $filter) {
            if (!empty($_GET[$filter])) {
                $filters[] = "{$filter} = ?";
                $params[] = trim((string) $_GET[$filter]);
            }
        }
        if (!empty($_GET['search'])) {
            $term = '%' . trim((string) $_GET['search']) . '%';
            $filters[] = '(make LIKE ? OR model LIKE ? OR plate_number LIKE ?)';
            array_push($params, $term, $term, $term);
        }
        $where = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
        $sorts = [
            'newest' => 'created_at DESC, id DESC',
            'price_asc' => 'daily_rental_price ASC, id DESC',
            'price_desc' => 'daily_rental_price DESC, id DESC',
            'name' => 'make ASC, model ASC',
        ];
        $sort = $sorts[$_GET['sort'] ?? 'newest'] ?? $sorts['newest'];
        $page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
        $perPage = min(48, max(1, filter_var($_GET['per_page'] ?? 12, FILTER_VALIDATE_INT) ?: 12));
        $count = (int) $this->db()->raw("SELECT COUNT(*) FROM cars{$where}", $params)->fetchColumn();
        $availableCount = (int) $this->db()->raw("SELECT COUNT(*) FROM cars WHERE status = 'available'")->fetchColumn();
        $unavailableCount = (int) $this->db()->raw("SELECT COUNT(*) FROM cars WHERE status <> 'available'")->fetchColumn();
        $categories = $this->db()->raw('SELECT DISTINCT category FROM cars ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
        $brands = $this->db()->raw('SELECT DISTINCT make FROM cars ORDER BY make')->fetchAll(PDO::FETCH_COLUMN);
        $cars = $this->db()->raw(
            "SELECT * FROM cars{$where} ORDER BY {$sort} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
        $cars = array_map(fn($car) => $this->withImages($car), $cars);
        $this->respond(200, true, 'Vehicles retrieved successfully.', $cars, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $count,
            'total_pages' => (int) ceil($count / $perPage),
            'available_count' => $availableCount,
            'unavailable_count' => $unavailableCount,
            'categories' => $categories,
            'brands' => $brands,
        ]);
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
            $duplicate = $this->db()->raw(
                'SELECT id FROM cars WHERE plate_number = ? AND id <> ? LIMIT 1',
                [$values['plate_number'], $currentId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($duplicate) {
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

    private function getCar($id)
    {
        $car = $this->db()->raw('SELECT * FROM cars WHERE id = ? LIMIT 1', [(int) $id])
            ->fetch(PDO::FETCH_ASSOC);
        return $car ? $this->withImages($car) : null;
    }

    private function withImages(array $car)
    {
        $car['images'] = $this->images((int) $car['id']);
        return $car;
    }

    private function images($carId)
    {
        return $this->db()->raw(
            'SELECT id, image_path, is_primary, created_at FROM car_images WHERE car_id = ? ORDER BY is_primary DESC, id ASC',
            [(int) $carId]
        )->fetchAll(PDO::FETCH_ASSOC);
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
        return $this->publicUser($user);
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
