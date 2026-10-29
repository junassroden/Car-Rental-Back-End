<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Vehicle maintenance records and the rule-based service-interval estimates.
 * Tables: tblvehicle_maintenance, tblai_maintenance_predictions
 * (created by database/add_maintenance_support.sql), plus cars.current_mileage.
 */
class MaintenanceModel extends Model
{
    const BASELINE_SERVICE_DAYS       = 180;
    const BASELINE_SERVICE_KILOMETERS = 5000;
    const WARNING_DAYS                = 30;

    const PREDICTIONS_TABLE = 'tblai_maintenance_predictions';

    // Statuses that count as a finished service (compared case-insensitively;
    // the column is ENUM('Scheduled','In Progress','Completed')).
    const DONE_STATUSES = ['completed', 'done', 'recorded'];

    const EDITABLE = [
        'maintenanceType', 'description', 'maintenanceDate', 'cost',
        'maintenanceStatus', 'nextMaintenanceDate', 'odometerReading',
    ];

    protected $table       = 'tblvehicle_maintenance';
    protected $primary_key = 'maintenanceID';
    protected $fillable    = ['vehicleID', 'maintenanceType', 'description', 'maintenanceDate', 'cost', 'maintenanceStatus', 'nextMaintenanceDate', 'odometerReading', 'updated_at'];
    protected $timestamps  = false;

    /* ---------------------------------------------------------------
     * Records
     * ------------------------------------------------------------- */

    /** Maintenance records with their car, newest first; optionally for one car. */
    public function records($vehicle_id = null)
    {
        $query = $this->db->table($this->table)
            ->select('tblvehicle_maintenance.maintenanceID, tblvehicle_maintenance.vehicleID, tblvehicle_maintenance.maintenanceType, tblvehicle_maintenance.description, tblvehicle_maintenance.maintenanceDate, tblvehicle_maintenance.cost, tblvehicle_maintenance.maintenanceStatus, tblvehicle_maintenance.nextMaintenanceDate, tblvehicle_maintenance.odometerReading, tblvehicle_maintenance.created_at, tblvehicle_maintenance.updated_at, cars.make, cars.model, cars.plate_number, cars.current_mileage')
            ->inner_join('cars', 'cars.id = tblvehicle_maintenance.vehicleID');

        if ($vehicle_id !== null) {
            $query->where('tblvehicle_maintenance.vehicleID', (int) $vehicle_id);
        }

        return $query->order_by('tblvehicle_maintenance.maintenanceDate', 'DESC')
            ->order_by('tblvehicle_maintenance.maintenanceID', 'DESC')
            ->get_all();
    }

    /**
     * Bounded, admin-only chat context. Legacy service entries are not
     * presented as verified faults; the newer evidence table is optional
     * until its migration has been applied.
     */
    public function chat_context($vehicle_id = null, $limit = 40)
    {
        $query = $this->db->table($this->table)
            ->select('tblvehicle_maintenance.maintenanceID, tblvehicle_maintenance.vehicleID, tblvehicle_maintenance.maintenanceType, tblvehicle_maintenance.description, tblvehicle_maintenance.maintenanceDate, tblvehicle_maintenance.maintenanceStatus, tblvehicle_maintenance.odometerReading, cars.make, cars.model')
            ->inner_join('cars', 'cars.id = tblvehicle_maintenance.vehicleID');

        if ($vehicle_id !== null) {
            $query->where('tblvehicle_maintenance.vehicleID', (int) $vehicle_id);
        }

        $legacy = $query
            ->order_by('tblvehicle_maintenance.maintenanceDate', 'DESC')
            ->order_by('tblvehicle_maintenance.maintenanceID', 'DESC')
            ->pagination(max(1, min((int) $limit, 40)), 1)
            ->get_all();

        $verifiedAvailable = (bool) $this->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            ['maintenance_data_records']
        )->fetchColumn();
        $verified = [];

        if ($verifiedAvailable) {
            $verifiedQuery = $this->db->table('maintenance_data_records')
                ->select('maintenance_data_records.id, maintenance_data_records.vehicle_id, maintenance_data_records.record_type, maintenance_data_records.recorded_at, maintenance_data_records.component, maintenance_data_records.symptoms, maintenance_data_records.inspection_findings, maintenance_data_records.dtc_codes, maintenance_data_records.confirmed_cause, maintenance_data_records.repair_action, maintenance_data_records.repair_outcome, maintenance_data_records.source, cars.make, cars.model')
                ->inner_join('cars', 'cars.id = maintenance_data_records.vehicle_id')
                ->where('maintenance_data_records.verification_status', 'verified');

            if ($vehicle_id !== null) {
                $verifiedQuery->where('maintenance_data_records.vehicle_id', (int) $vehicle_id);
            }

            $verified = $verifiedQuery
                ->order_by('maintenance_data_records.recorded_at', 'DESC')
                ->order_by('maintenance_data_records.id', 'DESC')
                ->pagination(max(1, min((int) $limit, 40)), 1)
                ->get_all();
        }

