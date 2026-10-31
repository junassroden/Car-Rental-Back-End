<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Forwards chat messages to the Python AI service (config: ai_service_api_url / ai_service_api_key).
 *
 * Rental mode is a two-step exchange. The AI service first reads the message and replies
 * with a retrieval plan made only of ChatRetrieval's read-only tools; this controller runs
 * the plan for the signed-in user (role and user id from the session) and sends the
 * results back, and the AI service answers from them. Diagnostic mode sends one
 * administrator-selected vehicle's records, as before.
 */
class ChatController extends Controller
{
    const MAX_BODY_BYTES   = 8192;
    const MAX_MESSAGE_LEN  = 4000;
    const UUID_PATTERN     = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    const UNAVAILABLE      = 'The local AI chat service is unavailable right now.';
    // Each message makes up to two AI-service calls and several read queries.
    const RATE_PER_USER    = 20;
    const RATE_PER_IP      = 60;
    const RATE_WINDOW      = 60;

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model(['CarModel', 'MaintenanceModel', 'ReservationModel', 'ComponentMaintenanceModel']);
        $this->call->library(['AiServiceClient', 'ComponentMaintenanceRules', 'ChatRetrieval']);
    }

    public function chat()
    {
        // Customers and admins only (the two roles the users table allows).
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }

        // LavaLust's built-in limiter (config: rate_limit_enabled in api.php): per signed-in user and per IP.
        $this->call->library('api');
        $this->api->rate_limit('chat_user_' . (int) $user['id'], self::RATE_PER_USER, self::RATE_WINDOW);
        $this->api->rate_limit('chat_ip_' . (string) $this->request->server('REMOTE_ADDR'), self::RATE_PER_IP, self::RATE_WINDOW);

        if ((int) $this->request->server('CONTENT_LENGTH') > self::MAX_BODY_BYTES) {
            $this->response->send_json_error('The chat message is too large.', 413);
            exit;
        }

        $input   = $this->request->is_json() ? $this->request->json() : $this->request->post();
        $input   = is_array($input) ? $input : [];
        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || strlen($message) > self::MAX_MESSAGE_LEN) {
            $this->response->send_json_error('Enter a message of up to 4,000 characters.', 422);
            exit;
        }
        $mode = $input['mode'] ?? 'rental';
        if (!is_string($mode) || !in_array($mode, ['rental', 'diagnostic'], true)) {
            $this->response->send_json_error('Chat mode must be rental or diagnostic.', 422);
            exit;
        }
        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && (!is_string($conversationId) || !preg_match(self::UUID_PATTERN, $conversationId))) {
            $this->response->send_json_error('The chat conversation is invalid.', 400);
            exit;
        }

        $this->config->load('api');
        $token = trim((string) config_item('ai_service_api_key'));
        $url   = trim((string) config_item('ai_service_api_url'));
        if (
            ($token !== '' && strlen($token) < 32)
            || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            $this->response->send_json_error('The local AI chat service is not configured.', 503);
            exit;
        }
        if (!function_exists('curl_init')) {
            $this->response->send_json_error(self::UNAVAILABLE, 503);
            exit;
        }

        $isAdmin = $user['role'] === 'admin';
        if ($mode === 'diagnostic' && !$isAdmin) {
            $this->response->send_json_error('Administrator access is required for vehicle diagnostics.', 403);
            exit;
        }

        // Without an id the browser could never continue a conversation, so follow-up questions need one.
        $conversationId = $conversationId ?? self::new_uuid();
        $request = [
            'message'            => trim($message),
            'conversation_id'    => $conversationId,
            // Role and scope come from the authenticated session, never from the message.
            'viewer_role'        => $isAdmin ? 'admin' : 'customer',
            'conversation_scope' => ($isAdmin ? 'admin:' : 'customer:') . (int) $user['id'],
        ];

        $result = $mode === 'diagnostic'
            ? $this->sendToAiService($url, $token, $request + ['rental_context' => $this->diagnosticContext($input)])
            : $this->answerFromDatabase($url, $token, $request, $isAdmin, (int) $user['id']);

        $this->response->send_json_success([
            'message'         => $result['answer'],
            'conversation_id' => $conversationId,
            'intent'          => $result['intent'] ?? null,
            'confidence'      => $result['confidence'] ?? null,
            'data'            => $result['data'] ?? null,
        ], 'Chat response generated.');
    }

    /* ---------------------------------------------------------------
     * Database-grounded answers
     * ------------------------------------------------------------- */

    /** Plan with the AI service, run the plan's authorised read-only retrievals, then get the answer. */
    private function answerFromDatabase($url, $token, array $request, $isAdmin, $userId)
    {
        $source = $this->call->library('ChatDatabaseSource', [
            'cars'         => $this->CarModel,
            'reservations' => $this->ReservationModel,
            'maintenance'  => $this->MaintenanceModel,
            'components'   => $this->ComponentMaintenanceModel,
        ]);
        try {
            // Names in this database, so the AI service recognises makes and models it has never seen.
            $vocabulary = ChatRetrieval::vocabulary($source->vehicles($isAdmin));
        } catch (Exception $exception) {
            error_log('Chat vocabulary query failed: ' . $exception->getMessage());
            $vocabulary = ChatRetrieval::vocabulary([]);
        }

        $request += ['retrieval_protocol' => 1, 'vocabulary' => $vocabulary];
        $first = $this->sendToAiService($url, $token, $request, true);
        if (($first['status'] ?? null) !== 'needs_data') {
            return $first;
        }

        $results = ChatRetrieval::execute(
            $first['plan'],
            ['role' => $isAdmin ? 'admin' : 'customer', 'user_id' => $userId],
            $source
        );

        return $this->sendToAiService($url, $token, $request + [
            'retrieval' => ['plan' => $first['plan'], 'results' => $results, 'data_as_of' => date(DATE_ATOM)],
        ]);
    }

    /* ---------------------------------------------------------------
     * Context sent to the AI service
     * ------------------------------------------------------------- */

    private function diagnosticContext(array $input)
    {
        $vehicleId = filter_var($input['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
        $component = $input['component'] ?? null;
        if ($vehicleId === false || $vehicleId < 1 || !is_string($component) || trim($component) === '' || strlen($component) > 80) {
            $this->response->send_json_error('Select a valid vehicle and diagnostic component.', 422);
            exit;
        }

        $vehicle = $this->CarModel->find($vehicleId);
        if (!$vehicle) {
            $this->response->send_json_error('Vehicle not found.', 404);
            exit;
        }

        $history = array_map(static function ($record) {
            return [
                'type'              => substr((string) $record['maintenanceType'], 0, 80),
                'date'              => $record['maintenanceDate'],
                'status'            => substr((string) $record['maintenanceStatus'], 0, 30),
                'odometer_km'       => $record['odometerReading'] === null ? null : (int) $record['odometerReading'],
                'next_service_date' => $record['nextMaintenanceDate'],
                'findings'          => substr((string) $record['description'], 0, 500),
            ];
        }, array_slice($this->MaintenanceModel->records($vehicleId), 0, 10));

        $prediction = $this->MaintenanceModel->predictions($vehicleId)[0] ?? null;

        // Inspections recorded for the selected 3D component, when the caller names one.
        $componentKey = $input['component_key'] ?? null;
        $inspections  = null;
        if (is_string($componentKey) && preg_match('/^[a-z0-9-]{1,40}$/', $componentKey)) {
            $inspections = array_map(static function ($record) {
                return [
                    'date'               => $record['inspected_on'],
                    'odometer_km'        => $record['odometer_km'],
                    'recorded_condition' => $record['condition_rating'],
                    'reported_symptoms'  => substr((string) $record['reported_symptoms'], 0, 300),
                    'dtc_codes'          => $record['dtc_codes'],
                    'findings'           => substr((string) $record['findings'], 0, 500),
                    'confirmed_fault'    => $record['confirmed_fault'],
                    'service_performed'  => (bool) $record['service_performed'],
                    'work_performed'     => substr((string) $record['work_performed'], 0, 300),
                    'parts_replaced'     => substr((string) $record['parts_replaced'], 0, 300),
                    'next_due_date'      => $record['next_due_date'],
                    'next_due_km'        => $record['next_due_km'],
                ];
            }, $this->ComponentMaintenanceModel->history($vehicleId, $componentKey, 10));
        }

        return [
            'viewer_scope'       => 'admin_diagnostic',
            'data_as_of'         => date(DATE_ATOM),
            'diagnostic_context' => [
                'vehicle' => [
                    'make'               => (string) $vehicle['make'],
                    'model'              => (string) $vehicle['model'],
                    'year'               => (int) $vehicle['year'],
                    'body_type'          => ComponentMaintenanceRules::body_type($vehicle['category']),
                    'plate_number'       => $vehicle['plate_number'],
                    'color'              => $vehicle['color'],
                    'fuel_type'          => $vehicle['fuel_type'],
                    'transmission'       => $vehicle['transmission'],
                    'seating_capacity'   => (int) $vehicle['seating_capacity'],
                    'current_mileage_km' => isset($vehicle['current_mileage']) ? (int) $vehicle['current_mileage'] : null,
                    'status'             => $vehicle['status'],
                ],
                'selected_component'  => trim($component),
                'maintenance_history' => $history,
                'component_inspections' => $inspections,
                'rule_based_estimate' => $prediction ? [
                    'status'             => $prediction['riskLevel'],
                    'due_date'           => $prediction['nextMaintenanceDate'],
                    'basis'              => substr((string) $prediction['predictedIssue'], 0, 500),
                    'recommended_action' => substr((string) $prediction['recommendedAction'], 0, 500),
                ] : null,
                'measurement_policy'  => 'No live sensor telemetry or component test readings are available. Do not infer condition, measurements, or faults.',
            ],
        ];
    }

    private static function new_uuid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /* ---------------------------------------------------------------
     * HTTP call to the AI service
     * ------------------------------------------------------------- */

    /**
     * POSTs the request and returns the decoded answer, or sends a 503. With $allowPlan,
     * a {"status": "needs_data", "plan": {...}} reply is also accepted.
     */
    private function sendToAiService($url, $token, array $request, $allowPlan = false)
    {
        $payload = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            error_log('Chat request could not be encoded: ' . json_last_error_msg());
            $this->response->send_json_error('The chat request could not be completed.', 500);
            exit;
        }

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'X-AI-Service-Key: ' . $token;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            $this->response->send_json_error(self::UNAVAILABLE, 503);
            exit;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body      = curl_exec($handle);
        $status    = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);

        if ($body === false) {
            error_log('Local AI chat service request failed: ' . $curlError);
            $this->response->send_json_error(self::UNAVAILABLE, 503);
            exit;
        }

        $result = json_decode($body, true);
        if ($status >= 200 && $status < 300 && is_array($result)) {
            if ($allowPlan && ($result['status'] ?? null) === 'needs_data' && is_array($result['plan'] ?? null)) {
                return $result;
            }
            if (($result['status'] ?? null) === 'success' && is_string($result['answer'] ?? null) && trim($result['answer']) !== '') {
                return $result;
            }
        }

        $detail = self::UNAVAILABLE;
        if (is_array($result) && is_string($result['error'] ?? null)) {
            $detail = $result['error'];
        } elseif (is_array($result) && is_string($result['message'] ?? null)) {
            $detail = $result['message'];
        }
        error_log('Local AI chat service returned an unsuccessful response (HTTP ' . $status . '): ' . $detail . ($curlError !== '' ? ' (' . $curlError . ')' : ''));
        $this->response->send_json_error($detail, 503);
        exit;
    }
}
