<?php

/**
 * Pure rules for component-level maintenance status. No database access, so
 * tests/component_maintenance_rules_test.php can exercise every branch.
 *
 * Three independent results are produced per component and never merged:
 *   condition - what the latest manual inspection recorded (or unknown)
 *   schedule  - whether a configured interval / recorded due point is reached
 *   prediction - decided elsewhere, from the AI service's validated models
 */
class ComponentMaintenanceRules
{
    const CONDITIONS = ['good', 'needs_attention', 'critical', 'not_assessed'];
    const BODY_TYPES = ['sedan', 'suv', 'pickup', 'van', 'minivan', 'motorcycle'];

    // Used only when a due point comes from a recorded next-service date
    // without a configured rule supplying its own warning window.
    const DEFAULT_DUE_SOON_DAYS = 30;
    const DEFAULT_DUE_SOON_KM   = 1000;

    const MAX_ODOMETER_KM = 2000000;

    /** Maps the free-text cars.category to a 3D rig body type, or NULL when no rig fits. */
    public static function body_type($category)
    {
        $value = strtolower(trim((string) $category));
        if ($value === '') {
            return null;
        }

        $patterns = [
            'motorcycle' => '/motor|bike|scooter/',
            'minivan'    => '/mini-?van|\bmpv\b|multi-?purpose/',
            'pickup'     => '/pick-?up|truck/',
            'van'        => '/\bvan\b/',
            'suv'        => '/\bsuv\b|crossover|4x4|off-?road/',
            'sedan'      => '/sedan|saloon|hatch|coupe|compact|economy|convertible|wagon|\bcar\b/',
        ];
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $value)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Condition from the newest inspection (records ordered newest first).
     * "Good" expires after $valid_days; recorded problems persist until a newer
     * inspection replaces them.
     */
    public static function evaluate_condition(array $records, DateTimeImmutable $today, $valid_days)
    {
        if (!$records) {
            return self::condition('unknown', 'No inspection has been recorded for this component.', null);
        }

        $latest   = $records[0];
        $evidence = self::evidence($latest, $today);
        $date     = $latest['inspected_on'];

        switch ($latest['condition_rating']) {
            case 'critical':
                return self::condition('critical', "Critical condition recorded on {$date}.", $evidence);
            case 'needs_attention':
                return self::condition('needs_attention', "Issue requiring attention recorded on {$date}.", $evidence);
            case 'good':
                if ($evidence['age_days'] > $valid_days) {
                    return self::condition(
                        'unknown',
                        "Last rated good on {$date} ({$evidence['age_days']} days ago), which is older than the {$valid_days}-day evidence window. Re-inspect to confirm.",
                        $evidence
                    );
                }
                return self::condition('good', "Rated good by inspection on {$date}.", $evidence);
            default:
                $previous = null;
                foreach ($records as $record) {
                    if ($record['condition_rating'] !== 'not_assessed') {
                        $previous = $record;
                        break;
                    }
                }
                $reason = "The latest record ({$date}) did not assess condition.";
                if ($previous) {
                    $reason .= ' Previously recorded as ' . str_replace('_', ' ', $previous['condition_rating']) . " on {$previous['inspected_on']}.";
                }
                return self::condition('unknown', $reason, $evidence);
        }
    }

