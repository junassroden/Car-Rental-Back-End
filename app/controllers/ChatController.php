<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Forwards chat messages to the Python AI service (config: ai_service_api_url / ai_service_api_key)
 * together with rental or diagnostic context from the database.
 */
class ChatController extends Controller
{
    const MAX_BODY_BYTES   = 8192;
    const MAX_MESSAGE_LEN  = 4000;
    const MAX_RENTAL_DAYS  = 65535;
    const UUID_PATTERN     = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    const UNAVAILABLE      = 'The local AI chat service is unavailable right now.';

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model(['CarModel', 'MaintenanceModel', 'ReservationModel', 'ComponentMaintenanceModel']);
    }

    public function chat()
    {
        // Customers and admins only (the two roles the users table allows).
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            return;
        }

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

        $payload = json_encode([
            'message'            => trim($message),
            'conversation_id'    => $conversationId,
            'conversation_scope' => $isAdmin ? 'admin:' . (int) $user['id'] : 'public',
            'rental_context'     => $mode === 'diagnostic'
                ? $this->diagnosticContext($input)
                : $this->rentalContext($isAdmin, (int) $user['id'], trim($message)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            error_log('Chat request could not be encoded: ' . json_last_error_msg());
            $this->response->send_json_error('The chat request could not be completed.', 500);
            exit;
        }

        $result = $this->sendToAiService($url, $token, $payload);

        $this->response->send_json_success([
            'message'         => $result['answer'],
            'conversation_id' => $conversationId,
            'intent'          => $result['intent'] ?? null,
            'confidence'      => $result['confidence'] ?? null,
            'data'            => $result['data'] ?? null,
        ], 'Chat response generated.');
    }

    /* ---------------------------------------------------------------
     * Context sent to the AI service
     * ------------------------------------------------------------- */

    private function rentalContext($isAdmin, $userId, $message)
    {
        $kind = $this->databaseQuestionKind($isAdmin, $message);
        if ($kind === null) {
            return null;
        }

        if ($kind === 'bookings') {
            return [
                'database_context' => [
                    'kind'         => 'customer_bookings',
                    'data_as_of'   => date(DATE_ATOM),
                    'booking_data' => $this->ReservationModel->chat_context_for_user($userId),
                ],
            ];
        }

        if ($kind === 'maintenance') {
            $vehicleId = $this->messageVehicleId($message);
            return [
                'database_context' => [
                    'kind'          => 'admin_maintenance',
                    'data_as_of'    => date(DATE_ATOM),
                    'vehicle_id'    => $vehicleId,
                    'records'       => $this->MaintenanceModel->chat_context($vehicleId, 10),
                    'data_meaning'  => 'Legacy rows are service-log entries, not verified fault labels. Evidence rows are included only when their verification status is verified.',
                ],
            ];
        }

        $availability = $kind === 'fleet' || $this->messageRequestsAvailability($message);
        $period = $availability ? $this->availabilityPeriod($message) : null;
        $excludeIds = [];
        if ($period !== null && $period['status'] === 'checked') {
            $excludeIds = $this->ReservationModel->booked_car_ids(
                $period['start_date'],
                $period['end_date']
            );
        }

        $vehicleId = $this->messageVehicleId($message);
        $context = $this->CarModel->chat_context($isAdmin, 100, $excludeIds, $vehicleId);
        $cars = array_map(function ($car) use ($isAdmin, $availability, $period, $excludeIds) {
            $car = $this->chatCar($car);
            $vehicleStatus = $car['status'] ?? null;
            unset($car['plate_number']);

            if (!$isAdmin) {
                unset($car['status']);
            }

            if ($availability) {
                $car['is_available'] = $vehicleStatus === 'available'
                    && !in_array((int) $car['id'], $excludeIds, true);
                if ($period !== null && $period['status'] === 'checked') {
                    $duration = (int) ((new DateTimeImmutable($period['start_date']))
                        ->diff(new DateTimeImmutable($period['end_date']))->format('%a'));
                    $car['minimum_duration_satisfied'] = $duration >= (int) $car['minimum_rental_duration'];
                    $car['is_available'] = $car['is_available'] && $car['minimum_duration_satisfied'];
                }
            }
            return $car;
        }, $context['cars']);

        $result = [
            'currency'                                      => 'PHP',
            'data_as_of'                                    => date(DATE_ATOM),
            'viewer_scope'                                  => $isAdmin ? 'admin' : 'public',
            'vehicle_count'                                 => $context['vehicle_count'],
            'vehicle_list_truncated'                        => $context['vehicle_count'] > count($cars),
            'vehicles'                                      => $cars,
            'vehicle_status_authorized'                     => true,
            'availability_period'                            => $period,
            'requested_vehicle_id'                            => $vehicleId,
        ];
        if ($this->messageRequestsPrice($message)
            && preg_match('/\bfor\s+(\d{1,4})\s+days?\b/i', $message, $durationMatch)) {
            $result['rental_duration_days'] = (int) $durationMatch[1];
        }

        if ($isAdmin) {
            $result['vehicle_counts_by_status'] = $this->CarModel->chat_status_counts();
        }

        if ($kind === 'fleet') {
            $result['database_context'] = ['kind' => 'admin_fleet'];
        } else {
            $result['database_context'] = [
                'kind'    => 'vehicle_inventory',
                'purpose' => $availability
                    ? 'availability'
                    : ($this->messageRequestsPrice($message) ? 'pricing' : 'details'),
            ];
        }

        return $result;
    }

    /** Decide whether a question needs database facts; general automotive chat stays on the knowledge path. */
    private function databaseQuestionKind($isAdmin, $message)
    {
        if (!$isAdmin && preg_match('/\bmy\s+(?:booking|reservation|rental|pickup|return)\b|\b(?:my booking|my reservation)\b/i', $message)) {
            return 'bookings';
        }

        if ($isAdmin && preg_match('/\b(?:maintenance|service|inspection|repair|fault|dtc)\b/i', $message)
            && preg_match('/\b(?:history|record|latest|recent|performed|serviced|inspected|confirmed|status|fleet|vehicle\s*#?\s*\d+|car\s*#?\s*\d+)\b/i', $message)) {
            return 'maintenance';
        }

        if ($isAdmin && preg_match('/\b(?:fleet|all vehicles|all cars|vehicle status|rental status|fleet status)\b/i', $message)) {
            return 'fleet';
        }

        if (preg_match('/\b(?:cars?|vehicles?|rental|rent|availability|available|price|pricing|rate|cheapest|booking|reserve)\b/i', $message)) {
            return 'inventory';
        }

        return null;
    }

    private function messageRequestsAvailability($message)
    {
        return (bool) preg_match('/\b(?:available|availability|book|reserve|rental period)\b/i', $message);
    }

    private function messageRequestsPrice($message)
    {
        return (bool) preg_match('/\b(?:price|pricing|rate|cost|cheapest|how much|afford)\b/i', $message);
    }

    /** Availability windows use the same half-open [start, end) dates as reservations. */
    private function availabilityPeriod($message)
    {
        preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $message, $matches);
        $dates = array_values(array_unique($matches[0]));
        preg_match('/\bfor\s+(\d{1,4})\s+days?\b/i', $message, $durationMatch);

        if (!$dates && !$durationMatch) {
            return ['status' => 'listing_status_only'];
        }
        if (count($dates) === 1 && !$durationMatch) {
            return ['status' => 'needs_details', 'missing' => 'rental duration or return date'];
        }
        if (!$dates) {
            return ['status' => 'needs_details', 'missing' => 'pickup date'];
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $dates[0]);
        if (!$start || $start->format('Y-m-d') !== $dates[0]) {
            return ['status' => 'invalid_dates'];
        }

        if (count($dates) > 1) {
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $dates[1]);
            if (!$end || $end->format('Y-m-d') !== $dates[1]) {
                return ['status' => 'invalid_dates'];
            }
        } else {
            $days = (int) $durationMatch[1];
            if ($days < 1 || $days > self::MAX_RENTAL_DAYS) {
                return ['status' => 'invalid_dates'];
            }
            $end = $start->modify('+' . $days . ' days');
        }

        if ($end <= $start || $start->format('Y-m-d') < date('Y-m-d')) {
            return ['status' => 'invalid_dates'];
        }

        return [
            'status'     => 'checked',
            'start_date' => $start->format('Y-m-d'),
            'end_date'   => $end->format('Y-m-d'),
        ];
    }

    private function messageVehicleId($message)
    {
        if (preg_match('/\b(?:vehicle|car)\s*(?:id)?\s*#?\s*(\d+)\b/i', $message, $match)) {
            $id = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $id === false ? null : (int) $id;
        }

        return null;
    }

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

    /** Typed car fields, with long text trimmed to keep the chat context small. */
    private function chatCar(array $car)
    {
        $car['daily_rental_price']      = (float) $car['daily_rental_price'];
        $car['security_deposit']        = (float) $car['security_deposit'];
        $car['minimum_rental_duration'] = (int) $car['minimum_rental_duration'];
        $car['seating_capacity']        = (int) $car['seating_capacity'];
        $car['air_conditioning']        = (bool) $car['air_conditioning'];
        $car['gps_navigation']          = (bool) $car['gps_navigation'];
        $car['description']             = substr((string) ($car['description'] ?? ''), 0, 240);

        $features = is_string($car['additional_features']) ? json_decode($car['additional_features'], true) : $car['additional_features'];
        $encoded  = json_encode($features);
        $car['additional_features'] = $encoded !== false && strlen($encoded) > 1000
            ? 'Feature details omitted because they exceed the chat context limit.'
            : $features;

        return $car;
    }

    /* ---------------------------------------------------------------
     * HTTP call to the AI service
     * ------------------------------------------------------------- */

    /** POSTs the payload and returns the decoded answer, or sends a 503. */
    private function sendToAiService($url, $token, $payload)
    {
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
        if (
            $status >= 200 && $status < 300
            && is_array($result) && ($result['status'] ?? null) === 'success'
            && is_string($result['answer'] ?? null) && trim($result['answer']) !== ''
        ) {
            return $result;
        }

        $detail = self::UNAVAILABLE;
        if (is_array($result) && is_string($result['error'] ?? null)) {
            $detail = $result['error'];
        } elseif (is_array($result) && is_string($result['message'] ?? null)) {
            $detail = $result['message'];
        } elseif ($curlError !== '') {
            $detail = 'The local AI chat service is unavailable right now: ' . $curlError;
        }
        error_log('Local AI chat service returned an unsuccessful response (HTTP ' . $status . '): ' . $detail);
        $this->response->send_json_error($detail, 503);
            exit;
    }
}
