<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries/MaintenanceDataPolicy.php';

class MaintenanceDataController extends Controller
{
    private $actor_id;

    public function __construct()
    {
        parent::__construct();
        $this->security_headers->apply_api();
        $this->call->model(['MaintenanceDataModel', 'UserModel']);
        $user = $this->UserModel->find((int) $this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->response->send_json_error('Authentication is required.', 401);
            exit;
        }
        if (!MaintenanceDataPolicy::can_access($user)) {
            $this->response->send_json_error('Administrator access is required.', 403);
            exit;
        }
        $this->actor_id = (int) $user['id'];
    }

    public function records()
    {
        try {
            $filters = $this->request->get();
            foreach (['vehicle_id', 'page', 'limit'] as $field) {
                if (isset($filters[$field]) && (!is_scalar($filters[$field])
                    || ($filters[$field] !== '' && filter_var($filters[$field], FILTER_VALIDATE_INT) === false))) {
                    throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' must be a whole number.');
                }
            }
            foreach (['search', 'component'] as $field) {
                if (isset($filters[$field]) && (!is_string($filters[$field]) || strlen($filters[$field]) > 200)) {
                    throw new InvalidArgumentException(ucfirst($field) . ' filter must be 200 characters or fewer.');
                }
            }
            if (!empty($filters['record_type']) && !in_array($filters['record_type'], MaintenanceDataValidation::RECORD_TYPES, true)) {
                throw new InvalidArgumentException('Unknown maintenance record type filter.');
            }
            if (!empty($filters['verification_status']) && !in_array($filters['verification_status'], MaintenanceDataValidation::STATUSES, true)) {
                throw new InvalidArgumentException('Unknown verification status filter.');
            }
            foreach (['date_from', 'date_to'] as $field) {
                if (!empty($filters[$field]) && !$this->is_date($filters[$field])) {
                    throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' must be YYYY-MM-DD.');
                }
            }
            if (!empty($filters['date_from']) && !empty($filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
                throw new InvalidArgumentException('Start date must not be after end date.');
            }
            if (!empty($filters['vehicle_id']) && (int) $filters['vehicle_id'] < 1) {
                throw new InvalidArgumentException('Vehicle ID must be positive.');
            }
            if (isset($filters['page']) && (int) $filters['page'] < 1) {
                throw new InvalidArgumentException('Page must be positive.');
            }
            if (isset($filters['limit']) && ((int) $filters['limit'] < 1 || (int) $filters['limit'] > 100)) {
                throw new InvalidArgumentException('Page size must be between 1 and 100.');
            }
            $this->response->send_json_success($this->MaintenanceDataModel->list_records($filters));
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
        } catch (Exception $exception) {
            error_log('Maintenance data list failed: ' . $exception->getMessage());
            $this->response->send_json_error('Maintenance records could not be loaded.', 500);
        }
    }

    public function detail($id)
    {
        $record_id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$record_id) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }
        try {
            $detail = $this->MaintenanceDataModel->detail($record_id);
        } catch (Exception $exception) {
            error_log('Maintenance data detail failed: ' . $exception->getMessage());
            $this->response->send_json_error('Maintenance record details could not be loaded.', 500);
            return;
        }
        if (!$detail) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }
        $this->response->send_json_success($detail);
    }

    public function create()
    {
        try {
            $record = MaintenanceDataValidation::normalize_record($this->input(), true);
            $key = $this->request->header('Idempotency-Key');
            if ($key !== null && $key !== '') {
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
                    throw new InvalidArgumentException('Idempotency-Key must be a UUID.');
                }
            } else {
                $key = null;
            }
            $saved = $this->MaintenanceDataModel->create_record($record, $this->actor_id, $key);
            $this->response->send_json_success($saved, 'Maintenance evidence record saved as a draft.', 201);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
        } catch (Exception $exception) {
            error_log('Maintenance data creation failed: ' . $exception->getMessage());
            $this->response->send_json_error('The maintenance evidence record could not be saved.', 500);
        }
    }

    public function update($id)
    {
        $record_id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$record_id) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }
        try {
            $input = MaintenanceDataValidation::normalize_record($this->input());
            $record = $this->MaintenanceDataModel->update_draft($record_id, $input, $this->actor_id);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Maintenance data update failed: ' . $exception->getMessage());
            $this->response->send_json_error('The maintenance evidence record could not be updated.', 500);
            return;
        }
        if (!$record) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }
        $this->response->send_json_success($record, 'Draft record updated.');
    }

    public function submit($id)
    {
        $this->run_transition($id, function ($record_id) {
            return $this->MaintenanceDataModel->submit_for_verification($record_id, $this->actor_id);
        }, 'Record submitted for verification.');
    }

    public function verify($id)
    {
        try {
            $input = $this->input();
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        }
        $status = $input['verification_status'] ?? '';
        $reason = $input['reason'] ?? '';
        if (!is_string($status) || !is_string($reason)) {
            $this->response->send_json_error('Verification status and reason must be text values.', 422);
            return;
        }
        $this->run_transition($id, function ($record_id) use ($input) {
            return $this->MaintenanceDataModel->verify_record(
                $record_id,
                $this->actor_id,
                $input['verification_status'] ?? '',
                $input['reason'] ?? ''
            );
        }, 'Verification status updated.');
    }

    public function supersede($id)
    {
        try {
            $input = $this->input();
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        }
        $replacement_id = filter_var(
            $input['replacement_record_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if (!$replacement_id) {
            $this->response->send_json_error('A verified replacement record is required.', 422);
            return;
        }
        $reason = $input['reason'] ?? '';
        if (!is_string($reason)) {
            $this->response->send_json_error('Supersession reason must be text.', 422);
            return;
        }
        $this->run_transition($id, function ($record_id) use ($replacement_id, $input) {
            return $this->MaintenanceDataModel->supersede_record(
                $record_id,
                $replacement_id,
                $this->actor_id,
                $input['reason'] ?? ''
            );
        }, 'Record superseded by the verified correction.');
    }

    public function export()
    {
        $format = strtolower((string) ($this->request->get('format') ?? 'json'));
        if (!in_array($format, ['json', 'csv'], true)) {
            $this->response->send_json_error('Export format must be json or csv.', 422);
            return;
        }
        try {
            $rows = $this->MaintenanceDataModel->verified_export();
            if ($format === 'json') {
                $this->response->send_json_success([
                    'schema_version' => '1.0',
                    'label_policy' => 'Only verified records are exported. confirmed_fault_label is true only for verified mechanic_confirmed_fault records with an explicit confirmed cause; otherwise it is null. Suspected causes, DTCs, and sensor values are not fault labels.',
                    'records' => $rows,
                ], 'Verified maintenance data exported.');
                return;
            }
            $fields = [
                'id', 'vehicle_id', 'make', 'model', 'year', 'category', 'engine_details',
                'record_type', 'recorded_at', 'odometer_reading', 'odometer_unit', 'component',
                'component_identifier',
                'symptoms', 'inspection_findings', 'dtc_codes', 'sensor_measurements',
                'suspected_cause', 'confirmed_cause', 'confirmed_fault_label',
                'diagnostic_tests', 'repair_action', 'replaced_components', 'repair_outcome',
                'source', 'verification_status',
                'verified_at', 'created_at',
            ];
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, $fields);
            foreach ($rows as $row) {
                $values = [];
                foreach ($fields as $field) {
                    $value = $row[$field] ?? null;
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                    $value = $value === null ? '' : (string) $value;
                    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                        $value = "'" . $value;
                    }
                    $values[] = $value;
                }
                fputcsv($stream, $values);
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            $this->response
                ->add_header('Content-Type', 'text/csv; charset=utf-8')
                ->add_header('Content-Disposition', 'attachment; filename="verified-maintenance-data.csv"')
                ->set_content($csv)
                ->send();
        } catch (Exception $exception) {
            error_log('Maintenance data export failed: ' . $exception->getMessage());
            $this->response->send_json_error('Verified maintenance data could not be exported.', 500);
        }
    }

    private function run_transition($id, callable $callback, $message)
    {
        $record_id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$record_id) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }
        try {
            $result = $callback($record_id);
        } catch (InvalidArgumentException $exception) {
            $this->response->send_json_error($exception->getMessage(), 422);
            return;
        } catch (Exception $exception) {
            error_log('Maintenance data status change failed: ' . $exception->getMessage());
            $this->response->send_json_error('The maintenance record status could not be changed.', 500);
            return;
        }
        if (!$result) {
            $this->response->send_json_error('Maintenance record not found.', 404);
            return;
        }
        $this->response->send_json_success($result, $message);
    }

    private function input()
    {
        $input = $this->request->is_json() ? $this->request->json() : $this->request->post();
        if (!is_array($input)) {
            throw new InvalidArgumentException('Request body must be a JSON object or form submission.');
        }
        return $input;
    }

    private function is_date($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }
}
