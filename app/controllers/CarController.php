<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CarController extends Controller
{
    // Values used for fields left out when a car is created.
    const DEFAULTS = [
        'plate_number'            => null,
        'color'                   => null,
        'description'             => null,
        'security_deposit'        => 0,
        'minimum_rental_duration' => 1,
        'transmission'            => 'Automatic',
        'fuel_type'               => 'Gasoline',
        'seating_capacity'        => 5,
        'air_conditioning'        => 0,
        'gps_navigation'          => 0,
        'additional_features'     => [],
        'status'                  => 'available',
    ];

    const NULLABLE = ['plate_number', 'color', 'description'];

    /** Public folder the vehicle images are saved in. */
    private $imageDirectory;

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model(['CarModel', 'CarImageModel', 'ReservationModel']);
        $this->imageDirectory = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cars';
    }

    /* ---------------------------------------------------------------
     * Endpoints
     * ------------------------------------------------------------- */

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
        $car = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            ? $this->CarModel->find_with_images((int) $id)
            : null;

        // Only admins can see vehicles that are not available.
        if ($car && $car['status'] !== 'available') {
            $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
            if (!$user || (int) $user['is_active'] !== 1 || $user['role'] !== 'admin') {
                $car = null;
            }
        }
        if (!$car) {
            $this->response->send_json_error('Vehicle not found.', 404);
            return;
        }

        $this->response->send_json_success($car, 'Vehicle retrieved successfully.');
    }

    public function createCar()
    {
        $this->requireAdmin();

        $id = $this->CarModel->insert($this->carValues());

        $this->response->send_json_success($this->CarModel->find_with_images((int) $id), 'Vehicle created successfully.', 201);
    }

    public function updateCar($id)
    {
        $this->requireAdmin();
        $id = $this->findCarId($id);

        $this->CarModel->update($id, $this->carValues($id));

        $this->response->send_json_success($this->CarModel->find_with_images($id), 'Vehicle updated successfully.');
    }

    public function deleteCar($id)
    {
        $this->requireAdmin();
        $id = $this->findCarId($id);

        try {
            $images = $this->CarModel->delete_with_images($id);
        } catch (Exception $exception) {
            error_log('Vehicle deletion failed: ' . $exception->getMessage());
            $this->response->send_json_error('This vehicle cannot be deleted while it is referenced by other records.', 409);
            return;
        }

        foreach ($images as $image) {
            @unlink($this->imageDirectory . DIRECTORY_SEPARATOR . basename($image['image_path']));
        }

        $this->response->send_json_success(null, 'Vehicle deleted successfully.');
    }

    public function uploadImages($id)
    {
        $this->requireAdmin();
        $id = $this->findCarId($id);

        $files = $this->uploadedFiles();
        if (!$files) {
            $this->response->send_json_error('Select at least one vehicle image.', 422);
            return;
        }

        // Save every file first; if one fails, remove the ones already saved.
        // A new Upload per file: the loader caches library instances,
        // so $this->call->library('upload', $file) would reuse the first file.
        $this->call->library('upload');
        $stored = [];
        foreach ($files as $file) {
            $upload = new Upload($file);
            $upload->set_dir($this->imageDirectory)
                ->max_size(5)
                ->allowed_extensions(['jpg', 'jpeg', 'png', 'webp'])
                ->allowed_mimes(['image/jpeg', 'image/png', 'image/webp'])
                ->is_image()
                ->encrypt_name();

            if (!$upload->do_upload()) {
                foreach ($stored as $filename) {
                    @unlink($this->imageDirectory . DIRECTORY_SEPARATOR . $filename);
                }
                $this->response->send_json_error(
                    'Images must be valid JPEG, PNG, or WebP files no larger than 5 MB each.',
                    422,
                    ['errors' => $upload->get_errors()]
                );
                return;
            }
            $stored[] = $upload->get_filename();
        }

        try {
            $saved = $this->CarImageModel->create_images($id, array_map(static function ($filename) {
                return '/uploads/cars/' . $filename;
            }, $stored));
        } catch (Exception $exception) {
            foreach ($stored as $filename) {
                @unlink($this->imageDirectory . DIRECTORY_SEPARATOR . $filename);
            }
            error_log('Vehicle image save failed: ' . $exception->getMessage());
            $this->response->send_json_error('The vehicle images could not be saved.', 500);
            return;
        }

        $this->response->send_json_success($saved, 'Vehicle images uploaded successfully.', 201);
    }

    public function deleteImage($id, $imageId)
    {
        $this->requireAdmin();

        $options = ['options' => ['min_range' => 1]];
        $image   = (filter_var($id, FILTER_VALIDATE_INT, $options) && filter_var($imageId, FILTER_VALIDATE_INT, $options))
            ? $this->CarImageModel->delete_image((int) $imageId, (int) $id)
            : null;

        if (!$image) {
            $this->response->send_json_error('Vehicle image not found.', 404);
            return;
        }

        @unlink($this->imageDirectory . DIRECTORY_SEPARATOR . basename($image['image_path']));

        $this->response->send_json_success($this->CarModel->find_with_images((int) $id), 'Vehicle image deleted successfully.');
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /** Sends a 401 or 403 unless an active administrator is signed in. */
    private function requireAdmin()
    {
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }
        if ($user['role'] !== 'admin') {
            $this->response->send_json_error('Administrator access is required.', 403);
            exit;
        }
    }

    private function listCars($availableOnly)
    {
        $startDate = $this->request->get('start_date');
        $endDate   = $this->request->get('end_date');
        $filters   = [];

        if ($startDate !== null || $endDate !== null) {
            $start = is_string($startDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $startDate) : false;
            $end   = is_string($endDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $endDate) : false;

            if (
                !$availableOnly
                || !$start || $start->format('Y-m-d') !== $startDate
                || !$end || $end->format('Y-m-d') !== $endDate
                || $endDate <= $startDate
                || $startDate < date('Y-m-d')
            ) {
                $this->response->send_json_error('Provide a valid future pickup and return date range.', 422);
                exit;
            }
            $filters['exclude_ids'] = $this->ReservationModel->booked_car_ids($startDate, $endDate);
        }

        foreach (['category', 'make', 'status', 'search'] as $filter) {
            $value = trim($this->request->string($filter));
            if ($value !== '') {
                $filters[$filter] = $value;
            }
        }

        $page    = max(1, $this->request->integer('page', 1));
        $perPage = min(48, max(1, $this->request->integer('per_page', 12)));
        $result  = $this->CarModel->read($availableOnly, $filters, $page, $perPage, $this->request->string('sort', 'newest'));

        $this->response->send_json([
            'success' => true,
            'message' => 'Vehicles retrieved successfully.',
            'data'    => $result['cars'],
            'meta'    => $result['meta'],
        ]);
    }

    /** Returns the car ID as an int, or sends a 404. */
    private function findCarId($id)
    {
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) || !$this->CarModel->find((int) $id)) {
            $this->response->send_json_error('Vehicle not found.', 404);
            exit;
        }

        return (int) $id;
    }

    /**
     * Validates the request body and returns the values to save.
     * Creating ($id = 0) fills in DEFAULTS; updating only checks the fields that were sent.
     */
    private function carValues($id = 0)
    {
        $input  = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $values = array_intersect_key(is_array($input) ? $input : [], array_flip(CarModel::FIELDS));

        if ($id && !$values) {
            $this->response->send_json_error('No valid vehicle fields were provided.', 422);
            exit;
        }
        if (!$id) {
            $values += self::DEFAULTS;
        }

        $rules = [
            'make|Make'                                       => 'required|max_length[100]',
            'model|Model'                                     => 'required|max_length[100]',
            'year|Year'                                       => 'required|pattern[int]|greater_than_equal_to[1900]|less_than_equal_to[' . (date('Y') + 1) . ']',
            'category|Category'                               => 'required|max_length[50]',
            'daily_rental_price|Daily rental price'           => 'required|numeric|greater_than_equal_to[0]',
            'security_deposit|Security deposit'               => 'required|numeric|greater_than_equal_to[0]',
            'minimum_rental_duration|Minimum rental duration' => 'required|pattern[int]|greater_than_equal_to[1]',
            'seating_capacity|Seating capacity'               => 'required|pattern[int]|greater_than_equal_to[1]',
            'transmission|Transmission'                       => 'required|max_length[50]',
            'fuel_type|Fuel type'                             => 'required|max_length[50]',
            'plate_number|Plate number'                       => 'max_length[40]',
            'color|Color'                                     => 'max_length[50]',
            'description|Description'                         => 'max_length[5000]',
        ];
        // An update only checks the fields that were sent.
        if ($id) {
            $rules = array_filter($rules, static function ($key) use ($values) {
                return array_key_exists(strtok($key, '|'), $values);
            }, ARRAY_FILTER_USE_KEY);
        }

        // Form_validation reads $_POST, so the values are placed there first.
        $_POST = $values;
        $this->call->library('form_validation');
        if (!$this->form_validation->validate($rules)) {
            $errors = $this->form_validation->get_errors();
            $this->response->send_json_error($errors[0], 422, ['errors' => $errors]);
            exit;
        }
        if (array_key_exists('status', $values) && !in_array($values['status'], CarModel::STATUSES, true)) {
            $this->response->send_json_error('"Status" is not a valid option.', 422);
            exit;
        }

        foreach (self::NULLABLE as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = trim((string) $values[$field]) === '' ? null : trim((string) $values[$field]);
            }
        }
        foreach (['year', 'minimum_rental_duration', 'seating_capacity'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = (int) $values[$field];
            }
        }
        foreach (['air_conditioning', 'gps_navigation'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = filter_var($values[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (array_key_exists('additional_features', $values)) {
            $values['additional_features'] = $this->featuresJson($values['additional_features']);
        }

        if (!empty($values['plate_number']) && $this->CarModel->plate_exists($values['plate_number'], $id)) {
            $this->response->send_json_error('That plate number is already assigned to another vehicle.', 409);
            exit;
        }

        return $values;
    }

    /** Accepts a list, a JSON list, or a comma-separated string, and returns a JSON list of strings. */
    private function featuresJson($features)
    {
        if (is_string($features)) {
            $decoded  = json_decode($features, true);
            $features = is_array($decoded) ? $decoded : explode(',', $features);
        }
        if (!is_array($features)) {
            $this->response->send_json_error('Additional features must be a list of text values.', 422);
            exit;
        }

        $clean = array_filter(array_map('trim', array_filter($features, 'is_string')), 'strlen');

        return json_encode(array_values($clean));
    }

    /** Flattens $_FILES['images'] (or 'image') into a list of single-file arrays. */
    private function uploadedFiles()
    {
        $input = $this->request->file('images') ?? $this->request->file('image');
        if (!$input) {
            return [];
        }
        if (!is_array($input['name'])) {
            return $input['name'] !== '' ? [$input] : [];
        }

        $files = [];
        foreach ($input['name'] as $index => $name) {
            if ($name !== '') {
                $files[] = [
                    'name'     => $name,
                    'type'     => $input['type'][$index],
                    'tmp_name' => $input['tmp_name'][$index],
                    'error'    => $input['error'][$index],
                    'size'     => $input['size'][$index],
                ];
            }
        }

        return $files;
    }
}
