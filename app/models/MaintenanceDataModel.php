<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries/MaintenanceDataValidation.php';
require_once APP_DIR . 'libraries/MaintenanceDataPolicy.php';

class MaintenanceDataModel extends Model
{
    private const TABLE = 'maintenance_data_records';
    private const AUDIT_TABLE = 'maintenance_data_audit';
    private const MUTABLE_FIELDS = [
        'recorded_at', 'odometer_reading', 'odometer_unit', 'component', 'component_identifier', 'symptoms',
        'inspection_findings', 'dtc_codes', 'sensor_measurements',
        'suspected_cause', 'confirmed_cause', 'diagnostic_tests',
        'repair_action', 'replaced_components', 'repair_outcome',
        'technician_reference', 'source', 'evidence_reference', 'engine_details',
    ];

    protected $table = self::TABLE;
    protected $primary_key = 'id';
    protected $fillable = [];
    protected $timestamps = false;

    public function list_records(array $filters)
    {
        $where = [];
        $bindings = [];
        foreach ([
            'vehicle_id' => 'r.vehicle_id',
            'record_type' => 'r.record_type',
            'verification_status' => 'r.verification_status',
        ] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = "{$column} = ?";
                $bindings[] = $filters[$key];
            }
        }
        if (($filters['component'] ?? '') !== '') {
            $where[] = 'r.component LIKE ?';
            $bindings[] = '%' . $filters['component'] . '%';
        }
        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'r.recorded_at >= ?';
            $bindings[] = $filters['date_from'] . ' 00:00:00';
        }
        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'r.recorded_at <= ?';
            $bindings[] = $filters['date_to'] . ' 23:59:59';
        }
        if (($filters['search'] ?? '') !== '') {
            $where[] = '(r.symptoms LIKE ? OR r.inspection_findings LIKE ? OR r.dtc_codes LIKE ? OR r.component LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR c.plate_number LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            array_push($bindings, $search, $search, $search, $search, $search, $search, $search);
        }

        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = (int) $this->db->raw(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' r INNER JOIN cars c ON c.id = r.vehicle_id' . $where_sql,
            $bindings
        )->fetchColumn();
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $rows = $this->db->raw(
            'SELECT r.*, r.vehicle_make AS make, r.vehicle_model AS model,
                    r.vehicle_year AS year, r.vehicle_type AS category, c.plate_number
             FROM ' . self::TABLE . ' r INNER JOIN cars c ON c.id = r.vehicle_id'
            . $where_sql . ' ORDER BY r.recorded_at DESC, r.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $bindings
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'records' => array_map([$this, 'decode_record'], $rows),
            'meta' => [
                'total' => $count,
                'page' => $page,
                'per_page' => $limit,
                'total_pages' => max(1, (int) ceil($count / $limit)),
            ],
        ];
    }

    public function find_record($id)
    {
        $row = $this->db->raw(
            'SELECT r.*, r.vehicle_make AS make, r.vehicle_model AS model,
                    r.vehicle_year AS year, r.vehicle_type AS category, c.plate_number,
                    creator.username AS created_by_username,
                    verifier.username AS verified_by_username
             FROM ' . self::TABLE . ' r
             INNER JOIN cars c ON c.id = r.vehicle_id
             LEFT JOIN users creator ON creator.id = r.created_by
             LEFT JOIN users verifier ON verifier.id = r.verified_by
             WHERE r.id = ? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode_record($row) : null;
    }

    public function detail($id)
    {
        $record = $this->find_record($id);
        if (!$record) {
            return null;
        }
        $events = $this->db->raw(
            'SELECT a.*, u.username AS actor_username
             FROM ' . self::AUDIT_TABLE . ' a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.maintenance_record_id = ?
             ORDER BY a.created_at ASC, a.id ASC',
            [(int) $id]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as &$event) {
            $event['before_snapshot'] = $event['before_snapshot'] === null
                ? null : json_decode($event['before_snapshot'], true);
            $event['after_snapshot'] = $event['after_snapshot'] === null
                ? null : json_decode($event['after_snapshot'], true);
        }
        unset($event);
        return ['record' => $record, 'history' => $events];
    }

    public function create_record(array $data, $user_id, $idempotency_key = null)
    {
        $idempotency_hash = hash(
            'sha256',
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        if ($idempotency_key !== null) {
            $existing = $this->db->table(self::TABLE)
                ->where('idempotency_key', $idempotency_key)
                ->limit(1)
                ->get();
            if ($existing) {
                if (!hash_equals((string) $existing['idempotency_hash'], $idempotency_hash)) {
                    throw new InvalidArgumentException('This Idempotency-Key was already used with different record contents. Review the existing record before creating another.');
                }
                return $this->find_record((int) $existing['id']);
            }
        }
        $vehicle = $this->db->table('cars')->where('id', $data['vehicle_id'])->limit(1)->get();
        if (!$vehicle) {
            throw new InvalidArgumentException('Vehicle not found.');
        }
        if ($data['record_type'] === 'mechanic_confirmed_fault' && empty($data['confirmed_cause'])) {
            throw new InvalidArgumentException('A mechanic-confirmed fault must include its confirmed cause.');
        }

        $data['vehicle_type'] = $vehicle['category'] ?? null;
        $data['vehicle_make'] = $vehicle['make'] ?? null;
        $data['vehicle_model'] = $vehicle['model'] ?? null;
        $data['vehicle_year'] = isset($vehicle['year']) ? (int) $vehicle['year'] : null;
        $record = MaintenanceDataValidation::encode_json_fields($data);
        $record['verification_status'] = 'draft';
        $record['created_by'] = (int) $user_id;
        $record['idempotency_key'] = $idempotency_key;
        $record['idempotency_hash'] = $idempotency_key === null ? null : $idempotency_hash;
        $record['created_at'] = gmdate('Y-m-d H:i:s');

        $this->db->transaction();
        try {
            $id = $this->db->table(self::TABLE)->insert($record);
            $saved = $this->find_record($id);
            $this->audit($id, $user_id, 'created', null, 'draft', null, null, $saved);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            if ($idempotency_key !== null) {
                $existing = $this->db->table(self::TABLE)
                    ->where('idempotency_key', $idempotency_key)
                    ->limit(1)
                    ->get();
                if ($existing) {
                    if (!hash_equals((string) $existing['idempotency_hash'], $idempotency_hash)) {
                        throw new InvalidArgumentException('This Idempotency-Key was already used with different record contents. Review the existing record before creating another.');
                    }
                    return $this->find_record((int) $existing['id']);
                }
            }
            throw $exception;
        }
        return $this->find_record($id);
    }

    public function update_draft($id, array $input, $user_id)
    {
        $before = $this->find_record($id);
        if (!$before) {
            return null;
        }
        if ($before['verification_status'] !== 'draft') {
            throw new InvalidArgumentException('Only draft records can be edited. Create a corrected record and supersede the verified record instead.');
        }
        $update = array_intersect_key($input, array_flip(self::MUTABLE_FIELDS));
        if (!$update) {
            throw new InvalidArgumentException('Provide at least one supported record field to update.');
        }
        if ($before['record_type'] === 'mechanic_confirmed_fault') {
            $cause = $update['confirmed_cause'] ?? $before['confirmed_cause'];
            if (!$cause) {
                throw new InvalidArgumentException('A mechanic-confirmed fault must include its confirmed cause.');
            }
        } elseif (!empty($update['confirmed_cause'])) {
            throw new InvalidArgumentException('A confirmed cause is only valid for a mechanic-confirmed fault record.');
        }
        $changed = false;
        foreach ($update as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return $before;
        }
        $update = MaintenanceDataValidation::encode_json_fields($update);
        $after = array_merge($before, $update);
        $this->db->transaction();
        try {
            $updated = $this->db->table(self::TABLE)
                ->where('id', (int) $id)
                ->where('verification_status', 'draft')
                ->update($update + ['updated_at' => gmdate('Y-m-d H:i:s')]);
            if (!$updated) {
                throw new InvalidArgumentException('The draft changed before it could be saved. Reload the record and try again.');
            }
            $this->audit($id, $user_id, 'edited', 'draft', 'draft', null, $before, $after);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
        return $this->find_record($id);
    }

    public function submit_for_verification($id, $user_id)
    {
        MaintenanceDataPolicy::assert_transition('draft', 'awaiting_verification');
        return $this->transition($id, $user_id, 'draft', 'awaiting_verification', null);
    }

    public function verify_record($id, $user_id, $status, $reason)
    {
        if (!in_array($status, ['verified', 'rejected'], true)) {
            throw new InvalidArgumentException('Verification status must be verified or rejected.');
        }
        MaintenanceDataPolicy::assert_transition('awaiting_verification', $status);
        if ($status === 'rejected' && trim((string) $reason) === '') {
            throw new InvalidArgumentException('A reason is required when rejecting a record.');
        }
        $before = $this->find_record($id);
        if (!$before) {
            return null;
        }
        if ($status === 'verified'
            && $before['record_type'] === 'mechanic_confirmed_fault'
            && empty($before['confirmed_cause'])) {
            throw new InvalidArgumentException('A confirmed fault requires an explicit confirmed cause.');
        }
        if ($before['verification_status'] !== 'awaiting_verification') {
            throw new InvalidArgumentException('Only records awaiting verification can be verified or rejected.');
        }
        if (!MaintenanceDataPolicy::can_verify($before, $user_id)) {
            throw new InvalidArgumentException('A different administrator must verify or reject the submitted record.');
        }

        $this->db->transaction();
        try {
            $changes = ['verification_status' => $status];
            if ($status === 'verified') {
                $changes['verified_by'] = (int) $user_id;
                $changes['verified_at'] = gmdate('Y-m-d H:i:s');
            }
            $updated = $this->db->table(self::TABLE)
                ->where('id', (int) $id)
                ->where('verification_status', 'awaiting_verification')
                ->update($changes);
            if (!$updated) {
                throw new InvalidArgumentException('The record changed before verification. Reload it and try again.');
            }
            $after = array_merge($before, $changes);
            $this->audit($id, $user_id, $status, 'awaiting_verification', $status, $reason, $before, $after);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
        return $this->find_record($id);
    }

    public function supersede_record($id, $replacement_id, $user_id, $reason)
    {
        if ((int) $id === (int) $replacement_id || trim((string) $reason) === '') {
            throw new InvalidArgumentException('Choose a different verified replacement record and provide a reason.');
        }
        $original = $this->find_record($id);
        $replacement = $this->find_record($replacement_id);
        if (!$original || !$replacement) {
            return null;
        }
        if ($original['verification_status'] !== 'verified' || $replacement['verification_status'] !== 'verified') {
            throw new InvalidArgumentException('Both the original and its replacement must be verified.');
        }
        MaintenanceDataPolicy::assert_transition('verified', 'superseded');
        if ((int) $original['vehicle_id'] !== (int) $replacement['vehicle_id']) {
            throw new InvalidArgumentException('A replacement record must refer to the same vehicle.');
        }

        $this->db->transaction();
        try {
            $updated = $this->db->table(self::TABLE)
                ->where('id', (int) $id)
                ->where('verification_status', 'verified')
                ->update([
                    'verification_status' => 'superseded',
                    'superseded_by_id' => (int) $replacement_id,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            if (!$updated) {
                throw new InvalidArgumentException('The record changed before it could be superseded. Reload and try again.');
            }
            $after = array_merge($original, [
                'verification_status' => 'superseded',
                'superseded_by_id' => (int) $replacement_id,
            ]);
            $this->audit($id, $user_id, 'superseded', 'verified', 'superseded', $reason, $original, $after);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
        return $this->detail($id);
    }

    public function verified_export()
    {
        $rows = $this->db->raw(
            'SELECT r.id, r.vehicle_id, r.vehicle_make AS make, r.vehicle_model AS model,
                    r.vehicle_year AS year, r.vehicle_type AS category, r.engine_details,
                    r.record_type, r.recorded_at, r.odometer_reading, r.odometer_unit, r.component,
                    r.component_identifier,
                    r.symptoms, r.inspection_findings, r.dtc_codes,
                    r.sensor_measurements, r.suspected_cause, r.confirmed_cause,
                    r.diagnostic_tests, r.repair_action, r.replaced_components,
                    r.repair_outcome, r.source,
                    r.verification_status, r.verified_at, r.created_at
             FROM ' . self::TABLE . ' r
             INNER JOIN cars c ON c.id = r.vehicle_id
             WHERE r.verification_status = ?
             ORDER BY r.recorded_at ASC, r.id ASC',
            ['verified']
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row = $this->decode_record($row);
            $row['confirmed_fault_label'] = MaintenanceDataPolicy::confirmed_fault_label($row)
                ? true : null;
        }
        unset($row);
        return $rows;
    }

    private function transition($id, $user_id, $from, $to, $reason)
    {
        MaintenanceDataPolicy::assert_transition($from, $to);
        $before = $this->find_record($id);
        if (!$before) {
            return null;
        }
        if ($before['verification_status'] !== $from) {
            throw new InvalidArgumentException("Only {$from} records can move to {$to}.");
        }
        $this->db->transaction();
        try {
            $updated = $this->db->table(self::TABLE)
                ->where('id', (int) $id)
                ->where('verification_status', $from)
                ->update([
                    'verification_status' => $to,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            if (!$updated) {
                throw new InvalidArgumentException('The record changed before the status update. Reload it and try again.');
            }
            $after = array_merge($before, ['verification_status' => $to]);
            $this->audit($id, $user_id, $to, $from, $to, $reason, $before, $after);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
        return $this->find_record($id);
    }

    private function audit($record_id, $actor_id, $event, $from, $to, $reason, $before, $after)
    {
        $this->db->table(self::AUDIT_TABLE)->insert([
            'maintenance_record_id' => (int) $record_id,
            'actor_user_id' => (int) $actor_id,
            'event_type' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'before_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'after_snapshot' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function decode_record(array $record)
    {
        unset($record['idempotency_key'], $record['idempotency_hash']);
        foreach (['dtc_codes', 'sensor_measurements', 'replaced_components'] as $field) {
            $record[$field] = empty($record[$field]) ? [] : json_decode($record[$field], true);
            if (!is_array($record[$field])) {
                $record[$field] = [];
            }
        }
        return $record;
    }
}
