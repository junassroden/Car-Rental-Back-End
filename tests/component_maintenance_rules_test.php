<?php

require_once __DIR__ . '/../app/libraries/ComponentMaintenanceRules.php';

function assert_same_value($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_invalid_input(callable $callback, $message)
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function inspection(array $fields)
{
    return $fields + [
        'id' => 1, 'inspected_on' => '2026-10-01', 'odometer_km' => null,
        'condition_rating' => 'good', 'service_performed' => 0, 'service_date' => null,
        'next_due_date' => null, 'next_due_km' => null, 'recommended_action' => null,
        'technician' => null, 'recorded_by_name' => 'admin',
    ];
}

function rule(array $fields = [])
{
    return $fields + [
        'id' => 1, 'scope' => 'vehicle', 'interval_days' => null, 'interval_km' => null,
        'due_soon_days' => 30, 'due_soon_km' => 1000, 'basis' => 'Fleet policy',
    ];
}

$today = new DateTimeImmutable('2026-10-29');

// Body type mapping from free-text categories.
assert_same_value('suv', ComponentMaintenanceRules::body_type('SUV'), 'SUV maps to suv');
assert_same_value('motorcycle', ComponentMaintenanceRules::body_type('Motorbike'), 'motorbike maps to motorcycle');
assert_same_value('minivan', ComponentMaintenanceRules::body_type('Minivan'), 'minivan is not treated as van');
assert_same_value('van', ComponentMaintenanceRules::body_type('Cargo Van'), 'van maps to van');
assert_same_value('pickup', ComponentMaintenanceRules::body_type('Pick-up truck'), 'pick-up maps to pickup');
assert_same_value('sedan', ComponentMaintenanceRules::body_type('Hatchback'), 'hatchback uses the sedan rig');
assert_same_value(null, ComponentMaintenanceRules::body_type('Boat'), 'unknown categories have no rig');

// Condition.
$condition = ComponentMaintenanceRules::evaluate_condition([], $today, 90);
assert_same_value('unknown', $condition['status'], 'no inspection means unknown condition');
assert_same_value(null, $condition['evidence'], 'no evidence is fabricated');

$condition = ComponentMaintenanceRules::evaluate_condition([inspection(['inspected_on' => '2026-10-01'])], $today, 90);
assert_same_value('good', $condition['status'], 'recent good inspection is good');
assert_same_value(28, $condition['evidence']['age_days'], 'evidence age is reported');

$condition = ComponentMaintenanceRules::evaluate_condition([inspection(['inspected_on' => '2026-01-01'])], $today, 90);
assert_same_value('unknown', $condition['status'], 'stale good inspection is not current confirmation');

$condition = ComponentMaintenanceRules::evaluate_condition([inspection(['inspected_on' => '2025-01-01', 'condition_rating' => 'critical'])], $today, 90);
assert_same_value('critical', $condition['status'], 'recorded critical issue persists until re-inspected');

$condition = ComponentMaintenanceRules::evaluate_condition([
    inspection(['id' => 2, 'inspected_on' => '2026-10-20', 'condition_rating' => 'not_assessed']),
    inspection(['id' => 1, 'inspected_on' => '2026-10-01', 'condition_rating' => 'needs_attention']),
], $today, 90);
assert_same_value('unknown', $condition['status'], 'unassessed latest record gives unknown condition');
assert_same_value(true, strpos($condition['reason'], 'needs attention') !== false, 'previous assessed condition is disclosed');

// Schedule.
$schedule = ComponentMaintenanceRules::evaluate_schedule([], null, 10000, $today);
assert_same_value('not_configured', $schedule['status'], 'no rule and no due point is not configured');

$schedule = ComponentMaintenanceRules::evaluate_schedule([], rule(['interval_days' => 180]), 10000, $today);
assert_same_value('unknown', $schedule['status'], 'rule without a service baseline is unknown');

$service = inspection(['service_performed' => 1, 'inspected_on' => '2026-06-01', 'odometer_km' => 20000]);
$schedule = ComponentMaintenanceRules::evaluate_schedule([$service], rule(['interval_days' => 365]), 21000, $today);
assert_same_value('not_yet_due', $schedule['status'], 'interval not reached is not yet due');
assert_same_value('2027-06-01', $schedule['due_date'], 'due date is computed from the last service');

$schedule = ComponentMaintenanceRules::evaluate_schedule([$service], rule(['interval_days' => 160]), 21000, $today);
assert_same_value('due_soon', $schedule['status'], 'interval within warning window is due soon');

$schedule = ComponentMaintenanceRules::evaluate_schedule([$service], rule(['interval_days' => 120]), 21000, $today);
assert_same_value('overdue', $schedule['status'], 'passed interval date is overdue');

$schedule = ComponentMaintenanceRules::evaluate_schedule([$service], rule(['interval_km' => 5000]), 25500, $today);
assert_same_value('overdue', $schedule['status'], 'exceeded mileage interval is overdue');
assert_same_value(-500, $schedule['km_remaining'], 'km remaining is reported');

$schedule = ComponentMaintenanceRules::evaluate_schedule([$service], rule(['interval_km' => 5000]), null, $today);
assert_same_value('unknown', $schedule['status'], 'mileage interval without current odometer is unknown');

$schedule = ComponentMaintenanceRules::evaluate_schedule([inspection(['next_due_date' => '2026-11-10'])], null, null, $today);
assert_same_value('due_soon', $schedule['status'], 'recorded next-service date drives the schedule without a rule');

$schedule = ComponentMaintenanceRules::evaluate_schedule([
    inspection(['id' => 2, 'inspected_on' => '2026-10-15', 'service_performed' => 1]),
    inspection(['id' => 1, 'inspected_on' => '2026-09-01', 'next_due_date' => '2026-10-01']),
], null, null, $today);
assert_same_value('not_configured', $schedule['status'], 'a later service satisfies an earlier recorded due date');

// Recommended action.
$action = ComponentMaintenanceRules::recommended_action([], ['status' => 'unknown'], ['status' => 'not_configured']);
assert_same_value('system_rule', $action['source'], 'derived actions are labelled as system rules');
$action = ComponentMaintenanceRules::recommended_action([inspection(['recommended_action' => 'Replace pads'])], ['status' => 'good'], ['status' => 'not_yet_due']);
assert_same_value('inspection_record', $action['source'], 'recorded recommendation is preferred');

// Inspection validation.
$record = ComponentMaintenanceRules::normalize_inspection([
    'inspected_on' => '2026-10-28', 'condition_rating' => 'good', 'odometer_km' => '15000',
    'condition' => 'critical', 'status' => 'verified',
], $today);
assert_same_value(15000, $record['odometer_km'], 'odometer is typed');
assert_same_value(false, array_key_exists('status', $record), 'client-supplied status is ignored');

assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-11-01', 'condition_rating' => 'good'], $today);
}, 'future inspection date is rejected');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-02-30', 'condition_rating' => 'good'], $today);
}, 'impossible date is rejected');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'broken'], $today);
}, 'unknown condition value is rejected');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'critical'], $today);
}, 'critical condition requires findings');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'good', 'confirmed_fault' => 'Leak'], $today);
}, 'confirmed fault cannot accompany good condition');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'good', 'work_performed' => 'Oil change'], $today);
}, 'work performed requires a service record');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'good', 'service_performed' => true], $today);
}, 'service record requires work performed');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'good', 'next_due_date' => '2026-09-01'], $today);
}, 'next due date must follow inspection');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'good', 'odometer_km' => -5], $today);
}, 'negative odometer is rejected');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'good', 'odometer_km' => 9000, 'next_due_km' => 8000], $today);
}, 'next due mileage must exceed odometer');

