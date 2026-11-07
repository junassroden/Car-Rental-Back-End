<?php

require_once __DIR__ . '/../app/libraries/ComponentMaintenanceRules.php';

function check($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function rejects(callable $callback, $message)
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$today = new DateTimeImmutable('2026-11-07');
$valid = ['assessed_on' => '2026-11-07', 'vehicle_id' => 1];
$normalize = static function (array $input) use ($today, $valid) {
    return ComponentMaintenanceRules::normalize_assessment($input + $valid, $today);
};

// The eight categories, and the Air Conditioning mapping.
$catalog = ComponentMaintenanceRules::assessment_catalog();
check(['engine', 'battery_electrical', 'brakes', 'transmission', 'suspension_steering', 'tires_wheels', 'cooling', 'air_conditioning'], array_column($catalog['categories'], 'key'), 'eight categories');
check('air_conditioning', ComponentMaintenanceRules::category_for_component('air-conditioning'), 'AC component maps to its category');
check('brakes', ComponentMaintenanceRules::category_for_component('brakes-rear'), 'rear brakes map to brakes');
check(null, ComponentMaintenanceRules::category_for_component('made-up'), 'unknown component has no category');

// Missing data is never a rating.
$clean = $normalize([]);
check(8, count($clean['components']), 'all categories are present');
foreach ($clean['components'] as $component) {
    check('not_assessed', $component['rating'], 'a missing rating stays not_assessed');
}
check(null, $clean['odometer_km'], 'a missing odometer stays NULL');
check(0, $normalize(['odometer_km' => 0])['odometer_km'], 'zero odometer is a real value');
$screened = ComponentMaintenanceRules::screen_component('engine', ['rating' => 'not_assessed']);
check('not_assessed', $screened['screening_status'], 'not assessed screens as not assessed');
check([], $screened['findings'], 'not assessed has no findings');

// Every rating level.
$expected = ['excellent' => 'no_concerns_recorded', 'good' => 'no_concerns_recorded', 'fair' => 'monitor', 'poor' => 'attention', 'critical' => 'critical', 'not_applicable' => 'not_applicable', 'unknown' => 'insufficient_data', 'not_assessed' => 'not_assessed'];
foreach ($expected as $rating => $status) {
    check($status, ComponentMaintenanceRules::screen_component('engine', ['rating' => $rating])['screening_status'], "rating {$rating}");
    check($rating, $normalize(['components' => ['engine' => ['rating' => $rating]]])['components']['engine']['rating'], "rating {$rating} is accepted");
}

// Safety rules, independent of any model.
$brake = ComponentMaintenanceRules::screen_component('brakes', ['rating' => 'good', 'symptoms' => ['pedal_to_floor']]);
check('critical', $brake['screening_status'], 'critical brake symptom outranks a good rating');
check(true, $brake['findings'][0]['safety'], 'brake symptom is a safety warning');
foreach (['component_name', 'observation', 'reason', 'recommended_action'] as $field) {
    check(true, $brake['findings'][0][$field] !== '', "warning states its {$field}");
}
check('critical', ComponentMaintenanceRules::screen_component('tires_wheels', ['symptoms' => ['tire_bulge']])['screening_status'], 'tire bulge');
$tread = ComponentMaintenanceRules::screen_component('tires_wheels', ['measurements' => ['tread_depth_min_mm' => 1.2]]);
check('critical', $tread['screening_status'], 'low tread');
check(true, strpos($tread['findings'][0]['observation'], '1.2 mm') !== false, 'observation quotes the reading');
check('critical', ComponentMaintenanceRules::screen_component('cooling', ['symptoms' => ['overheating']])['screening_status'], 'overheating');
check(true, ComponentMaintenanceRules::screen_component('brakes', ['rating' => 'critical'])['findings'][0]['safety'], 'critical brakes rating is a safety warning');
check(false, ComponentMaintenanceRules::screen_component('air_conditioning', ['rating' => 'critical'])['findings'][0]['safety'], 'critical AC rating is not a road-safety warning');
check([], ComponentMaintenanceRules::screen_component('tires_wheels', ['measurements' => ['tread_depth_min_mm' => 'unknown']])['findings'], 'unknown measurement triggers nothing');
check('critical', ComponentMaintenanceRules::screen_component('tires_wheels', ['measurements' => ['tread_depth_min_mm' => 0.0]])['screening_status'], 'zero tread is a real value');

// Mileage checks.
check('decrease', ComponentMaintenanceRules::odometer_check(100, 500)['flag'], 'decrease flagged');
check('none', ComponentMaintenanceRules::odometer_check(500, 500)['flag'], 'same reading is fine');
check('none', ComponentMaintenanceRules::odometer_check(0, null)['flag'], 'zero with no history is fine');
check('missing', ComponentMaintenanceRules::odometer_check(null, 500)['flag'], 'missing differs from zero');

// Overall status never turns missing data into an all-clear.
check('critical', ComponentMaintenanceRules::overall_screening(['no_concerns_recorded', 'critical']), 'worst status wins');
check('no_concerns_recorded', ComponentMaintenanceRules::overall_screening(['no_concerns_recorded', 'not_applicable']), 'not applicable is ignored');
check('insufficient_data', ComponentMaintenanceRules::overall_screening(['insufficient_data', 'not_assessed']), 'insufficient outranks not assessed');
check('not_assessed', ComponentMaintenanceRules::overall_screening(['not_applicable']), 'nothing rated is not assessed');

// Monthly reassessment.
check('never_assessed', ComponentMaintenanceRules::reassessment_status(null, $today, 30)['status'], 'never assessed');
check('current', ComponentMaintenanceRules::reassessment_status('2026-11-01', $today, 30)['status'], 'recent');
check('due_soon', ComponentMaintenanceRules::reassessment_status('2026-10-12', $today, 30)['status'], 'due soon');
check('overdue', ComponentMaintenanceRules::reassessment_status('2026-09-01', $today, 30)['status'], 'overdue');

// Vehicle #9 style inconsistencies are reported, with no specific model inferred.
$flags = ComponentMaintenanceRules::vehicle_data_flags(['make' => 'Toyota Fortuner', 'model' => '2024 model', 'year' => 2026, 'current_mileage' => null], $today);
check(4, count($flags), 'the vehicle #9 pattern raises four flags');
check([], ComponentMaintenanceRules::vehicle_data_flags(['make' => 'Toyota', 'model' => 'Vios', 'year' => 2022, 'current_mileage' => 15000], $today), 'a clean record raises none');
check(4, ComponentMaintenanceRules::vehicle_age_years(2022, $today), 'vehicle age');
check(null, ComponentMaintenanceRules::vehicle_age_years(2030, $today), 'a future year has no age');

// Submission validation.
rejects(static function () use ($normalize) { $normalize(['assessed_on' => 'soon']); }, 'bad date');
rejects(static function () use ($normalize) { $normalize(['assessed_on' => '2027-01-01']); }, 'future date');
rejects(static function () use ($normalize) { $normalize(['odometer_km' => -1]); }, 'negative odometer');
rejects(static function () use ($normalize) { $normalize(['idempotency_key' => 'abc']); }, 'bad idempotency key');
rejects(static function () use ($normalize) { $normalize(['components' => ['wipers' => []]]); }, 'unknown category');
rejects(static function () use ($normalize) { $normalize(['components' => ['engine' => ['rating' => 5]]]); }, 'a numeric rating is not accepted');
rejects(static function () use ($normalize) { $normalize(['components' => ['engine' => ['answers' => ['made_up' => 'yes']]]]); }, 'unknown question');
rejects(static function () use ($normalize) { $normalize(['components' => ['engine' => ['answers' => ['oil_level_ok' => 'maybe']]]]); }, 'invalid answer');
rejects(static function () use ($normalize) { $normalize(['components' => ['engine' => ['symptoms' => ['made_up']]]]); }, 'unknown symptom');
rejects(static function () use ($normalize) { $normalize(['components' => ['brakes' => ['measurements' => ['front_pad_mm' => 500]]]]); }, 'measurement out of range');
rejects(static function () use ($normalize) { $normalize(['components' => ['engine' => ['fault_codes' => ['XYZ']]]]); }, 'bad fault code');
rejects(static function () use ($normalize) { $normalize(['components' => ['tires_wheels' => ['fault_codes' => ['C0035']]]]); }, 'tires take no fault codes');
rejects(static function () use ($normalize) { $normalize(['components' => ['engine' => ['last_maintenance_date' => '2026-12-01']]]); }, 'maintenance after assessment');
$codes = $normalize(['components' => ['engine' => ['fault_codes' => ['p0118', 'P0118']]]])['components']['engine']['fault_codes'];
check(['P0118'], $codes, 'codes are normalized and de-duplicated');

// Profile validation.
check(['acquisition_date' => null], ComponentMaintenanceRules::normalize_vehicle_profile(['acquisition_date' => ''], $today), 'blank clears to NULL');
check(['usage_frequency' => 'daily'], ComponentMaintenanceRules::normalize_vehicle_profile(['usage_frequency' => 'daily'], $today), 'valid usage');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_vehicle_profile(['usage_frequency' => 'hourly'], $today); }, 'bad usage');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_vehicle_profile(['acquisition_date' => '2030-01-01'], $today); }, 'future acquisition');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_vehicle_profile(['make' => 'x'], $today); }, 'other fields cannot be set here');

// Outcomes.
$outcome = ComponentMaintenanceRules::normalize_outcome(['decision' => 'deferred', 'verification_status' => 'verified'], $today);
check('deferred', $outcome['decision'], 'deferred is valid');
check(false, array_key_exists('verification_status', $outcome), 'a client cannot mark its own outcome verified');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_outcome(['decision' => 'maybe'], $today); }, 'bad decision');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_outcome(['decision' => 'completed'], $today); }, 'completed needs the work described');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_outcome(['decision' => 'deferred', 'result' => 'confirmed_failure'], $today); }, 'failure needs a date');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_outcome(['decision' => 'deferred', 'result' => 'successful_repair'], $today); }, 'repair needs a date');
rejects(static function () use ($today) { ComponentMaintenanceRules::normalize_outcome(['decision' => 'deferred', 'failure_date' => '2026-11-05', 'repair_date' => '2026-11-01'], $today); }, 'repair before failure');

echo "Vehicle assessment rule tests passed.\n";
