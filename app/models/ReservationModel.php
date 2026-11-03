<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once ROOT_DIR . 'app' . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'RentalAgreement.php';
require_once ROOT_DIR . 'app' . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'ReservationPolicy.php';

class ReservationModel extends Model
{
    protected $table       = 'reservations';
    protected $primary_key = 'id';

    // Mass-assignment whitelist (insert()/update() respect this).
    protected $fillable = [
        'user_id', 'car_id', 'start_date', 'end_date', 'rental_days',
        'daily_rate', 'total_amount', 'status', 'special_duration_request',
        'contract_text', 'contract_version', 'contract_accepted_at', 'payment_required',
        'checked_out_at', 'returned_at',
    ];

    // created_at uses the column default and updated_at is ON UPDATE CURRENT_TIMESTAMP.
    protected $timestamps = false;

    const STATUSES = ['pending', 'confirmed', 'cancelled', 'rejected', 'completed'];

    // Reservation statuses that block a car for the requested dates.
    const BLOCKING_STATUSES = ['pending', 'confirmed'];

    // Status changes an admin may make, keyed by the current status.
    const ADMIN_TRANSITIONS = [
        'pending'   => ['confirmed', 'rejected', 'cancelled'],
        'confirmed' => ['cancelled'],
    ];

    const DETAIL_COLUMNS = 'reservations.id, reservations.user_id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, reservations.special_duration_request, reservations.contract_text, reservations.contract_version, reservations.contract_accepted_at, reservations.payment_required, reservations.checked_out_at, reservations.returned_at, reservations.created_at, reservations.updated_at, cars.make, cars.model, cars.year, cars.category, cars.plate_number, cars.primary_image, cars.security_deposit, users.full_name, users.username, users.email';

    const MAX_AMOUNT = 99999999.99;

    /** All reservations for an admin, or only the user's own for a customer. Newest first. */
    public function for_user($user_id, $is_admin)
    {
        $query = $this->details_query();
        if (!$is_admin) {
            $query->where('reservations.user_id', (int) $user_id);
        }

        $reservations = $query->order_by('reservations.created_at', 'DESC')
            ->order_by('reservations.id', 'DESC')
            ->get_all();
        return $this->attach_payment_summary($reservations);
    }

    /** One reservation with its car and customer details, or NULL. */
    public function find_with_details($id)
    {
        $reservation = $this->details_query()
            ->where('reservations.id', (int) $id)
            ->limit(1)
            ->row_array();
        if (!$reservation) {
            return null;
        }
        return $this->attach_payment_summary([$reservation])[0];
    }

    /** IDs of cars already reserved for part of the period [start, end). */
    public function booked_car_ids($start_date, $end_date)
    {
        $ids = (array) $this->overlapping($start_date, $end_date)
            ->select('car_id')
            ->get_column(0);

        return array_values(array_unique($ids));
    }