$record = ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'not_assessed', 'dtc_codes' => 'p0301, P0301 c1234'], $today);
assert_same_value('P0301,C1234', $record['dtc_codes'], 'DTCs are normalized and de-duplicated');
assert_invalid_input(function () use ($today) {
    ComponentMaintenanceRules::normalize_inspection(['inspected_on' => '2026-10-01', 'condition_rating' => 'good', 'dtc_codes' => 'engine bad'], $today);
}, 'malformed DTC is rejected');

// Interval validation.
$interval = ComponentMaintenanceRules::normalize_interval(['scope' => 'vehicle', 'interval_km' => '10000', 'basis' => 'Owner manual p.12']);
assert_same_value(10000, $interval['interval_km'], 'interval km is typed');
assert_same_value(30, $interval['due_soon_days'], 'due-soon window defaults are applied');
assert_invalid_input(function () {
    ComponentMaintenanceRules::normalize_interval(['scope' => 'vehicle', 'basis' => 'Fleet policy']);
}, 'interval requires days or km');
assert_invalid_input(function () {
    ComponentMaintenanceRules::normalize_interval(['scope' => 'vehicle', 'interval_days' => 90]);
}, 'interval requires a stated source');
assert_invalid_input(function () {
    ComponentMaintenanceRules::normalize_interval(['scope' => 'fleet', 'interval_days' => 90, 'basis' => 'Fleet policy']);
}, 'interval scope is validated');

echo "Component maintenance rule tests passed.\n";
