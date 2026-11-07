<?php

/**
 * End-to-end test of the assessment workflow over HTTP. Run it against a COPY of
 * the database: it creates assessments and accounts.
 *
 *   ASSESSMENT_TEST_URL=http://127.0.0.1:3010 ADMIN_LOGIN=... CUSTOMER_LOGIN=... TEST_PASSWORD=...
 *   VEHICLE_ID=2 PROTECTED_VEHICLE_ID=9 php tests/vehicle_assessment_http_test.php ai_down|ai_up
 *
 * ai_down expects the Python service to be unreachable, ai_up expects it to be
 * running. Run ai_down first: ai_up retries the assessment that ai_down left failed.
 */

$phase    = $argv[1] ?? 'ai_up';
$base     = rtrim((string) getenv('ASSESSMENT_TEST_URL'), '/');
$password = (string) getenv('TEST_PASSWORD');
$vehicle  = (int) getenv('VEHICLE_ID');
$stateFile = sys_get_temp_dir() . '/assessment_http_test_state.json';
$passed   = 0;
$failed   = 0;

function call($method, $path, $token = null, $body = null, $file = null)
{
    global $base;
    $handle  = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 40];
    if ($file) {
        $options[CURLOPT_POSTFIELDS] = ['evidence' => new CURLFile($file['path'], $file['mime'], $file['name'])];
    } elseif ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($handle, $options);
    $raw    = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
}

function check($condition, $message, $detail = null)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: {$message}" . ($detail === null ? '' : ' -- ' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400)) . "\n";
}

function login($identity, $password)
{
    global $base;
    $handle = curl_init($base . '/api/login');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['email' => $identity, 'password' => $password])]);
    $decoded = json_decode((string) curl_exec($handle), true);
    return $decoded['access_token'] ?? ($decoded['data']['access_token'] ?? null);
}

function uuid()
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

$admin    = login(getenv('ADMIN_LOGIN'), $password);
$customer = login(getenv('CUSTOMER_LOGIN'), $password);
check((bool) $admin, 'admin can log in');
check((bool) $customer, 'customer can log in');
if (!$admin || !$customer) {
    exit(1);
}

$today = date('Y-m-d');