        return [
            'legacy_service_records' => array_map(static function ($record) {
                $record['maintenanceType'] = substr((string) $record['maintenanceType'], 0, 80);
                $record['description'] = substr((string) $record['description'], 0, 300);
                return $record;
            }, $legacy),
            'verified_evidence_records' => array_map(static function ($record) {
                foreach (['component', 'symptoms', 'inspection_findings', 'confirmed_cause', 'repair_action', 'repair_outcome'] as $field) {
                    $record[$field] = substr((string) ($record[$field] ?? ''), 0, 300);
                }
                $record['dtc_codes'] = substr((string) ($record['dtc_codes'] ?? ''), 0, 120);
                $record['source'] = substr((string) ($record['source'] ?? ''), 0, 160);
                return $record;
            }, $verified),
            'verified_evidence_table_available' => $verifiedAvailable,
        ];
    }

    public function find_record($id)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->limit(1)
            ->get();
    }

    /** Inserts a record and returns it. Throws InvalidArgumentException for bad input. */
    public function create_record(array $data)
    {
        $record = [
            'vehicleID'           => (int) ($data['vehicleID'] ?? 0),
            'maintenanceType'     => trim((string) ($data['maintenanceType'] ?? 'general-inspection')),
            'description'         => trim((string) ($data['description'] ?? '')),
            'maintenanceDate'     => $data['maintenanceDate'] ?? date('Y-m-d'),
            'cost'                => number_format(isset($data['cost']) ? (float) $data['cost'] : 0.0, 2, '.', ''),
            'maintenanceStatus'   => trim((string) ($data['maintenanceStatus'] ?? 'completed')),
            'nextMaintenanceDate' => $data['nextMaintenanceDate'] ?? null,
            'odometerReading'     => isset($data['odometerReading']) ? (int) $data['odometerReading'] : null,
        ];

        if ($record['vehicleID'] < 1) {
            throw new InvalidArgumentException('A valid vehicle is required.');
        }
        if ($record['maintenanceType'] === '') {
            throw new InvalidArgumentException('Maintenance type is required.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $record['maintenanceDate'])) {
            throw new InvalidArgumentException('Maintenance date must be a valid YYYY-MM-DD value.');
        }
        if ($record['nextMaintenanceDate'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $record['nextMaintenanceDate'])) {
            throw new InvalidArgumentException('Next maintenance date must be a valid YYYY-MM-DD value.');
        }

        $id = $this->db->table($this->table)->insert($record);

        if ($record['odometerReading'] !== null) {
            $this->update_mileage($record['vehicleID'], $record['odometerReading']);
        }

        return $this->find_record($id);
    }

    /**
     * Updates the EDITABLE fields present in $input.
     * Returns NULL when the record does not exist, otherwise ['record' => ..., 'updated' => bool].
     */
    public function update_record($id, array $input)
    {
        $record = $this->find_record($id);
        if (!$record) {
            return null;
        }

        $update = array_intersect_key($input, array_flip(self::EDITABLE));
        if (!$update) {
            return ['record' => $record, 'updated' => false];
        }
        if (array_key_exists('cost', $update)) {
            $update['cost'] = number_format((float) $update['cost'], 2, '.', '');
        }

        $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->update($update + ['updated_at' => date('Y-m-d H:i:s')]);

        if (isset($input['odometerReading']) && $input['odometerReading'] !== '') {
            $this->update_mileage((int) $record['vehicleID'], (int) $input['odometerReading']);
        }

        return ['record' => $this->find_record($id), 'updated' => true];
    }

    /** Sets a car's current mileage and returns the car summary row. */
    public function update_mileage($vehicle_id, $mileage)
    {
        $mileage = (int) $mileage;
        if ($vehicle_id < 1 || $mileage < 0) {
            throw new InvalidArgumentException('A valid mileage value is required.');
        }

        $this->db->table('cars')
            ->where('id', (int) $vehicle_id)
            ->update(['current_mileage' => $mileage]);

        return $this->db->table('cars')
            ->select('id, make, model, plate_number, current_mileage')
            ->where('id', (int) $vehicle_id)
            ->limit(1)
            ->get();
    }

    /* ---------------------------------------------------------------
     * Vehicle overview
     * ------------------------------------------------------------- */

    public function vehicles()
    {
        return $this->db->table('cars')
            ->select('id, make, model, plate_number, current_mileage')
            ->order_by('make', 'ASC')
            ->order_by('model', 'ASC')
            ->get_all();
    }

    /** Every car with its latest-service details. */
    public function vehicle_overview()
    {
        $vehicles = $this->db->table('cars')
            ->select('id, make, model, year, plate_number, status, current_mileage')
            ->order_by('make', 'ASC')
            ->order_by('model', 'ASC')
            ->order_by('id', 'ASC')
            ->get_all();
        $history = $this->service_history();

        foreach ($vehicles as &$vehicle) {
            $vehicle += $history[(int) $vehicle['id']] ?? self::empty_history();
        }
        unset($vehicle);

        return $vehicles;
    }

    /** Overdue / approaching / insufficient counts from vehicle_overview() rows. */
    public function overview_summary(array $vehicles)
    {
        $summary = ['total_vehicles' => count($vehicles), 'approaching' => 0, 'overdue' => 0, 'insufficient' => 0];
        $warning = (new DateTimeImmutable('today'))->modify('+' . self::WARNING_DAYS . ' days');

        foreach ($vehicles as $vehicle) {
            $lastDate     = $vehicle['last_maintenance_date'];
            $mileage      = $vehicle['current_mileage'];
            $lastOdometer = $vehicle['last_maintenance_odometer'];

            if ($lastDate === null && $mileage === null) {
                $summary['insufficient']++;
            } elseif ($lastDate !== null) {
                $nextDue = (new DateTimeImmutable($lastDate))->modify('+' . self::BASELINE_SERVICE_DAYS . ' days');

                if ($mileage !== null && $lastOdometer !== null && (int) $lastOdometer + self::BASELINE_SERVICE_KILOMETERS <= (int) $mileage) {
                    $summary['overdue']++;
                } elseif ($nextDue <= $warning) {
                    $summary['approaching']++;
                }
            }
        }

        return $summary;
    }

    /* ---------------------------------------------------------------
     * Predictions (rule-based service-interval estimates)
     * ------------------------------------------------------------- */

    public function predictions($vehicle_id = null)
    {
        $query = $this->db->table(self::PREDICTIONS_TABLE)
            ->select('tblai_maintenance_predictions.predictionID, tblai_maintenance_predictions.vehicleID, tblai_maintenance_predictions.predictedFor, tblai_maintenance_predictions.predictedMaintenanceType, tblai_maintenance_predictions.predictedIssue, tblai_maintenance_predictions.riskLevel, tblai_maintenance_predictions.recommendedAction, tblai_maintenance_predictions.predictionDate, tblai_maintenance_predictions.nextMaintenanceDate, tblai_maintenance_predictions.created_at, tblai_maintenance_predictions.updated_at, cars.make, cars.model, cars.plate_number, cars.current_mileage')
            ->inner_join('cars', 'cars.id = tblai_maintenance_predictions.vehicleID');

        if ($vehicle_id !== null) {
            $query->where('tblai_maintenance_predictions.vehicleID', (int) $vehicle_id);
        }

        return $query->order_by('predictedFor', 'ASC')
            ->order_by('predictionID', 'DESC')
            ->get_all();
    }

    /** Overdue / approaching / insufficient counts from prediction risk levels. */
    public function prediction_summary(array $predictions, $total_vehicles)
    {
        $summary = ['total_vehicles' => (int) $total_vehicles, 'approaching' => 0, 'overdue' => 0, 'insufficient' => 0];

        foreach ($predictions as $prediction) {
            $level = strtolower((string) ($prediction['riskLevel'] ?? 'low'));
            if ($level === 'high' || $level === 'overdue') {
                $summary['overdue']++;
            } elseif ($level === 'medium' || $level === 'approaching') {
                $summary['approaching']++;
            } elseif ($level === 'insufficient') {
                $summary['insufficient']++;
            }
        }

        return $summary;
    }

    /** Recalculates and replaces the stored estimate for every car. Returns the new rows. */
    public function generate_predictions()
    {
        $history = $this->service_history();
        $today   = new DateTimeImmutable('today');
        $rows    = [];
        $records = [];

        foreach ($this->vehicles() as $vehicle) {
            $vehicleId      = (int) $vehicle['id'];
            $service        = $history[$vehicleId] ?? self::empty_history();
            $lastDate       = $service['last_maintenance_date'];
            $lastMileage    = $service['last_maintenance_odometer'];
            $currentMileage = $vehicle['current_mileage'];
            $type           = $service['last_maintenance_type'] ?: 'general inspection';
            $nextDueDate    = $service['next_scheduled_date'];

            $nextMaintenanceDate = null;

            if ($lastDate !== null) {
                $nextMaintenanceDate = (new DateTimeImmutable($lastDate))->modify('+' . self::BASELINE_SERVICE_DAYS . ' days')->format('Y-m-d');
                $estimatedMileage    = $lastMileage !== null ? (int) $lastMileage + self::BASELINE_SERVICE_KILOMETERS : null;

                if ($currentMileage !== null && $estimatedMileage !== null && $currentMileage >= $estimatedMileage) {
                    list($risk, $issue, $action) = ['high', 'Recorded mileage has reached the system baseline service interval since the last completed service. This is a maintenance schedule status, not a confirmed mechanical fault.', 'Review the manufacturer service schedule and record the service outcome.'];
                } elseif ($nextDueDate !== null && $today > new DateTimeImmutable($nextDueDate)) {
                    list($risk, $issue, $action) = ['high', 'A recorded scheduled maintenance date has passed. This is not a confirmed mechanical fault.', 'Review the scheduled task and record its completion or revised due date.'];
                } elseif ($today > new DateTimeImmutable($nextMaintenanceDate)) {
                    list($risk, $issue, $action) = ['high', 'The system baseline date interval has passed. Check the vehicle manufacturer schedule before deciding service.', 'Review the manufacturer schedule and record the service outcome.'];
                } elseif ($today->diff(new DateTimeImmutable($nextMaintenanceDate))->days <= self::WARNING_DAYS) {
                    list($risk, $issue, $action) = ['medium', 'The system baseline service date is within 30 days.', 'Review the manufacturer schedule and plan the next service.'];
                } else {
                    list($risk, $issue, $action) = ['low', 'No baseline service threshold has been reached based on the recorded service history. This is not a mechanical health assessment.', 'Check the vehicle manufacturer schedule and continue recording completed service and mileage.'];
                }
            } elseif ($currentMileage !== null) {
                list($risk, $issue, $action) = ['insufficient', 'Current mileage is recorded, but no completed service history is available for an interval estimate.', 'Record the date and odometer reading from the latest completed service.'];
            } else {
                list($risk, $issue, $action) = ['insufficient', 'Maintenance history and current mileage are missing.', 'Add the last service date and mileage before generating maintenance forecasts.'];
            }

            $prediction = [
                'vehicleID'                => $vehicleId,
                'predictedFor'             => $nextMaintenanceDate ?? $today->format('Y-m-d'),
                'predictedMaintenanceType' => $type,
                'predictedIssue'           => $issue,
                'riskLevel'                => $risk,
                'recommendedAction'        => $action,
                'nextMaintenanceDate'      => $nextMaintenanceDate,
            ];

            $records[] = $prediction + ['predictionDate' => $today->format('Y-m-d H:i:s'), 'confidenceScore' => null];
            $rows[]    = $prediction + [
                'make'            => $vehicle['make'],
                'model'           => $vehicle['model'],
                'plate_number'    => $vehicle['plate_number'],
                'current_mileage' => $currentMileage,
            ];
        }

        // Replace the old estimates atomically.
        $this->db->transaction();
        try {
            $this->db->table(self::PREDICTIONS_TABLE)->delete();
            foreach (array_chunk($records, 200) as $batch) {
                $this->db->table(self::PREDICTIONS_TABLE)->bulk_insert($batch);
            }
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }

        return $rows;
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /** Latest-service details per vehicle ID, built from all maintenance records. */
    private function service_history()
    {
        $records = $this->db->table($this->table)
            ->order_by('maintenanceDate', 'DESC')
            ->order_by('maintenanceID', 'DESC')
            ->get_all();

        $history = [];
        foreach ($records as $record) {
            $id = (int) $record['vehicleID'];

            // Records are newest first, so the first one seen sets type and next date.
            if (!isset($history[$id])) {
                $history[$id] = [
                    'last_maintenance_type' => $record['maintenanceType'],
                    'next_scheduled_date'   => $record['nextMaintenanceDate'],
                ] + self::empty_history();
            }
            $history[$id]['maintenance_count']++;

            $odometer = $record['odometerReading'];
            if ($odometer !== null && ($history[$id]['last_maintenance_odometer'] === null || (int) $odometer > (int) $history[$id]['last_maintenance_odometer'])) {
                $history[$id]['last_maintenance_odometer'] = $odometer;
            }

            $date = $record['maintenanceDate'];
            if (
                in_array(strtolower((string) $record['maintenanceStatus']), self::DONE_STATUSES, true)
                && $date !== null
                && ($history[$id]['last_maintenance_date'] === null || $date > $history[$id]['last_maintenance_date'])
            ) {
                $history[$id]['last_maintenance_date'] = $date;
            }
        }

        return $history;
    }

    private static function empty_history()
    {
        return [
            'last_maintenance_date'     => null,
            'last_maintenance_odometer' => null,
            'last_maintenance_type'     => null,
            'next_scheduled_date'       => null,
            'maintenance_count'         => 0,
        ];
    }
}
