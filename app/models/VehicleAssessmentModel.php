<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Vehicle assessments, their AI analysis attempts, evidence and recorded outcomes.
 * Tables: database/vehicle_assessments.sql (migration 013).
 *
 * Assessments and analyses are append-only: a retry adds a new analysis row, so
 * earlier attempts remain readable. vehicle_component_inspections keeps its own
 * three-level ratings and is only read here, never converted.
 */
class VehicleAssessmentModel extends Model
{
    const ASSESSMENTS = 'vehicle_assessments';
    const COMPONENTS  = 'assessment_components';
    const FINDINGS    = 'assessment_findings';
    const ANALYSES    = 'assessment_ai_analyses';
    const AI_FINDINGS = 'assessment_ai_findings';
    const EVIDENCE    = 'assessment_evidence';
    const OUTCOMES    = 'assessment_outcomes';

    // A claim older than this belongs to a request that died, so it may be retried.
    const STALE_CLAIM_SECONDS = 120;
    const STALE_TRAINING_SECONDS = 600;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('ComponentMaintenanceRules');
    }

    /* ---------------------------------------------------------------
     * Vehicles
     * ------------------------------------------------------------- */

    public function vehicle($vehicle_id)
    {
        return $this->db->table('cars')
            ->select('id, make, model, year, category, fuel_type, plate_number, color, status, current_mileage, air_conditioning, primary_image, acquisition_date, usage_frequency, previous_maintenance_date')
            ->where('id', (int) $vehicle_id)
            ->limit(1)
            ->get() ?: null;
    }

    public function update_vehicle_profile($vehicle_id, array $values)
    {
        $this->db->table('cars')->where('id', (int) $vehicle_id)->update($values);
    }

    /** Every vehicle with its latest assessment, for the overview. */
    public function fleet_overview()
    {
        return $this->db->raw(
            'SELECT cars.id, cars.make, cars.model, cars.year, cars.category, cars.plate_number, cars.status, cars.current_mileage,
                    cars.primary_image, cars.acquisition_date, cars.usage_frequency, cars.previous_maintenance_date,
                    latest.id AS assessment_id, latest.assessed_on, latest.overall_screening, latest.safety_warning_count,
                    latest.ai_status, latest.review_status, latest.assessor_name, latest.odometer_flag
             FROM cars
             LEFT JOIN vehicle_assessments latest ON latest.id = (
                 SELECT va.id FROM vehicle_assessments va
                 WHERE va.vehicle_id = cars.id
                 ORDER BY va.assessed_on DESC, va.id DESC LIMIT 1
             )
             ORDER BY cars.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ---------------------------------------------------------------
     * Assessments
     * ------------------------------------------------------------- */

    /**
     * Saves the assessment, its components and its screening findings in one
     * transaction. Returns [id, created]. The same idempotency key returns the
     * existing assessment, so a double submit cannot create a second one.
     */
    public function create_assessment(array $vehicle, array $clean, array $actor, $interval_days)
    {
        $catalog = ComponentMaintenanceRules::assessment_catalog();
        $id      = (int) $vehicle['id'];

        $this->db->transaction();
        try {
            // Locking the vehicle serializes submissions for it, so readings compare in order.
            $locked = $this->db->table('cars')->select('id, current_mileage')->where('id', $id)->for_update()->get();
            if (!$locked) {
                throw new InvalidArgumentException('Vehicle not found.');
            }

            if ($clean['idempotency_key'] !== null) {
                $existing = $this->db->table(self::ASSESSMENTS)->select('id, vehicle_id')->where('idempotency_key', $clean['idempotency_key'])->get();
                if ($existing) {
                    if ((int) $existing['vehicle_id'] !== $id) {
                        throw new InvalidArgumentException('This idempotency key was already used for another vehicle.');
                    }
                    $this->db->commit();
                    return [(int) $existing['id'], false];
                }
            }

            $previous      = $this->previous_assessment($id, $clean['assessed_on']);
            $previousRows  = $previous ? $this->component_rows((int) $previous['id']) : [];
            $priorReading  = $this->prior_odometer($id, $clean['assessed_on'], $locked['current_mileage']);
            $odometer      = ComponentMaintenanceRules::odometer_check($clean['odometer_km'], $priorReading);

            $screened = [];
            $findings = $odometer['finding'] ? [$odometer['finding']] : [];
            $statuses = [];
            foreach ($clean['components'] as $key => $component) {
                $screened[$key] = ComponentMaintenanceRules::screen_component($key, $component, $previousRows[$key] ?? null, $clean['assessed_on']);
                $statuses[]     = $screened[$key]['screening_status'];
                $findings       = array_merge($findings, $screened[$key]['findings']);
            }
            $safetyCount = count(array_filter($findings, static function ($finding) { return $finding['safety']; }));

            $due = ComponentMaintenanceRules::reassessment_status($clean['assessed_on'], new DateTimeImmutable('today'), $interval_days)['due_on'];
            $this->db->table(self::ASSESSMENTS)->insert([
                'vehicle_id'           => $id,
                'idempotency_key'      => $clean['idempotency_key'],
                'assessed_on'          => $clean['assessed_on'],
                'odometer_km'          => $clean['odometer_km'],
                'previous_odometer_km' => $priorReading,
                'odometer_flag'        => $odometer['flag'],
                'vehicle_snapshot'     => self::json(['make' => $vehicle['make'], 'model' => $vehicle['model'], 'year' => (int) $vehicle['year'], 'category' => $vehicle['category'], 'plate_number' => $vehicle['plate_number'], 'acquisition_date' => $vehicle['acquisition_date'], 'usage_frequency' => $vehicle['usage_frequency'], 'previous_maintenance_date' => $vehicle['previous_maintenance_date']]),
                'assessor_id'          => (int) $actor['id'],
                'assessor_name'        => (string) ($actor['full_name'] ?: $actor['username']),
                'notes'                => $clean['notes'],
                'catalog_version'      => $catalog['version'],
                'overall_screening'    => ComponentMaintenanceRules::overall_screening($statuses),
                'safety_warning_count' => $safetyCount,
                'ai_status'            => 'pending',
                'next_assessment_due'  => $due,
            ]);
            $assessmentId = (int) $this->db->last_id();

            foreach ($clean['components'] as $key => $component) {
                $this->db->table(self::COMPONENTS)->insert([
                    'assessment_id'         => $assessmentId,
                    'category_key'          => $key,
                    'rating'                => $component['rating'],
                    'rating_score'          => $catalog['by_rating'][$component['rating']]['score'],
                    'answers'               => self::json($component['answers']),
                    'symptoms'              => self::json($component['symptoms']),
                    'measurements'          => self::json($component['measurements']),
                    'fault_codes'           => self::json($component['fault_codes']),
                    'last_maintenance_date' => $component['last_maintenance_date'],
                    'notes'                 => $component['notes'],
                    'screening_status'      => $screened[$key]['screening_status'],
                    'data_sufficiency'      => $screened[$key]['data_sufficiency'],
                    'missing_questions'     => self::json($screened[$key]['missing_questions']),
                ]);
            }

            foreach ($findings as $finding) {
                $this->db->table(self::FINDINGS)->insert([
                    'assessment_id'      => $assessmentId,
                    'category_key'       => $finding['component_key'],
                    'rule_id'            => $finding['rule_id'],
                    'source'             => $finding['source'],
                    'severity'           => $finding['severity'],
                    'is_safety'          => $finding['safety'] ? 1 : 0,
                    'observation'        => $finding['observation'],
                    'reason'             => $finding['reason'],
                    'recommended_action' => $finding['recommended_action'],
                ]);
            }

            // A lower reading is flagged above and never replaces the vehicle's mileage.
            if ($clean['odometer_km'] !== null && ($locked['current_mileage'] === null || $clean['odometer_km'] > (int) $locked['current_mileage'])) {
                $this->db->table('cars')->where('id', $id)->update(['current_mileage' => $clean['odometer_km']]);
            }

            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }

        return [$assessmentId, true];
    }

    public function assessment_row($assessment_id)
    {
        return $this->db->table(self::ASSESSMENTS)->where('id', (int) $assessment_id)->limit(1)->get() ?: null;
    }

    /** One assessment with everything recorded about it. */
    public function detail($assessment_id)
    {
        $row = $this->assessment_row($assessment_id);
        if (!$row) {
            return null;
        }
        $id         = (int) $row['id'];
        $components = $this->component_rows($id);
        $findings   = array_map([self::class, 'present_finding'], $this->db->table(self::FINDINGS)->where('assessment_id', $id)->order_by('id', 'ASC')->get_all());
        $previous   = $this->previous_assessment((int) $row['vehicle_id'], $row['assessed_on'], $id);

        return self::present_assessment($row) + [
            'components'      => $components,
            'findings'        => $findings,
            'safety_warnings' => array_values(array_filter($findings, static function ($finding) { return $finding['is_safety']; })),
            'analysis'        => $this->analysis_history($id),
            'evidence'        => array_map([self::class, 'present_evidence'], $this->db->table(self::EVIDENCE)->where('assessment_id', $id)->order_by('id', 'ASC')->get_all()),
            'outcomes'        => $this->outcomes($id),
            'comparison'      => $this->comparison($components, $previous),
        ];
    }

    /** Summaries for one vehicle, newest first. */
    public function history($vehicle_id, $limit = 50)
    {
        return array_map([self::class, 'present_assessment'], $this->db->table(self::ASSESSMENTS)
            ->where('vehicle_id', (int) $vehicle_id)
            ->order_by('assessed_on', 'DESC')
            ->order_by('id', 'DESC')
            ->limit(max(1, min((int) $limit, 100)))
            ->get_all());
    }

    public function latest_assessment_id($vehicle_id)
    {
        $row = $this->db->table(self::ASSESSMENTS)->select('id')
            ->where('vehicle_id', (int) $vehicle_id)
            ->order_by('assessed_on', 'DESC')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get();
        return $row ? (int) $row['id'] : null;
    }

    /** One category across assessments, plus legacy inspections and service records, each with its source named. */
    public function category_history($vehicle_id, $category_key, array $component_keys)
    {
        $rows = $this->db->raw(
            'SELECT va.id AS assessment_id, va.assessed_on, va.odometer_km, va.assessor_name, ac.rating, ac.rating_score, ac.screening_status,
                    ac.symptoms, ac.measurements, ac.fault_codes, ac.last_maintenance_date, ac.notes
             FROM assessment_components ac
             INNER JOIN vehicle_assessments va ON va.id = ac.assessment_id
             WHERE va.vehicle_id = ? AND ac.category_key = ?
             ORDER BY va.assessed_on DESC, va.id DESC',
            [(int) $vehicle_id, (string) $category_key]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['symptoms', 'measurements', 'fault_codes'] as $field) {
                $row[$field] = json_decode((string) $row[$field], true) ?: [];
            }
            $row['assessment_id'] = (int) $row['assessment_id'];
            $row['odometer_km']   = $row['odometer_km'] === null ? null : (int) $row['odometer_km'];
            $row['rating_score']  = $row['rating_score'] === null ? null : (int) $row['rating_score'];
        }
        unset($row);

        $legacy = [];
        if ($component_keys) {
            $legacy = $this->db->table('vehicle_component_inspections')
                ->select('id, component_key, inspected_on, odometer_km, condition_rating, findings, confirmed_fault, service_performed, service_date, work_performed, parts_replaced, technician')
                ->where('vehicle_id', (int) $vehicle_id)
                ->in('component_key', $component_keys)
                ->order_by('inspected_on', 'DESC')
                ->get_all();
        }

        return [
            'assessments'         => $rows,
            // Original three-level values, shown as recorded. Never converted to the new scale.
            'legacy_inspections'  => array_map(static function ($row) { return $row + ['rating_scale' => 'legacy_three_level']; }, $legacy),
            'outcomes'            => $this->outcomes_for_category($vehicle_id, $category_key),
        ];
    }

    /** Vehicle-wide service records (not tied to one component). */
    public function service_records($vehicle_id, $limit = 50)
    {
        return $this->db->table('tblvehicle_maintenance')
            ->select('maintenanceID AS id, maintenanceType AS type, description, maintenanceDate AS date, maintenanceStatus AS status, odometerReading AS odometer_km')
            ->where('vehicleID', (int) $vehicle_id)
            ->order_by('maintenanceDate', 'DESC')
            ->limit(max(1, min((int) $limit, 100)))
            ->get_all();
    }

    /** Service records dated on or before the assessment. */
    private function service_records_before($vehicle_id, $assessed_on)
    {
        return $this->db->table('tblvehicle_maintenance')
            ->select('maintenanceID AS id, maintenanceType AS type, maintenanceDate AS date, odometerReading AS odometer_km')
            ->where('vehicleID', (int) $vehicle_id)
            ->where('maintenanceDate', '<=', $assessed_on)
            ->order_by('maintenanceDate', 'DESC')
            ->limit(100)
            ->get_all();
    }

    /** Verified outcomes per category that had already happened by the given date. */
    private function prior_outcome_counts($vehicle_id, $assessed_on)
    {
        $rows = $this->db->raw(
            "SELECT category_key, COUNT(*) AS outcomes,
                    SUM(result = 'confirmed_failure' OR (result = 'successful_repair' AND mechanic_confirmed = 'confirmed')) AS faults
             FROM assessment_outcomes
             WHERE vehicle_id = ? AND verification_status = 'verified' AND outcome_date < ?
             GROUP BY category_key",
            [(int) $vehicle_id, $assessed_on]
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach (ComponentMaintenanceRules::assessment_catalog()['categories'] as $category) {
            $counts[$category['key']] = ['prior_outcomes' => 0, 'prior_faults' => 0];
        }
        foreach ($rows as $row) {
            $counts[$row['category_key']] = ['prior_outcomes' => (int) $row['outcomes'], 'prior_faults' => (int) $row['faults']];
        }
        return $counts;
    }

    public function review($assessment_id, $user_id, $notes)
    {
        $this->db->table(self::ASSESSMENTS)->where('id', (int) $assessment_id)->update([
            'review_status' => 'reviewed',
            'reviewed_by'   => (int) $user_id,
            'reviewed_at'   => date('Y-m-d H:i:s'),
            'review_notes'  => $notes,
        ]);
    }

    /* ---------------------------------------------------------------
     * AI analysis
     * ------------------------------------------------------------- */

    /**
     * Atomically claims an assessment for analysis and opens a new attempt.
     * Returns the attempt row, or a string explaining why it cannot run. This
     * is what prevents duplicate processing of the same assessment.
     */
    public function claim_analysis($assessment_id)
    {
        $id = (int) $assessment_id;
        $this->db->transaction();
        try {
            $row = $this->db->table(self::ASSESSMENTS)->select('id, ai_status, ai_attempts, ai_claimed_at')->where('id', $id)->for_update()->get();
            if (!$row) {
                throw new InvalidArgumentException('Assessment not found.');
            }
            if ($row['ai_status'] === 'completed') {
                $this->db->commit();
                return 'This assessment has already been analysed.';
            }
            $claimedAt = $row['ai_claimed_at'] ? strtotime($row['ai_claimed_at']) : 0;
            if ($row['ai_status'] === 'processing' && time() - $claimedAt < self::STALE_CLAIM_SECONDS) {
                $this->db->commit();
                return 'Analysis is already in progress for this assessment.';
            }
            // A stale attempt's request died, so close it before opening the next one.
            if ($row['ai_status'] === 'processing') {
                $this->db->table(self::ANALYSES)
                    ->where('assessment_id', $id)
                    ->where('status', 'processing')
                    ->update(['status' => 'failed', 'error_message' => 'The request did not finish.', 'completed_at' => date('Y-m-d H:i:s')]);
            }

            $attempt = (int) $row['ai_attempts'] + 1;
            $now     = date('Y-m-d H:i:s');
            $this->db->table(self::ANALYSES)->insert(['assessment_id' => $id, 'attempt' => $attempt, 'status' => 'processing', 'started_at' => $now]);
            $analysisId = (int) $this->db->last_id();
            $this->db->table(self::ASSESSMENTS)->where('id', $id)->update(['ai_status' => 'processing', 'ai_attempts' => $attempt, 'ai_claimed_at' => $now, 'ai_last_error' => null]);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
        return ['id' => $analysisId, 'attempt' => $attempt];
    }

    public function record_analysis_failure($assessment_id, $analysis_id, $message)
    {
        $message = mb_substr((string) $message, 0, 255);
        $this->db->table(self::ANALYSES)->where('id', (int) $analysis_id)->update(['status' => 'failed', 'error_message' => $message, 'completed_at' => date('Y-m-d H:i:s')]);
        $this->db->table(self::ASSESSMENTS)->where('id', (int) $assessment_id)->update(['ai_status' => 'failed', 'ai_last_error' => $message, 'ai_claimed_at' => null]);
    }

    public function record_analysis_success($assessment_id, $analysis_id, array $response)
    {
        $id = (int) $assessment_id;
        $this->db->transaction();
        try {
            $this->db->table(self::ANALYSES)->where('id', (int) $analysis_id)->update([
                'status'            => 'completed',
                'processing_status' => mb_substr((string) ($response['processing']['status'] ?? ''), 0, 40),
                'catalog_version'   => mb_substr((string) ($response['processing']['catalog_version'] ?? ''), 0, 40),
                'predictive_status' => mb_substr((string) ($response['predictive_capability']['label'] ?? 'Insufficient Data'), 0, 40),
                'response_json'     => self::json($response),
                'completed_at'      => date('Y-m-d H:i:s'),
            ]);
            foreach ($response['components'] as $component) {
                $model = is_array($component['ai_model'] ?? null) ? $component['ai_model'] : [];
                $this->db->table(self::AI_FINDINGS)->insert([
                    'analysis_id'         => (int) $analysis_id,
                    'assessment_id'       => $id,
                    'category_key'        => (string) $component['component_key'],
                    'screening_status'    => (string) $component['screening_status'],
                    'explanation'         => $component['explanation'] ?? null,
                    'recommended_action'  => isset($component['recommended_action']) ? mb_substr((string) $component['recommended_action'], 0, 500) : null,
                    'data_sufficiency'    => $component['data_sufficiency']['status'] ?? null,
                    'identified_symptoms' => self::json($component['identified_symptoms'] ?? []),
                    'identified_faults'   => self::json($component['identified_faults'] ?? []),
                    'diagnostic_guidance' => self::json($component['diagnostic_guidance'] ?? []),
                    'evidence_used'       => self::json($component['evidence_used'] ?? []),
                    'ai_model'            => $model['name'] ?? null,
                    'ai_model_version'    => $model['version'] ?? null,
                    'confidence'          => is_numeric($component['confidence'] ?? null) ? (float) $component['confidence'] : null,
                    'prediction_horizon'  => $component['prediction_horizon'] ?? null,
                    'risk_level'          => $component['prediction']['risk_level'] ?? null,
                    'model_score'         => is_numeric($component['prediction']['model_score'] ?? null) ? (float) $component['prediction']['model_score'] : null,
                    'prediction'          => self::json($component['prediction'] ?? null),
                    'predictive_status'   => mb_substr((string) ($component['predictive_status'] ?? 'Insufficient Data'), 0, 40),
                ]);
            }
            $this->db->table(self::ASSESSMENTS)->where('id', $id)->update(['ai_status' => 'completed', 'ai_last_error' => null, 'ai_claimed_at' => null]);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    /**
     * The JSON sent to the Python service, for analysis and for training. It carries only
     * what was recorded and known on the assessment date, so later records cannot leak in.
     */
    public function ai_payload($assessment_id, array $vehicle)
    {
        $row        = $this->assessment_row($assessment_id);
        $snapshot   = json_decode((string) $row['vehicle_snapshot'], true) ?: [];
        $components = [];
        foreach ($this->component_rows((int) $row['id']) as $key => $component) {
            $components[$key] = [
                'rating'                => $component['rating'],
                'answers'               => (object) $component['answers'],
                'symptoms'              => $component['symptoms'],
                'measurements'          => (object) $component['measurements'],
                'fault_codes'           => $component['fault_codes'],
                'last_maintenance_date' => $component['last_maintenance_date'],
                'notes'                 => $component['notes'],
            ];
        }

        $previous = $this->previous_assessment((int) $row['vehicle_id'], $row['assessed_on'], (int) $row['id']);
        $previousPayload = null;
        if ($previous) {
            $previousComponents = [];
            foreach ($this->component_rows((int) $previous['id']) as $key => $component) {
                $previousComponents[$key] = ['rating' => $component['rating'], 'screening_status' => $component['screening_status'], 'measurements' => (object) $component['measurements']];
            }
            $previousPayload = ['assessed_on' => $previous['assessed_on'], 'odometer_km' => $previous['odometer_km'] === null ? null : (int) $previous['odometer_km'], 'components' => $previousComponents];
        }

        return [
            'assessment_id' => (int) $row['id'],
            'vehicle'       => [
                'id'               => (int) $vehicle['id'],
                'make'             => $vehicle['make'],
                'model'            => $vehicle['model'],
                'year'             => (int) $vehicle['year'],
                'category'         => $vehicle['category'],
                'fuel_type'        => $vehicle['fuel_type'],
                'age_years'        => ComponentMaintenanceRules::vehicle_age_years($vehicle['year'], new DateTimeImmutable($row['assessed_on'])),
                'acquisition_date' => $snapshot['acquisition_date'] ?? null,
                'usage_frequency'  => $snapshot['usage_frequency'] ?? null,
                'previous_maintenance_date' => $snapshot['previous_maintenance_date'] ?? null,
            ],
            'assessed_on'          => $row['assessed_on'],
            'odometer_km'          => $row['odometer_km'] === null ? null : (int) $row['odometer_km'],
            'previous_odometer_km' => $row['previous_odometer_km'] === null ? null : (int) $row['previous_odometer_km'],
            'components'           => $components,
            'previous_assessment'  => $previousPayload,
            'maintenance_history'  => $this->service_records_before($vehicle['id'], $row['assessed_on']),
            'category_history'     => $this->prior_outcome_counts($vehicle['id'], $row['assessed_on']),
        ];
    }

    /* ---------------------------------------------------------------
     * Evidence
     * ------------------------------------------------------------- */

    public function add_evidence($assessment_id, $category_key, $filename, $original_name, $user_id)
    {
        $this->db->table(self::EVIDENCE)->insert([
            'assessment_id' => (int) $assessment_id,
            'category_key'  => (string) $category_key,
            'filename'      => (string) $filename,
            'original_name' => mb_substr((string) $original_name, 0, 255),
            'uploaded_by'   => (int) $user_id,
        ]);
        $id = (int) $this->db->last_id();
        return self::present_evidence($this->db->table(self::EVIDENCE)->where('id', $id)->get());
    }

    public function evidence($evidence_id)
    {
        return $this->db->table(self::EVIDENCE)->where('id', (int) $evidence_id)->limit(1)->get() ?: null;
    }

    /* ---------------------------------------------------------------
     * Outcomes
     * ------------------------------------------------------------- */

    /**
     * Appends an outcome. The recommendation text and its time are copied from
     * the screening finding or AI finding, so they survive whatever happens next.
     * Starts unverified: only verified outcomes may be used for model evaluation.
     */
    public function record_outcome(array $assessment, $category_key, $component_key, array $outcome, $user_id)
    {
        $id = (int) $assessment['id'];
        foreach (['failure_date' => 'Failure date', 'repair_date' => 'Repair date', 'outcome_date' => 'Outcome date'] as $field => $label) {
            if ($outcome[$field] !== null && $outcome[$field] < $assessment['assessed_on']) {
                throw new InvalidArgumentException("{$label} cannot be before the assessment date.");
            }
        }
        if ($outcome['mileage_km'] !== null && $assessment['odometer_km'] !== null && $outcome['mileage_km'] < (int) $assessment['odometer_km']) {
            throw new InvalidArgumentException('Outcome mileage cannot be lower than the odometer on the assessment.');
        }
        $duplicate = $this->db->table(self::OUTCOMES)->select('id')
            ->where('assessment_id', $id)
            ->where('category_key', (string) $category_key)
            ->where('decision', $outcome['decision'])
            ->where('result', $outcome['result']);
        $duplicate = $outcome['outcome_date'] === null ? $duplicate->where_null('outcome_date') : $duplicate->where('outcome_date', $outcome['outcome_date']);
        if ($duplicate->limit(1)->get()) {
            throw new DomainException('This outcome has already been recorded for this assessment.');
        }

        $source = null;
        if ($outcome['finding_id'] !== null) {
            $finding = $this->db->table(self::FINDINGS)->where('id', $outcome['finding_id'])->where('assessment_id', $id)->limit(1)->get();
            if (!$finding || $finding['category_key'] !== $category_key) {
                throw new InvalidArgumentException('That finding does not belong to this assessment and category.');
            }
            $source = ['text' => $finding['recommended_action'], 'at' => $finding['created_at']];
        } else {
            $finding = $this->db->table(self::AI_FINDINGS)->where('assessment_id', $id)->where('category_key', (string) $category_key)->order_by('id', 'DESC')->limit(1)->get();
            if ($finding && $finding['recommended_action']) {
                $source = ['text' => $finding['recommended_action'], 'at' => $finding['created_at']];
            }
        }
        // Healthy results matter most where nothing was flagged. Without them a model only ever sees problems.
        if (!$source) {
            $source = ['text' => 'No recommendation: screening triggered nothing for this category.', 'at' => $assessment['created_at']];
        }

        $this->db->table(self::OUTCOMES)->insert($outcome + [
            'assessment_id'           => $id,
            'vehicle_id'              => (int) $assessment['vehicle_id'],
            'category_key'            => (string) $category_key,
            'component_key'           => $component_key,
            'original_recommendation' => $source['text'],
            'recommended_at'          => $source['at'],
            'recorded_by'             => (int) $user_id,
        ]);
        return self::present_outcome($this->outcome((int) $this->db->last_id()));
    }

    public function outcome($outcome_id)
    {
        return $this->db->table(self::OUTCOMES)->where('id', (int) $outcome_id)->limit(1)->get() ?: null;
    }

    public function verify_outcome($outcome_id, $status, $user_id)
    {
        $this->db->table(self::OUTCOMES)->where('id', (int) $outcome_id)->update([
            'verification_status' => $status,
            'verified_by'         => (int) $user_id,
            'verified_at'         => date('Y-m-d H:i:s'),
        ]);
        return self::present_outcome($this->outcome($outcome_id));
    }

    public function outcomes($assessment_id)
    {
        return array_map([self::class, 'present_outcome'], $this->db->table(self::OUTCOMES)
            ->where('assessment_id', (int) $assessment_id)
            ->order_by('id', 'DESC')
            ->get_all());
    }

    private function outcomes_for_category($vehicle_id, $category_key)
    {
        return array_map([self::class, 'present_outcome'], $this->db->table(self::OUTCOMES)
            ->where('vehicle_id', (int) $vehicle_id)
            ->where('category_key', (string) $category_key)
            ->order_by('id', 'DESC')
            ->get_all());
    }

    /** Every outcome with the assessment facts its eligibility depends on, optionally for one category. */
    public function training_records($category_key = null)
    {
        $sql = 'SELECT o.id AS outcome_id, o.vehicle_id, cars.plate_number, o.category_key, o.assessment_id, va.assessed_on, va.odometer_km,
                       va.previous_odometer_km, va.odometer_flag, ac.rating, ac.screening_status, o.result, o.mechanic_confirmed, o.verification_status,
                       o.outcome_date, o.failure_date, o.repair_date, o.mileage_km AS outcome_mileage_km, o.parts_replaced
                FROM assessment_outcomes o
                INNER JOIN vehicle_assessments va ON va.id = o.assessment_id
                INNER JOIN cars ON cars.id = o.vehicle_id
                LEFT JOIN assessment_components ac ON ac.assessment_id = o.assessment_id AND ac.category_key = o.category_key';
        $args = [];
        if ($category_key !== null) {
            $sql   .= ' WHERE o.category_key = ?';
            $args[] = (string) $category_key;
        }
        $rows = $this->db->raw($sql . ' ORDER BY o.id ASC', $args)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['outcome_id', 'vehicle_id', 'assessment_id'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            foreach (['odometer_km', 'previous_odometer_km', 'outcome_mileage_km'] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }
        unset($row);
        return $rows;
    }

    /** The eligible records for one category, each with the assessment exactly as the model will see it. */
    public function training_dataset($category_key)
    {
        $result = ComponentMaintenanceRules::evaluate_training_records($this->training_records($category_key));
        $rows   = [];
        foreach ($result['eligible'] as $record) {
            $vehicle = $this->vehicle($record['vehicle_id']);
            $payload = $this->ai_payload($record['assessment_id'], $vehicle);
            $rows[]  = $record + [
                'label'              => ComponentMaintenanceRules::outcome_label($record),
                'acquisition_date'   => $payload['vehicle']['acquisition_date'],
                'vehicle_age_years'  => $payload['vehicle']['age_years'],
                'usage_frequency'    => $payload['vehicle']['usage_frequency'],
                'mileage_difference' => $record['odometer_km'] !== null && $record['previous_odometer_km'] !== null ? $record['odometer_km'] - $record['previous_odometer_km'] : null,
                'previous_maintenance_date' => $payload['components'][$category_key]['last_maintenance_date'] ?? $payload['vehicle']['previous_maintenance_date'],
                'assessment'         => $payload,
            ];
        }
        return ['rows' => $rows, 'excluded' => $result['excluded'], 'summary' => $result['summary']];
    }

    /**
     * Opens a training run, or returns a message if one is already running for the
     * category. A run older than this limit belongs to a request that died.
     */
    public function claim_training_run($category_key, array $summary, $user_id)
    {
        $this->db->transaction();
        try {
            $running = $this->db->table('model_training_runs')->where('category_key', (string) $category_key)->where('status', 'running')->for_update()->get();
            if ($running && time() - strtotime($running['started_at']) < self::STALE_TRAINING_SECONDS) {
                $this->db->commit();
                return 'Training is already running for this category.';
            }
            if ($running) {
                $this->db->table('model_training_runs')->where('id', (int) $running['id'])->update(['status' => 'failed', 'error_message' => 'The request did not finish.', 'completed_at' => date('Y-m-d H:i:s')]);
            }
            $this->db->table('model_training_runs')->insert([
                'category_key'     => (string) $category_key,
                'status'           => 'running',
                'dataset_rows'     => $summary['eligible'],
                'dataset_vehicles' => $summary['vehicles'],
                'started_by'       => (int) $user_id,
                'started_at'       => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->last_id();
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
        return ['id' => $id];
    }

    public function finish_training_run($run_id, array $values)
    {
        $this->db->table('model_training_runs')->where('id', (int) $run_id)->update($values + ['completed_at' => date('Y-m-d H:i:s')]);
    }

    public function training_runs($limit = 40)
    {
        return $this->db->table('model_training_runs')->order_by('id', 'DESC')->limit(max(1, min((int) $limit, 100)))->get_all();
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /** Latest assessment on or before a date. $exclude_id stops an assessment comparing with itself. */
    private function previous_assessment($vehicle_id, $assessed_on, $exclude_id = null)
    {
        if ($exclude_id === null) {
            $row = $this->db->raw(
                'SELECT * FROM vehicle_assessments WHERE vehicle_id = ? AND assessed_on <= ? ORDER BY assessed_on DESC, id DESC LIMIT 1',
                [(int) $vehicle_id, $assessed_on]
            )->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->db->raw(
                'SELECT * FROM vehicle_assessments WHERE vehicle_id = ? AND id <> ? AND (assessed_on < ? OR (assessed_on = ? AND id < ?)) ORDER BY assessed_on DESC, id DESC LIMIT 1',
                [(int) $vehicle_id, (int) $exclude_id, $assessed_on, $assessed_on, (int) $exclude_id]
            )->fetch(PDO::FETCH_ASSOC);
        }
        return $row ?: null;
    }

    /**
     * Highest reading dated on or before the assessment, across assessments and
     * legacy inspections. cars.current_mileage has no date, so it counts only when
     * no later dated reading exists, which allows a genuine backdated assessment.
     */
    private function prior_odometer($vehicle_id, $assessed_on, $current_mileage)
    {
        $row = $this->db->raw(
            'SELECT
                (SELECT MAX(odometer_km) FROM vehicle_assessments WHERE vehicle_id = ? AND assessed_on <= ?) AS assessed,
                (SELECT MAX(odometer_km) FROM vehicle_component_inspections WHERE vehicle_id = ? AND inspected_on <= ?) AS inspected,
                (SELECT MAX(assessed_on) FROM vehicle_assessments WHERE vehicle_id = ? AND odometer_km IS NOT NULL) AS last_assessed,
                (SELECT MAX(inspected_on) FROM vehicle_component_inspections WHERE vehicle_id = ? AND odometer_km IS NOT NULL) AS last_inspected',
            [$vehicle_id, $assessed_on, $vehicle_id, $assessed_on, $vehicle_id, $vehicle_id]
        )->fetch(PDO::FETCH_ASSOC);

        $readings = array_filter([$row['assessed'], $row['inspected']], static function ($value) { return $value !== null; });
        $hasLater = ($row['last_assessed'] !== null && $row['last_assessed'] > $assessed_on)
            || ($row['last_inspected'] !== null && $row['last_inspected'] > $assessed_on);
        if ($current_mileage !== null && !$hasLater) {
            $readings[] = $current_mileage;
        }
        return $readings ? (int) max($readings) : null;
    }

    /** Components keyed by category, with JSON decoded and missing scores kept as NULL. */
    private function component_rows($assessment_id)
    {
        $rows = [];
        foreach ($this->db->table(self::COMPONENTS)->where('assessment_id', (int) $assessment_id)->order_by('id', 'ASC')->get_all() as $row) {
            foreach (['answers', 'symptoms', 'measurements', 'fault_codes', 'missing_questions'] as $field) {
                $row[$field] = json_decode((string) $row[$field], true) ?: [];
            }
            $row['id']           = (int) $row['id'];
            $row['rating_score'] = $row['rating_score'] === null ? null : (int) $row['rating_score'];
            $rows[$row['category_key']] = $row;
        }
        return $rows;
    }

    private function analysis_history($assessment_id)
    {
        $attempts = $this->db->table(self::ANALYSES)->where('assessment_id', (int) $assessment_id)->order_by('attempt', 'ASC')->get_all();
        $latest   = null;
        foreach ($attempts as $attempt) {
            if ($attempt['status'] === 'completed') {
                $latest = $attempt;
            }
        }

        $findings = [];
        if ($latest) {
            foreach ($this->db->table(self::AI_FINDINGS)->where('analysis_id', (int) $latest['id'])->order_by('id', 'ASC')->get_all() as $row) {
                foreach (['identified_symptoms', 'identified_faults', 'diagnostic_guidance', 'evidence_used'] as $field) {
                    $row[$field] = json_decode((string) $row[$field], true) ?: [];
                }
                $row['prediction'] = json_decode((string) $row['prediction'], true);
                $row['confidence'] = $row['confidence'] === null ? null : (float) $row['confidence'];
                $findings[$row['category_key']] = $row;
            }
        }

        $response = $latest ? json_decode((string) $latest['response_json'], true) : null;
        return [
            'attempts' => array_map(static function ($attempt) {
                unset($attempt['response_json']);
                $attempt['id']      = (int) $attempt['id'];
                $attempt['attempt'] = (int) $attempt['attempt'];
                return $attempt;
            }, $attempts),
            'processed_at'          => $latest['completed_at'] ?? null,
            'processing_status'     => $latest['processing_status'] ?? null,
            'knowledge_message'     => $response['processing']['message'] ?? null,
            'predictive_capability' => $response['predictive_capability'] ?? null,
            'findings'              => $findings,
        ];
    }

    private function comparison(array $components, $previous)
    {
        if (!$previous) {
            return null;
        }
        $earlier = $this->component_rows((int) $previous['id']);
        $rows    = [];
        foreach ($components as $key => $component) {
            $before = $earlier[$key] ?? null;
            $change = 'not_comparable';
            if ($before && $component['rating_score'] !== null && $before['rating_score'] !== null) {
                $change = $component['rating_score'] > $before['rating_score'] ? 'improved' : ($component['rating_score'] < $before['rating_score'] ? 'worsened' : 'unchanged');
            }
            $rows[$key] = [
                'current_rating'        => $component['rating'],
                'previous_rating'       => $before['rating'] ?? null,
                'current_screening'     => $component['screening_status'],
                'previous_screening'    => $before['screening_status'] ?? null,
                'change'                => $change,
                'current_measurements'  => $component['measurements'],
                'previous_measurements' => $before['measurements'] ?? [],
            ];
        }
        return [
            'previous_assessment_id' => (int) $previous['id'],
            'previous_assessed_on'   => $previous['assessed_on'],
            'previous_odometer_km'   => $previous['odometer_km'] === null ? null : (int) $previous['odometer_km'],
            'categories'             => $rows,
        ];
    }

    private static function json($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function present_assessment(array $row)
    {
        foreach (['id', 'vehicle_id', 'safety_warning_count', 'ai_attempts'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['odometer_km', 'previous_odometer_km'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['vehicle_snapshot'] = json_decode((string) $row['vehicle_snapshot'], true);
        unset($row['idempotency_key'], $row['ai_claimed_at']);
        return $row;
    }

    private static function present_finding(array $row)
    {
        $row['id']        = (int) $row['id'];
        $row['is_safety'] = (int) $row['is_safety'] === 1;
        $row['component_name'] = $row['category_key'] === null
            ? 'Vehicle record'
            : ComponentMaintenanceRules::assessment_catalog()['by_category'][$row['category_key']]['name'];
        return $row;
    }

    private static function present_evidence(array $row)
    {
        unset($row['filename']);
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private static function present_outcome(array $row)
    {
        foreach (['id', 'assessment_id', 'vehicle_id'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['mileage_km'] = $row['mileage_km'] === null ? null : (int) $row['mileage_km'];
        return $row;
    }
}
