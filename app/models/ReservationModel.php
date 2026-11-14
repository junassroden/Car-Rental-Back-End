<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationModel extends Model
{
    protected $table       = 'reservations';
    protected $primary_key = 'id';

    // Mass-assignment whitelist (insert()/update() respect this).
    protected $fillable = [
        'user_id', 'car_id', 'start_date', 'end_date', 'rental_days',
        'daily_rate', 'total_amount', 'status', 'special_duration_request',
        'contract_text', 'contract_version', 'contract_accepted_at', 'payment_required',
        'checked_out_at', 'returned_at', 'variant_id', 'expires_at',
    ];

    // created_at uses the column default and updated_at is ON UPDATE CURRENT_TIMESTAMP.
    protected $timestamps = false;

    const STATUSES = ['pending', 'confirmed', 'cancelled', 'rejected', 'completed', 'expired'];

    // Reservation statuses that can block a car for the requested dates. A pending one blocks
    // only while its hold is running (expires_at in the future); see overlapping().
    const BLOCKING_STATUSES = ['pending', 'confirmed'];

    // How long an unpaid reservation holds its dates. The Xendit invoice expires at the same time.
    const PENDING_HOLD_MINUTES = 60;

    // Unpaid reservations one customer may hold at once, so nobody can lock the fleet.
    const MAX_PENDING_PER_CUSTOMER = 2;

    // Status changes an admin may make, keyed by the current status. A pending reservation is
    // awaiting payment and is confirmed only by a verified payment (PaymentModel::mark_paid).
    const ADMIN_TRANSITIONS = [
        'pending'   => ['cancelled'],
        'confirmed' => ['cancelled'],
    ];

    const DETAIL_COLUMNS = 'reservations.variant_id, car_color_variants.color AS variant_color, reservations.id, reservations.user_id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, reservations.expires_at, reservations.special_duration_request, reservations.contract_text, reservations.contract_version, reservations.contract_accepted_at, reservations.payment_required, reservations.checked_out_at, reservations.returned_at, reservations.created_at, reservations.updated_at, cars.make, cars.model, cars.year, cars.category, cars.plate_number, cars.primary_image, cars.security_deposit, users.full_name, users.username, users.email';

    const MAX_AMOUNT = 99999999.99;

    public function __construct()
    {
        parent::__construct();
        $this->call->library(['RentalAgreement', 'ReservationPolicy']);
    }

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

    /** IDs of cars with nothing left to rent for the period [start, end): every colour is fully booked. */
    public function booked_car_ids($start_date, $end_date)
    {
        $reservationsByCar = [];
        foreach ($this->overlapping($start_date, $end_date)->select('car_id, variant_id')->get_all() as $reservation) {
            $reservationsByCar[(int) $reservation['car_id']][] = $reservation;
        }
        $variantsByCar = [];
        foreach ($this->db->table('car_color_variants')->select('id, car_id, stock')->get_all() as $variant) {
            $variantsByCar[(int) $variant['car_id']][] = $variant;
        }

        $ids = [];
        foreach (array_keys($reservationsByCar + $variantsByCar) as $carId) {
            $reservations = $reservationsByCar[$carId] ?? [];
            $variants = $variantsByCar[$carId] ?? [];
            if (!$variants) {
                $ids[] = $carId;
                continue;
            }
            $hasFreeUnit = false;
            foreach ($variants as $variant) {
                if ($this->units_left($variant, $reservations) > 0) {
                    $hasFreeUnit = true;
                    break;
                }
            }
            if (!$hasFreeUnit) {
                $ids[] = $carId;
            }
        }

        return $ids;
    }

    /** Units of one colour free for the period, or NULL when the car has no such colour. */
    public function units_available($car_id, $variant_id, $start_date, $end_date)
    {
        foreach ($this->variants_for_car($car_id) as $variant) {
            if ((int) $variant['id'] === (int) $variant_id) {
                return $this->units_left($variant, $this->overlapping_for_car($car_id, $start_date, $end_date));
            }
        }

        return null;
    }

    public function is_available_for_dates($car_id, $start_date, $end_date, $variant_id = null)
    {
        $car = $this->db->table('cars')
            ->select('status')
            ->where('id', (int) $car_id)
            ->limit(1)
            ->row_array();

        return $car
            && $car['status'] === 'available'
            && !$this->has_conflict($car_id, $start_date, $end_date, 0, $variant_id);
    }

    /** Minimal reservation facts for the authenticated customer's own chat. */
    public function chat_context_for_user($user_id, $limit = 20)
    {
        $total = (int) $this->db->table($this->table)->where('user_id', (int) $user_id)->count();

        $records = $this->db->table($this->table)
            ->select('reservations.id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, reservations.expires_at, reservations.payment_required, reservations.checked_out_at, reservations.returned_at, cars.make, cars.model')
            ->inner_join('cars', 'cars.id = reservations.car_id')
            ->where('reservations.user_id', (int) $user_id)
            ->order_by('reservations.created_at', 'DESC')
            ->order_by('reservations.id', 'DESC')
            ->pagination(max(1, min((int) $limit, 20)), 1)
            ->get_all();

        // "Did my payment go through?": the same payment status My Reservations shows.
        return ['total_count' => $total, 'reservations' => $this->attach_payment_summary($this->with_lapsed_holds($records))];
    }

    /**
     * Every customer's reservations for the administrator chat, newest first. Only the
     * customer's name is included, never contact details. Up to 5,000 rows, so totals and
     * rankings count every reservation; lists shown in chat are cut down by ChatRetrieval.
     */
    public function chat_context_all($limit = 200)
    {
        $columns = 'reservations.id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, reservations.expires_at, reservations.payment_required, reservations.checked_out_at, reservations.returned_at, cars.make, cars.model, users.full_name AS customer_name';
        $total = (int) $this->db->table($this->table)->count();
        $records = $this->db->table($this->table)
            ->select($columns)
            ->inner_join('cars', 'cars.id = reservations.car_id')
            ->inner_join('users', 'users.id = reservations.user_id')
            ->order_by('reservations.created_at', 'DESC')
            ->order_by('reservations.id', 'DESC')
            ->pagination(max(1, min((int) $limit, 5000)), 1)
            ->get_all();

        return ['total_count' => $total, 'reservations' => $this->attach_payment_summary($this->with_lapsed_holds($records))];
    }

    /** Reports a pending reservation whose hold has run out as expired, as the next job run will. */
    private function with_lapsed_holds(array $records)
    {
        foreach ($records as &$record) {
            if ($record['status'] === 'pending' && !self::hold_active($record)) {
                $record['status'] = 'expired';
            }
        }
        unset($record);

        return $records;
    }

    /**
     * Creates a pending reservation after locking the car and checking for overlaps.
     * Returns ['status' => 'created', 'id' => ...] or ['status' => <reason>].
     */
    public function create_reservation($user_id, $car_id, $start_date, $end_date, $rental_days, $special_request, $accept_contract, $variant_id = null)
    {
        if (!$this->db->transaction()) {
            return ['status' => 'start_failed'];
        }

        try {
            // Locking the customer first makes concurrent requests from one account take turns,
            // so they cannot both pass the pending-limit check below.
            $renter = $this->db->table('users')
                ->select('id, full_name, username, email')
                ->where('id', (int) $user_id)
                ->for_update()
                ->row_array();
            if (!$renter) {
                return $this->abort(['status' => 'error']);
            }
            $now = date('Y-m-d H:i:s');
            $activePending = (int) $this->db->table($this->table)
                ->where('user_id', (int) $user_id)
                ->where('status', 'pending')
                ->where('expires_at', '>', $now)
                ->count();
            if ($activePending >= self::MAX_PENDING_PER_CUSTOMER) {
                return $this->abort(['status' => 'too_many_pending']);
            }

            $car = $this->db->table('cars')
                ->select('id, daily_rental_price, minimum_rental_duration, status, year, make, model, plate_number, security_deposit')
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
            $variants = $this->variants_for_car($car_id);
            $variant = null;
            if ($variants) {
                if ($variant_id === null) {
                    return $this->abort(['status' => 'color_required']);
                }
                foreach ($variants as $option) {
                    if ((int) $option['id'] === (int) $variant_id) {
                        $variant = $option;
                    }
                }
                if (!$variant) {
                    return $this->abort(['status' => 'color_invalid']);
                }
            }
            if ($this->has_conflict($car_id, $start_date, $end_date, 0, $variant ? (int) $variant['id'] : null)) {
                return $this->abort(['status' => 'date_conflict']);
            }

            $daily_rate   = round((float) $car['daily_rental_price'], 2);
            $total_amount = round($daily_rate * $rental_days, 2);
            if (!is_finite($total_amount) || $total_amount > self::MAX_AMOUNT) {
                return $this->abort(['status' => 'amount_too_large']);
            }

            $acceptedAt = $now;
            $id = $this->insert([
                'user_id'      => (int) $user_id,
                'car_id'       => (int) $car_id,
                'start_date'   => $start_date,
                'end_date'     => $end_date,
                'rental_days'  => (int) $rental_days,
                'daily_rate'   => number_format($daily_rate, 2, '.', ''),
                'total_amount' => number_format($total_amount, 2, '.', ''),
                // Pending means awaiting payment. Nothing to pay means nothing to wait for.
                'status'       => $total_amount > 0 ? 'pending' : 'confirmed',
                'expires_at'   => $total_amount > 0
                    ? date('Y-m-d H:i:s', strtotime($now) + self::PENDING_HOLD_MINUTES * 60)
                    : null,
                'special_duration_request' => $rental_days > 7 ? 1 : 0,
                'contract_version' => RentalAgreement::VERSION,
                'contract_accepted_at' => $acceptedAt,
                'payment_required' => $total_amount > 0 ? 1 : 0,
                'variant_id'   => $variant ? (int) $variant['id'] : null,
            ]);
            if (!$id) {
                return $this->abort(['status' => 'error']);
            }

            $contract = RentalAgreement::generate([
                'id' => (int) $id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'rental_days' => $rental_days,
                'daily_rate' => number_format($daily_rate, 2, '.', ''),
                'total_amount' => number_format($total_amount, 2, '.', ''),
            ], $car + ['variant_color' => $variant['color'] ?? ''], $renter, $acceptedAt);
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
                    || $this->has_conflict($reservation['car_id'], $reservation['start_date'], $reservation['end_date'], $id, $reservation['variant_id'])
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
                // With colours, other units can still be rented, so the listing stays available.
                if (!$this->variants_for_car($reservation['car_id'])) {
                    $this->db->table('cars')
                        ->where('id', (int) $reservation['car_id'])
                        ->update(['status' => 'rented']);
                }
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
            ->inner_join('users', 'users.id = reservations.user_id')
            ->left_join('car_color_variants', 'car_color_variants.id = reservations.variant_id');
    }

    private function attach_payment_summary(array $reservations)
    {
        if (!$reservations) {
            return $reservations;
        }

        $ids = array_values(array_map(static function ($reservation) {
            return (int) $reservation['id'];
        }, array_filter($reservations, static function ($reservation) {
            return (int) ($reservation['payment_required'] ?? 0) === 1;
        })));
        $payments = [];
        if ($ids) {
            $payments = $this->db->table('checkout_payments')
                ->select('id, reservation_id, provider, checkout_session_id, checkout_url, reference_number, amount, status, expires_at, paid_at, created_at, review_status, review_reason')
                ->in('reservation_id', $ids)
                ->order_by('id', 'DESC')
                ->get_all();
        }
        $summary = [];
        foreach ($payments as $payment) {
            $reservationId = (int) $payment['reservation_id'];
            if (!isset($summary[$reservationId])) {
                $summary[$reservationId] = [
                    'paid' => false,
                    'id' => (int) $payment['id'],
                    'status' => $payment['status'],
                    'reference' => $payment['reference_number'],
                    'amount' => $payment['amount'],
                    'method' => ['manual' => 'GCash (manual reference)', 'paymongo' => 'PayMongo GCash checkout'][$payment['provider'] ?? ''] ?? 'Xendit online checkout',
                    'created_at' => $payment['created_at'],
                    'expires_at' => $payment['expires_at'],
                    'paid_at' => null,
                    'attempts' => 0,
                    'review_reason' => null,
                ];
            }
            $summary[$reservationId]['attempts']++;
            if ($payment['review_status'] === 'needs_review' && $summary[$reservationId]['review_reason'] === null) {
                $summary[$reservationId]['review_reason'] = $payment['review_reason'];
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
            // paid: verified by the gateway. pending: checkout started, not yet verified.
            // failed: the attempt expired or was rejected. unpaid: no attempt yet.
            $reservation['payment_status'] = (int) $reservation['payment_required'] === 0
                ? 'not_required'
                : (!$payment ? 'unpaid'
                    : ($payment['paid'] ? 'paid'
                        : ($payment['status'] === 'pending' ? 'pending' : 'failed')));
            $reservation['payment_id'] = $payment['id'] ?? null;
            $reservation['payment_reference'] = $payment['reference'] ?? null;
            $reservation['payment_amount'] = $payment['amount'] ?? null;
            $reservation['payment_method'] = $payment['method'] ?? null;
            $reservation['payment_created_at'] = $payment['created_at'] ?? null;
            $reservation['payment_paid_at'] = $payment['paid_at'] ?? null;
            $reservation['payment_expires_at'] = $payment['expires_at'] ?? null;
            $reservation['payment_attempts'] = $payment['attempts'] ?? 0;
            $reservation['payment_review_reason'] = $payment['review_reason'] ?? null;
            // Seconds left to pay, so the browser can count down without comparing time zones.
            $reservation['hold_seconds_left'] = ($reservation['status'] ?? '') === 'pending' && !empty($reservation['expires_at'])
                ? max(0, strtotime($reservation['expires_at']) - time())
                : null;
        }
        unset($reservation);

        return $reservations;
    }

    /**
     * TRUE when the period [start, end) cannot take another booking.
     * Without colours the car is one unit. With colours, a colour is full once its overlapping
     * bookings reach its stock. With no colour named, the car is full when every colour is.
     */
    private function has_conflict($car_id, $start_date, $end_date, $ignore_id = 0, $variant_id = null)
    {
        $reservations = $this->overlapping_for_car($car_id, $start_date, $end_date, $ignore_id);
        $variants = $this->variants_for_car($car_id);
        if (!$variants) {
            return count($reservations) > 0;
        }

        foreach ($variants as $variant) {
            $matches = $variant_id === null || (int) $variant['id'] === (int) $variant_id;
            if ($matches && $this->units_left($variant, $reservations) > 0) {
                return false;
            }
        }

        return true;
    }

    private function variants_for_car($car_id)
    {
        return $this->db->table('car_color_variants')
            ->where('car_id', (int) $car_id)
            ->get_all();
    }

    private function overlapping_for_car($car_id, $start_date, $end_date, $ignore_id = 0)
    {
        $query = $this->overlapping($start_date, $end_date)->where('car_id', (int) $car_id);
        if ($ignore_id) {
            $query->where('id', '<>', (int) $ignore_id);
        }

        return $query->select('id, variant_id')->get_all();
    }

    /**
     * Stock left in a colour after the given bookings. A booking with no colour was made before
     * the car had colours. Its unit is unknown, so it counts against every colour to stay safe.
     */
    private function units_left(array $variant, array $reservations)
    {
        $used = 0;
        foreach ($reservations as $reservation) {
            if ($reservation['variant_id'] === null || (int) $reservation['variant_id'] === (int) $variant['id']) {
                $used++;
            }
        }

        return max(0, (int) $variant['stock'] - $used);
    }

    private function has_paid_payment($reservation_id)
    {
        return (bool) $this->db->table('checkout_payments')
            ->where('reservation_id', (int) $reservation_id)
            ->where('status', 'paid')
            ->limit(1)
            ->row_array();
    }

    /**
     * Blocking reservations that overlap the half-open period [start, end): every confirmed one,
     * and pending ones whose hold has not run out. An unpaid reservation stops blocking the
     * moment expires_at passes, even if the expiry job has not run yet.
     */
    private function overlapping($start_date, $end_date)
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->table($this->table)
            ->in('status', self::BLOCKING_STATUSES)
            ->grouped(static function ($query) use ($now) {
                $query->where('status', 'confirmed')
                      ->or_where('expires_at', '>', $now);
            })
            ->where('start_date', '<', $end_date)
            ->where('end_date', '>', $start_date);
    }

    /** TRUE while a pending reservation still holds its dates. */
    public static function hold_active(array $reservation)
    {
        return ($reservation['status'] ?? '') === 'pending'
            && !empty($reservation['expires_at'])
            && strtotime($reservation['expires_at']) > time();
    }

    /**
     * Marks every pending reservation whose hold has run out as expired, one row at a time
     * under a row lock, and returns their IDs. Safe to run repeatedly or concurrently: a row
     * already changed (paid, cancelled or expired by another run) is skipped.
     */
    public function expire_stale($limit = 500)
    {
        $now = date('Y-m-d H:i:s');
        $candidates = $this->db->table($this->table)
            ->select('id')
            ->where('status', 'pending')
            ->where('expires_at', '<=', $now)
            ->order_by('expires_at', 'ASC')
            ->limit(max(1, (int) $limit))
            ->get_all();

        $expired = [];
        foreach ($candidates as $candidate) {
            if (!$this->db->transaction()) {
                continue;
            }
            try {
                $row = $this->db->table($this->table)
                    ->select('id, status, expires_at')
                    ->where('id', (int) $candidate['id'])
                    ->for_update()
                    ->row_array();
                if (!$row || $row['status'] !== 'pending' || empty($row['expires_at']) || $row['expires_at'] > $now) {
                    $this->db->roll_back();
                    continue;
                }
                $this->db->table($this->table)->where('id', (int) $row['id'])->update(['status' => 'expired']);
                if ($this->db->commit()) {
                    $expired[] = (int) $row['id'];
                }
            } catch (Exception $exception) {
                $this->db->roll_back();
                throw $exception;
            }
        }

        return $expired;
    }

    /**
     * Decides what a verified payment does to its reservation. Must run inside the caller's
     * open transaction (PaymentModel::mark_paid). Locks the reservation row.
     *
     * Returns ['outcome' => confirmed|paid|review, 'review' => reason or NULL].
     * - A pending reservation whose hold is running is confirmed.
     * - A pending or expired one whose hold ran out is confirmed only if its dates are still
     *   free and its pickup date has not passed; otherwise the payment goes to review.
     * - A confirmed, unpaid one (approved before automatic confirmation) just becomes paid.
     * - Anything else, or a second payment, is recorded and flagged for an administrator.
     */
    public function settle_payment($reservation_id, $other_payment_paid)
    {
        $reservation = $this->db->table($this->table)
            ->where('id', (int) $reservation_id)
            ->for_update()
            ->row_array();
        if (!$reservation) {
            return ['outcome' => 'review', 'review' => 'The reservation for this payment no longer exists.'];
        }
        if ($other_payment_paid) {
            return ['outcome' => 'review', 'review' => 'This reservation was already paid by another invoice. Refund the duplicate payment.'];
        }

        $status = $reservation['status'];
        if ($status === 'confirmed') {
            return ['outcome' => 'paid', 'review' => null];
        }
        if ($status === 'pending' && self::hold_active($reservation)) {
            $this->db->table($this->table)->where('id', (int) $reservation['id'])->update(['status' => 'confirmed']);
            return ['outcome' => 'confirmed', 'review' => null];
        }
        if (in_array($status, ['pending', 'expired'], true)) {
            $car = $this->db->table('cars')
                ->select('status')
                ->where('id', (int) $reservation['car_id'])
                ->for_update()
                ->row_array();
            $free = $car
                && $car['status'] === 'available'
                && $reservation['start_date'] >= date('Y-m-d')
                && !$this->has_conflict($reservation['car_id'], $reservation['start_date'], $reservation['end_date'], (int) $reservation['id'], $reservation['variant_id']);
            if ($free) {
                $this->db->table($this->table)->where('id', (int) $reservation['id'])->update(['status' => 'confirmed']);
                return ['outcome' => 'confirmed', 'review' => null];
            }
            if ($status === 'pending') {
                $this->db->table($this->table)->where('id', (int) $reservation['id'])->update(['status' => 'expired']);
            }
            return ['outcome' => 'review', 'review' => 'Paid after the reservation hold ran out, and the dates are no longer available. Refund or rebook the customer.'];
        }

        return ['outcome' => 'review', 'review' => "Paid after the reservation was {$status}. Refund the customer."];
    }

    /** Rolls back the open transaction and passes the result through. */
    private function abort($result)
    {
        $this->db->roll_back();
        return $result;
    }
}
