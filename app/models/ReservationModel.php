<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationModel extends Model
{
    protected $table = 'reservations';

    public function getForUser(array $user)
    {
        $query = $this->db->table($this->table)
            ->select('reservations.id, reservations.user_id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, reservations.created_at, reservations.updated_at, cars.make, cars.model, cars.year, cars.category, cars.plate_number, cars.primary_image, users.full_name, users.username, users.email')
            ->inner_join('cars', 'cars.id = reservations.car_id')
            ->inner_join('users', 'users.id = reservations.user_id');
        if ($user['role'] !== 'admin') {
            $query->where('reservations.user_id', (int) $user['id']);
        }

        return $query->order_by('reservations.created_at', 'DESC')
            ->order_by('reservations.id', 'DESC')
            ->get_all();
    }

    public function findWithDetails($id)
    {
        return $this->db->table($this->table)
            ->select('reservations.id, reservations.user_id, reservations.car_id, reservations.start_date, reservations.end_date, reservations.rental_days, reservations.daily_rate, reservations.total_amount, reservations.status, reservations.created_at, reservations.updated_at, cars.make, cars.model, cars.year, cars.category, cars.plate_number, cars.primary_image, users.full_name, users.username, users.email')
            ->inner_join('cars', 'cars.id = reservations.car_id')
            ->inner_join('users', 'users.id = reservations.user_id')
            ->where('reservations.id', (int) $id)
            ->limit(1)
            ->get();
    }

    public function createReservation($userId, $carId, $startDate, $endDate, $rentalDays)
    {
        $db = $this->db;
        if (!$db->transaction()) {
            return ['status' => 'start_failed'];
        }

        try {
            $car = $db->table('cars')
                ->select('id, daily_rental_price, minimum_rental_duration, status')
                ->where('id', (int) $carId)
                ->for_update()
                ->get();
            if (!$car) {
                $db->roll_back();
                return ['status' => 'vehicle_not_found'];
            }
            if ($car['status'] !== 'available') {
                $db->roll_back();
                return ['status' => 'vehicle_unavailable'];
            }
            if ($rentalDays < (int) $car['minimum_rental_duration']) {
                $db->roll_back();
                return ['status' => 'duration_too_short'];
            }

            $conflict = $db->table($this->table)
                ->select('id')
                ->where('car_id', (int) $carId)
                ->in('status', ['pending', 'confirmed'])
                ->where('start_date', '<', $endDate)
                ->where('end_date', '>', $startDate)
                ->limit(1)
                ->get();
            if ($conflict) {
                $db->roll_back();
                return ['status' => 'date_conflict'];
            }

            $dailyRate = round((float) $car['daily_rental_price'], 2);
            $totalAmount = round($dailyRate * $rentalDays, 2);
            if (!is_finite($totalAmount) || $totalAmount > 99999999.99) {
                $db->roll_back();
                return ['status' => 'amount_too_large'];
            }

            $id = $db->table($this->table)->insert([
                'user_id' => (int) $userId,
                'car_id' => (int) $carId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'rental_days' => (int) $rentalDays,
                'daily_rate' => number_format($dailyRate, 2, '.', ''),
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'status' => 'pending',
            ]);
            if (!$db->commit()) {
                return ['status' => 'commit_failed'];
            }

            return ['status' => 'created', 'id' => $id];
        } catch (Exception $exception) {
            $db->roll_back();
            throw $exception;
        }
    }

    public function updateStatus($id, array $user, $status)
    {
        $db = $this->db;
        if (!$db->transaction()) {
            return 'start_failed';
        }

        try {
            $reservation = $db->table($this->table)
                ->where('id', (int) $id)
                ->for_update()
                ->get();
            if (!$reservation || ($user['role'] !== 'admin' && (int) $reservation['user_id'] !== (int) $user['id'])) {
                $db->roll_back();
                return 'not_found';
            }

            if ($user['role'] === 'admin') {
                $transitions = [
                    'pending' => ['confirmed', 'rejected', 'cancelled'],
                    'confirmed' => ['cancelled', 'completed'],
                ];
                if (!in_array($status, $transitions[$reservation['status']] ?? [], true)) {
                    $db->roll_back();
                    return 'invalid_transition';
                }
            } elseif ($status !== 'cancelled' || !in_array($reservation['status'], ['pending', 'confirmed'], true)) {
                $db->roll_back();
                return 'customer_forbidden';
            }

            if ($user['role'] === 'admin' && $status === 'confirmed') {
                $car = $db->table('cars')
                    ->select('status')
                    ->where('id', (int) $reservation['car_id'])
                    ->for_update()
                    ->get();
                $conflict = $db->table($this->table)
                    ->select('id')
                    ->where('car_id', (int) $reservation['car_id'])
                    ->where('id', '<>', (int) $id)
                    ->in('status', ['pending', 'confirmed'])
                    ->where('start_date', '<', $reservation['end_date'])
                    ->where('end_date', '>', $reservation['start_date'])
                    ->limit(1)
                    ->get();
                if (!$car || $car['status'] !== 'available' || $conflict) {
                    $db->roll_back();
                    return 'vehicle_unavailable';
                }
            }

            $db->table($this->table)
                ->where('id', (int) $id)
                ->update([
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if (!$db->commit()) {
                return 'commit_failed';
            }

            return 'updated';
        } catch (Exception $exception) {
            $db->roll_back();
            throw $exception;
        }
    }
}