    /**
     * Schedule status from an optional configured rule, the latest completed
     * service, and any next-service point recorded on an inspection.
     * $rule: interval_days, interval_km, due_soon_days, due_soon_km, basis, scope.
     */
    public static function evaluate_schedule(array $records, $rule, $current_km, DateTimeImmutable $today)
    {
        $service  = null;
        $explicit = null;
        foreach ($records as $record) {
            if ($service === null && (int) $record['service_performed'] === 1) {
                $service = $record;
            }
            if ($explicit === null && ($record['next_due_date'] !== null || $record['next_due_km'] !== null)) {
                $explicit = $record;
            }
        }
        // A service completed after the due point was recorded satisfies it.
        if ($explicit && $service && (int) $explicit['id'] !== (int) $service['id'] && self::service_date($service) > $explicit['inspected_on']) {
            $explicit = null;
        }

        $result = [
            'status'       => 'not_configured',
            'reason'       => 'No service interval is configured and no next-service point has been recorded.',
            'rule'         => $rule,
            'last_service' => $service ? [
                'record_id'   => (int) $service['id'],
                'date'        => self::service_date($service),
                'odometer_km' => $service['odometer_km'] === null ? null : (int) $service['odometer_km'],
            ] : null,
            'due_date'       => null,
            'due_km'         => null,
            'days_remaining' => null,
            'km_remaining'   => null,
            'basis'          => [],
        ];

        $dates = [];
        $kms   = [];
        if ($rule && $service) {
            if ($rule['interval_days'] !== null) {
                $dates[] = (new DateTimeImmutable(self::service_date($service)))->modify('+' . (int) $rule['interval_days'] . ' days')->format('Y-m-d');
            }
            if ($rule['interval_km'] !== null && $service['odometer_km'] !== null) {
                $kms[] = (int) $service['odometer_km'] + (int) $rule['interval_km'];
            }
            $result['basis'][] = 'Configured interval (' . $rule['basis'] . ') from the service on ' . self::service_date($service);
        }
        if ($explicit) {
            if ($explicit['next_due_date'] !== null) {
                $dates[] = $explicit['next_due_date'];
            }
            if ($explicit['next_due_km'] !== null) {
                $kms[] = (int) $explicit['next_due_km'];
            }
            $result['basis'][] = 'Next-service point recorded on the inspection of ' . $explicit['inspected_on'];
        }

        if (!$dates && !$kms) {
            if ($rule) {
                $result['status'] = 'unknown';
                $result['reason'] = $service
                    ? 'An interval is configured, but the last service has no odometer reading to measure the mileage interval from.'
                    : 'An interval is configured, but no completed service is recorded to measure it from.';
            }
            return $result;
        }

        $soon_days = $rule ? (int) $rule['due_soon_days'] : self::DEFAULT_DUE_SOON_DAYS;
        $soon_km   = $rule ? (int) $rule['due_soon_km'] : self::DEFAULT_DUE_SOON_KM;

        if ($dates) {
            sort($dates);
            $result['due_date']       = $dates[0];
            $result['days_remaining'] = (int) $today->diff(new DateTimeImmutable($dates[0]))->format('%r%a');
        }
        if ($kms) {
            sort($kms);
            $result['due_km'] = $kms[0];
            if ($current_km !== null) {
                $result['km_remaining'] = $kms[0] - (int) $current_km;
            }
        }

        $days = $result['days_remaining'];
        $km   = $result['km_remaining'];
        if (($days !== null && $days < 0) || ($km !== null && $km < 0)) {
            $result['status'] = 'overdue';
            $result['reason'] = $days !== null && $days < 0
                ? 'The due date passed ' . abs($days) . ' day(s) ago.'
                : 'Recorded mileage is ' . number_format(abs($km)) . ' km past the due point.';
        } elseif (($days !== null && $days <= $soon_days) || ($km !== null && $km <= $soon_km)) {
            $result['status'] = 'due_soon';
            $result['reason'] = $days !== null && $days <= $soon_days
                ? "Due in {$days} day(s)."
                : 'Due in ' . number_format($km) . ' km.';
        } elseif ($days === null && $km === null) {
            $result['status'] = 'unknown';
            $result['reason'] = 'The due point is mileage-based, but the vehicle has no current odometer reading.';
        } else {
            $result['status'] = 'not_yet_due';
            $result['reason'] = 'No configured or recorded due point has been reached.';
        }
        if ($result['status'] !== 'unknown' && $kms && $current_km === null) {
            $result['reason'] .= ' The mileage due point could not be checked because no current odometer reading is recorded.';
        }

        return $result;
    }

