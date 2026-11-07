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

    /* ---------------------------------------------------------------
     * Vehicle assessments: 5-level ratings and condition-based screening.
     * Mirrors ai_api/assessment_service.py. Both read the same catalog, so
     * safety warnings are produced here at submission, without the AI service.
     * ------------------------------------------------------------- */

    const SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'advisory' => 2];
    const SCREENING_BY_SEVERITY = ['critical' => 'critical', 'warning' => 'attention', 'advisory' => 'monitor'];
    const SCREENING_RANK = ['critical', 'attention', 'monitor', 'no_concerns_recorded', 'insufficient_data', 'not_assessed'];
    const USAGE_FREQUENCIES = ['daily', 'several_per_week', 'weekly', 'occasional'];
    const OUTCOME_DECISIONS = ['completed', 'deferred', 'rejected'];
    const OUTCOME_CONFIRMATIONS = ['confirmed', 'not_confirmed', 'not_checked'];
    const OUTCOME_RESULTS = ['pending', 'confirmed_failure', 'successful_repair', 'no_fault_found'];
    const RECENT_SERVICE_DAYS = 30;
    const REASSESSMENT_DUE_SOON_DAYS = 7;
    const MAX_NOTE_LENGTH = 4000;
    const MAX_FAULT_CODES = 40;

    /** The questionnaire and rules (app/libraries/assessment_catalog.json), indexed for lookup. */
    public static function assessment_catalog()
    {
        static $catalog = null;
        if ($catalog !== null) {
            return $catalog;
        }

        $catalog = json_decode((string) file_get_contents(__DIR__ . '/assessment_catalog.json'), true);
        if (!is_array($catalog)) {
            throw new RuntimeException('The assessment catalog could not be read.');
        }
        $catalog['by_category'] = array_column($catalog['categories'], null, 'key');
        $catalog['by_rating']   = array_column($catalog['ratings'], null, 'key');
        $catalog['rules_by_category'] = [];
        foreach ($catalog['rules'] as $rule) {
            $catalog['rules_by_category'][$rule['category']][] = $rule;
        }
        return $catalog;
    }

    /** The assessment category a 3D component key belongs to, or NULL. */
    public static function category_for_component($component_key)
    {
        foreach (self::assessment_catalog()['categories'] as $category) {
            if (in_array($component_key, $category['component_keys'], true)) {
                return $category['key'];
            }
        }
        return null;
    }

    /** Validates a submission into a clean structure. Missing categories become not_assessed, never a rating. */
    public static function normalize_assessment(array $input, DateTimeImmutable $today)
    {
        $catalog    = self::assessment_catalog();
        $assessedOn = self::required_date($input, 'assessed_on', 'Assessment date', $today);

        $key = $input['idempotency_key'] ?? null;
        if ($key !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $key)) {
            throw new InvalidArgumentException('Idempotency key must be a UUID.');
        }

        $submitted = $input['components'] ?? [];
        if (!is_array($submitted)) {
            throw new InvalidArgumentException('Components must be an object keyed by category.');
        }
        foreach (array_keys($submitted) as $name) {
            if (!isset($catalog['by_category'][$name])) {
                throw new InvalidArgumentException("Unknown assessment category '{$name}'.");
            }
        }

        $components = [];
        foreach ($catalog['categories'] as $category) {
            $components[$category['key']] = self::normalize_category($category, $submitted[$category['key']] ?? [], $assessedOn);
        }

        return [
            'idempotency_key' => $key === null ? null : strtolower((string) $key),
            'assessed_on'     => $assessedOn,
            'odometer_km'     => self::optional_int($input, 'odometer_km', 'Odometer', 0, self::MAX_ODOMETER_KM),
            'notes'           => self::optional_text($input, 'notes', 'Notes', self::MAX_NOTE_LENGTH),
            'components'      => $components,
        ];
    }

    private static function normalize_category(array $category, $input, $assessedOn)
    {
        $catalog = self::assessment_catalog();
        $name    = $category['name'];
        if (!is_array($input)) {
            throw new InvalidArgumentException("{$name}: the submitted section is invalid.");
        }

        $rating = $input['rating'] ?? 'not_assessed';
        if (!is_string($rating) || !isset($catalog['by_rating'][$rating])) {
            throw new InvalidArgumentException("{$name}: choose a valid rating.");
        }

        $questions = array_column($category['questions'], null, 'key');
        $answers   = [];
        foreach ((array) ($input['answers'] ?? []) as $question => $answer) {
            if (!isset($questions[$question])) {
                throw new InvalidArgumentException("{$name}: unknown question '{$question}'.");
            }
            if ($answer === null || $answer === '') {
                continue;
            }
            if (!in_array($answer, $catalog['answers'], true)) {
                throw new InvalidArgumentException("{$name}: invalid answer for '{$questions[$question]['text']}'.");
            }
            $answers[$question] = $answer;
        }

        $known    = array_column($category['symptoms'], 'key');
        $symptoms = [];
        foreach ((array) ($input['symptoms'] ?? []) as $symptom) {
            if (!is_string($symptom) || !in_array($symptom, $known, true)) {
                throw new InvalidArgumentException("{$name}: unknown symptom.");
            }
            $symptoms[$symptom] = $symptom;
        }

        $specs        = array_column($category['measurements'], null, 'key');
        $measurements = [];
        foreach ((array) ($input['measurements'] ?? []) as $field => $value) {
            if (!isset($specs[$field])) {
                throw new InvalidArgumentException("{$name}: unknown measurement '{$field}'.");
            }
            if ($value === null || $value === '') {
                continue;
            }
            if ($value === 'unknown' || $value === 'not_applicable') {
                $measurements[$field] = $value;
                continue;
            }
            $spec = $specs[$field];
            if (!is_numeric($value) || (float) $value < $spec['min'] || (float) $value > $spec['max']) {
                throw new InvalidArgumentException("{$name}: {$spec['label']} must be between {$spec['min']} and {$spec['max']} {$spec['unit']}.");
            }
            $measurements[$field] = (float) $value;
        }

        $codes = [];
        foreach ((array) ($input['fault_codes'] ?? []) as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') {
                continue;
            }
            if (!preg_match('/^[PBCU][0-9][0-9A-F]{3}$/', $code)) {
                throw new InvalidArgumentException("{$name}: '{$code}' is not a valid fault code.");
            }
            $codes[$code] = $code;
        }
        if ($codes && !$category['supports_fault_codes']) {
            throw new InvalidArgumentException("{$name} does not accept fault codes.");
        }
        if (count($codes) > self::MAX_FAULT_CODES) {
            throw new InvalidArgumentException("{$name}: enter at most " . self::MAX_FAULT_CODES . ' fault codes.');
        }

        $maintenance = self::optional_date($input, 'last_maintenance_date', "{$name}: last maintenance date");
        if ($maintenance !== null && $maintenance > $assessedOn) {
            throw new InvalidArgumentException("{$name}: last maintenance date cannot be after the assessment date.");
        }

        return [
            'rating'                => $rating,
            'answers'               => $answers,
            'symptoms'              => array_values($symptoms),
            'measurements'          => $measurements,
            'fault_codes'           => array_values($codes),
            'last_maintenance_date' => $maintenance,
            'notes'                 => self::optional_text($input, 'notes', "{$name}: notes", self::MAX_NOTE_LENGTH),
        ];
    }

    /**
     * Findings, screening status and data sufficiency for one category. Every
     * finding names the observation that triggered it. A status of
     * no_concerns_recorded only means no rule fired; it never means healthy.
     */
    public static function screen_component($key, array $component, $previous = null, $assessed_on = null)
    {
        $catalog  = self::assessment_catalog();
        $category = $catalog['by_category'][$key];
        $rating   = $component['rating'] ?? 'not_assessed';
        $name     = $category['name'];
        $label    = static function ($value) use ($catalog) {
            return $catalog['by_rating'][$value]['label'];
        };
        $findings = [];

        if (isset($catalog['rating_rules'][$rating])) {
            $rule = $catalog['rating_rules'][$rating];
            $findings[] = self::assessment_finding(
                "rating.{$rating}", 'rating', $rule['severity'], $rule['severity'] === 'critical' && $category['safety_critical'], $category,
                "Assessor rated {$name} as {$label($rating)}.", $rule['reason'], $rule['action']
            );
        }

        foreach ($catalog['rules_by_category'][$key] ?? [] as $rule) {
            $value = null;
            if (!self::rule_triggered($rule['when'], $component, $value)) {
                continue;
            }
            $observation = $value === null ? $rule['observation'] : str_replace('{value}', self::format_number($value), $rule['observation']);
            $findings[]  = self::assessment_finding($rule['id'], $rule['when']['kind'], $rule['severity'], $rule['safety'], $category, $observation, $rule['reason'], $rule['action']);
        }

        $score          = $catalog['by_rating'][$rating]['score'];
        $previousRating = is_array($previous) ? ($previous['rating'] ?? null) : null;
        $previousScore  = $previousRating !== null && isset($catalog['by_rating'][$previousRating]) ? $catalog['by_rating'][$previousRating]['score'] : null;
        if ($score !== null && $previousScore !== null && $score < $previousScore) {
            $findings[] = self::assessment_finding(
                'trend.rating_drop', 'previous_assessment', $previousScore - $score >= 2 ? 'warning' : 'advisory', false, $category,
                "Rating fell from {$label($previousRating)} to {$label($rating)} since the previous assessment.",
                'Condition has worsened between assessments.', 'Compare both assessments and find what changed.'
            );
        }
        // A problem stays visible until the category is re-rated, including one found
        // by a symptom or measurement under a good rating.
        $previousStatus = is_array($previous) ? ($previous['screening_status'] ?? null) : null;
        $ratedLow       = $previousScore !== null && $previousScore <= 2;
        if ($score === null && $rating !== 'not_applicable' && ($ratedLow || in_array($previousStatus, ['critical', 'attention'], true))) {
            $earlier = $ratedLow ? $label($previousRating) : ($previousStatus === 'critical' ? 'having a critical finding' : 'needing attention');
            $findings[] = self::assessment_finding(
                'trend.prior_problem_not_rechecked', 'previous_assessment', 'warning', false, $category,
                "Previously recorded as {$earlier}, but not rated on this assessment.",
                'A recorded problem cannot be treated as resolved without a new rating.', 'Inspect this component and record a rating.'
            );
        }

        $serviced = $component['last_maintenance_date'] ?? null;
        if ($serviced && $assessed_on && $score !== null && $score <= 2) {
            $days = (int) (new DateTimeImmutable($serviced))->diff(new DateTimeImmutable($assessed_on))->format('%r%a');
            if ($days >= 0 && $days <= self::RECENT_SERVICE_DAYS) {
                $findings[] = self::assessment_finding(
                    'history.problem_after_recent_service', 'maintenance_history', 'advisory', false, $category,
                    "Rated {$label($rating)} {$days} days after maintenance on {$serviced}.",
                    'A problem soon after service can mean the original fault was not resolved.', 'Review the earlier repair with the mechanic.'
                );
            }
        }

        // usort is stable, so rules of equal severity stay in catalog order, as in Python.
        usort($findings, static function ($a, $b) {
            return self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']];
        });

        $answers  = array_filter($component['answers'] ?? []);
        $known    = array_filter($answers, static function ($answer) { return $answer !== 'unknown'; });
        $measured = array_filter($component['measurements'] ?? [], static function ($value) { return is_int($value) || is_float($value); });
        $observed = $answers || $measured || !empty($component['symptoms']) || !empty($component['fault_codes']) || trim((string) ($component['notes'] ?? '')) !== '';
        $missing  = [];
        foreach ($category['questions'] as $question) {
            if (!isset($answers[$question['key']]) || $answers[$question['key']] === 'unknown') {
                $missing[] = $question['text'];
            }
        }

        if ($findings) {
            $status = self::SCREENING_BY_SEVERITY[$findings[0]['severity']];
        } elseif ($rating === 'not_applicable') {
            $status = 'not_applicable';
        } elseif ($rating === 'not_assessed' && !$observed) {
            $status = 'not_assessed';
        } elseif ($score === null) {
            $status = 'insufficient_data';
        } else {
            $status = 'no_concerns_recorded';
        }

        if ($rating === 'not_applicable') {
            $sufficiency = 'not_applicable';
        } elseif ($rating === 'not_assessed' && !$observed) {
            $sufficiency = 'not_assessed';
        } elseif ($score === null) {
            $sufficiency = $observed ? 'partial' : 'insufficient';
        } else {
            $sufficiency = count($known) * 2 >= count($category['questions']) ? 'sufficient' : 'partial';
        }

        return [
            'findings'          => $findings,
            'screening_status'  => $status,
            'data_sufficiency'  => $sufficiency,
            'missing_questions' => in_array($sufficiency, ['partial', 'insufficient'], true) ? $missing : [],
        ];
    }

    private static function rule_triggered(array $when, array $component, &$value)
    {
        switch ($when['kind']) {
            case 'symptom':
                return in_array($when['key'], $component['symptoms'] ?? [], true);
            case 'answer':
                return ($component['answers'][$when['question']] ?? null) === $when['equals'];
            case 'measurement':
                $reading = $component['measurements'][$when['key']] ?? null;
                if (!is_int($reading) && !is_float($reading)) {
                    return false;
                }
                $value = $reading;
                return (!isset($when['lt']) || $reading < $when['lt'])
                    && (!isset($when['lte']) || $reading <= $when['lte'])
                    && (!isset($when['gt']) || $reading > $when['gt'])
                    && (!isset($when['gte']) || $reading >= $when['gte']);
        }
        return false;
    }

    private static function assessment_finding($rule_id, $source, $severity, $safety, array $category, $observation, $reason, $action)
    {
        return [
            'rule_id'            => $rule_id,
            'source'             => $source,
            'severity'           => $severity,
            'safety'             => (bool) $safety,
            'component_key'      => $category['key'],
            'component_name'     => $category['name'],
            'observation'        => $observation,
            'reason'             => $reason,
            'recommended_action' => $action,
        ];
    }

    private static function format_number($value)
    {
        return (float) $value === floor((float) $value) ? (string) (int) $value : (string) $value;
    }

    /** The odometer flag: missing, a decrease against any earlier reading, or none. */
    public static function odometer_check($odometer, $previous)
    {
        if ($odometer === null) {
            return ['flag' => 'missing', 'finding' => null];
        }
        if ($previous === null || $odometer >= $previous) {
            return ['flag' => 'none', 'finding' => null];
        }
        return ['flag' => 'decrease', 'finding' => [
            'rule_id'            => 'odometer.decrease',
            'source'             => 'odometer',
            'severity'           => 'warning',
            'safety'             => false,
            'component_key'      => null,
            'component_name'     => 'Vehicle record',
            'observation'        => "Odometer reading {$odometer} km is lower than the previous reading of {$previous} km.",
            'reason'             => 'An odometer should not decrease, so one of the readings may be wrong.',
            'recommended_action' => 'Verify the odometer and correct the source of the wrong reading.',
        ]];
    }

    /** The most serious category status. not_applicable says nothing about the vehicle, so it is excluded. */
    public static function overall_screening(array $statuses)
    {
        foreach (self::SCREENING_RANK as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }
        return 'not_assessed';
    }

    /** Monthly reassessment state. Derived on each read; no scheduler is involved. */
    public static function reassessment_status($last_assessed_on, DateTimeImmutable $today, $interval_days)
    {
        if ($last_assessed_on === null) {
            return ['status' => 'never_assessed', 'due_on' => null, 'days_until_due' => null];
        }
        $due  = (new DateTimeImmutable($last_assessed_on))->modify('+' . (int) $interval_days . ' days');
        $days = (int) $today->diff($due)->format('%r%a');
        return [
            'status'         => $days < 0 ? 'overdue' : ($days <= self::REASSESSMENT_DUE_SOON_DAYS ? 'due_soon' : 'current'),
            'due_on'         => $due->format('Y-m-d'),
            'days_until_due' => $days,
        ];
    }

    /**
     * Inconsistencies in a cars row, reported for a person to resolve. Nothing here
     * changes the record, and no specific model is inferred from it.
     */
    public static function vehicle_data_flags(array $vehicle, DateTimeImmutable $today)
    {
        $flags = [];
        $year  = (int) ($vehicle['year'] ?? 0);
        if ($year > (int) $today->format('Y') + 1) {
            $flags[] = "Model year {$year} is in the future.";
        }
        if (preg_match('/\b((?:19|20)\d{2})\b/', (string) ($vehicle['model'] ?? ''), $match) && (int) $match[1] !== $year) {
            $flags[] = "The model field says {$match[1]}, but the year field says {$year}.";
        }
        if (preg_match('/^\s*(?:19|20)\d{2}\s*(?:model)?\s*$/i', (string) ($vehicle['model'] ?? ''))) {
            $flags[] = 'The model field holds a year, not a model name.';
        }
        if (preg_match('/\s/', trim((string) ($vehicle['make'] ?? '')))) {
            $flags[] = "The make field contains several words ('{$vehicle['make']}'), so it may include the model name.";
        }
        if (array_key_exists('current_mileage', $vehicle) && $vehicle['current_mileage'] === null) {
            $flags[] = 'Current mileage has not been recorded.';
        }
        return $flags;
    }

    /** Whole years since the model year, or NULL when the year is unusable. A future year is not a negative age. */
    public static function vehicle_age_years($year, DateTimeImmutable $today)
    {
        $year = (int) $year;
        if ($year < 1900 || $year > (int) $today->format('Y')) {
            return null;
        }
        return (int) $today->format('Y') - $year;
    }

    public static function normalize_vehicle_profile(array $input, DateTimeImmutable $today)
    {
        $values = [];
        foreach (['acquisition_date' => 'Acquisition date', 'previous_maintenance_date' => 'Previous maintenance date'] as $field => $label) {
            if (array_key_exists($field, $input)) {
                $values[$field] = self::optional_date($input, $field, $label);
                if ($values[$field] !== null && $values[$field] > $today->format('Y-m-d')) {
                    throw new InvalidArgumentException("{$label} cannot be in the future.");
                }
            }
        }
        if (array_key_exists('usage_frequency', $input)) {
            $usage = $input['usage_frequency'] === '' ? null : $input['usage_frequency'];
            if ($usage !== null && !in_array($usage, self::USAGE_FREQUENCIES, true)) {
                throw new InvalidArgumentException('Choose a valid usage frequency.');
            }
            $values['usage_frequency'] = $usage;
        }
        if (!$values) {
            throw new InvalidArgumentException('No vehicle profile fields were provided.');
        }
        return $values;
    }

    /** Validates a recommendation outcome. Client-sent verification fields are ignored. */
    public static function normalize_outcome(array $input, DateTimeImmutable $today)
    {
        $decision = $input['decision'] ?? null;
        if (!in_array($decision, self::OUTCOME_DECISIONS, true)) {
            throw new InvalidArgumentException('Choose whether the recommendation was completed, deferred or rejected.');
        }
        $confirmed = $input['mechanic_confirmed'] ?? 'not_checked';
        if (!in_array($confirmed, self::OUTCOME_CONFIRMATIONS, true)) {
            throw new InvalidArgumentException('Choose a valid mechanic confirmation.');
        }
        $result = $input['result'] ?? 'pending';
        if (!in_array($result, self::OUTCOME_RESULTS, true)) {
            throw new InvalidArgumentException('Choose a valid outcome.');
        }

        $failure = self::optional_date($input, 'failure_date', 'Failure date');
        $repair  = self::optional_date($input, 'repair_date', 'Repair date');
        foreach ([$failure, $repair] as $date) {
            if ($date !== null && $date > $today->format('Y-m-d')) {
                throw new InvalidArgumentException('Failure and repair dates cannot be in the future.');
            }
        }
        if ($failure !== null && $repair !== null && $repair < $failure) {
            throw new InvalidArgumentException('Repair date cannot be before the failure date.');
        }
        if ($result === 'confirmed_failure' && $failure === null) {
            throw new InvalidArgumentException('A confirmed failure needs a failure date.');
        }
        if ($result === 'successful_repair' && $repair === null) {
            throw new InvalidArgumentException('A successful repair needs a repair date.');
        }
        // The date the result became known: the failure, the repair, or the check that found nothing.
        $outcomeDate = self::optional_date($input, 'outcome_date', 'Outcome date');
        if ($outcomeDate !== null && $outcomeDate > $today->format('Y-m-d')) {
            throw new InvalidArgumentException('Outcome date cannot be in the future.');
        }
        if ($result === 'no_fault_found' && $outcomeDate === null) {
            throw new InvalidArgumentException('A no-fault result needs the date of the check.');
        }
        $outcomeDate = $outcomeDate ?? $failure ?? $repair;
        $performed = self::optional_text($input, 'maintenance_performed', 'Maintenance performed', self::MAX_NOTE_LENGTH);
        if ($decision === 'completed' && $performed === null) {
            throw new InvalidArgumentException('Describe the maintenance performed.');
        }

        return [
            'finding_id'            => self::optional_int($input, 'finding_id', 'Finding', 1, PHP_INT_MAX),
            'decision'              => $decision,
            'mechanic_confirmed'    => $confirmed,
            'result'                => $result,
            'maintenance_performed' => $performed,
            'parts_replaced'        => self::optional_text($input, 'parts_replaced', 'Parts replaced', self::MAX_NOTE_LENGTH),
            'failure_date'          => $failure,
            'repair_date'           => $repair,
            'outcome_date'          => $outcomeDate,
            'mileage_km'            => self::optional_int($input, 'mileage_km', 'Mileage', 0, self::MAX_ODOMETER_KM),
            'mechanic_name'         => self::optional_text($input, 'mechanic_name', 'Mechanic name', 150),
            'notes'                 => self::optional_text($input, 'notes', 'Notes', self::MAX_NOTE_LENGTH),
        ];
    }

    private static function optional_date(array $input, $key, $label)
    {
        $value = $input[$key] ?? null;
        return $value === null || $value === '' ? null : self::date_value($value, $label);
    }

    /* ---------------------------------------------------------------
     * Training data eligibility. Mirrors evaluate_records in
     * ai_api/category_models.py; a test compares both on the same cases.
     * ------------------------------------------------------------- */

    /** fault, healthy, or NULL when the record does not establish either. */
    public static function outcome_label(array $record)
    {
        $result = $record['result'] ?? null;
        if ($result === 'no_fault_found') {
            return 'healthy';
        }
        if ($result === 'confirmed_failure') {
            return 'fault';
        }
        // A completed repair alone does not establish a fault; a mechanic's confirmation does.
        return $result === 'successful_repair' && ($record['mechanic_confirmed'] ?? null) === 'confirmed' ? 'fault' : null;
    }

    private static function training_exclusion(array $record, array $policy, array $ratings)
    {
        if (($record['verification_status'] ?? null) !== 'verified') {
            return 'not_verified';
        }
        if (self::outcome_label($record) === null) {
            return 'no_usable_label';
        }
        foreach ($policy['excluded_plate_prefixes'] as $prefix) {
            if (stripos((string) ($record['plate_number'] ?? ''), $prefix) === 0) {
                return 'demo_vehicle';
            }
        }
        if (empty($record['vehicle_id']) || empty($record['assessed_on'])) {
            return 'missing_vehicle_history';
        }
        if (($record['odometer_km'] ?? null) === null) {
            return 'missing_odometer';
        }
        if (($record['odometer_flag'] ?? null) === 'decrease') {
            return 'odometer_decrease';
        }
        if (empty($record['outcome_date'])) {
            return 'missing_outcome_date';
        }
        if (($ratings[$record['rating'] ?? '']['score'] ?? null) === null) {
            return 'category_not_rated';
        }
        $days = (int) (new DateTimeImmutable($record['assessed_on']))->diff(new DateTimeImmutable($record['outcome_date']))->format('%r%a');
        if ($days < 0) {
            return 'outcome_before_assessment';
        }
        if ($days === 0) {
            return 'no_lead_time';
        }
        if ($days > $policy['horizon_days']) {
            return 'outside_horizon';
        }
        $mileage = $record['outcome_mileage_km'] ?? null;
        if ($mileage !== null && $mileage < $record['odometer_km']) {
            return 'invalid_mileage';
        }
        return null;
    }

    /** Splits one category's outcomes into eligible and excluded, and judges whether the category qualifies. */
    public static function evaluate_training_records(array $records)
    {
        $catalog = self::assessment_catalog();
        $policy  = $catalog['training_policy'];
        usort($records, static function ($a, $b) { return $a['outcome_id'] <=> $b['outcome_id']; });

        $excluded = [];
        $valid    = [];
        foreach ($records as $record) {
            $reason = self::training_exclusion($record, $policy, $catalog['by_rating']);
            if ($reason !== null) {
                $excluded[] = ['outcome_id' => $record['outcome_id'], 'reason' => $reason];
            } else {
                $valid[] = $record;
            }
        }

        // One assessment gives one label. Conflicting verified results cannot be trusted.
        $byAssessment = [];
        foreach ($valid as $record) {
            $byAssessment[$record['assessment_id']][] = $record;
        }
        $single = [];
        foreach ($byAssessment as $group) {
            $labels = array_unique(array_map([self::class, 'outcome_label'], $group));
            foreach ($group as $index => $record) {
                if (count($labels) > 1) {
                    $excluded[] = ['outcome_id' => $record['outcome_id'], 'reason' => 'conflicting_outcomes'];
                } elseif ($index > 0) {
                    $excluded[] = ['outcome_id' => $record['outcome_id'], 'reason' => 'duplicate_outcome'];
                } else {
                    $single[] = $record;
                }
            }
        }

        // One real event recorded against several assessments counts once, from the nearest assessment.
        $byEvent = [];
        foreach ($single as $record) {
            $byEvent[$record['vehicle_id'] . '|' . self::outcome_label($record) . '|' . $record['outcome_date']][] = $record;
        }
        $eligible = [];
        foreach ($byEvent as $group) {
            usort($group, static function ($a, $b) {
                return [$a['assessed_on'], $a['assessment_id']] <=> [$b['assessed_on'], $b['assessment_id']];
            });
            $eligible[] = array_pop($group);
            foreach ($group as $record) {
                $excluded[] = ['outcome_id' => $record['outcome_id'], 'reason' => 'duplicate_event'];
            }
        }
        usort($eligible, static function ($a, $b) { return $a['outcome_id'] <=> $b['outcome_id']; });
        usort($excluded, static function ($a, $b) { return $a['outcome_id'] <=> $b['outcome_id']; });

        return ['eligible' => $eligible, 'excluded' => $excluded, 'summary' => self::training_summary($records, $eligible, $excluded, $policy)];
    }

    private static function training_summary(array $records, array $eligible, array $excluded, array $policy)
    {
        $perVehicle = [];
        $faults     = 0;
        foreach ($eligible as $record) {
            $perVehicle[$record['vehicle_id']] = ($perVehicle[$record['vehicle_id']] ?? 0) + 1;
            $faults += self::outcome_label($record) === 'fault' ? 1 : 0;
        }
        $total   = count($eligible);
        $healthy = $total - $faults;
        $share   = $total ? round(max($perVehicle) / $total, 4) : 0;
        $minimum = $policy['min_outcomes_per_category'];

        $blockers = [];
        if ($total < $minimum) {
            $blockers[] = ($minimum - $total) . " more eligible outcomes needed (minimum {$minimum}).";
        }
        if (count($perVehicle) < $policy['min_vehicles']) {
            $blockers[] = 'Outcomes come from ' . count($perVehicle) . " vehicles; at least {$policy['min_vehicles']} are needed.";
        }
        if ($total && $share > $policy['max_vehicle_share']) {
            $blockers[] = 'One vehicle supplies more than ' . round($policy['max_vehicle_share'] * 100) . '% of the outcomes.';
        }
        if ($faults < $policy['min_failures']) {
            $blockers[] = ($policy['min_failures'] - $faults) . " more confirmed faults needed (minimum {$policy['min_failures']}).";
        }
        if ($healthy < $policy['min_healthy']) {
            $blockers[] = ($policy['min_healthy'] - $healthy) . " more healthy outcomes needed (minimum {$policy['min_healthy']}).";
        }

        $reasons = [];
        foreach ($excluded as $item) {
            $reasons[$item['reason']] = ($reasons[$item['reason']] ?? 0) + 1;
        }
        return [
            'status'                => $blockers ? 'insufficient' : 'sufficient',
            'total_outcomes'        => count($records),
            'verified_outcomes'     => count(array_filter($records, static function ($record) { return ($record['verification_status'] ?? null) === 'verified'; })),
            'eligible'              => $total,
            'remaining'             => max(0, $minimum - $total),
            'minimum'               => $minimum,
            'vehicles'              => count($perVehicle),
            'faults'                => $faults,
            'healthy'               => $healthy,
            'largest_vehicle_share' => $share,
            'excluded_by_reason'    => $reasons,
            'blockers'              => $blockers,
        ];
    }
}