if ($phase === 'ai_down') {
    /* ---------------------- Roles and permissions ---------------------- */
    check(call('GET', '/api/assessments/catalog')['status'] === 401, 'anonymous requests are refused');
    foreach (['/api/assessments/catalog', '/api/assessments/fleet', "/api/assessments/vehicles/{$vehicle}"] as $path) {
        check(call('GET', $path, $customer)['status'] === 403, "customer cannot read {$path}");
    }
    check(call('POST', '/api/assessments', $customer, ['vehicle_id' => $vehicle, 'assessed_on' => $today])['status'] === 403, 'customer cannot submit an assessment');

    check(call('GET', '/api/assessments/mechanics', $admin)['status'] === 404, 'mechanic account management is gone');
    check(call('POST', '/api/assessments/mechanics', $admin, ['full_name' => 'X', 'email' => 'x@example.test', 'password' => $password])['status'] === 404, 'mechanic accounts cannot be created');

    /* ---------------------- Catalog and vehicle profile ---------------------- */
    $catalog = call('GET', '/api/assessments/catalog', $admin)['body']['data'];
    check(count($catalog['categories']) === 8, 'catalog has eight categories');
    $workspace = call('GET', "/api/assessments/vehicles/{$vehicle}", $admin);
    check($workspace['status'] === 200, 'workspace loads', $workspace['raw']);
    $keys = array_column($workspace['body']['data']['parts'], 'key');
    check(in_array('air-conditioning', $keys, true), 'workspace lists the AC component');
    $startMileage = $workspace['body']['data']['vehicle']['current_mileage'];

    check(call('PATCH', "/api/assessments/vehicles/{$vehicle}/profile", $admin, ['usage_frequency' => 'hourly'])['status'] === 422, 'invalid usage frequency is refused');
    check(call('PATCH', "/api/assessments/vehicles/{$vehicle}/profile", $admin, ['acquisition_date' => '2099-01-01'])['status'] === 422, 'future acquisition date is refused');
    check(call('PATCH', "/api/assessments/vehicles/{$vehicle}/profile", $admin, ['acquisition_date' => '2021-06-01', 'usage_frequency' => 'daily'])['status'] === 200, 'admin saves profile fields');

    /* ---------------------- Submission with the AI service down ---------------------- */
    check(call('POST', '/api/assessments', $admin, ['vehicle_id' => $vehicle])['status'] === 422, 'a missing date is refused');
    check(call('POST', '/api/assessments', $admin, ['vehicle_id' => 999999, 'assessed_on' => $today])['status'] === 404, 'an unknown vehicle is refused');

    $key     = uuid();
    $payload = ['vehicle_id' => $vehicle, 'assessed_on' => $today, 'odometer_km' => $startMileage + 500, 'idempotency_key' => $key, 'components' => [
        'engine'              => ['rating' => 'excellent', 'answers' => ['starts_normally' => 'yes', 'idles_smoothly' => 'yes', 'oil_level_ok' => 'yes'], 'fault_codes' => ['P0118']],
        'battery_electrical'  => ['rating' => 'good', 'measurements' => ['battery_rest_voltage_v' => 11.5]],
        'brakes'              => ['rating' => 'good', 'symptoms' => ['pedal_to_floor']],
        'transmission'        => ['rating' => 'fair'],
        'suspension_steering' => ['rating' => 'poor'],
        'tires_wheels'        => ['rating' => 'unknown', 'measurements' => ['tread_depth_min_mm' => 'unknown']],
        'air_conditioning'    => ['rating' => 'not_applicable'],
        // cooling is deliberately left out.
    ]];
    $saved = call('POST', '/api/assessments', $admin, $payload);
    check($saved['status'] === 201, 'assessment is saved with the AI service down', $saved['raw']);
    $data = $saved['body']['data'];
    check($data['ai_status'] === 'failed', 'AI failure is recorded, not hidden', $data['ai_status'] ?? null);
    check($data['components']['cooling']['rating'] === 'not_assessed', 'an omitted category stays not_assessed');
    check($data['components']['cooling']['rating_score'] === null, 'an omitted category has no score');
    check($data['components']['engine']['rating_score'] === 5, 'excellent stores score 5');
    check($data['components']['air_conditioning']['screening_status'] === 'not_applicable', 'AC N/A is stored');
    check($data['components']['tires_wheels']['screening_status'] === 'insufficient_data', 'unknown screens as insufficient data');
    check($data['overall_screening'] === 'critical', 'overall screening reflects the critical brake symptom');

    $warning = $data['safety_warnings'][0] ?? [];
    check(($warning['rule_id'] ?? null) === 'brakes.pedal_to_floor', 'the safety warning appears without AI', $data['safety_warnings']);
    check(!empty($warning['component_name']) && !empty($warning['observation']) && !empty($warning['reason']) && !empty($warning['recommended_action']), 'the warning names component, observation, reason and action');
    check(in_array('battery.low_rest_voltage', array_column($data['findings'], 'rule_id'), true), 'measurement rules run without AI');
    check(strpos($saved['raw'], '11.5 V') !== false, 'the finding quotes the measurement');

    $again = call('POST', '/api/assessments', $admin, $payload);
    check($again['status'] === 200 && $again['body']['data']['id'] === $data['id'] && $again['body']['data']['duplicate_submission'] === true, 'a double submit returns the same assessment', $again['raw']);

    /* ---------------------- Mileage ---------------------- */
    $lower = call('POST', '/api/assessments', $admin, ['vehicle_id' => $vehicle, 'assessed_on' => $today, 'odometer_km' => 10, 'components' => ['engine' => ['rating' => 'good']]]);
    check($lower['status'] === 201 && $lower['body']['data']['odometer_flag'] === 'decrease', 'a mileage decrease is flagged', $lower['raw']);
    check(in_array('odometer.decrease', array_column($lower['body']['data']['findings'], 'rule_id'), true), 'the decrease has an explained finding');
    $after = call('GET', "/api/assessments/vehicles/{$vehicle}", $admin)['body']['data'];
    check($after['vehicle']['current_mileage'] === $startMileage + 500, 'a lower reading does not replace the mileage', $after['vehicle']['current_mileage']);
    $noReading = call('POST', '/api/assessments', $admin, ['vehicle_id' => $vehicle, 'assessed_on' => $today, 'components' => []]);
    check($noReading['body']['data']['odometer_flag'] === 'missing', 'a missing reading differs from zero', $noReading['raw']);
    check($noReading['body']['data']['overall_screening'] !== 'no_concerns_recorded', 'an empty assessment is never a pass');
    check($noReading['body']['data']['components']['cooling']['rating'] === 'not_assessed', 'an empty assessment stores not_assessed');
    check(in_array('trend.prior_problem_not_rechecked', array_column($noReading['body']['data']['findings'], 'rule_id'), true), 'an earlier problem stays visible until it is re-rated');

    /* ---------------------- Previous-assessment comparison ---------------------- */
    $compare = $lower['body']['data']['comparison'];
    check($compare['previous_assessment_id'] === $data['id'], 'comparison finds the previous assessment');
    check($compare['categories']['engine']['change'] === 'worsened', 'comparison reports excellent to good as worsened');
    check($compare['categories']['cooling']['change'] === 'not_comparable', 'not-assessed categories are not comparable');
    check(in_array('trend.rating_drop', array_column($lower['body']['data']['findings'], 'rule_id'), true), 'a rating drop raises a finding');
    check(in_array('trend.prior_problem_not_rechecked', array_column($lower['body']['data']['findings'], 'rule_id'), true), 'a prior Poor rating left unchecked raises a finding');

    file_put_contents($stateFile, json_encode(['assessment' => $data['id'], 'start_mileage' => $startMileage]));
} elseif ($phase === 'ai_up') {
    $state    = json_decode((string) file_get_contents($stateFile), true);
    $id       = $state['assessment'];

    /* ---------------------- Retry and duplicate prevention ---------------------- */
    $before = call('GET', "/api/assessments/{$id}", $admin)['body']['data'];
    check($before['ai_status'] === 'failed', 'the assessment is still failed before retry');
    $retry = call('POST', "/api/assessments/{$id}/analysis", $admin);
    check($retry['status'] === 200, 'retry runs', $retry['raw']);
    $data = $retry['body']['data'];
    check($data['ai_status'] === 'completed', 'retry completes', $data['ai_last_error'] ?? null);
    check(count($data['analysis']['attempts']) === 2 && $data['analysis']['attempts'][0]['status'] === 'failed', 'the failed attempt is preserved');
    check($data['components'] == $before['components'] && $data['findings'] == $before['findings'], 'analysis does not alter the submitted assessment');
    check(call('POST', "/api/assessments/{$id}/analysis", $admin)['status'] === 409, 'a completed assessment cannot be processed twice');

    /* ---------------------- Stored analysis ---------------------- */
    $findings = $data['analysis']['findings'];
    check(count($findings) === 8, 'all eight categories have an AI finding');
    check(($findings['engine']['identified_faults'][0]['code'] ?? null) === 'P0118', 'fault code lookup is stored', $findings['engine'] ?? null);
    check(($findings['engine']['identified_faults'][0]['found'] ?? false) === true, 'fault code found in the reference');
    foreach ($findings as $finding) {
        check($finding['ai_model'] === null && $finding['confidence'] === null && $finding['prediction_horizon'] === null, 'no model, confidence or horizon is invented');
        check($finding['predictive_status'] === 'Insufficient Data', 'predictive status is Insufficient Data');
    }
    check($data['analysis']['predictive_capability']['status'] === 'unavailable', 'predictive capability is unavailable');

    $fresh = call('POST', '/api/assessments', $admin, ['vehicle_id' => $data['vehicle_id'], 'assessed_on' => $today, 'odometer_km' => $state['start_mileage'] + 900, 'components' => ['air_conditioning' => ['rating' => 'poor', 'symptoms' => ['not_cooling']], 'cooling' => ['symptoms' => ['overheating']]]]);
    check($fresh['status'] === 201 && $fresh['body']['data']['ai_status'] === 'completed', 'a new assessment analyses immediately', $fresh['raw']);
    $freshData = $fresh['body']['data'];
    check($freshData['analysis']['findings']['air_conditioning']['screening_status'] === 'attention', 'AC reaches the AI endpoint and returns a finding');

    /* ---------------------- All rating levels ---------------------- */
    foreach (['excellent', 'good', 'fair', 'poor', 'critical', 'not_applicable', 'unknown', 'not_assessed'] as $rating) {
        $one = call('POST', '/api/assessments', $admin, ['vehicle_id' => $data['vehicle_id'], 'assessed_on' => $today, 'components' => ['transmission' => ['rating' => $rating]]]);
        check($one['status'] === 201 && $one['body']['data']['components']['transmission']['rating'] === $rating, "rating {$rating} round-trips", $one['raw']);
    }

    /* ---------------------- Component history and 3D mapping ---------------------- */
    $workspace = call('GET', "/api/assessments/vehicles/{$data['vehicle_id']}", $admin)['body']['data'];
    $ac        = array_values(array_filter($workspace['parts'], static function ($part) { return $part['key'] === 'air-conditioning'; }))[0] ?? null;
    check(($ac['category_key'] ?? null) === 'air_conditioning', 'the AC component is mapped to its category', $ac);
    foreach ($workspace['parts'] as $part) {
        check($part['category_key'] !== null, "component {$part['key']} maps to a category");
    }
    $history = call('GET', "/api/assessments/vehicles/{$data['vehicle_id']}/categories/engine/history", $admin);
    check($history['status'] === 200 && count($history['body']['data']['assessments']) >= 2, 'category history is readable', $history['raw']);
    check(call('GET', "/api/assessments/vehicles/{$data['vehicle_id']}/categories/wipers/history", $admin)['status'] === 404, 'an unknown category returns 404');

    /* ---------------------- Outcomes ---------------------- */
    $finding = array_values(array_filter($data['findings'], static function ($row) { return $row['rule_id'] === 'brakes.pedal_to_floor'; }))[0];
    $url = "/api/assessments/{$id}/categories/brakes/outcomes";
    check(call('POST', $url, $customer, ['decision' => 'deferred'])['status'] === 403, 'customer cannot record an outcome');
    check(call('POST', $url, $admin, ['decision' => 'completed', 'finding_id' => $finding['id']])['status'] === 422, 'completed needs the work described');
    check(call('POST', "/api/assessments/{$id}/categories/cooling/outcomes", $admin, ['decision' => 'deferred', 'finding_id' => $finding['id']])['status'] === 422, 'a finding cannot be used under another category');
    $outcome = call('POST', $url, $admin, ['decision' => 'completed', 'finding_id' => $finding['id'], 'component_key' => 'brakes-front', 'mechanic_confirmed' => 'confirmed', 'result' => 'successful_repair', 'repair_date' => $today, 'maintenance_performed' => 'Replaced master cylinder and bled the system', 'parts_replaced' => 'Master cylinder', 'mileage_km' => $state['start_mileage'] + 500, 'mechanic_name' => 'Workshop Mechanic', 'verification_status' => 'verified']);
    check($outcome['status'] === 201, 'admin records an outcome', $outcome['raw']);
    $row = $outcome['body']['data'];
    check($row['original_recommendation'] === $finding['recommended_action'] && $row['recommended_at'] === $finding['created_at'], 'the original recommendation and time are preserved');
    check($row['verification_status'] === 'unverified', 'a client cannot self-verify an outcome');
    check($row['mileage_km'] === $state['start_mileage'] + 500, 'outcome mileage is stored');
    check($row['outcome_date'] === $today, 'the outcome date comes from the repair date');
    $verifiedBefore = call('GET', '/api/assessments/training/overview', $admin)['body']['data']['sufficiency']['totals']['verified_outcomes'];
    check(call('POST', "/api/assessments/outcomes/{$row['id']}/verify", $admin, ['status' => 'verified'])['status'] === 200, 'admin verifies an outcome');
    check(call('POST', "/api/assessments/outcomes/{$row['id']}/verify", $admin, ['status' => 'rejected'])['status'] === 409, 'a reviewed outcome cannot be changed');
    $overview = call('GET', '/api/assessments/training/overview', $admin)['body']['data'];
    check($overview['sufficiency']['totals']['verified_outcomes'] === $verifiedBefore + 1, 'a verified outcome is counted');
    $brakes = array_values(array_filter($overview['sufficiency']['categories'], static function ($item) { return $item['key'] === 'brakes'; }))[0];
    check($brakes['status'] === 'insufficient' && $brakes['eligible'] === 0 && ($brakes['excluded_by_reason']['demo_vehicle'] ?? 0) === 1, 'an outcome on a demo vehicle does not count toward training', $brakes);
    check(call('POST', $url, $admin, ['decision' => 'completed', 'finding_id' => $finding['id'], 'component_key' => 'brakes-front', 'mechanic_confirmed' => 'confirmed', 'result' => 'successful_repair', 'repair_date' => $today, 'maintenance_performed' => 'Same again'])['status'] === 409, 'a duplicate outcome is refused');
    check(call('POST', $url, $admin, ['decision' => 'deferred', 'finding_id' => $finding['id'], 'result' => 'confirmed_failure', 'failure_date' => '2020-01-01'])['status'] === 422, 'an outcome before its assessment is refused');
    check(call('POST', $url, $admin, ['decision' => 'deferred', 'finding_id' => $finding['id'], 'mileage_km' => 1])['status'] === 422, 'outcome mileage below the assessment odometer is refused');
    check(call('POST', $url, $admin, ['decision' => 'deferred', 'finding_id' => $finding['id'], 'result' => 'no_fault_found'])['status'] === 422, 'a no-fault result needs a date');
    $clean = call('POST', "/api/assessments/{$id}/categories/transmission/outcomes", $admin, ['decision' => 'deferred', 'result' => 'no_fault_found', 'outcome_date' => $today]);
    check($clean['status'] === 201, 'a healthy result can be recorded against a category', $clean['raw']);

    /* ---------------------- Review and evidence ---------------------- */
    check(call('POST', "/api/assessments/{$id}/review", $admin, ['notes' => 'Checked'])['body']['data']['review_status'] === 'reviewed', 'admin reviews an assessment');

    $image = tempnam(sys_get_temp_dir(), 'ev');
    file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $evidenceUrl = "/api/assessments/{$id}/categories/brakes/evidence";
    $upload = call('POST', $evidenceUrl, $admin, null, ['path' => $image, 'mime' => 'image/png', 'name' => 'brake.png']);
    check($upload['status'] === 201, 'evidence uploads', $upload['raw']);
    $evidenceId = $upload['body']['data']['id'] ?? 0;
    check(!array_key_exists('filename', $upload['body']['data'] ?? []), 'the stored file name is not exposed');
    check(call('GET', "/api/assessments/evidence/{$evidenceId}", $admin)['status'] === 200, 'admin reads evidence');
    check(call('GET', "/api/assessments/evidence/{$evidenceId}", $customer)['status'] === 403, 'customer cannot read evidence');
    $fake = tempnam(sys_get_temp_dir(), 'ev');
    file_put_contents($fake, '<?php echo 1;');
    check(call('POST', $evidenceUrl, $admin, null, ['path' => $fake, 'mime' => 'image/png', 'name' => 'shell.php'])['status'] === 422, 'a non-image upload is refused');

    /* ---------------------- Protected vehicle and regression ---------------------- */
    $protected = (int) getenv('PROTECTED_VEHICLE_ID');
    $car = call('GET', "/api/assessments/vehicles/{$protected}", $admin)['body']['data'];
    check($car['vehicle']['make'] === 'Toyota Fortuner' && $car['vehicle']['model'] === '2024 model' && $car['vehicle']['year'] === 2026 && $car['vehicle']['current_mileage'] === null, 'vehicle #9 is unchanged', $car['vehicle']);
    check(count($car['data_flags']) === 4, 'vehicle #9 inconsistencies are reported', $car['data_flags']);
    check($car['vehicle']['acquisition_date'] === null && $car['vehicle']['usage_frequency'] === null, 'new profile fields stay empty until entered');

    check(call('GET', '/api/cars', $admin)['status'] === 200, 'admin vehicle list still works');
    check(call('GET', '/api/cars/available?start_date=2030-01-01&end_date=2030-01-03', $customer)['status'] !== 500, 'customer availability search still responds');
    check(call('GET', "/api/cars/{$data['vehicle_id']}")['status'] === 200, 'vehicle detail still works');
    check(call('GET', '/api/me', $admin)['body']['data']['role'] === 'admin', 'admin /api/me still works');
    check(call('GET', '/api/me', $customer)['body']['data']['role'] === 'customer', 'customer /api/me still works');
    check(call('GET', '/api/reservations', $customer)['status'] === 200, 'customer reservations still work');
    check(call('GET', '/api/reservations', $admin)['status'] === 200, 'admin reservations still work');
    check(call('GET', '/api/maintenance', $admin)['status'] === 200, 'admin maintenance records still work');
    check(call('GET', '/api/maintenance', $customer)['status'] === 403, 'customers stay out of maintenance records');
    $legacy = call('GET', "/api/maintenance/vehicles/{$data['vehicle_id']}/components", $admin);
    check($legacy['status'] === 200, 'the existing component endpoint still works', $legacy['raw']);
    $legacyKeys = array_column($legacy['body']['data']['components'] ?? [], 'key');
    check(in_array('air-conditioning', $legacyKeys, true) && in_array('engine', $legacyKeys, true), 'the existing endpoint lists AC and the original components');
    foreach ($legacy['body']['data']['components'] ?? [] as $component) {
        if ($component['key'] === 'air-conditioning') {
            check($component['condition']['status'] === 'unknown', 'AC starts as unknown on the existing scale, not good');
        }
    }
} else {
    /*
     * SYNTHETIC DATA, ON A DISPOSABLE DATABASE ONLY. Seeds fake history through the real API to
     * exercise the whole pipeline. Brakes get a learnable pattern; engine outcomes are coin flips.
     * The vehicles must have plates starting SYNTH- and exist only in that copy of the database.
     */
    $vehicles = array_filter(array_map('intval', explode(',', (string) getenv('SYNTH_VEHICLE_IDS'))));
    check(count($vehicles) >= 8, 'synthetic vehicles exist in this test database');
    $overview = static function () use ($admin) { return call('GET', '/api/assessments/training/overview', $admin)['body']['data']; };
    $category = static function ($data, $key) {
        return array_values(array_filter($data['sufficiency']['categories'], static function ($item) use ($key) { return $item['key'] === $key; }))[0];
    };
    $brakeUrl = '/api/assessments/training/brakes';

    check(call('POST', "{$brakeUrl}/train", $customer)['status'] === 403, 'a customer cannot start training');
    check(call('GET', '/api/assessments/training/overview', $customer)['status'] === 403, 'a customer cannot read training data');
    check(call('POST', '/api/assessments/training/wipers/train', $admin)['status'] === 404, 'an unknown category is refused');
    $refused = call('POST', "{$brakeUrl}/train", $admin);
    check($refused['status'] === 409 && !empty($refused['body']['blockers']), 'training is refused with no data', $refused['raw']);

    mt_srand(11);
    $start = strtotime('-3 years');
    $count = 200;
    $odometer = array_fill_keys($vehicles, 20000);
    $seed = static function ($index) use ($admin, $vehicles, $start, &$odometer) {
        $vehicle = $vehicles[$index % count($vehicles)];
        $assessed = date('Y-m-d', $start + $index * 3 * 86400);
        $odometer[$vehicle] += 800;
        $fault = mt_rand(0, 1) === 1;
        $saved = call('POST', '/api/assessments', $admin, ['vehicle_id' => $vehicle, 'assessed_on' => $assessed, 'odometer_km' => $odometer[$vehicle], 'components' => [
            'brakes' => ['rating' => $fault ? ['poor', 'critical'][mt_rand(0, 1)] : ['excellent', 'good'][mt_rand(0, 1)]],
            'engine' => ['rating' => ['excellent', 'good', 'fair', 'poor'][mt_rand(0, 3)]],
        ]]);
        $id = $saved['body']['data']['id'] ?? null;
        $outcomeDate = date('Y-m-d', strtotime($assessed) + mt_rand(5, 40) * 86400);
        $out = [];
        foreach (['brakes' => $fault, 'engine' => mt_rand(0, 1) === 1] as $key => $isFault) {
            $body = $isFault
                ? ['decision' => 'deferred', 'result' => 'confirmed_failure', 'failure_date' => $outcomeDate, 'mileage_km' => $odometer[$vehicle] + 100]
                : ['decision' => 'deferred', 'result' => 'no_fault_found', 'outcome_date' => $outcomeDate, 'mileage_km' => $odometer[$vehicle] + 100];
            $out[$key] = call('POST', "/api/assessments/{$id}/categories/{$key}/outcomes", $admin, $body)['body']['data']['id'] ?? null;
        }
        return $out;
    };

    // Below the minimum, unverified outcomes do not count, then verified ones do.
    $ids = [];
    for ($index = 0; $index < 20; $index++) {
        $ids[] = $seed($index);
    }
    check($category($overview(), 'brakes')['eligible'] === 0, 'unverified outcomes are excluded');
    check(($category($overview(), 'brakes')['excluded_by_reason']['not_verified'] ?? 0) === 20, 'unverified outcomes are reported as excluded');
    foreach ($ids as $pair) {
        foreach ($pair as $outcomeId) {
            call('POST', "/api/assessments/outcomes/{$outcomeId}/verify", $admin, ['status' => 'verified']);
        }
    }
    $few = $category($overview(), 'brakes');
    check($few['eligible'] === 20 && $few['status'] === 'insufficient' && $few['remaining'] === 10, 'a category under 30 cannot qualify', $few);
    check($few['faults'] > 0 && $few['healthy'] > 0, 'faults and healthy results are both counted', $few);
    check(call('POST', "{$brakeUrl}/train", $admin)['status'] === 409, 'training is refused under the minimum');

    // The rest of the history.
    for ($index = 20; $index < $count; $index++) {
        foreach ($seed($index) as $outcomeId) {
            call('POST', "/api/assessments/outcomes/{$outcomeId}/verify", $admin, ['status' => 'verified']);
        }
    }
    $full = $overview();
    check($category($full, 'brakes')['status'] === 'sufficient', 'a diverse, balanced category qualifies', $category($full, 'brakes'));
    check($category($full, 'tires_wheels')['status'] === 'insufficient', 'qualifying one category does not qualify another');

    $dataset = call('GET', "{$brakeUrl}/dataset", $admin)['body']['data'];
    check(count($dataset['rows']) === $count, 'the export holds the eligible records', count($dataset['rows']));
    $leaks = 0;
    foreach ($dataset['rows'] as $row) {
        $payload = $row['assessment'];
        foreach ($payload['maintenance_history'] as $service) {
            $leaks += $service['date'] > $row['assessed_on'] ? 1 : 0;
        }
        $previous = $payload['previous_assessment']['assessed_on'] ?? null;
        $leaks += $previous !== null && $previous > $row['assessed_on'] ? 1 : 0;
        $leaks += isset($payload['result']) || isset($payload['outcome_date']) ? 1 : 0;
        $leaks += ($payload['category_history']['brakes']['prior_outcomes'] ?? 0) > $count ? 1 : 0;
    }
    check($leaks === 0, 'the export holds nothing from after each assessment', $leaks);
    $first = $dataset['rows'][0];
    foreach (['vehicle_id', 'acquisition_date', 'vehicle_age_years', 'usage_frequency', 'assessed_on', 'odometer_km', 'previous_odometer_km', 'mileage_difference', 'category_key', 'previous_maintenance_date', 'failure_date', 'parts_replaced', 'outcome_date', 'outcome_mileage_km', 'label'] as $field) {
        check(array_key_exists($field, $first), "the export includes {$field}");
    }

    // A learnable category passes evaluation but is not accepted automatically.
    $beforeTrain = $overview();
    $trained = call('POST', "{$brakeUrl}/train", $admin);
    check($trained['status'] === 200, 'training runs on qualifying data', $trained['raw']);
    $model = $trained['body']['data'] ?? [];
    check(($model['status'] ?? null) === 'evaluated_pending', 'a model that passes waits for review', $model['report']['decision_reasons'] ?? null);
    check(($model['eligible_for_inference'] ?? true) === false, 'a pending model cannot serve');
    $report = $model['report'] ?? [];
    check(($report['periods']['training'][1] ?? 'z') < ($report['periods']['test'][0] ?? ''), 'evaluation uses later records than training');
    check(isset($report['metrics']['confusion_matrix'], $report['false_negative_analysis'], $report['acceptance_criteria']), 'the report has a confusion matrix, false negatives and criteria');

    $pendingCheck = call('POST', '/api/assessments', $admin, ['vehicle_id' => $vehicles[0], 'assessed_on' => $today, 'odometer_km' => $odometer[$vehicles[0]] + 500, 'components' => ['brakes' => ['rating' => 'critical']]]);
    check(($pendingCheck['body']['data']['analysis']['findings']['brakes']['prediction']['available'] ?? true) === false, 'a pending model gives no predictions');

    check(call('POST', "{$brakeUrl}/accept", $admin, ['version' => 'nope'])['status'] === 409, 'an unknown version cannot be accepted');
    $accepted = call('POST', "{$brakeUrl}/accept", $admin, ['version' => $model['model_version'] ?? '']);
    check(($accepted['body']['data']['status'] ?? null) === 'evaluated_accepted', 'a passed model can be accepted', $accepted['raw']);

    // A category with no learnable pattern fails.
    $noise = call('POST', '/api/assessments/training/engine/train', $admin);
    check(($noise['body']['data']['status'] ?? null) === 'evaluated_rejected', 'a model with no signal is rejected', $noise['body']['data']['report']['decision_reasons'] ?? $noise['raw']);
    check(!empty($noise['body']['data']['report']['decision_reasons']), 'a rejection states its reasons');
    check(call('POST', '/api/assessments/training/engine/accept', $admin, ['version' => $noise['body']['data']['model_version'] ?? ''])['status'] === 409, 'a rejected model cannot be accepted');

    // Predictions unlock per category. Screening still runs.
    $live = call('POST', '/api/assessments', $admin, ['vehicle_id' => $vehicles[1], 'assessed_on' => $today, 'odometer_km' => $odometer[$vehicles[1]] + 500, 'components' => [
        'brakes' => ['rating' => 'critical', 'symptoms' => ['pedal_to_floor']], 'engine' => ['rating' => 'poor'], 'cooling' => ['rating' => 'good'],
    ]]);
    $findings = $live['body']['data']['analysis']['findings'] ?? [];
    check(($findings['brakes']['prediction']['available'] ?? false) === true, 'the accepted category gives a prediction', $findings['brakes'] ?? $live['raw']);
    check(($findings['brakes']['risk_level'] ?? null) === 'elevated', 'the stored prediction has a risk level');
    check(($findings['brakes']['ai_model_version'] ?? null) === ($model['model_version'] ?? 'x'), 'the prediction records its model version');
    check($findings['brakes']['model_score'] !== null && $findings['brakes']['confidence'] === null, 'the score is stored and no confidence is claimed');
    check(($findings['brakes']['prediction_horizon'] ?? null) === '90 days', 'the prediction states its horizon');
    check(($findings['engine']['prediction']['available'] ?? true) === false && $findings['engine']['ai_model'] === null, 'the rejected category stays unavailable');
    check(($findings['cooling']['prediction']['available'] ?? true) === false, 'an untrained category stays unavailable');
    check(in_array('brakes.pedal_to_floor', array_column($live['body']['data']['safety_warnings'], 'rule_id'), true), 'screening still runs with a model active');

    $listing = $overview();
    $brakeModel = array_values(array_filter($listing['models'], static function ($item) { return $item['key'] === 'brakes'; }))[0];
    check($brakeModel['available'] === true && $brakeModel['active_version'] === $model['model_version'], 'the registry shows the active model');
    check(count($listing['runs']) === 2 && $listing['runs'][0]['status'] === 'completed', 'training runs are recorded', $listing['runs']);

    // Revoking closes it again.
    check(call('POST', "{$brakeUrl}/revoke", $admin, [])['status'] === 409, 'withdrawing needs a reason');
    check(call('POST', "{$brakeUrl}/revoke", $admin, ['reason' => 'Test'])['status'] === 200, 'a model can be withdrawn');
    $after = call('POST', '/api/assessments', $admin, ['vehicle_id' => $vehicles[2], 'assessed_on' => $today, 'odometer_km' => $odometer[$vehicles[2]] + 500, 'components' => ['brakes' => ['rating' => 'critical']]]);
    check(($after['body']['data']['analysis']['findings']['brakes']['prediction']['available'] ?? true) === false, 'a withdrawn model stops predicting');
}

echo "{$phase}: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
