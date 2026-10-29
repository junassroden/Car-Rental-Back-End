<?php

class MaintenanceDataValidation
{
    public const RECORD_TYPES = [
        'vehicle_inspection',
        'sensor_measurement',
        'diagnostic_scan',
        'diagnostic_trouble_code',
        'suspected_fault',
        'mechanic_confirmed_fault',
        'repair_performed',
        'component_replaced',
        'follow_up_inspection',
        'preventive_maintenance',
    ];

    public const STATUSES = [
        'draft',
        'awaiting_verification',
        'verified',
        'rejected',
        'superseded',
    ];

    private const TEXT_LIMITS = [
        'component' => 160,
        'component_identifier' => 160,
        'symptoms' => 10000,
        'inspection_findings' => 10000,
        'suspected_cause' => 5000,
        'confirmed_cause' => 5000,
        'diagnostic_tests' => 10000,
        'repair_action' => 10000,
        'repair_outcome' => 5000,
        'technician_reference' => 160,
        'source' => 160,
        'evidence_reference' => 5000,
        'engine_details' => 500,
    ];

    public static function normalize_record(array $input, $is_create = false)
    {
        if ($is_create) {
            $vehicle_id = filter_var(
                $input['vehicle_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if (!$vehicle_id) {
                throw new InvalidArgumentException('A valid vehicle is required.');
            }
        } else {
            $vehicle_id = null;
        }

        $raw_record_type = $input['record_type'] ?? '';
        if (!is_scalar($raw_record_type)) {
            throw new InvalidArgumentException('Record type must be a supported text value.');
        }
        $record_type = trim((string) $raw_record_type);
        if ($is_create && !in_array($record_type, self::RECORD_TYPES, true)) {
            throw new InvalidArgumentException('Choose a supported maintenance record type.');
        }

        $recorded_at = null;
        if ($is_create || array_key_exists('recorded_at', $input)) {
            $recorded_at = self::normalize_timestamp($input['recorded_at'] ?? null);
        }

        $odometer = null;
        if (array_key_exists('odometer_reading', $input) && $input['odometer_reading'] !== '' && $input['odometer_reading'] !== null) {
            $odometer = filter_var(
                $input['odometer_reading'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 4294967295]]
            );
            if ($odometer === false) {
                throw new InvalidArgumentException('Odometer must be a non-negative whole number.');
            }
        }
        $odometer_unit = null;
        if ($odometer !== null) {
            $raw_unit = $input['odometer_unit'] ?? '';
            if (!is_string($raw_unit) || !in_array($raw_unit, ['km', 'mi'], true)) {
                throw new InvalidArgumentException('Choose km or mi for the odometer unit.');
            }
            $odometer_unit = $raw_unit;
        } elseif (array_key_exists('odometer_unit', $input) && $input['odometer_unit'] !== null && $input['odometer_unit'] !== '') {
            if (!is_string($input['odometer_unit']) || !in_array($input['odometer_unit'], ['km', 'mi'], true)) {
                throw new InvalidArgumentException('Odometer unit must be km or mi.');
            }
            $odometer_unit = $input['odometer_unit'];
        }

        $values = [];
        foreach (self::TEXT_LIMITS as $field => $limit) {
            if (!$is_create && !array_key_exists($field, $input)) {
                continue;
            }
            $raw_value = $input[$field] ?? '';
            if (!is_scalar($raw_value)) {
                throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . ' must be text.');
            }
            $value = trim((string) $raw_value);
            if (strlen($value) > $limit) {
                throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . " must be {$limit} characters or fewer.");
            }
            $values[$field] = $value === '' ? null : $value;
        }

        if ($is_create || array_key_exists('dtc_codes', $input)) {
            $values['dtc_codes'] = self::normalize_list($input['dtc_codes'] ?? [], 'DTC codes', 100, 32);
            foreach ($values['dtc_codes'] as $code) {
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $code)) {
                    throw new InvalidArgumentException('DTC codes may contain only letters, numbers, dots, underscores, and hyphens.');
                }
            }
        }

        if ($is_create || array_key_exists('sensor_measurements', $input)) {
            $measurements = $input['sensor_measurements'] ?? [];
            if (!is_array($measurements) || count($measurements) > 100) {
                throw new InvalidArgumentException('Sensor measurements must be a list of at most 100 entries.');
            }
            $values['sensor_measurements'] = [];
            foreach ($measurements as $measurement) {
                if (!is_array($measurement)) {
                    throw new InvalidArgumentException('Each sensor measurement must include a name, value, and unit.');
                }
                $raw_name = $measurement['name'] ?? '';
                $raw_unit = $measurement['unit'] ?? '';
                if (!is_string($raw_name) || !is_string($raw_unit)) {
                    throw new InvalidArgumentException('Sensor names and units must be text.');
                }
                $name = trim($raw_name);
                $unit = trim($raw_unit);
                $raw_value = $measurement['value'] ?? null;
                if ($name === '' || strlen($name) > 160 || $unit === '' || strlen($unit) > 40 || !is_numeric($raw_value) || !is_finite((float) $raw_value)) {
                    throw new InvalidArgumentException('Each sensor measurement needs a name, finite numeric value, and unit.');
                }
                $values['sensor_measurements'][] = [
                    'name' => $name,
                    'value' => (float) $raw_value,
                    'unit' => $unit,
                ];
            }
        }

        if ($is_create || array_key_exists('replaced_components', $input)) {
            $values['replaced_components'] = self::normalize_list(
                $input['replaced_components'] ?? [],
                'Replaced components',
                100,
                160
            );
        }

        $result = $values;
        if ($is_create) {
            if (!empty($values['confirmed_cause']) && $record_type !== 'mechanic_confirmed_fault') {
                throw new InvalidArgumentException('A confirmed cause is only valid for a mechanic-confirmed fault record.');
            }
            if ($record_type === 'sensor_measurement' && empty($values['sensor_measurements'])) {
                throw new InvalidArgumentException('A sensor measurement record requires at least one measurement with a value and unit.');
            }
            $result += [
                'vehicle_id' => (int) $vehicle_id,
                'record_type' => $record_type,
                'recorded_at' => $recorded_at,
                'odometer_reading' => $odometer,
                'odometer_unit' => $odometer_unit,
                'source' => $values['source'] ?? 'manual_entry',
            ];
        } else {
            if (array_key_exists('odometer_reading', $input)) {
                $result['odometer_reading'] = $odometer;
                $result['odometer_unit'] = $odometer_unit;
            }
            if (array_key_exists('recorded_at', $input)) {
                $result['recorded_at'] = $recorded_at;
            }
        }

        return $result;
    }

    public static function normalize_timestamp($value)
    {
        if (!is_string($value)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new InvalidArgumentException('Timestamp must be ISO-8601 with seconds and an explicit timezone.');
        }
        try {
            $timestamp = new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Timestamp must be a valid ISO-8601 date and time.');
        }
        $date_errors = DateTimeImmutable::getLastErrors();
        if ($date_errors !== false && ($date_errors['warning_count'] || $date_errors['error_count'])) {
            throw new InvalidArgumentException('Timestamp must be a valid calendar date and time.');
        }
        $timestamp = $timestamp->setTimezone(new DateTimeZone('UTC'));
        $future_limit = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 minutes');
        if ($timestamp > $future_limit) {
            throw new InvalidArgumentException('A record timestamp cannot be in the future.');
        }
        return $timestamp->format('Y-m-d H:i:s');
    }

    public static function normalize_list($value, $label, $maximum, $item_limit)
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value);
        }
        if (!is_array($value) || count($value) > $maximum) {
            throw new InvalidArgumentException("{$label} must be a list of at most {$maximum} entries.");
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new InvalidArgumentException("Each {$label} entry must be text.");
            }
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (strlen($item) > $item_limit) {
                throw new InvalidArgumentException("Each {$label} entry must be {$item_limit} characters or fewer.");
            }
            $items[] = $item;
        }
        return array_values(array_unique($items));
    }

    public static function encode_json_fields(array $record)
    {
        foreach (['dtc_codes', 'sensor_measurements', 'replaced_components'] as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = json_encode($record[$field], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        return $record;
    }
}
