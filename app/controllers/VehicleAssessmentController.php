<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Vehicle assessments for active administrators. Everyone else gets 403.
 *
 * Submission saves the assessment and its rule-based safety warnings first, then
 * calls the Python service in the same request. There is no queue: if the service
 * fails, the assessment stays saved, ai_status becomes "failed", and POST
 * /api/assessments/{id}/analysis retries it safely.
 */
class VehicleAssessmentController extends Controller
{
    const AI_TIMEOUT_SECONDS = 20;
    const TRAINING_TIMEOUT_SECONDS = 300;
    const EVIDENCE_MAX_MB = 8;

    private $user;
    private $evidenceDirectory;

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->config->load('api');
        $this->call->model(['VehicleAssessmentModel', 'ComponentMaintenanceModel']);
        $this->call->library(['ComponentMaintenanceRules', 'AiServiceClient']);
        $this->evidenceDirectory = ROOT_DIR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'assessment-evidence';

        $this->user = $this->UserModel->find_active($this->authenticated_user_id());
        if (!$this->user) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }
        if ($this->user['role'] !== 'admin') {
            $this->response->send_json_error('Administrator access is required.', 403);
            exit;
        }
    }

    /** GET /api/assessments/catalog */
    public function catalog()
    {
        $catalog = ComponentMaintenanceRules::assessment_catalog();
        $this->response->send_json_success([
            'version'                    => $catalog['version'],
            'ratings'                    => $catalog['ratings'],
            'answers'                    => $catalog['answers'],
            'categories'                 => $catalog['categories'],
            'reassessment_interval_days' => $catalog['reassessment_interval_days'],
            'usage_frequencies'          => ComponentMaintenanceRules::USAGE_FREQUENCIES,
        ], 'Assessment catalog retrieved successfully.');
    }

    /** GET /api/assessments/fleet */
    public function fleet()
    {
        $today    = new DateTimeImmutable('today');
        $interval = $this->interval_days();
        $vehicles = array_map(function ($row) use ($today, $interval) {
            $row['id']              = (int) $row['id'];
            $row['year']            = (int) $row['year'];
            $row['current_mileage'] = $row['current_mileage'] === null ? null : (int) $row['current_mileage'];
            $row['assessment_id']   = $row['assessment_id'] === null ? null : (int) $row['assessment_id'];
            $row['safety_warning_count'] = $row['safety_warning_count'] === null ? null : (int) $row['safety_warning_count'];
            $row['reassessment']    = ComponentMaintenanceRules::reassessment_status($row['assessed_on'], $today, $interval);
            $row['data_flags']      = ComponentMaintenanceRules::vehicle_data_flags($row, $today);
            return $row;
        }, $this->VehicleAssessmentModel->fleet_overview());

        $this->response->send_json_success(['vehicles' => $vehicles], 'Fleet assessment overview retrieved successfully.');
    }

    /** GET /api/assessments/vehicles/{id} */
    public function vehicle($id)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle) {
            return;
        }
        $today    = new DateTimeImmutable('today');
        $latestId = $this->VehicleAssessmentModel->latest_assessment_id($vehicle['id']);
        $latest   = $latestId ? $this->VehicleAssessmentModel->detail($latestId) : null;
        $bodyType = ComponentMaintenanceRules::body_type($vehicle['category']);

        // Rows for the 3D model. Status comes from the latest assessment; a component
        // whose category was not assessed stays not_assessed.
        $parts = [];
        foreach ($this->ComponentMaintenanceModel->component_types($bodyType) as $type) {
            $category  = ComponentMaintenanceRules::category_for_component($type['component_key']);
            $component = $category && $latest ? ($latest['components'][$category] ?? null) : null;
            $parts[]   = [
                'key'              => $type['component_key'],
                'name'             => $type['display_name'],
                'category_key'     => $category,
                'rating'           => $component['rating'] ?? 'not_assessed',
                'screening_status' => $component['screening_status'] ?? 'not_assessed',
            ];
        }

        $this->response->send_json_success([
            'vehicle' => [
                'id'                        => (int) $vehicle['id'],
                'make'                      => $vehicle['make'],
                'model'                     => $vehicle['model'],
                'year'                      => (int) $vehicle['year'],
                'category'                  => $vehicle['category'],
                'body_type'                 => $bodyType,
                'fuel_type'                 => $vehicle['fuel_type'],
                'plate_number'              => $vehicle['plate_number'],
                'color'                     => $vehicle['color'],
                'status'                    => $vehicle['status'],
                'current_mileage'           => $vehicle['current_mileage'] === null ? null : (int) $vehicle['current_mileage'],
                'has_air_conditioning'      => (int) $vehicle['air_conditioning'] === 1,
                'acquisition_date'          => $vehicle['acquisition_date'],
                'usage_frequency'           => $vehicle['usage_frequency'],
                'previous_maintenance_date' => $vehicle['previous_maintenance_date'],
                'age_years'                 => ComponentMaintenanceRules::vehicle_age_years($vehicle['year'], $today),
            ],
            'data_flags'   => ComponentMaintenanceRules::vehicle_data_flags($vehicle, $today),
            'reassessment' => ComponentMaintenanceRules::reassessment_status($latest['assessed_on'] ?? null, $today, $this->interval_days()),
            'latest'       => $latest,
            'history'      => $this->VehicleAssessmentModel->history($vehicle['id']),
            'parts'        => $parts,
        ], 'Vehicle assessment workspace retrieved successfully.');
    }

    /** PATCH /api/assessments/vehicles/{id}/profile */
    public function updateProfile($id)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle) {
            return;
        }
        try {
            $values = ComponentMaintenanceRules::normalize_vehicle_profile($this->input(), new DateTimeImmutable('today'));
            $this->VehicleAssessmentModel->update_vehicle_profile($vehicle['id'], $values);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Vehicle profile update failed: ' . $exception->getMessage());
            $this->response->send_json_error('The vehicle profile could not be saved.', 500);
            return;
        }
        $this->response->send_json_success($values, 'Vehicle profile saved successfully.');
    }

    /** GET /api/assessments/vehicles/{id}/categories/{key}/history */
    public function categoryHistory($id, $key)
    {
        $vehicle = $this->find_vehicle($id);
        if (!$vehicle) {
            return;
        }
        $category = ComponentMaintenanceRules::assessment_catalog()['by_category'][$key] ?? null;
        if (!$category) {
            $this->response->send_json_error('Assessment category not found.', 404);
            return;
        }
        $this->response->send_json_success(
            $this->VehicleAssessmentModel->category_history($vehicle['id'], $key, $category['component_keys'])
                + ['service_records' => $this->VehicleAssessmentModel->service_records($vehicle['id'])],
            'Category history retrieved successfully.'
        );
    }

    /** POST /api/assessments */
    public function create()
    {
        try {
            $input   = $this->input();
            $vehicle = $this->find_vehicle($input['vehicle_id'] ?? null);
            if (!$vehicle) {
                return;
            }
            $clean = ComponentMaintenanceRules::normalize_assessment($input, new DateTimeImmutable('today'));
            [$assessmentId, $created] = $this->VehicleAssessmentModel->create_assessment($vehicle, $clean, $this->user, $this->interval_days());
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Assessment save failed: ' . $exception->getMessage());
            $this->response->send_json_error('The assessment could not be saved.', 500);
            return;
        }

        if ($created) {
            $this->run_analysis($assessmentId, $vehicle);
        }
        $this->response->send_json_success(
            $this->VehicleAssessmentModel->detail($assessmentId) + ['duplicate_submission' => !$created],
            $created ? 'Assessment saved.' : 'This assessment was already submitted.',
            $created ? 201 : 200
        );
    }

    /** GET /api/assessments/{id} */
    public function show($id)
    {
        $detail = $this->valid_id($id) ? $this->VehicleAssessmentModel->detail($id) : null;
        if (!$detail) {
            $this->response->send_json_error('Assessment not found.', 404);
            return;
        }
        $this->response->send_json_success($detail, 'Assessment retrieved successfully.');
    }

    /** POST /api/assessments/{id}/analysis: safe retry. Completed or running analyses return 409. */
    public function analyze($id)
    {
        $row = $this->find_assessment($id);
        if (!$row) {
            return;
        }
        $vehicle = $this->VehicleAssessmentModel->vehicle($row['vehicle_id']);
        $refusal = $this->run_analysis((int) $row['id'], $vehicle);
        if ($refusal !== null) {
            $this->response->send_json_error($refusal, 409);
            return;
        }
        $this->response->send_json_success($this->VehicleAssessmentModel->detail($row['id']), 'Analysis attempt finished.');
    }

    /** POST /api/assessments/{id}/review */
    public function review($id)
    {
        $row = $this->find_assessment($id);
        if (!$row) {
            return;
        }
        try {
            $notes = trim((string) ($this->input()['notes'] ?? ''));
            if (mb_strlen($notes) > ComponentMaintenanceRules::MAX_NOTE_LENGTH) {
                throw new InvalidArgumentException('Review notes are too long.');
            }
            $this->VehicleAssessmentModel->review($row['id'], $this->user['id'], $notes === '' ? null : $notes);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        }
        $this->response->send_json_success($this->VehicleAssessmentModel->detail($row['id']), 'Assessment marked as reviewed.');
    }

    /** POST /api/assessments/{id}/categories/{key}/evidence (multipart field "evidence") */
    public function uploadEvidence($id, $key)
    {
        $row = $this->find_assessment($id);
        if (!$row) {
            return;
        }
        if (!isset(ComponentMaintenanceRules::assessment_catalog()['by_category'][$key])) {
            $this->response->send_json_error('Assessment category not found.', 404);
            return;
        }
        $file = $this->request->file('evidence');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->response->send_json_error('Choose an image to attach.', 422);
            return;
        }

        $this->call->library('upload', $file);
        $this->upload->set_dir($this->evidenceDirectory)
            ->max_size(self::EVIDENCE_MAX_MB)
            ->allowed_extensions(['jpg', 'jpeg', 'png', 'webp'])
            ->allowed_mimes(['image/jpeg', 'image/png', 'image/webp'])
            ->is_image()
            ->encrypt_name();
        if (!$this->upload->do_upload()) {
            $this->response->send_json_error('Evidence must be a JPEG, PNG or WebP image no larger than ' . self::EVIDENCE_MAX_MB . ' MB.', 422, ['errors' => $this->upload->get_errors()]);
            return;
        }

        $filename = $this->upload->get_filename();
        try {
            $saved = $this->VehicleAssessmentModel->add_evidence($row['id'], $key, $filename, (string) ($file['name'] ?? ''), $this->user['id']);
        } catch (Exception $exception) {
            @unlink($this->evidenceDirectory . DIRECTORY_SEPARATOR . $filename);
            error_log('Evidence save failed: ' . $exception->getMessage());
            $this->response->send_json_error('The evidence could not be saved.', 500);
            return;
        }
        $this->response->send_json_success($saved, 'Evidence attached.', 201);
    }

    /** GET /api/assessments/evidence/{id}: served from private storage to administrators. */
    public function evidence($id)
    {
        $row  = $this->valid_id($id) ? $this->VehicleAssessmentModel->evidence($id) : null;
        $path = $row ? $this->evidenceDirectory . DIRECTORY_SEPARATOR . basename($row['filename']) : null;
        if (!$row || !is_file($path)) {
            $this->response->send_json_error('Evidence not found.', 404);
            return;
        }
        $this->response->set_status_code(200);
        $this->response->content_type(mime_content_type($path), null);
        $this->response->add_header('Content-Length', filesize($path));
        $this->response->add_header('Cache-Control', 'private, no-store, max-age=0');
        $this->response->send_headers();
        readfile($path);
    }

    /** POST /api/assessments/{id}/categories/{key}/outcomes */
    public function createOutcome($id, $key)
    {
        $row = $this->find_assessment($id);
        if (!$row) {
            return;
        }
        $category = ComponentMaintenanceRules::assessment_catalog()['by_category'][$key] ?? null;
        if (!$category) {
            $this->response->send_json_error('Assessment category not found.', 404);
            return;
        }
        try {
            $input     = $this->input();
            $outcome   = ComponentMaintenanceRules::normalize_outcome($input, new DateTimeImmutable('today'));
            $component = $input['component_key'] ?? null;
            if ($component !== null && $component !== '' && !in_array($component, $category['component_keys'], true)) {
                throw new InvalidArgumentException('That component does not belong to this category.');
            }
            $saved = $this->VehicleAssessmentModel->record_outcome($row, $key, $component ?: null, $outcome, $this->user['id']);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (DomainException $exception) {
            $this->response->send_json_error($exception->getMessage(), 409);
            return;
        } catch (Exception $exception) {
            error_log('Outcome save failed: ' . $exception->getMessage());
            $this->response->send_json_error('The outcome could not be saved.', 500);
            return;
        }
        $this->response->send_json_success($saved, 'Outcome recorded. It stays unverified until it is reviewed.', 201);
    }

    /** POST /api/assessments/outcomes/{id}/verify */
    public function verifyOutcome($id)
    {
        $outcome = $this->valid_id($id) ? $this->VehicleAssessmentModel->outcome($id) : null;
        if (!$outcome) {
            $this->response->send_json_error('Outcome not found.', 404);
            return;
        }
        if ($outcome['verification_status'] !== 'unverified') {
            $this->response->send_json_error('This outcome has already been reviewed.', 409);
            return;
        }
        try {
            $status = $this->input()['status'] ?? null;
            if (!in_array($status, ['verified', 'rejected'], true)) {
                throw new InvalidArgumentException('Status must be verified or rejected.');
            }
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        }
        $this->response->send_json_success($this->VehicleAssessmentModel->verify_outcome($outcome['id'], $status, $this->user['id']), 'Outcome reviewed.');
    }

    /** GET /api/assessments/training/overview: data sufficiency, models and runs. */
    public function trainingOverview()
    {
        $models = AiServiceClient::request('GET', AiServiceClient::endpoint('training/models'));
        $this->response->send_json_success([
            'sufficiency'   => $this->readiness(),
            'policy'        => ComponentMaintenanceRules::assessment_catalog()['training_policy'],
            'runs'          => $this->VehicleAssessmentModel->training_runs(),
            'service'       => ['reachable' => is_array($models) && ($models['status'] ?? null) === 'ok'],
            'models'        => is_array($models) ? ($models['categories'] ?? []) : [],
        ], 'Training overview retrieved successfully.');
    }

    /** GET /api/assessments/training/{category}/dataset: the export for one category. */
    public function trainingDataset($category)
    {
        if (!$this->valid_category($category)) {
            return;
        }
        $this->response->send_json_success($this->VehicleAssessmentModel->training_dataset($category), 'Training dataset retrieved successfully.');
    }

    /** POST /api/assessments/training/{category}/train: refused unless this category qualifies. */
    public function train($category)
    {
        if (!$this->valid_category($category)) {
            return;
        }
        $dataset = $this->VehicleAssessmentModel->training_dataset($category);
        if ($dataset['summary']['status'] !== 'sufficient') {
            $this->response->send_json_error('This category does not have enough eligible data to train.', 409, ['blockers' => $dataset['summary']['blockers']]);
            return;
        }
        $claim = $this->VehicleAssessmentModel->claim_training_run($category, $dataset['summary'], $this->user['id']);
        if (is_string($claim)) {
            $this->response->send_json_error($claim, 409);
            return;
        }

        // PHP's limit counts wall-clock time on Windows, which includes waiting for the AI service.
        set_time_limit(self::TRAINING_TIMEOUT_SECONDS + 30);
        $response = AiServiceClient::request('POST', AiServiceClient::endpoint('training/train'), [
            'category'     => $category,
            'requested_by' => (int) $this->user['id'],
            'rows'         => $dataset['rows'],
        ], self::TRAINING_TIMEOUT_SECONDS);

        if (!is_array($response) || ($response['status'] ?? null) !== 'success') {
            $reason = is_array($response) && is_string($response['error'] ?? null) ? $response['error'] : 'The AI service could not be reached.';
            $this->VehicleAssessmentModel->finish_training_run($claim['id'], ['status' => 'failed', 'error_message' => mb_substr($reason, 0, 255)]);
            $this->response->send_json_error($reason, 502);
            return;
        }
        $this->VehicleAssessmentModel->finish_training_run($claim['id'], [
            'status'            => 'completed',
            'model_version'     => $response['model']['model_version'] ?? null,
            'evaluation_status' => $response['model']['status'] ?? null,
        ]);
        $this->response->send_json_success($response['model'], 'Training finished.');
    }

    /** POST /api/assessments/training/{category}/accept: an administrator's review of a passed model. */
    public function acceptModel($category)
    {
        if ($this->valid_category($category)) {
            $this->forward_registry_change('training/promote', ['category' => $category, 'version' => (string) ($this->input()['version'] ?? ''), 'by' => (int) $this->user['id']], 'Model accepted.');
        }
    }

    /** POST /api/assessments/training/{category}/revoke: withdraws the active model. */
    public function revokeModel($category)
    {
        if ($this->valid_category($category)) {
            $this->forward_registry_change('training/revoke', ['category' => $category, 'reason' => trim((string) ($this->input()['reason'] ?? '')), 'by' => (int) $this->user['id']], 'Model withdrawn.');
        }
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /**
     * Runs one analysis attempt. Returns NULL when an attempt ran (it may have
     * failed; that is recorded), or a message when the claim was refused.
     * The submitted assessment is never altered by the outcome.
     */
    private function run_analysis($assessment_id, array $vehicle)
    {
        $claim = $this->VehicleAssessmentModel->claim_analysis($assessment_id);
        if (is_string($claim)) {
            return $claim;
        }

        try {
            $payload  = $this->VehicleAssessmentModel->ai_payload($assessment_id, $vehicle);
            $response = AiServiceClient::request('POST', AiServiceClient::endpoint('assessment/analyze'), $payload, self::AI_TIMEOUT_SECONDS);

            if (!is_array($response)) {
                $this->VehicleAssessmentModel->record_analysis_failure($assessment_id, $claim['id'], 'The AI service could not be reached.');
            } elseif (($response['status'] ?? null) !== 'success' || !is_array($response['components'] ?? null) || (int) ($response['assessment_id'] ?? 0) !== (int) $assessment_id) {
                $reason = is_string($response['error'] ?? null) ? $response['error'] : 'The AI service returned an unusable response.';
                $this->VehicleAssessmentModel->record_analysis_failure($assessment_id, $claim['id'], $reason);
            } else {
                $this->VehicleAssessmentModel->record_analysis_success($assessment_id, $claim['id'], $response);
            }
        } catch (Exception $exception) {
            error_log('Assessment analysis failed: ' . $exception->getMessage());
            $this->VehicleAssessmentModel->record_analysis_failure($assessment_id, $claim['id'], 'The analysis result could not be saved.');
        }
        return null;
    }

    /** Each category's eligibility, judged independently. */
    private function readiness()
    {
        $grouped = [];
        foreach ($this->VehicleAssessmentModel->training_records() as $record) {
            $grouped[$record['category_key']][] = $record;
        }
        $categories = [];
        $vehicles   = [];
        $totals     = ['verified_outcomes' => 0, 'eligible' => 0, 'faults' => 0, 'healthy' => 0];
        foreach (ComponentMaintenanceRules::assessment_catalog()['categories'] as $category) {
            $result  = ComponentMaintenanceRules::evaluate_training_records($grouped[$category['key']] ?? []);
            $summary = $result['summary'];
            foreach ($result['eligible'] as $record) {
                $vehicles[$record['vehicle_id']] = true;
            }
            foreach (array_keys($totals) as $field) {
                $totals[$field] += $summary[$field];
            }
            $categories[] = ['key' => $category['key'], 'name' => $category['name'], 'safety_critical' => $category['safety_critical']] + $summary;
        }
        return ['categories' => $categories, 'totals' => $totals + ['vehicles' => count($vehicles)]];
    }

    private function interval_days()
    {
        return (int) ComponentMaintenanceRules::assessment_catalog()['reassessment_interval_days'];
    }

    private function valid_category($category)
    {
        if (isset(ComponentMaintenanceRules::assessment_catalog()['by_category'][$category])) {
            return true;
        }
        $this->response->send_json_error('Assessment category not found.', 404);
        return false;
    }

    private function forward_registry_change($path, array $body, $message)
    {
        $response = AiServiceClient::request('POST', AiServiceClient::endpoint($path), $body);
        if (!is_array($response)) {
            $this->response->send_json_error('The AI service could not be reached.', 502);
        } elseif (($response['status'] ?? null) !== 'success') {
            $this->response->send_json_error($response['error'] ?? 'The model registry refused this change.', 409);
        } else {
            $this->response->send_json_success($response['model'], $message);
        }
    }

    private function valid_id($id)
    {
        return (bool) filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private function find_vehicle($id)
    {
        $vehicle = $this->valid_id($id) ? $this->VehicleAssessmentModel->vehicle($id) : null;
        if (!$vehicle) {
            $this->response->send_json_error('Vehicle not found.', 404);
            return null;
        }
        return $vehicle;
    }

    private function find_assessment($id)
    {
        $row = $this->valid_id($id) ? $this->VehicleAssessmentModel->assessment_row($id) : null;
        if (!$row) {
            $this->response->send_json_error('Assessment not found.', 404);
            return null;
        }
        return $row;
    }

    private function input()
    {
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();
        if (!is_array($input)) {
            throw new InvalidArgumentException('Request body must be a JSON object.');
        }
        return $input;
    }
}
