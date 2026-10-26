<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CarController extends ApiController
{
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
}
