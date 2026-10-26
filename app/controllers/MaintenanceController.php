<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MaintenanceController extends ApiController
{
    public function maintenanceRecords()
    {
        $this->requireAdmin();
        $this->maintenanceModel->ensureSchema();

        $vehicles = $this->maintenanceModel->getVehicleOverview();

        $records = $this->maintenanceModel->getVehicleRecords();

        $summary = [
            'total_vehicles' => count($vehicles),
            'approaching' => 0,
            'overdue' => 0,
            'insufficient' => 0,
        ];

        $warningWindow = new DateTimeImmutable('today');
        $warningWindow = $warningWindow->modify('+30 days');

        foreach ($vehicles as $vehicle) {
            $lastDate = $vehicle['last_maintenance_date'];
            $mileage = $vehicle['current_mileage'];
            if ($lastDate === null && $mileage === null) {
                $summary['insufficient']++;
                continue;
            }
            if ($lastDate !== null) {
                $threshold = new DateTimeImmutable($lastDate);
                $nextDue = $threshold->modify('+180 days');
                if ($mileage !== null && ($vehicle['last_maintenance_odometer'] !== null && ((int) $vehicle['last_maintenance_odometer'] + 5000) <= (int) $mileage)) {
                    $summary['overdue']++;
                } elseif ($nextDue <= $warningWindow) {
                    $summary['approaching']++;
                }
            }
        }

        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance records retrieved successfully.', 'data' => [
            'summary' => $summary,
            'vehicles' => $vehicles,
            'records' => $records,
        ]], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function maintenancePredictions()
    {
        $this->requireAdmin();
        $this->maintenanceModel->ensureSchema();

        $predictions = $this->maintenanceModel->getPredictions();
        $vehicles = $this->maintenanceModel->getVehicles();

        $summary = [
            'total_vehicles' => count($vehicles),
            'approaching' => 0,
            'overdue' => 0,
            'insufficient' => 0,
        ];
        foreach ($predictions as $prediction) {
            $level = strtolower((string) ($prediction['riskLevel'] ?? 'low'));
            if ($level === 'high' || $level === 'overdue') {
                $summary['overdue']++;
            } elseif ($level === 'medium' || $level === 'approaching') {
                $summary['approaching']++;
            } elseif ($level === 'insufficient') {
                $summary['insufficient']++;
            }
        }

        if (!$predictions) {
            $predictions = $this->maintenanceModel->generatePredictions();
            $summary = [
                'total_vehicles' => count($vehicles),
                'approaching' => 0,
                'overdue' => 0,
                'insufficient' => 0,
            ];
            foreach ($predictions as $prediction) {
                $level = strtolower((string) ($prediction['riskLevel'] ?? 'low'));
                if ($level === 'high' || $level === 'overdue') {
                    $summary['overdue']++;
                } elseif ($level === 'medium' || $level === 'approaching') {
                    $summary['approaching']++;
                } elseif ($level === 'insufficient') {
                    $summary['insufficient']++;
                }
            }
        }

        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance predictions retrieved successfully.', 'data' => [
            'summary' => $summary,
            'vehicles' => $vehicles,
            'predictions' => $predictions,
        ]], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function createMaintenanceRecord()
    {
        $this->requireAdmin();
        $input = $this->input();

        $required = ['vehicleID', 'maintenanceType'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
                $this->response->send_json(['success' => false, 'message' => 'Vehicle and maintenance type are required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }

        if (!$this->carModel->findCar((int) $input['vehicleID'])) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $record = $this->maintenanceModel->createRecord($input);
            $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance record saved successfully.', 'data' => $record], function ($value) { return $value !== null; }), 201, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json(['success' => false, 'message' => $exception->getMessage()], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } catch (Exception $exception) {
            error_log('Maintenance record creation failed: ' . $exception->getMessage());
            $this->response->send_json(['success' => false, 'message' => 'The maintenance record could not be saved.'], 500, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

    public function updateMaintenanceRecord($id)
    {
        $this->requireAdmin();

        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->response->send_json(['success' => false, 'message' => 'Maintenance record not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $input = $this->input();
        $result = $this->maintenanceModel->updateRecord((int) $id, $input);
        if ($result === null) {
            $this->response->send_json(['success' => false, 'message' => 'Maintenance record not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!$result['updated']) {
            $this->response->send_json(['success' => false, 'message' => 'No fields were provided to update.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance record updated successfully.', 'data' => $result['record']], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function updateVehicleMileage()
    {
        $this->requireAdmin();
        $input = $this->input();

        $vehicleId = (int) ($input['vehicleID'] ?? $input['car_id'] ?? 0);
        $mileage = isset($input['current_mileage']) ? (int) $input['current_mileage'] : (isset($input['mileage']) ? (int) $input['mileage'] : 0);
        if ($vehicleId < 1 || $mileage < 0) {
            $this->response->send_json(['success' => false, 'message' => 'A valid vehicle and mileage are required.'], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (!$this->carModel->findCar((int) $vehicleId)) {
            $this->response->send_json(['success' => false, 'message' => 'Vehicle not found.'], 404, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        try {
            $updated = $this->maintenanceModel->updateMileage($vehicleId, $mileage);
            $this->response->send_json(array_filter(['success' => true, 'message' => 'Vehicle mileage updated successfully.', 'data' => $updated], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json(['success' => false, 'message' => $exception->getMessage()], 422, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

    public function generateMaintenancePredictions()
    {
        $this->requireAdmin();
        $predictions = $this->maintenanceModel->generatePredictions();
        $this->response->send_json(array_filter(['success' => true, 'message' => 'Maintenance predictions were recalculated successfully.', 'data' => $predictions], function ($value) { return $value !== null; }), 200, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
