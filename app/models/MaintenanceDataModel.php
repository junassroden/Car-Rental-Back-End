<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

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

    // The query builder accepts no "r.*", and idempotency_key/idempotency_hash must stay out of responses.
    private const RECORD_COLUMNS = 'r.id, r.vehicle_id, r.vehicle_type, r.vehicle_make, r.vehicle_model, r.vehicle_year, r.engine_details, r.record_type, r.recorded_at, r.odometer_reading, r.odometer_unit, r.component, r.component_identifier, r.symptoms, r.inspection_findings, r.dtc_codes, r.sensor_measurements, r.suspected_cause, r.confirmed_cause, r.diagnostic_tests, r.repair_action, r.replaced_components, r.repair_outcome, r.technician_reference, r.source, r.evidence_reference, r.verification_status, r.created_by, r.verified_by, r.verified_at, r.superseded_by_id, r.created_at, r.updated_at, r.vehicle_make AS make, r.vehicle_model AS model, r.vehicle_year AS year, r.vehicle_type AS category, c.plate_number';

    protected $table = self::TABLE;
    protected $primary_key = 'id';
    protected $fillable = [];
    protected $timestamps = false;

    public function __construct()
    {
        parent::__construct();
        $this->call->library(['MaintenanceDataValidation', 'MaintenanceDataPolicy']);
    }

    public function list_records(array $filters)
    {
        $apply = function () use ($filters) {
            $query = $this->records_query();
            foreach ([
                'vehicle_id' => 'r.vehicle_id',
                'record_type' => 'r.record_type',
                'verification_status' => 'r.verification_status',
            ] as $key => $column) {
                if (($filters[$key] ?? '') !== '') {
                    $query->where($column, $filters[$key]);
                }
            }
            if (($filters['component'] ?? '') !== '') {
                $query->like('r.component', '%' . $filters['component'] . '%');
            }
            if (($filters['date_from'] ?? '') !== '') {
                $query->where('r.recorded_at', '>=', $filters['date_from'] . ' 00:00:00');
            }
            if (($filters['date_to'] ?? '') !== '') {
                $query->where('r.recorded_at', '<=', $filters['date_to'] . ' 23:59:59');
            }
            if (($filters['search'] ?? '') !== '') {
                $search = '%' . $filters['search'] . '%';
                $query->grouped(static function ($q) use ($search) {
                    $q->like('r.symptoms', $search)
                      ->or_like('r.inspection_findings', $search)
                      ->or_like('r.dtc_codes', $search)
                      ->or_like('r.component', $search)
                      ->or_like('c.make', $search)
                      ->or_like('c.model', $search)
                      ->or_like('c.plate_number', $search);
                });
            }
            return $query;
        };

        // The builder's count() ignores joins, and the filters can use the joined cars columns.
        $count = (int) $apply()->select('COUNT(*) AS total')->get()['total'];
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $apply()
            ->select(self::RECORD_COLUMNS)
            ->order_by('r.recorded_at', 'DESC')
            ->order_by('r.id', 'DESC')
            ->pagination($limit, $page)
            ->get_all();

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
        $row = $this->records_query()
            ->select(self::RECORD_COLUMNS . ', creator.username AS created_by_username, verifier.username AS verified_by_username')
            ->left_join('users creator', 'creator.id = r.created_by')
            ->left_join('users verifier', 'verifier.id = r.verified_by')
            ->where('r.id', (int) $id)
            ->limit(1)
            ->get();
        return $row ? $this->decode_record($row) : null;
    }

    public function detail($id)
    {
        $record = $this->find_record($id);
        if (!$record) {
            return null;
        }
        $events = $this->db->table(self::AUDIT_TABLE . ' a')
            ->select('a.id, a.maintenance_record_id, a.actor_user_id, a.event_type, a.from_status, a.to_status, a.reason, a.before_snapshot, a.after_snapshot, a.created_at, u.username AS actor_username')
            ->left_join('users u', 'u.id = a.actor_user_id')
            ->where('a.maintenance_record_id', (int) $id)
            ->order_by('a.created_at', 'ASC')
            ->order_by('a.id', 'ASC')
            ->get_all();
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
        $replayed = $this->idempotent_replay($idempotency_key, $idempotency_hash);
        if ($replayed) {
            return $replayed;
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
            // A parallel request with the same key may have won the unique index.
            $replayed = $this->idempotent_replay($idempotency_key, $idempotency_hash);
            if ($replayed) {
                return $replayed;
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
        $rows = $this->records_query()
            ->select('r.id, r.vehicle_id, r.vehicle_make AS make, r.vehicle_model AS model, r.vehicle_year AS year, r.vehicle_type AS category, r.engine_details, r.record_type, r.recorded_at, r.odometer_reading, r.odometer_unit, r.component, r.component_identifier, r.symptoms, r.inspection_findings, r.dtc_codes, r.sensor_measurements, r.suspected_cause, r.confirmed_cause, r.diagnostic_tests, r.repair_action, r.replaced_components, r.repair_outcome, r.source, r.verification_status, r.verified_at, r.created_at')
            ->where('r.verification_status', 'verified')
            ->order_by('r.recorded_at', 'ASC')
            ->order_by('r.id', 'ASC')
            ->get_all();
        foreach ($rows as &$row) {
            $row = $this->decode_record($row);
            $row['confirmed_fault_label'] = MaintenanceDataPolicy::confirmed_fault_label($row)
                ? true : null;
        }
        unset($row);
        return $rows;
    }

    private function records_query()
    {
        return $this->db->table(self::TABLE . ' r')->inner_join('cars c', 'c.id = r.vehicle_id');
    }

    /** The record already saved under this Idempotency-Key, or NULL. Throws when its contents differ. */
    private function idempotent_replay($idempotency_key, $idempotency_hash)
    {
        if ($idempotency_key === null) {
            return null;
        }
        $existing = $this->db->table(self::TABLE)
            ->where('idempotency_key', $idempotency_key)
            ->limit(1)
            ->get();
        if (!$existing) {
            return null;
        }
        if (!hash_equals((string) $existing['idempotency_hash'], $idempotency_hash)) {
            throw new InvalidArgumentException('This Idempotency-Key was already used with different record contents. Review the existing record before creating another.');
        }
        return $this->find_record((int) $existing['id']);
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
        foreach (['dtc_codes', 'sensor_measurements', 'replaced_components'] as $field) {
            $record[$field] = empty($record[$field]) ? [] : json_decode($record[$field], true);
            if (!is_array($record[$field])) {
                $record[$field] = [];
            }
        }
        return $record;
    }
}
