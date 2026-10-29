<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationModel extends Model
{
    protected $table       = 'reservations';
    protected $primary_key = 'id';

    // Mass-assignment whitelist (insert()/update() respect this).
    protected $fillable = [
        'user_id', 'car_id', 'start_date', 'end_date', 'rental_days',
        'daily_rate', 'total_amount', 'status',
    ];

    // created_at uses the column default and updated_at is ON UPDATE CURRENT_TIMESTAMP.
    protected $timestamps = false;

    const STATUSES = ['pending', 'confirmed', 'cancelled', 'rejected', 'completed'];

    // Reservation statuses that block a car for the requested dates.
    const BLOCKING_STATUSES = ['pending', 'confirmed'];

    // Status changes an admin may make, keyed by the current status.
    const ADMIN_TRANSITIONS = [
        'pending'   => ['confirmed', 'rejected', 'cancelled'],
        'confirmed' => ['cancelled', 'completed'],
    ];

    const DETAIL_COLUMNS = 'reservations.id, reservations.user_id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, reservations.created_at, reservations.updated_at, cars.make, cars.model, cars.year, cars.category, cars.plate_number, cars.primary_image, users.full_name, users.username, users.email';

    const MAX_AMOUNT = 99999999.99;

    /** All reservations for an admin, or only the user's own for a customer. Newest first. */
    public function for_user($user_id, $is_admin)
    {
        $query = $this->details_query();
        if (!$is_admin) {
            $query->where('reservations.user_id', (int) $user_id);
        }

        return $query->order_by('reservations.created_at', 'DESC')
            ->order_by('reservations.id', 'DESC')
            ->get_all();
    }

    /** One reservation with its car and customer details, or NULL. */
    public function find_with_details($id)
    {
        return $this->details_query()
            ->where('reservations.id', (int) $id)
            ->limit(1)
            ->row_array();
    }

    /** IDs of cars already reserved for part of the period [start, end). */
    public function booked_car_ids($start_date, $end_date)
    {
        $ids = (array) $this->overlapping($start_date, $end_date)
            ->select('car_id')
            ->get_column(0);

        return array_values(array_unique($ids));
    }

    /** Minimal reservation facts for the authenticated customer's own chat. */
    public function chat_context_for_user($user_id, $limit = 20)
    {
        $query = $this->db->table($this->table)
            ->select('reservations.id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, cars.make, cars.model')
            ->inner_join('cars', 'cars.id = reservations.car_id')
            ->where('reservations.user_id', (int) $user_id);
        $total = (int) $query->count();

        $records = $this->db->table($this->table)
            ->select('reservations.id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, cars.make, cars.model')
            ->inner_join('cars', 'cars.id = reservations.car_id')
            ->where('reservations.user_id', (int) $user_id)
            ->order_by('reservations.created_at', 'DESC')
            ->order_by('reservations.id', 'DESC')
            ->pagination(max(1, min((int) $limit, 20)), 1)
            ->get_all();

        return ['total_count' => $total, 'reservations' => $records];
    }

    /**
     * Creates a pending reservation after locking the car and checking for overlaps.
     * Returns ['status' => 'created', 'id' => ...] or ['status' => <reason>].
     */
    public function create_reservation($user_id, $car_id, $start_date, $end_date, $rental_days)
    {
        if (!$this->db->transaction()) {
            return ['status' => 'start_failed'];
        }

        try {
            $car = $this->db->table('cars')
                ->select('id, daily_rental_price, minimum_rental_duration, status')
                ->where('id', (int) $car_id)
                ->for_update()
                ->row_array();

            if (!$car) {
                return $this->abort(['status' => 'vehicle_not_found']);
            }
            if ($car['status'] !== 'available') {
                return $this->abort(['status' => 'vehicle_unavailable']);
            }
            if ($rental_days < (int) $car['minimum_rental_duration']) {
                return $this->abort(['status' => 'duration_too_short']);
            }
            if ($this->has_conflict($car_id, $start_date, $end_date)) {
                return $this->abort(['status' => 'date_conflict']);
            }

            $daily_rate   = round((float) $car['daily_rental_price'], 2);
            $total_amount = round($daily_rate * $rental_days, 2);
            if (!is_finite($total_amount) || $total_amount > self::MAX_AMOUNT) {
                return $this->abort(['status' => 'amount_too_large']);
            }

            $id = $this->insert([
                'user_id'      => (int) $user_id,
                'car_id'       => (int) $car_id,
                'start_date'   => $start_date,
                'end_date'     => $end_date,
                'rental_days'  => (int) $rental_days,
                'daily_rate'   => number_format($daily_rate, 2, '.', ''),
                'total_amount' => number_format($total_amount, 2, '.', ''),
                'status'       => 'pending',
            ]);

            if (!$this->db->commit()) {
                return ['status' => 'commit_failed'];
            }

            return ['status' => 'created', 'id' => (int) $id];
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    /**
     * Changes a reservation's status.
     * Admins follow ADMIN_TRANSITIONS; customers may only cancel their own pending/confirmed bookings.
     * Returns 'updated' or a failure reason.
     */
    public function update_status($id, $user_id, $is_admin, $status)
    {
        if (!$this->db->transaction()) {
            return 'start_failed';
        }

        try {
            $reservation = $this->db->table($this->table)
                ->where('id', (int) $id)
                ->for_update()
                ->row_array();

            if (!$reservation || (!$is_admin && (int) $reservation['user_id'] !== (int) $user_id)) {
                return $this->abort('not_found');
            }

            if ($is_admin) {
                if (!in_array($status, self::ADMIN_TRANSITIONS[$reservation['status']] ?? [], true)) {
                    return $this->abort('invalid_transition');
                }
            } elseif ($status !== 'cancelled' || !in_array($reservation['status'], self::BLOCKING_STATUSES, true)) {
                return $this->abort('customer_forbidden');
            }

            if ($status === 'confirmed') {
                $car = $this->db->table('cars')
                    ->select('status')
                    ->where('id', (int) $reservation['car_id'])
                    ->for_update()
                    ->row_array();

                if (
                    !$car
                    || $car['status'] !== 'available'
                    || $this->has_conflict($reservation['car_id'], $reservation['start_date'], $reservation['end_date'], $id)
                ) {
                    return $this->abort('vehicle_unavailable');
                }
            }

            $this->update((int) $id, ['status' => $status]);

            if (!$this->db->commit()) {
                return 'commit_failed';
            }

            return 'updated';
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private function details_query()
    {
        return $this->db->table($this->table)
            ->select(self::DETAIL_COLUMNS)
            ->inner_join('cars', 'cars.id = reservations.car_id')
            ->inner_join('users', 'users.id = reservations.user_id');
    }

    /** TRUE when another blocking reservation overlaps the half-open period [start, end). */
    private function has_conflict($car_id, $start_date, $end_date, $ignore_id = 0)
    {
        $query = $this->overlapping($start_date, $end_date)->where('car_id', (int) $car_id);
        if ($ignore_id) {
            $query->where('id', '<>', (int) $ignore_id);
        }

        return (bool) $query->limit(1)->row_array();
    }

    /** Blocking reservations that overlap the half-open period [start, end). */
    private function overlapping($start_date, $end_date)
    {
        return $this->db->table($this->table)
            ->in('status', self::BLOCKING_STATUSES)
            ->where('start_date', '<', $end_date)
            ->where('end_date', '>', $start_date);
    }

    /** Rolls back the open transaction and passes the result through. */
    private function abort($result)
    {
        $this->db->roll_back();
        return $result;
    }
}
