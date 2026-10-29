<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Admin-only vehicle maintenance records and service-interval estimates. */
class MaintenanceController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model(['MaintenanceModel', 'CarModel']);

        // Every endpoint here is for administrators only.
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }
        if ($user['role'] !== 'admin') {
            $this->response->send_json_error('Administrator access is required.', 403);
            exit;
        }
    }

    public function maintenanceRecords()
    {
        $vehicles = $this->MaintenanceModel->vehicle_overview();

        $this->response->send_json_success([
            'summary'  => $this->MaintenanceModel->overview_summary($vehicles),
            'vehicles' => $vehicles,
            'records'  => $this->MaintenanceModel->records(),
        ], 'Maintenance records retrieved successfully.');
    }

    public function maintenancePredictions()
    {
        $vehicles    = $this->MaintenanceModel->vehicles();
        $predictions = $this->MaintenanceModel->predictions() ?: $this->MaintenanceModel->generate_predictions();

        $this->response->send_json_success([
            'summary'     => $this->MaintenanceModel->prediction_summary($predictions, count($vehicles)),
            'vehicles'    => $vehicles,
            'predictions' => $predictions,
        ], 'Maintenance predictions retrieved successfully.');
    }

    public function generateMaintenancePredictions()
    {
        $this->response->send_json_success($this->MaintenanceModel->generate_predictions(), 'Maintenance predictions were recalculated successfully.');
    }

    public function createMaintenanceRecord()
    {
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $input = is_array($input) ? $input : [];

        foreach (['vehicleID', 'maintenanceType'] as $field) {
            if (!isset($input[$field]) || !is_scalar($input[$field]) || trim((string) $input[$field]) === '') {
                $this->response->send_json_error('Vehicle and maintenance type are required.', 422);
                return;
            }
        }

        $vehicleId = filter_var($input['vehicleID'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$vehicleId || !$this->CarModel->find($vehicleId)) {
            $this->response->send_json_error('Vehicle not found.', 404);
            return;
        }

        try {
            $record = $this->MaintenanceModel->create_record($input);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Maintenance record creation failed: ' . $exception->getMessage());
            $this->response->send_json_error('The maintenance record could not be saved.', 500);
            return;
        }

        $this->response->send_json_success($record, 'Maintenance record saved successfully.', 201);
    }

    public function updateMaintenanceRecord($id)
    {
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }

        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();

        try {
            $result = $this->MaintenanceModel->update_record((int) $id, is_array($input) ? $input : []);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Maintenance record update failed: ' . $exception->getMessage());
            $this->response->send_json_error('The maintenance record could not be updated.', 500);
            return;
        }

        if ($result === null) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }
        if (!$result['updated']) {
            $this->response->send_json_error('No fields were provided to update.', 422);
            return;
        }

        $this->response->send_json_success($result['record'], 'Maintenance record updated successfully.');
    }

    public function updateVehicleMileage()
    {
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();

        $vehicleId = (int) ($input['vehicleID'] ?? $input['car_id'] ?? 0);
        $mileage   = (int) ($input['current_mileage'] ?? $input['mileage'] ?? 0);
        if ($vehicleId < 1 || $mileage < 0) {
            $this->response->send_json_error('A valid vehicle and mileage are required.', 422);
            return;
        }
        if (!$this->CarModel->find($vehicleId)) {
            $this->response->send_json_error('Vehicle not found.', 404);
            return;
        }

        try {
            $vehicle = $this->MaintenanceModel->update_mileage($vehicleId, $mileage);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        }

        $this->response->send_json_success($vehicle, 'Vehicle mileage updated successfully.');
    }
}
