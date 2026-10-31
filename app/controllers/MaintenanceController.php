<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries/AiServiceClient.php';
require_once APP_DIR . 'libraries/ComponentMaintenanceRules.php';

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
            'summary'           => $this->MaintenanceModel->prediction_summary($predictions, count($vehicles)),
            'vehicles'          => $vehicles,
            'predictions'       => $predictions,
            'model_predictions' => $this->modelPredictions(),
        ], 'Maintenance predictions retrieved successfully.');
    }

    /**
     * Service-need predictions from the registered model for every vehicle the
     * model can score. Rule-based 'predictions' above are unchanged; this block
     * is separate so the two are never mixed.
     */
    private function modelPredictions()
    {
        $this->config->load('api');
        $vehicles = array_map(static function ($vehicle) {
            return AiServiceClient::model_vehicle($vehicle, ComponentMaintenanceRules::body_type($vehicle['category']));
        }, $this->MaintenanceModel->model_feature_vehicles());
        if (!$vehicles) {
            return ['status' => 'not_run', 'reason' => 'There are no vehicles in the fleet.'];
        }

        $result = AiServiceClient::request('POST', AiServiceClient::endpoint('fleet/analyze'), ['vehicles' => $vehicles], 20);
        if (!is_array($result)) {
            return ['status' => 'unavailable', 'reason' => 'The AI service could not be reached.'];
        }
        if (($result['status'] ?? null) !== 'success') {
            return ['status' => 'unavailable', 'reason' => (string) ($result['error'] ?? 'The maintenance model is not available.')];
        }

        return [
            'status'        => 'available',
            'model_version' => $result['model_version'] ?? null,
            'generated_at'  => date(DATE_ATOM),
            'summary'       => $result['fleet_summary'] ?? null,
            'vehicles'      => $result['prioritized_vehicles'] ?? [],
            'not_scored'    => $result['rejected_vehicles'] ?? [],
            'meaning'       => 'Probability that each service item would be performed at a workshop visit at the current odometer. Not a detected fault or a failure date.',
        ];
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
