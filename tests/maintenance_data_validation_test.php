<?php

require_once __DIR__ . '/../app/libraries/MaintenanceDataValidation.php';
require_once __DIR__ . '/../app/libraries/MaintenanceDataPolicy.php';

function assert_same_value($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
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

$manual = MaintenanceDataValidation::normalize_record([
    'vehicle_id' => '12',
    'record_type' => 'vehicle_inspection',
    'recorded_at' => '2025-03-14T09:26:53Z',
    'odometer_reading' => '',
    'inspection_findings' => 'No issue confirmed.',
    'verification_status' => 'verified',
], true);
assert_same_value(null, $manual['odometer_reading'], 'manual inspection permits missing odometer');
assert_same_value([], $manual['sensor_measurements'], 'manual inspection does not require sensor data');
assert_same_value(null, $manual['confirmed_cause'], 'confirmed cause remains optional');
assert_same_value('2025-03-14 09:26:53', $manual['recorded_at'], 'timestamps normalize to UTC');
assert_same_value(false, array_key_exists('verification_status', $manual), 'client-submitted status is ignored');

$measurement = MaintenanceDataValidation::normalize_record([
    'vehicle_id' => 3,
    'record_type' => 'sensor_measurement',
    'recorded_at' => '2025-03-14T09:26:53+00:00',
    'sensor_measurements' => [['name' => 'coolant_temperature', 'value' => '91.5', 'unit' => 'degC']],
    'dtc_codes' => ['P0128', 'P0128'],
], true);
assert_same_value(91.5, $measurement['sensor_measurements'][0]['value'], 'sensor readings are numeric');
assert_same_value(['P0128'], $measurement['dtc_codes'], 'duplicate DTC entries are removed');

assert_invalid_input(static function () {
    MaintenanceDataValidation::normalize_record([
        'vehicle_id' => 1,
        'record_type' => 'unknown_failure',
        'recorded_at' => '2025-01-01T00:00:00Z',
    ], true);
}, 'unsupported record types are rejected');

assert_invalid_input(static function () {
    MaintenanceDataValidation::normalize_record([
        'vehicle_id' => 1,
        'record_type' => 'sensor_measurement',
        'recorded_at' => '2025-01-01T00:00:00Z',
        'sensor_measurements' => [['name' => 'rpm', 'value' => 'NaN', 'unit' => 'rpm']],
    ], true);
}, 'non-finite sensor values are rejected');
assert_invalid_input(static function () {
    MaintenanceDataValidation::normalize_record([
        'vehicle_id' => 3,
        'record_type' => 'sensor_measurement',
        'recorded_at' => '2025-03-14T09:26:53Z',
        'sensor_measurements' => [],
    ], true);
}, 'sensor measurement records require at least one measurement');

assert_invalid_input(static function () {
    MaintenanceDataValidation::normalize_timestamp('not a timestamp');
}, 'invalid timestamps are rejected');
assert_invalid_input(static function () {
    MaintenanceDataValidation::normalize_timestamp('2025-02-31T00:00:00Z');
}, 'invalid calendar dates are rejected');
assert_invalid_input(static function () {
    MaintenanceDataValidation::normalize_timestamp('2025-03-14 09:26:53');
}, 'timestamps without an explicit timezone are rejected');

assert_invalid_input(static function () {
    MaintenanceDataValidation::normalize_record([
        'vehicle_id' => 1,
        'record_type' => 'vehicle_inspection',
        'recorded_at' => '2025-01-01T00:00:00Z',
        'odometer_reading' => -1,
    ], true);
}, 'negative odometer readings are rejected');

assert_same_value(true, MaintenanceDataPolicy::can_access(['role' => 'admin', 'is_active' => 1]), 'active admins can access maintenance evidence');
assert_same_value(false, MaintenanceDataPolicy::can_access(['role' => 'customer', 'is_active' => 1]), 'customers cannot access maintenance evidence');
assert_same_value(false, MaintenanceDataPolicy::can_access(['role' => 'admin', 'is_active' => 0]), 'inactive admins cannot access maintenance evidence');
assert_same_value(false, MaintenanceDataPolicy::can_verify(['created_by' => 7], 7), 'submitters cannot verify their own records');
assert_same_value(true, MaintenanceDataPolicy::can_verify(['created_by' => 7], 8), 'another admin can verify a submitted record');
MaintenanceDataPolicy::assert_transition('draft', 'awaiting_verification');
MaintenanceDataPolicy::assert_transition('awaiting_verification', 'verified');
MaintenanceDataPolicy::assert_transition('awaiting_verification', 'rejected');
assert_invalid_input(static function () {
    MaintenanceDataPolicy::assert_transition('draft', 'verified');
}, 'draft records cannot be verified without submission');
assert_same_value(false, MaintenanceDataPolicy::confirmed_fault_label([
    'verification_status' => 'verified',
    'record_type' => 'diagnostic_trouble_code',
    'confirmed_cause' => 'P0128',
]), 'a DTC must never become a confirmed fault label');
assert_same_value(false, MaintenanceDataPolicy::confirmed_fault_label([
    'verification_status' => 'awaiting_verification',
    'record_type' => 'mechanic_confirmed_fault',
    'confirmed_cause' => 'Thermostat stuck open',
]), 'unverified confirmed-fault claims must not become export labels');
assert_same_value(true, MaintenanceDataPolicy::confirmed_fault_label([
    'verification_status' => 'verified',
    'record_type' => 'mechanic_confirmed_fault',
    'confirmed_cause' => 'Thermostat stuck open',
]), 'only explicitly verified confirmed faults produce positive labels');

$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../app/migrations/008_create_maintenance_data_records.php');
$model = file_get_contents(__DIR__ . '/../app/models/MaintenanceDataModel.php');
if (!preg_match('/maintenance_data_vehicle_fk[\s\S]+?REFERENCES cars\(id\) ON DELETE RESTRICT/i', $schema)
    || !preg_match('/maintenance_data_verifier_fk[\s\S]+?REFERENCES users\(id\) ON DELETE SET NULL/i', $schema)
    || !preg_match('/maintenance_audit_record_fk[\s\S]+?REFERENCES maintenance_data_records\(id\) ON DELETE RESTRICT/i', $schema)
    || strpos($schema, 'maintenance_data_component_identifier_idx') === false
    || strpos($migration, 'maintenance_data_records') === false
    || strpos($migration, 'maintenance_data_audit') === false) {
    fwrite(STDERR, "FAIL: maintenance data schema integrity constraints are incomplete.\n");
    exit(1);
}
$export_start = strpos($model, 'public function verified_export()');
$export_end = strpos($model, 'private function transition(', $export_start);
$export_code = substr($model, $export_start, $export_end - $export_start);
if ($export_start === false || $export_end === false
    || strpos($export_code, "WHERE r.verification_status = ?") === false
    || strpos($export_code, "'verified'") === false
    || strpos($export_code, 'evidence_reference') !== false
    || strpos($export_code, 'verified_by') !== false) {
    fwrite(STDERR, "FAIL: verified export includes unverified rows or unnecessary identifiers.\n");
    exit(1);
}

echo "Maintenance data validation tests passed.\n";
