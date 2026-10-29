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
        'date_conflict'       => ['This vehicle is already reserved for part or all of those dates.', 409],
        'invalid_transition'  => ['That reservation status change is not allowed.', 409],
        'customer_forbidden'  => ['You may only cancel your own pending or confirmed reservations.', 403],
        'duration_too_short'  => ['The selected dates do not meet this vehicle’s minimum rental duration.', 422],
        'amount_too_large'    => ['The selected rental period exceeds the maximum supported amount.', 422],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model('ReservationModel');
    }

    public function reservations()
    {
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }

        $this->response->send_json_success(
            $this->ReservationModel->for_user($user['id'], $user['role'] === 'admin'),
            'Reservations retrieved successfully.'
        );
    }

    public function reservation($id)
    {
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }

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
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }
        if ($user['role'] === 'admin') {
            $this->response->send_json_error('Customer access is required.', 403);
            return;
        }

        // Form_validation reads $_POST, so a JSON body is placed there first.
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $_POST = is_array($input) ? $input : [];
        $this->call->library('form_validation');
        if (!$this->form_validation->validate([
            'car_id|Vehicle'         => 'required|pattern[int]|greater_than_equal_to[1]',
            'start_date|Pickup date' => 'required|pattern[date_ymd]',
            'end_date|Return date'   => 'required|pattern[date_ymd]',
        ])) {
            $errors = $this->form_validation->get_errors();
            $this->response->send_json_error($errors[0], 422, ['errors' => $errors]);
            return;
        }

        $startDate = $this->request->post('start_date');
        $endDate   = $this->request->post('end_date');
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

        $rentalDays = (int) $start->diff($end)->format('%r%a');
        if ($rentalDays < 1 || $rentalDays > self::MAX_RENTAL_DAYS) {
            $this->response->send_json_error('The return date must be after the pickup date.', 422);
            return;
        }

        try {
            $result = $this->ReservationModel->create_reservation(
                $user['id'],
                (int) $this->request->post('car_id'),
                $startDate,
                $endDate,
                $rentalDays
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
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $this->response->send_json_error('Reservation not found.', 404);
            return;
        }

        $input  = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $status = $input['status'] ?? null;
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
}
