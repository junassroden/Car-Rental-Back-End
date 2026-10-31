<?php

require_once __DIR__ . '/../../app/libraries/ChatRetrieval.php';

/**
 * ChatDataSource over in-memory rows shaped like the MySQL query results, for
 * tests/chat_retrieval_test.php and the chatbot evaluation. Applies the same viewer
 * rules as the SQL (customers see available cars only; bookings by user id).
 *
 * Fixture keys: cars, reservations (with user_id), users (id, full_name),
 * maintenance_records, verified_evidence_records, maintenance_due, inspections,
 * and optional "fail": [method names that throw, to test database errors].
 */
class ChatFixtureSource implements ChatDataSource
{
    private $db;

    public function __construct(array $db)
    {
        $this->db = $db + ['cars' => [], 'reservations' => [], 'users' => [], 'maintenance_records' => [],
                           'verified_evidence_records' => [], 'maintenance_due' => [], 'inspections' => [], 'fail' => []];
    }

    private function guard($method)
    {
        if (in_array($method, $this->db['fail'], true)) {
            throw new RuntimeException('Simulated database failure in ' . $method);
        }
    }

    public function vehicles($is_admin)
    {
        $this->guard('vehicles');
        $rows = $is_admin ? $this->db['cars'] : array_filter($this->db['cars'], static function ($car) {
            return $car['status'] === 'available';
        });
        usort($rows, static function ($a, $b) { return $b['id'] <=> $a['id']; });
        return array_values($rows);
    }

    public function booked_vehicle_ids($start_date, $end_date)
    {
        $this->guard('booked_vehicle_ids');
        $ids = [];
        foreach ($this->db['reservations'] as $row) {
            if (in_array($row['status'], ['pending', 'confirmed'], true) && $row['start_date'] < $end_date && $row['end_date'] > $start_date) {
                $ids[] = (int) $row['car_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    public function reservations_for_user($user_id, $limit)
    {
        $this->guard('reservations_for_user');
        $rows = array_values(array_filter($this->db['reservations'], static function ($row) use ($user_id) {
            return (int) $row['user_id'] === (int) $user_id;
        }));
        return ['total_count' => count($rows), 'reservations' => array_map([$this, 'with_car'], array_slice($rows, 0, $limit))];
    }

    public function reservations_all($limit)
    {
        $this->guard('reservations_all');
        $names = array_column($this->db['users'], 'full_name', 'id');
        $rows  = array_map(function ($row) use ($names) {
            return $this->with_car($row) + ['customer_name' => $names[$row['user_id']] ?? null];
        }, array_slice($this->db['reservations'], 0, $limit));
        return ['total_count' => count($this->db['reservations']), 'reservations' => $rows];
    }

    public function vehicle_status_counts()
    {
        $this->guard('vehicle_status_counts');
        return array_count_values(array_column($this->db['cars'], 'status'));
    }

    public function maintenance_records($vehicle_id, $limit)
    {
        $this->guard('maintenance_records');
        $filter = static function ($rows, $key) use ($vehicle_id, $limit) {
            return array_slice(array_values(array_filter($rows, static function ($row) use ($vehicle_id, $key) {
                return $vehicle_id === null || (int) $row[$key] === (int) $vehicle_id;
            })), 0, $limit);
        };
        return [
            'legacy_service_records'            => $filter($this->db['maintenance_records'], 'vehicleID'),
            'verified_evidence_records'         => $filter($this->db['verified_evidence_records'], 'vehicle_id'),
            'verified_evidence_table_available' => true,
        ];
    }

    public function maintenance_due()
    {
        $this->guard('maintenance_due');
        return $this->db['maintenance_due'];
    }

    public function component_inspections($vehicle_id, $limit)
    {
        $this->guard('component_inspections');
        return array_slice(array_values(array_filter($this->db['inspections'], static function ($row) use ($vehicle_id) {
            return $vehicle_id === null || (int) $row['vehicle_id'] === (int) $vehicle_id;
        })), 0, $limit);
    }

    public function maintenance_cost_records()
    {
        $this->guard('maintenance_cost_records');
        return $this->db['maintenance_records'];
    }

    public function model_feature_vehicles()
    {
        $this->guard('model_feature_vehicles');
        return array_map(static function ($car) {
            return ['id' => (int) $car['id'], 'brand' => $car['make'], 'model' => $car['model'], 'vehicle_type' => strtolower((string) $car['category']),
                    'fuel_type' => $car['fuel_type'], 'make_year' => (int) $car['year'], 'mileage' => (int) ($car['current_mileage'] ?? 0)];
        }, $this->db['cars']);
    }

    private function with_car(array $row)
    {
        foreach ($this->db['cars'] as $car) {
            if ((int) $car['id'] === (int) $row['car_id']) {
                return $row + ['make' => $car['make'], 'model' => $car['model']];
            }
        }
        return $row;
    }
}