    public function is_available_for_dates($car_id, $start_date, $end_date)
    {
        $car = $this->db->table('cars')
            ->select('status')
            ->where('id', (int) $car_id)
            ->limit(1)
            ->row_array();

        return $car
            && $car['status'] === 'available'
            && !$this->has_conflict($car_id, $start_date, $end_date);
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
    public function create_reservation($user_id, $car_id, $start_date, $end_date, $rental_days, $special_request, $accept_contract)
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
            $durationError = ReservationPolicy::duration_error(
                (int) $rental_days,
                (int) $car['minimum_rental_duration'],
                (bool) $special_request
            );
            if ($durationError !== null) {
                return $this->abort(['status' => $durationError]);
            }
            if (!$accept_contract) {
                return $this->abort(['status' => 'contract_not_accepted']);
            }
            if ($this->has_conflict($car_id, $start_date, $end_date)) {
                return $this->abort(['status' => 'date_conflict']);
            }

            $daily_rate   = round((float) $car['daily_rental_price'], 2);
            $total_amount = round($daily_rate * $rental_days, 2);
            if (!is_finite($total_amount) || $total_amount > self::MAX_AMOUNT) {
                return $this->abort(['status' => 'amount_too_large']);
            }

            $acceptedAt = date('Y-m-d H:i:s');
            $id = $this->insert([
                'user_id'      => (int) $user_id,
                'car_id'       => (int) $car_id,
                'start_date'   => $start_date,
                'end_date'     => $end_date,
                'rental_days'  => (int) $rental_days,
                'daily_rate'   => number_format($daily_rate, 2, '.', ''),
                'total_amount' => number_format($total_amount, 2, '.', ''),
                'status'       => 'pending',
                'special_duration_request' => $rental_days > 7 ? 1 : 0,
                'contract_version' => RentalAgreement::VERSION,
                'contract_accepted_at' => $acceptedAt,
                'payment_required' => $total_amount > 0 ? 1 : 0,
            ]);
            if (!$id) {
                return $this->abort(['status' => 'error']);
            }

            $renter = $this->db->table('users')
                ->select('full_name, username, email')
                ->where('id', (int) $user_id)
                ->limit(1)
                ->row_array();
            if (!$renter) {
                return $this->abort(['status' => 'error']);
            }
            $carDetails = $this->db->table('cars')
                ->select('year, make, model, plate_number, security_deposit')
                ->where('id', (int) $car_id)
                ->limit(1)
                ->row_array();
            $contract = RentalAgreement::generate([
                'id' => (int) $id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'rental_days' => $rental_days,
                'daily_rate' => number_format($daily_rate, 2, '.', ''),
                'total_amount' => number_format($total_amount, 2, '.', ''),
            ], $carDetails, $renter, $acceptedAt);
            $this->db->table($this->table)
                ->where('id', (int) $id)
                ->update(['contract_text' => $contract]);

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

            if ($status === 'cancelled' && !empty($reservation['checked_out_at'])) {
                return $this->abort('vehicle_in_use');
            }
            if ($status === 'cancelled' && $this->has_paid_payment($id)) {
                return $this->abort('payment_refund_required');
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

    public function update_vehicle_use($id, $is_admin, $action)
    {
        if (!$is_admin) {
            return 'admin_forbidden';
        }
        if (!in_array($action, ['checkout', 'return'], true)) {
            return 'invalid_transition';
        }
        if (!$this->db->transaction()) {
            return 'start_failed';
        }

        try {
            $reservation = $this->db->table($this->table)
                ->where('id', (int) $id)
                ->for_update()
                ->row_array();
            if (!$reservation) {
                return $this->abort('not_found');
            }
            if ($reservation['status'] !== 'confirmed') {
                return $this->abort('invalid_transition');
            }

            $car = $this->db->table('cars')
                ->select('status')
                ->where('id', (int) $reservation['car_id'])
                ->for_update()
                ->row_array();
            if (!$car) {
                return $this->abort('vehicle_not_found');
            }

            if ($action === 'checkout') {
                $today = date('Y-m-d');
                if (!empty($reservation['checked_out_at']) || $reservation['start_date'] > $today || $reservation['end_date'] <= $today) {
                    return $this->abort('checkout_not_due');
                }
                if ($car['status'] !== 'available') {
                    return $this->abort('vehicle_unavailable');
                }
                if ((int) $reservation['payment_required'] === 1 && !$this->has_paid_payment($id)) {
                    return $this->abort('payment_required');
                }

                $this->db->table($this->table)
                    ->where('id', (int) $id)
                    ->update(['checked_out_at' => date('Y-m-d H:i:s')]);
                $this->db->table('cars')
                    ->where('id', (int) $reservation['car_id'])
                    ->update(['status' => 'rented']);
            } else {
                if (empty($reservation['checked_out_at'])) {
                    return $this->abort('vehicle_not_checked_out');
                }
                $this->db->table($this->table)
                    ->where('id', (int) $id)
                    ->update([
                        'status' => 'completed',
                        'returned_at' => date('Y-m-d H:i:s'),
                    ]);
                if ($car['status'] === 'rented') {
                    $this->db->table('cars')
                        ->where('id', (int) $reservation['car_id'])
                        ->update(['status' => 'available']);
                }
            }

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

    private function attach_payment_summary(array $reservations)
    {
        if (!$reservations) {
            return $reservations;
        }

        $ids = array_map(static function ($reservation) {
            return (int) $reservation['id'];
        }, $reservations);
        $payments = $this->db->table('checkout_payments')
            ->select('id, reservation_id, reference_number, status, paid_at')
            ->in('reservation_id', $ids)
            ->order_by('id', 'DESC')
            ->get_all();
        $summary = [];
        foreach ($payments as $payment) {
            $reservationId = (int) $payment['reservation_id'];
            if (!isset($summary[$reservationId])) {
                $summary[$reservationId] = [
                    'paid' => false,
                    'status' => $payment['status'],
                    'reference' => $payment['reference_number'],
                    'paid_at' => null,
                ];
            }
            if ($payment['status'] === 'paid') {
                $summary[$reservationId]['paid'] = true;
                if ($summary[$reservationId]['paid_at'] === null) {
                    $summary[$reservationId]['paid_at'] = $payment['paid_at'];
                }
            }
        }

        foreach ($reservations as &$reservation) {
            $reservationId = (int) $reservation['id'];
            $payment = $summary[$reservationId] ?? null;
            $reservation['payment_status'] = (int) $reservation['payment_required'] === 0
                ? 'not_required'
                : ($payment && $payment['paid'] ? 'paid' : ($payment && $payment['status'] === 'pending' ? 'pending' : 'unpaid'));
            $reservation['payment_reference'] = $payment['reference'] ?? null;
            $reservation['payment_paid_at'] = $payment['paid_at'] ?? null;
        }
        unset($reservation);

        return $reservations;
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

    private function has_paid_payment($reservation_id)
    {
        return (bool) $this->db->table('checkout_payments')
            ->where('reservation_id', (int) $reservation_id)
            ->where('status', 'paid')
            ->limit(1)
            ->row_array();
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
