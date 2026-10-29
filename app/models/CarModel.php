<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CarModel extends Model
{
    const FIELDS = [
        'make', 'model', 'year', 'category', 'plate_number', 'color', 'description',
        'daily_rental_price', 'security_deposit', 'minimum_rental_duration',
        'transmission', 'fuel_type', 'seating_capacity',
        'air_conditioning', 'gps_navigation', 'additional_features', 'status',
    ];

    const STATUSES = ['available', 'rented', 'maintenance', 'unavailable'];

    const SORTS = [
        'newest'     => ['id', 'DESC'],
        'oldest'     => ['id', 'ASC'],
        'price_asc'  => ['daily_rental_price', 'ASC'],
        'price_desc' => ['daily_rental_price', 'DESC'],
        'year_desc'  => ['year', 'DESC'],
        'year_asc'   => ['year', 'ASC'],
    ];

    protected $table       = 'cars';
    protected $primary_key = 'id';
    protected $fillable    = self::FIELDS;
    protected $timestamps  = false;

    /** Car row plus an `images` array, or NULL when the car does not exist. */
    public function find_with_images($id)
    {
        $car = $this->find($id);
        if (!$car) {
            return null;
        }

        $car['images'] = $this->db->table('car_images')
            ->where('car_id', $id)
            ->order_by('id', 'ASC')
            ->result_array();

        return $car;
    }

    public function plate_exists($plate_number, $ignore_id = 0)
    {
        $query = $this->query()->where('plate_number', $plate_number);
        if ($ignore_id) {
            $query->where('id', '<>', (int) $ignore_id);
        }

        return $query->count() > 0;
    }

    /**
     * Cars the chatbot may describe: every car for an admin, only available ones otherwise.
     * Returns ['cars' => up to $limit newest rows, 'vehicle_count' => total matching].
     */
    public function chat_context($is_admin, $limit = 50, array $exclude_ids = [], $vehicle_id = null)
    {
        $scope = static function ($query) use ($is_admin, $exclude_ids, $vehicle_id) {
            if (!$is_admin) {
                $query->where('status', 'available');
            }
            if ($exclude_ids) {
                $query->not_in('id', array_map('intval', $exclude_ids));
            }
            if ($vehicle_id !== null) {
                $query->where('id', (int) $vehicle_id);
            }
            return $query;
        };

        return [
            'vehicle_count' => (int) $scope($this->query())->count(),
            'cars'          => $scope($this->query())
                ->order_by('id', 'DESC')
                ->pagination(max(1, min((int) $limit, 100)), 1)
                ->result_array(),
        ];
    }

    /** Vehicle status totals for the administrator fleet view. */
    public function chat_status_counts()
    {
        $rows = $this->db->table($this->table)
            ->select('status, COUNT(*) AS vehicle_count')
            ->group_by('status')
            ->get_all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['vehicle_count'];
        }

        return $counts;
    }

    /**
     * Paginated, filtered list.
     * Filters: category, make, status, search, and exclude_ids (cars to leave out).
     *
     * @return array ['cars' => [...], 'meta' => [...]]
     */
    public function read($available_only, array $filters, $page, $per_page, $sort = 'newest')
    {
        $apply = static function ($query) use ($available_only, $filters) {
            if ($available_only) {
                $query->where('status', 'available');
            } elseif (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            foreach (['category', 'make'] as $column) {
                if (!empty($filters[$column])) {
                    $query->where($column, $filters[$column]);
                }
            }

            if (!empty($filters['search'])) {
                $like = '%' . $filters['search'] . '%';
                $query->grouped(static function ($q) use ($like) {
                    $q->like('make', $like)
                      ->or_like('model', $like)
                      ->or_like('plate_number', $like);
                });
            }

            if (!empty($filters['exclude_ids'])) {
                $query->not_in('id', $filters['exclude_ids']);
            }

            return $query;
        };

        $total = (int) $apply($this->query())->count();

        list($column, $direction) = self::SORTS[$sort] ?? self::SORTS['newest'];

        $cars = $apply($this->query())
            ->order_by($column, $direction)
            ->pagination($per_page, $page)
            ->result_array();

        return [
            'cars' => $cars,
            'meta' => [
                'total'        => $total,
                'per_page'     => $per_page,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $per_page)),
            ],
        ];
    }

    /**
     * Deletes a car and its image rows in one transaction.
     * Returns the deleted image rows so the controller can remove the files.
     * Throws when the car is still referenced elsewhere (foreign key).
     */
    public function delete_with_images($id)
    {
        $this->db->transaction();

        try {
            $images = $this->db->table('car_images')->where('car_id', $id)->result_array();
            $this->db->table('car_images')->where('car_id', $id)->delete();
            $this->delete($id);
            $this->db->commit();

            return $images;
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }
}
