<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CarModel extends Model
{
    protected $table = 'cars';
    protected $primary_key = 'id';
    protected $fillable = [
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
    protected $guarded = ['id'];

    public function __construct()
    {
        parent::__construct();
    }

    public function read($availableOnly, array $filters, $page, $perPage, $sort)
    {
        $cars = $this->carQuery($availableOnly, $filters)->get_all();

        if (!empty($filters['start_date']) && !empty($filters['end_date']) && $cars) {
            $carIds = array_map('intval', array_column($cars, 'id'));
            $reservations = $this->db->table('reservations')
                ->select('car_id, start_date, end_date')
                ->grouped(static function ($query) {
                    $query->where('status', 'pending')
                        ->or_where('status', 'confirmed');
                })
                ->where('start_date', '<', $filters['end_date'])
                ->where('end_date', '>', $filters['start_date'])
                ->in('car_id', $carIds)
                ->get_all();

            $unavailableCarIds = [];
            foreach ($reservations as $reservation) {
                $unavailableCarIds[(int) $reservation['car_id']] = true;
            }

            $cars = array_values(array_filter($cars, static function ($car) use ($unavailableCarIds) {
                return !isset($unavailableCarIds[(int) $car['id']]);
            }));
        }

        $this->sortCars($cars, $sort);
        $total = count($cars);
        $cars = array_slice($cars, ($page - 1) * $perPage, $perPage);
        $cars = array_map(function ($car) {
            return $this->withImages($car);
        }, $cars);

        $availableCount = $this->db->table($this->table)
            ->where('status', 'available')
            ->count();
        $unavailableCount = $this->db->table($this->table)
            ->where('status', '<>', 'available')
            ->count();
        $categories = $this->db->table($this->table)
            ->select('category')
            ->group_by('category')
            ->order_by('category')
            ->get_all();
        $brands = $this->db->table($this->table)
            ->select('make')
            ->group_by('make')
            ->order_by('make')
            ->get_all();

        return [
            'cars' => $cars,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
                'available_count' => (int) $availableCount,
                'unavailable_count' => (int) $unavailableCount,
                'categories' => array_column($categories, 'category'),
                'brands' => array_column($brands, 'make'),
            ],
        ];
    }

    public function create(array $data)
    {
        $data = $this->fillable_attributes($data);
        $this->db->table($this->table)->insert($data);

        return (int) $this->db->last_id();
    }

    public function update($id, array $data)
    {
        $data = $this->fillable_attributes($data);
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->update($data);
    }

    public function delete($id)
    {
        $images = $this->images($id);
        $this->db->table('car_images')->where('car_id', (int) $id)->delete();
        $this->db->table($this->table)->where($this->primary_key, (int) $id)->delete();

        return $images;
    }

    public function findCar($id)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->get();
    }

    public function getChatContext($isAdmin)
    {
        $vehicleCount = (int) $this->carQuery(!$isAdmin, [])->count();
        $query = $this->db->table($this->table);
        if (!$isAdmin) {
            $query->where('status', 'available');
        }
        $cars = $query->select(
            'make, model, year, category, daily_rental_price, security_deposit, minimum_rental_duration, transmission, fuel_type, seating_capacity, air_conditioning, gps_navigation, additional_features, description, status'
        )
            ->order_by('make', 'ASC')
            ->order_by('model', 'ASC')
            ->limit(100)
            ->get_all();
        $statusCounts = [];
        if ($isAdmin) {
            $statusRows = $this->db->table($this->table)
                ->select('status')
                ->select_count('status', 'vehicle_count')
                ->group_by('status')
                ->order_by('status')
                ->get_all();
            foreach ($statusRows as $row) {
                $statusCounts[$row['status']] = (int) $row['vehicle_count'];
            }
        }

        return [
            'vehicle_count' => $vehicleCount,
            'cars' => $cars,
            'status_counts' => $statusCounts,
        ];
    }

    public function findWithImages($id)
    {
        $car = $this->findCar($id);

        return $car ? $this->withImages($car) : null;
    }

    public function plateNumberExists($plateNumber, $exceptId = 0)
    {
        return (bool) $this->db->table($this->table)
            ->where('plate_number', $plateNumber)
            ->where($this->primary_key, '<>', (int) $exceptId)
            ->get();
    }

    public function images($carId)
    {
        $images = $this->db->table('car_images')
            ->select('id, image_path, is_primary, created_at')
            ->where('car_id', (int) $carId)
            ->order_by('id', 'ASC')
            ->get_all();

        usort($images, static function ($left, $right) {
            $primaryOrder = (int) $right['is_primary'] <=> (int) $left['is_primary'];

            return $primaryOrder !== 0
                ? $primaryOrder
                : (int) $left['id'] <=> (int) $right['id'];
        });

        return $images;
    }

    public function createImage($carId, $imagePath)
    {
        $db = $this->db;
        $hasImages = $db->table('car_images')
            ->select('id')
            ->where('car_id', (int) $carId)
            ->limit(1)
            ->get();
        $isPrimary = $hasImages ? 0 : 1;

        $db->table('car_images')->insert([
            'car_id' => (int) $carId,
            'image_path' => $imagePath,
            'is_primary' => $isPrimary,
        ]);
        $id = (int) $db->last_id();

        if ($isPrimary) {
            $db->table($this->table)
                ->where($this->primary_key, (int) $carId)
                ->update(['primary_image' => $imagePath]);
        }

        return [
            'id' => $id,
            'image_path' => $imagePath,
            'is_primary' => $isPrimary,
        ];
    }

    public function findImage($imageId, $carId)
    {
        return $this->db->table('car_images')
            ->select('id, image_path, is_primary')
            ->where('id', (int) $imageId)
            ->where('car_id', (int) $carId)
            ->get();
    }

    public function deleteImage($imageId, $carId)
    {
        $image = $this->findImage($imageId, $carId);
        if (!$image) {
            return null;
        }

        $db = $this->db;
        $db->table('car_images')->where('id', (int) $imageId)->delete();
        if ((int) $image['is_primary'] === 1) {
            $next = $db->table('car_images')
                ->select('id, image_path')
                ->where('car_id', (int) $carId)
                ->order_by('id', 'ASC')
                ->limit(1)
                ->get();

            if ($next) {
                $db->table('car_images')
                    ->where('id', (int) $next['id'])
                    ->update(['is_primary' => 1]);
            }

            $db->table($this->table)
                ->where($this->primary_key, (int) $carId)
                ->update(['primary_image' => $next['image_path'] ?? null]);
        }

        return $image;
    }

    private function carQuery($availableOnly, array $filters)
    {
        $query = $this->db->table($this->table);
        if ($availableOnly) {
            $query->where('status', 'available');
        }

        foreach (['category', 'make', 'status'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->grouped(static function ($query) use ($term) {
                $query->like('make', $term)
                    ->or_like('model', $term)
                    ->or_like('plate_number', $term);
            });
        }

        return $query;
    }

    private function sortCars(array &$cars, $sort)
    {
        usort($cars, static function ($left, $right) use ($sort) {
            switch ($sort) {
                case 'price_asc':
                    $comparison = (float) $left['daily_rental_price'] <=> (float) $right['daily_rental_price'];
                    break;
                case 'price_desc':
                    $comparison = (float) $right['daily_rental_price'] <=> (float) $left['daily_rental_price'];
                    break;
                case 'name':
                    $comparison = strcmp($left['make'], $right['make']);
                    if ($comparison === 0) {
                        $comparison = strcmp($left['model'], $right['model']);
                    }
                    return $comparison;
                default:
                    $comparison = strcmp($right['created_at'], $left['created_at']);
                    break;
            }

            return $comparison !== 0
                ? $comparison
                : (int) $right['id'] <=> (int) $left['id'];
        });
    }

    private function withImages(array $car)
    {
        $car['images'] = $this->images((int) $car['id']);

        return $car;
    }
}
