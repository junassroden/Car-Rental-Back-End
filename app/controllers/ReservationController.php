<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationController extends Controller
{
    // Longest rental the SMALLINT UNSIGNED rental_days column can store.
    const MAX_RENTAL_DAYS = 65535;

    // Failure results from ReservationModel, mapped to [message, HTTP status].
    const ERRORS = [
        'not_found'           => ['Reservation not found.', 404],
        'vehicle_not_found'   => ['Vehicle not found.', 404],
        'vehicle_unavailable' => ['This vehicle is not available for those dates.', 409],
        'color_required'      => ['Choose a colour for this vehicle.', 422],
        'color_invalid'       => ['That colour is not offered for this vehicle.', 422],
        'date_conflict'       => ['This vehicle is already reserved for part or all of those dates.', 409],
        'invalid_transition'  => ['That reservation status change is not allowed.', 409],
        'customer_forbidden'  => ['You may only cancel your own pending or confirmed reservations.', 403],
        'duration_too_short'  => ['The selected rental period must be at least 3 days and meet the vehicle minimum.', 422],
        'duration_too_long'   => ['Rentals cannot exceed 21 days.', 422],
        'special_request_required' => ['Rentals longer than 7 days must be submitted as a special-duration request.', 422],
        'contract_not_accepted' => ['Accept the rental agreement before submitting your reservation.', 422],
        'amount_too_large'    => ['The selected rental period exceeds the maximum supported amount.', 422],
        'payment_required'    => ['A verified GCash payment is required before vehicle handover.', 409],
        'payment_refund_required' => ['A paid reservation cannot be cancelled here. Contact an administrator to arrange a refund.', 409],
        'vehicle_in_use'      => ['A vehicle in use must be marked returned before its reservation can be cancelled.', 409],
        'checkout_not_due'    => ['Vehicle handover is only available during the approved rental period.', 409],
        'vehicle_not_checked_out' => ['This vehicle has not been checked out.', 409],
        'admin_forbidden'     => ['Administrator access is required.', 403],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model('ReservationModel');
    }

    public function reservations()
    {
        $user = $this->requireUser();

        $this->response->send_json_success(
            $this->ReservationModel->for_user($user['id'], $user['role'] === 'admin'),
            'Reservations retrieved successfully.'
        );
    }

    public function reservation($id)
    {
        $user = $this->requireUser();

        $reservation = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            ? $this->ReservationModel->find_with_details((int) $id)
            : null;

        // Customers only see their own reservations; anything else is reported as missing.
        if (!$reservation || ($user['role'] !== 'admin' && (int) $reservation['user_id'] !== (int) $user['id'])) {
            $this->response->send_json_error('Reservation not found.', 404);
            return;
        }

        $this->response->send_json_success($reservation, 'Reservation retrieved successfully.');
    }

    public function createReservation()
    {
        $user = $this->requireUser();
        if ($user['role'] === 'admin') {
            $this->response->send_json_error('Customer access is required.', 403);
            return;
        }

        $input = $this->requestPayload();
        $this->validateInput($input, [
            'car_id|Vehicle'         => 'required|pattern[int]|greater_than_equal_to[1]',
            'start_date|Pickup date' => 'required|pattern[date_ymd]',
            'end_date|Return date'   => 'required|pattern[date_ymd]',
        ]);

        $startDate = $input['start_date'] ?? null;
        $endDate   = $input['end_date'] ?? null;
        $specialRequest = filter_var($input['special_request'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $acceptContract = filter_var($input['accept_contract'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $start     = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end       = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);

        // Rejects impossible dates such as 2026-02-31.
        if (!$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate) {
            $this->response->send_json_error('Enter valid rental dates.', 422);
            return;
        }
        if ($startDate < date('Y-m-d')) {
            $this->response->send_json_error('The rental start date cannot be in the past.', 422);
            return;
        }

        $variantId = null;
        if (isset($input['variant_id']) && $input['variant_id'] !== '') {
            $variantId = filter_var($input['variant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($variantId === false) {
                $this->response->send_json_error('That colour is not offered for this vehicle.', 422);
                return;
            }
        }

        $rentalDays = (int) $start->diff($end)->format('%r%a');
        if ($rentalDays < 1 || $rentalDays > self::MAX_RENTAL_DAYS) {
            $this->response->send_json_error('The return date must be after the pickup date.', 422);
            return;
        }

        try {
            $result = $this->ReservationModel->create_reservation(
                $user['id'],
                (int) $input['car_id'],
                $startDate,
                $endDate,
                $rentalDays,
                $specialRequest,
                $acceptContract,
                $variantId
            );
        } catch (Exception $exception) {
            error_log('Reservation creation failed: ' . $exception->getMessage());
            $result = ['status' => 'error'];
        }

        if ($result['status'] !== 'created') {
            error_log('Reservation request ended with: ' . $result['status']);
            [$message, $status] = self::ERRORS[$result['status']] ?? ['The reservation could not be created. Please try again.', 500];
            $this->response->send_json_error($message, $status);
            return;
        }

        $this->response->send_json_success(
            $this->ReservationModel->find_with_details($result['id']),
            'Reservation created successfully and is awaiting confirmation.',
            201
        );
    }

    public function updateReservation($id)
    {
        $user = $this->requireUser();
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $this->response->send_json_error('Reservation not found.', 404);
            return;
        }

        $status = $this->requestPayload()['status'] ?? null;
        if (!in_array($status, ReservationModel::STATUSES, true)) {
            $this->response->send_json_error('A valid reservation status is required.', 422);
            return;
        }

        try {
            $result = $this->ReservationModel->update_status((int) $id, $user['id'], $user['role'] === 'admin', $status);
        } catch (Exception $exception) {
            error_log('Reservation update failed: ' . $exception->getMessage());
            $result = 'error';
        }

        if ($result !== 'updated') {
            error_log('Reservation request ended with: ' . $result);
            [$message, $code] = self::ERRORS[$result] ?? ['The reservation could not be updated. Please try again.', 500];
            $this->response->send_json_error($message, $code);
            return;
        }

        $this->response->send_json_success($this->ReservationModel->find_with_details((int) $id), 'Reservation status updated successfully.');
    }

    public function vehicleCheckout($id)
    {
        $this->updateVehicleUse($id, 'checkout');
    }

    public function vehicleReturn($id)
    {
        $this->updateVehicleUse($id, 'return');
    }

    private function updateVehicleUse($id, $action)
    {
        $user = $this->requireUser();
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $this->response->send_json_error('Reservation not found.', 404);
            return;
        }

        try {
            $result = $this->ReservationModel->update_vehicle_use((int) $id, $user['role'] === 'admin', $action);
        } catch (Throwable $exception) {
            error_log('Vehicle handover update failed: ' . $exception->getMessage());
            $result = 'error';
        }

        if ($result !== 'updated') {
            error_log('Vehicle handover request ended with: ' . $result);
            [$message, $status] = self::ERRORS[$result] ?? ['The vehicle handover could not be updated.', 500];
            $this->response->send_json_error($message, $status);
            return;
        }

        $this->response->send_json_success(
            $this->ReservationModel->find_with_details((int) $id),
            $action === 'checkout' ? 'Vehicle checked out successfully.' : 'Vehicle return recorded successfully.'
        );
    }

    /** Returns the signed-in user, or sends a 401. */
    private function requireUser()
    {
        $user = $this->UserModel->find_active($this->authenticated_user_id());
        if (!$user) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }

        return $user;
    }

    /** The submitted form fields, or else the JSON body. Always an array. */
    private function requestPayload()
    {
        $form = $this->request->post();
        if ($form !== []) {
            return $form;
        }

        $json = $this->request->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Runs Form_validation on $input, or sends a 422. Form_validation reads $_POST, so the
     * values are placed there first, as strings, with '' for any field a rule names that
     * was not sent: its strlen() rejects NULL and its `required` rule treats the number 0 as empty.
     */
    private function validateInput(array $input, array $rules)
    {
        $fields = array_map(static function ($field) {
            return strtok($field, '|');
        }, array_keys($rules));

        $_POST = array_map(static function ($value) {
            return is_scalar($value) || $value === null ? (string) $value : $value;
        }, $input) + array_fill_keys($fields, '');

        $this->call->library('form_validation');
        if (!$this->form_validation->validate($rules)) {
            $errors = $this->form_validation->get_errors();
            $this->response->send_json_error($errors[0], 422, ['errors' => $errors]);
            exit;
        }
    }
}