    /**
     * The recorded recommendation when the latest inspection has one,
     * otherwise a rule-derived next step labelled as such.
     */
    public static function recommended_action(array $records, array $condition, array $schedule)
    {
        if ($records && trim((string) $records[0]['recommended_action']) !== '') {
            return ['text' => $records[0]['recommended_action'], 'source' => 'inspection_record'];
        }

        $text = null;
        if ($condition['status'] === 'critical') {
            $text = 'Resolve the recorded critical issue and re-inspect before the vehicle is rented.';
        } elseif ($condition['status'] === 'needs_attention') {
            $text = 'Review the recorded finding and schedule a repair or re-inspection.';
        } elseif ($schedule['status'] === 'overdue') {
            $text = 'Perform the scheduled service and record the outcome.';
        } elseif ($schedule['status'] === 'due_soon') {
            $text = 'Plan the scheduled service.';
        } elseif ($condition['status'] === 'unknown') {
            $text = 'Inspect this component and record its condition.';
        }

        return $text === null ? null : ['text' => $text, 'source' => 'system_rule'];
    }

    /* ---------------------------------------------------------------
     * Input validation
     * ------------------------------------------------------------- */

    /** Validates an inspection submission. Unknown keys (including any status) are ignored. */
    public static function normalize_inspection(array $input, DateTimeImmutable $today)
    {
        $record = [
            'inspected_on'       => self::required_date($input, 'inspected_on', 'Inspection date', $today),
            'odometer_km'        => self::optional_int($input, 'odometer_km', 'Odometer', 0, self::MAX_ODOMETER_KM),
            'condition_rating'   => $input['condition_rating'] ?? null,
            'reported_symptoms'  => self::optional_text($input, 'reported_symptoms', 'Reported symptoms', 2000),
            'dtc_codes'          => self::dtc_codes($input['dtc_codes'] ?? null),
            'findings'           => self::optional_text($input, 'findings', 'Findings', 2000),
            'confirmed_fault'    => self::optional_text($input, 'confirmed_fault', 'Confirmed fault', 255),
            'service_performed'  => filter_var($input['service_performed'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'service_date'       => null,
            'work_performed'     => self::optional_text($input, 'work_performed', 'Work performed', 2000),
            'parts_replaced'     => self::optional_text($input, 'parts_replaced', 'Parts replaced', 1000),
            'recommended_action' => self::optional_text($input, 'recommended_action', 'Recommended action', 255),
            'next_due_date'      => null,
            'next_due_km'        => self::optional_int($input, 'next_due_km', 'Next service mileage', 1, self::MAX_ODOMETER_KM),
            'technician'         => self::optional_text($input, 'technician', 'Technician', 120),
        ];

        if (!is_string($record['condition_rating']) || !in_array($record['condition_rating'], self::CONDITIONS, true)) {
            throw new InvalidArgumentException('Select the observed condition.');
        }
        if (in_array($record['condition_rating'], ['needs_attention', 'critical'], true) && $record['findings'] === null) {
            throw new InvalidArgumentException('Describe the observed issue when recording attention or critical condition.');
        }
        if ($record['confirmed_fault'] !== null && !in_array($record['condition_rating'], ['needs_attention', 'critical'], true)) {
            throw new InvalidArgumentException('A confirmed fault can only be recorded with attention or critical condition.');
        }

        if ($record['service_performed']) {
            $record['service_date'] = isset($input['service_date']) && $input['service_date'] !== ''
                ? self::required_date($input, 'service_date', 'Service date', $today)
                : $record['inspected_on'];
            if ($record['work_performed'] === null) {
                throw new InvalidArgumentException('Describe the work performed for a service record.');
            }
        } elseif ($record['work_performed'] !== null || $record['parts_replaced'] !== null) {
            throw new InvalidArgumentException('Mark the record as a service to record work performed or parts replaced.');
        }

        if (isset($input['next_due_date']) && $input['next_due_date'] !== '' && $input['next_due_date'] !== null) {
            $record['next_due_date'] = self::date_value($input['next_due_date'], 'Next service date');
            if ($record['next_due_date'] <= $record['inspected_on']) {
                throw new InvalidArgumentException('The next service date must be after the inspection date.');
            }
        }
        if ($record['next_due_km'] !== null && $record['odometer_km'] !== null && $record['next_due_km'] <= $record['odometer_km']) {
            throw new InvalidArgumentException('The next service mileage must be greater than the inspection odometer.');
        }

        return $record;
    }

    /** Validates a service-interval rule submission. */
    public static function normalize_interval(array $input)
    {
        $scope = $input['scope'] ?? null;
        if (!in_array($scope, ['vehicle', 'body_type'], true)) {
            throw new InvalidArgumentException('Choose whether the interval applies to this vehicle or to its body type.');
        }

        $rule = [
            'scope'         => $scope,
            'interval_days' => self::optional_int($input, 'interval_days', 'Interval days', 1, 3650),
            'interval_km'   => self::optional_int($input, 'interval_km', 'Interval distance', 1, 1000000),
            'due_soon_days' => self::optional_int($input, 'due_soon_days', 'Due-soon days', 0, 365) ?? self::DEFAULT_DUE_SOON_DAYS,
            'due_soon_km'   => self::optional_int($input, 'due_soon_km', 'Due-soon distance', 0, 100000) ?? self::DEFAULT_DUE_SOON_KM,
            'basis'         => self::optional_text($input, 'basis', 'Interval source', 255),
        ];
        if ($rule['interval_days'] === null && $rule['interval_km'] === null) {
            throw new InvalidArgumentException('Enter an interval in days, kilometres, or both.');
        }
        if ($rule['basis'] === null || strlen($rule['basis']) < 3) {
            throw new InvalidArgumentException('State the source of this interval, such as the owner\'s manual or fleet policy.');
        }
        return $rule;
    }

    public static function is_date($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private static function condition($status, $reason, $evidence)
    {
        return ['status' => $status, 'reason' => $reason, 'evidence' => $evidence];
    }

    private static function evidence(array $record, DateTimeImmutable $today)
    {
        return [
            'record_id'   => (int) $record['id'],
            'date'        => $record['inspected_on'],
            'age_days'    => (int) (new DateTimeImmutable($record['inspected_on']))->diff($today)->format('%r%a'),
            'source'      => 'Manual inspection record',
            'technician'  => $record['technician'] ?? null,
            'recorded_by' => $record['recorded_by_name'] ?? null,
        ];
    }

    private static function service_date(array $record)
    {
        return $record['service_date'] ?? $record['inspected_on'];
    }

    private static function required_date(array $input, $key, $label, DateTimeImmutable $today)
    {
        $value = self::date_value($input[$key] ?? null, $label);
        if ($value > $today->format('Y-m-d')) {
            throw new InvalidArgumentException("{$label} cannot be in the future.");
        }
        if ($value < '1980-01-01') {
            throw new InvalidArgumentException("{$label} is not a plausible date.");
        }
        return $value;
    }

    private static function date_value($value, $label)
    {
        if (!self::is_date($value)) {
            throw new InvalidArgumentException("{$label} must be a valid YYYY-MM-DD date.");
        }
        return $value;
    }

    /** Diagnostic trouble codes as a comma-separated, de-duplicated list of standard OBD-II codes. */
    private static function dtc_codes($value)
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value) || count($value) > 12) {
            throw new InvalidArgumentException('Enter up to 12 diagnostic trouble codes.');
        }
        $codes = [];
        foreach ($value as $code) {
            $code = strtoupper(trim((string) $code));
            if (!preg_match('/^[PCBU][0-3][0-9A-F]{3}$/', $code)) {
                throw new InvalidArgumentException("\"{$code}\" is not a valid diagnostic trouble code (for example P0301).");
            }
            $codes[$code] = true;
        }
        return implode(',', array_keys($codes));
    }

    private static function optional_int(array $input, $key, $label, $min, $max)
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }
        $value = filter_var($input[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($value === false) {
            throw new InvalidArgumentException("{$label} must be a whole number between {$min} and " . number_format($max) . '.');
        }
        return $value;
    }

    private static function optional_text(array $input, $key, $label, $max)
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (!is_scalar($input[$key])) {
            throw new InvalidArgumentException("{$label} must be text.");
        }
        $value = trim((string) $input[$key]);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} must be {$max} characters or fewer.");
        }
        return $value;
    }
}
