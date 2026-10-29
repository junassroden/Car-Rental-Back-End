<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries/ComponentMaintenanceRules.php';
require_once APP_DIR . 'libraries/MaintenanceDataPolicy.php';

/**
 * Admin-only component maintenance for the 3D Predictive Maintenance page:
 * per-component condition, schedule and prediction status, inspection history,
 * manual inspections, and configured service intervals.
 */
class ComponentMaintenanceController extends Controller
{
    const PREDICTION_UNAVAILABLE = 'Prediction unavailable: insufficient validated data.';

    private $actor_id;

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model(['ComponentMaintenanceModel']);

        // Every endpoint here is for active administrators only.
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }
        if (!MaintenanceDataPolicy::can_access($user)) {
            $this->response->send_json_error('Administrator access is required.', 403);
            exit;
        }
        $this->actor_id = (int) $user['id'];
    }

    /** GET /api/maintenance/vehicles/{id}/components */
    public function components($id)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle) {
            return;
        }

        $this->config->load('api');
        $validDays  = max(1, (int) config_item('component_condition_valid_days'));
        $today      = new DateTimeImmutable('today');
        $bodyType   = ComponentMaintenanceRules::body_type($vehicle['category']);
        $currentKm  = $vehicle['current_mileage'] === null ? null : (int) $vehicle['current_mileage'];
        $types      = $this->ComponentMaintenanceModel->component_types($bodyType);
        $history    = $this->ComponentMaintenanceModel->inspections_by_component($vehicle['id']);
        $rules      = $this->ComponentMaintenanceModel->intervals($vehicle['id'], $bodyType);
        $prediction = $this->prediction_gate($vehicle, $bodyType);

        $components = [];
        foreach ($types as $type) {
            $key       = $type['component_key'];
            $records   = $history[$key] ?? [];
            $condition = ComponentMaintenanceRules::evaluate_condition($records, $today, $validDays);
            $schedule  = ComponentMaintenanceRules::evaluate_schedule($records, $rules[$key] ?? null, $currentKm, $today);

            $components[] = [
                'key'                => $key,
                'name'               => $type['display_name'],
                'condition'          => $condition,
                'schedule'           => $schedule,
                'prediction'         => $prediction['components'][$key] ?? $prediction['default'],
                'latest_record'      => $records[0] ?? null,
                'last_service'       => $schedule['last_service'],
                'history_count'      => count($records),
                'recommended_action' => ComponentMaintenanceRules::recommended_action($records, $condition, $schedule),
            ];
        }

        $this->response->send_json_success([
            'vehicle' => [
                'id'              => (int) $vehicle['id'],
                'make'            => $vehicle['make'],
                'model'           => $vehicle['model'],
                'year'            => (int) $vehicle['year'],
                'category'        => $vehicle['category'],
                'body_type'       => $bodyType,
                'plate_number'    => $vehicle['plate_number'],
                'color'           => $vehicle['color'],
                'status'          => $vehicle['status'],
                'current_mileage' => $currentKm,
            ],
            'evaluated_on'            => $today->format('Y-m-d'),
            'condition_evidence_days' => $validDays,
            'prediction_service'      => $prediction['service'],
            'components'              => $components,
        ], 'Component maintenance status retrieved successfully.');
    }

    /** GET /api/maintenance/vehicles/{id}/components/{key}/history */
    public function history($id, $key)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle || !$this->find_component($vehicle, $key)) {
            return;
        }

        $this->response->send_json_success([
            'vehicle_id'    => (int) $vehicle['id'],
            'component_key' => $key,
            'records'       => $this->ComponentMaintenanceModel->history($vehicle['id'], $key),
        ], 'Component history retrieved successfully.');
    }

    /** POST /api/maintenance/vehicles/{id}/components/{key}/inspections */
    public function createInspection($id, $key)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle || !$this->find_component($vehicle, $key)) {
            return;
        }

        try {
            $record = ComponentMaintenanceRules::normalize_inspection($this->input(), new DateTimeImmutable('today'));
            $saved  = $this->ComponentMaintenanceModel->create_inspection($vehicle['id'], $key, $record, $this->actor_id);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Component inspection save failed: ' . $exception->getMessage());
            $this->response->send_json_error('The inspection could not be saved.', 500);
            return;
        }

        $this->response->send_json_success($saved, 'Inspection recorded successfully.', 201);
    }

    /** PUT /api/maintenance/vehicles/{id}/components/{key}/schedule */
    public function saveSchedule($id, $key)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle || !$this->find_component($vehicle, $key)) {
            return;
        }

        try {
            $rule     = ComponentMaintenanceRules::normalize_interval($this->input());
            $bodyType = ComponentMaintenanceRules::body_type($vehicle['category']);
            if ($rule['scope'] === 'body_type' && $bodyType === null) {
                throw new InvalidArgumentException('This vehicle category has no body type, so the interval can only apply to this vehicle.');
            }
            $this->ComponentMaintenanceModel->save_interval($vehicle['id'], $bodyType, $key, $rule, $this->actor_id);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Service interval save failed: ' . $exception->getMessage());
            $this->response->send_json_error('The service interval could not be saved.', 500);
            return;
        }

        $this->response->send_json_success(null, 'Service interval saved successfully.');
    }

    /** DELETE /api/maintenance/vehicles/{id}/components/{key}/schedule?scope=vehicle|body_type */
    public function deleteSchedule($id, $key)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle || !$this->find_component($vehicle, $key)) {
            return;
        }

        $scope = $this->request->get('scope');
        if (!in_array($scope, ['vehicle', 'body_type'], true)) {
            $this->response->send_json_error('Specify which interval to remove.', 422);
            return;
        }

        $bodyType = ComponentMaintenanceRules::body_type($vehicle['category']);
        if (!$this->ComponentMaintenanceModel->delete_interval($vehicle['id'], $bodyType, $key, $scope)) {
            $this->response->send_json_error('No interval is configured for that scope.', 404);
            return;
        }

        $this->response->send_json_success(null, 'Service interval removed.');
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private function find_vehicle($id)
    {
        $id      = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $vehicle = $id ? $this->ComponentMaintenanceModel->vehicle($id) : null;
        if (!$vehicle) {
            $this->response->send_json_error('Vehicle not found.', 404);
            return null;
        }
        return $vehicle;
    }

    /** The component type, provided it exists and applies to the vehicle's body type. */
    private function find_component(array $vehicle, $key)
    {
        $bodyType = ComponentMaintenanceRules::body_type($vehicle['category']);
        $type     = preg_match('/^[a-z0-9-]{1,40}$/', (string) $key) ? $this->ComponentMaintenanceModel->component_type($key) : null;
        if (!$type || !ComponentMaintenanceModel::applies($type, $bodyType)) {
            $this->response->send_json_error('Component not found for this vehicle.', 404);
            return null;
        }
        return $type;
    }

    private function input()
    {
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();
        if (!is_array($input)) {
            throw new InvalidArgumentException('Request body must be a JSON object.');
        }
        return $input;
    }

    /**
     * Asks the Django service which maintenance models are validated. A
     * component gets a prediction only when a model eligible for inference
     * lists it as a target and returns a score for it; anything else is
     * reported as unavailable with the reason.
     */
    private function prediction_gate(array $vehicle, $bodyType)
    {
        $unavailable = function ($reason) {
            return ['status' => 'unavailable', 'message' => self::PREDICTION_UNAVAILABLE, 'reason' => $reason];
        };

        $this->config->load('api');
        $capabilities = $this->ai_request('GET', (string) config_item('ai_service_capabilities_url'));
        if (!is_array($capabilities)) {
            return [
                'service'    => ['reachable' => false, 'model_status' => null],
                'default'    => $unavailable('The AI service could not be reached, so no validated model could be confirmed.'),
                'components' => [],
            ];
        }

        $model    = is_array($capabilities['maintenance_model'] ?? null) ? $capabilities['maintenance_model'] : [];
        $eligible = ($model['eligible_for_inference'] ?? false) === true && ($model['status'] ?? '') === 'evaluated_accepted';
        $service  = ['reachable' => true, 'model_status' => $model['status'] ?? 'not_registered'];
        $reason   = is_string($model['reason'] ?? null) && $model['reason'] !== ''
            ? 'The maintenance model is ' . str_replace('_', ' ', $service['model_status']) . ': ' . $model['reason']
            : 'No validated maintenance model is registered.';

        if (!$eligible) {
            return ['service' => $service, 'default' => $unavailable($reason), 'components' => []];
        }

        $targets = array_keys(is_array($model['target_definitions'] ?? null) ? $model['target_definitions'] : []);
        $default = $unavailable('The validated maintenance model does not cover this component.');
        if (!$targets) {
            return ['service' => $service, 'default' => $default, 'components' => []];
        }

        $result = $this->ai_request('POST', preg_replace('#/capabilities/?$#', '/predict/', (string) config_item('ai_service_capabilities_url')), [
            'vehicle' => [
                'id'           => (int) $vehicle['id'],
                'brand'        => $vehicle['make'],
                'model'        => $vehicle['model'],
                'vehicle_type' => $bodyType,
                'make_year'    => (int) $vehicle['year'],
                'mileage'      => $vehicle['current_mileage'] === null ? null : (int) $vehicle['current_mileage'],
            ],
        ]);

        $components = [];
        foreach ($targets as $target) {
            $components[$target] = $unavailable(
                is_array($result) && is_string($result['error'] ?? null)
                    ? 'The validated model could not score this vehicle: ' . $result['error']
                    : 'The validated model returned no score for this component.'
            );
        }
        if (is_array($result) && ($result['status'] ?? null) === 'success' && is_array($result['all_categories'] ?? null)) {
            foreach ($result['all_categories'] as $item) {
                $target = $item['component'] ?? null;
                if (isset($components[$target]) && is_numeric($item['score'] ?? null)) {
                    $components[$target] = [
                        'status'         => 'available',
                        'score'          => (float) $item['score'],
                        'threshold'      => is_numeric($item['threshold'] ?? null) ? (float) $item['threshold'] : null,
                        'recommendation' => $item['recommendation'] ?? null,
                        'reason'         => (string) ($item['reason'] ?? ''),
                        'model_status'   => $service['model_status'],
                    ];
                }
            }
        }

        return ['service' => $service, 'default' => $default, 'components' => $components];
    }

    /** JSON request to the AI service; returns the decoded body, or NULL on transport failure. */
    private function ai_request($method, $url, $body = null)
    {
        if (!function_exists('curl_init') || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $headers = ['Accept: application/json'];
        $token   = trim((string) config_item('ai_service_api_key'));
        if ($token !== '') {
            $headers[] = 'X-AI-Service-Key: ' . $token;
        }

        $handle  = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($method === 'POST') {
            $headers[]                   = 'Content-Type: application/json';
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        if ($response === false) {
            error_log('AI service request failed: ' . curl_error($handle));
            return null;
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
