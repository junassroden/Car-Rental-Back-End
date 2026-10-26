<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationController extends ApiController
{
    public function reservations()
    {
        $user = $this->authenticatedUser();
        $reservations = $this->reservationModel->getForUser($user);
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservations retrieved successfully.', 'data' => $reservations], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function reservation($id)
    {
        $user = $this->authenticatedUser();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $reservation = $this->findReservation((int) $id);
        if (!$reservation || ($user['role'] !== 'admin' && (int) $reservation['user_id'] !== $user['id'])) {
            $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservation retrieved successfully.', 'data' => $reservation], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function createReservation()
    {
        $user = $this->authenticatedUser();
        if (!$this->isCustomerRole($user['role'])) {
            $this->response->send_json(['success' => false, 'message' => 'Only customer accounts can create reservations.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $input = $this->input();
        $carId = filter_var($input['car_id'] ?? null, FILTER_VALIDATE_INT);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? null;
        if (!$carId || $carId < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Select a valid vehicle.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!$this->isValidReservationDate($startDate) || !$this->isValidReservationDate($endDate)) {
            $this->response->send_json(['success' => false, 'message' => 'Enter valid rental dates.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        $today = new DateTimeImmutable('today');
        $rentalDays = (int) $start->diff($end)->format('%a');
        if ($start < $today) {
            $this->response->send_json(['success' => false, 'message' => 'The rental start date cannot be in the past.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if ($end <= $start || $rentalDays < 1 || $rentalDays > 65535) {
            $this->response->send_json(['success' => false, 'message' => 'The return date must be after the pickup date.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $result = $this->reservationModel->createReservation(
                $user['id'],
                $carId,
                $startDate,
                $endDate,
                $rentalDays
            );
        } catch (Exception $exception) {
            error_log('Reservation creation failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The reservation could not be created. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        switch ($result['status']) {
            case 'vehicle_not_found':
                $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'vehicle_unavailable':
                $this->response->send_json(['success' => false, 'message' => 'This vehicle is not currently available for reservations.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'duration_too_short':
                $this->response->send_json(['success' => false, 'message' => 'The selected dates do not meet this vehicle’s minimum rental duration.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'date_conflict':
                $this->response->send_json(['success' => false, 'message' => 'This vehicle is already reserved for part or all of those dates.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'amount_too_large':
                $this->response->send_json(['success' => false, 'message' => 'The selected rental period exceeds the maximum supported amount.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'start_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be started. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'commit_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be committed. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'created':
                break;
            default:
                error_log('Reservation creation returned an unexpected result.');
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be created. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
        }

        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservation created successfully and is awaiting confirmation.', 'data' => $this->findReservation($result['id'])], function ($value) { return $value !== null; }), 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function updateReservation($id)
    {
        $user = $this->authenticatedUser();
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $input = $this->input();
        $status = $input['status'] ?? null;
        if (!is_string($status)) {
            $this->response->send_json(['success' => false, 'message' => 'A valid reservation status is required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $result = $this->reservationModel->updateStatus((int) $id, $user, $status);
        } catch (Exception $exception) {
            error_log('Reservation update failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The reservation could not be updated. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        switch ($result) {
            case 'not_found':
                $this->response->send_json(['success' => false, 'message' => 'Reservation not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'invalid_transition':
                $this->response->send_json(['success' => false, 'message' => 'That reservation status change is not allowed.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'customer_forbidden':
                $this->response->send_json(['success' => false, 'message' => 'You may only cancel your own pending or confirmed reservations.'], 403, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'vehicle_unavailable':
                $this->response->send_json(['success' => false, 'message' => 'This vehicle is no longer available for those dates.'], 409, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'start_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation update could not be started. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'commit_failed':
                $this->response->send_json(['success' => false, 'message' => 'The reservation update could not be committed. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            case 'updated':
                break;
            default:
                error_log('Reservation update returned an unexpected result.');
                $this->response->send_json(['success' => false, 'message' => 'The reservation could not be updated. Please try again.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Reservation status updated successfully.', 'data' => $this->findReservation((int) $id)], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    protected function findReservation($id)
    {
        return $this->reservationModel->findWithDetails($id);
    }
}
