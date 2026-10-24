<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MaintenanceModel extends Model
{
    protected $table = 'tblvehicle_maintenance';
    protected $primary_key = 'maintenanceID';
    protected $fillable = [
        'vehicleID',
        'maintenanceType',
        'description',
        'maintenanceDate',
        'cost',
        'maintenanceStatus',
        'nextMaintenanceDate',
        'odometerReading',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function ensureSchema()
    {
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $this->ensureSchemaStructure();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
    }

    private function ensureSchemaStructure()
    {
        $lava = lava_instance();
        $lava->call->dbforge();
        $forge = $lava->dbforge;

        if (!$forge->column_exists('cars', 'current_mileage')) {
            $forge->add_column('cars', [
                'current_mileage' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => true,
                ],
            ]);
        }

        if (!$forge->table_exists($this->table)) {
            $forge->add_field([
                'maintenanceID' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'vehicleID' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'maintenanceType' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => false],
                'description' => ['type' => 'TEXT', 'null' => true],
                'maintenanceDate' => ['type' => 'DATE', 'null' => true],
                'cost' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true, 'default' => 0],
                'maintenanceStatus' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false, 'default' => 'scheduled'],
                'nextMaintenanceDate' => ['type' => 'DATE', 'null' => true],
                'odometerReading' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
            $forge->add_key('maintenanceID', true);
            $forge->add_key(['vehicleID', 'maintenanceDate'], false, false, 'maintenance_vehicle_date_idx');
            $forge->add_foreign_key('vehicleID', 'cars', 'id', 'CASCADE', 'CASCADE');
            $forge->create_table($this->table);
        }
        foreach ([
            'nextMaintenanceDate' => ['type' => 'DATE', 'null' => true],
            'odometerReading' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ] as $column => $definition) {
            if (!$forge->column_exists($this->table, $column)) {
                $forge->add_column($this->table, [
                    $column => $definition,
                ]);
            }
        }

        if (!$forge->table_exists('tblai_maintenance_predictions')) {
            $forge->add_field([
                'predictionID' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'vehicleID' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'predictedFor' => ['type' => 'DATE', 'null' => false],
                'predictedMaintenanceType' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => false],
                'predictedIssue' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'riskLevel' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'medium'],
                'recommendedAction' => ['type' => 'TEXT', 'null' => true],
                'predictionDate' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
                'nextMaintenanceDate' => ['type' => 'DATE', 'null' => true],
                'confidenceScore' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'default' => 0],
                'created_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
            $forge->add_key('predictionID', true);
            $forge->add_key(['vehicleID', 'predictedFor'], false, false, 'maintenance_prediction_vehicle_idx');
            $forge->add_foreign_key('vehicleID', 'cars', 'id', 'CASCADE', 'CASCADE');
            $forge->create_table('tblai_maintenance_predictions');
        }

        foreach ([
            'nextMaintenanceDate' => ['type' => 'DATE', 'null' => true],
            'confidenceScore' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ] as $column => $definition) {
            if (!$forge->column_exists('tblai_maintenance_predictions', $column)) {
                $forge->add_column('tblai_maintenance_predictions', [
                    $column => $definition,
                ]);
            }
        }
        $riskLevel = $this->db->raw(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['tblai_maintenance_predictions', 'riskLevel']
        )->fetch(PDO::FETCH_ASSOC);
        if ($riskLevel && strtolower($riskLevel['column_type']) !== 'varchar(20)') {
            $forge->modify_column('tblai_maintenance_predictions', [
                'riskLevel' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => false,
                    'default' => 'medium',
                ],
            ]);
        }

        $this->ensureVehicleForeignKey($this->table, 'tblvehicle_maintenance_car_fk');
        $this->ensureVehicleForeignKey('tblai_maintenance_predictions', 'tblai_maintenance_predictions_car_fk');
    }

    private function ensureVehicleForeignKey($table, $constraintName)
    {
        $foreignKeys = $this->db->raw(
            'SELECT CONSTRAINT_NAME AS constraint_name, REFERENCED_TABLE_NAME AS referenced_table FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND referenced_table_name IS NOT NULL',
            [$table, 'vehicleID']
        )->fetchAll(PDO::FETCH_ASSOC);
        $hasCarsForeignKey = false;

        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['referenced_table'] === 'cars') {
                $hasCarsForeignKey = true;
            }
        }

        if (!$hasCarsForeignKey) {
            $orphanCount = $this->db->raw(
                "SELECT COUNT(*) FROM `{$table}` AS records LEFT JOIN cars ON cars.id = records.vehicleID WHERE cars.id IS NULL"
            )->fetchColumn();
            if ((int) $orphanCount > 0) {
                throw new RuntimeException('Maintenance data references vehicles that do not exist in the cars table.');
            }
        }

        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['referenced_table'] !== 'cars') {
                $existingConstraint = str_replace('`', '``', $foreignKey['constraint_name']);
                $this->db->raw("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$existingConstraint}`");
            }
        }

        if ($hasCarsForeignKey) {
            return;
        }

        $vehicleColumn = $this->db->raw(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, 'vehicleID']
        )->fetch(PDO::FETCH_ASSOC);
        if ($vehicleColumn && strpos(strtolower($vehicleColumn['column_type']), 'unsigned') === false) {
            $this->db->raw("ALTER TABLE `{$table}` MODIFY COLUMN `vehicleID` INT UNSIGNED NOT NULL");
        }

        $this->db->raw(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`vehicleID`) REFERENCES `cars` (`id`) ON DELETE CASCADE ON UPDATE CASCADE"
        );
    }

    public function getVehicleRecords($vehicleId = null)
    {
        $this->ensureSchema();
        $query = $this->db->table($this->table)
            ->select('tblvehicle_maintenance.maintenanceID, tblvehicle_maintenance.vehicleID, tblvehicle_maintenance.maintenanceType, tblvehicle_maintenance.description, tblvehicle_maintenance.maintenanceDate, tblvehicle_maintenance.cost, tblvehicle_maintenance.maintenanceStatus, tblvehicle_maintenance.nextMaintenanceDate, tblvehicle_maintenance.odometerReading, tblvehicle_maintenance.created_at, tblvehicle_maintenance.updated_at, cars.make, cars.model, cars.plate_number, cars.current_mileage')
            ->inner_join('cars', 'cars.id = tblvehicle_maintenance.vehicleID');
        if ($vehicleId !== null) {
            $query->where('tblvehicle_maintenance.vehicleID', (int) $vehicleId);
        }

        return $query->order_by('tblvehicle_maintenance.maintenanceDate', 'DESC')
            ->order_by('tblvehicle_maintenance.maintenanceID', 'DESC')
            ->get_all();
    }

    public function getVehicleOverview()
    {
        $this->ensureSchema();
        $vehicles = $this->db->table('cars')
            ->select('id, make, model, year, plate_number, status, current_mileage')
            ->order_by('make', 'ASC')
            ->order_by('model', 'ASC')
            ->order_by('id', 'ASC')
            ->get_all();
        $records = $this->db->table($this->table)
            ->order_by('maintenanceDate', 'DESC')
            ->order_by('maintenanceID', 'DESC')
            ->get_all();
        $overview = [];

        foreach ($records as $record) {
            $vehicleId = (int) $record['vehicleID'];
            if (!isset($overview[$vehicleId])) {
                $overview[$vehicleId] = [
                    'last_maintenance_date' => null,
                    'last_maintenance_odometer' => null,
                    'last_maintenance_type' => $record['maintenanceType'],
                    'next_scheduled_date' => $record['nextMaintenanceDate'],
                    'maintenance_count' => 0,
                ];
            }

            $overview[$vehicleId]['maintenance_count']++;
            if ($record['odometerReading'] !== null
                && ($overview[$vehicleId]['last_maintenance_odometer'] === null
                    || (int) $record['odometerReading'] > (int) $overview[$vehicleId]['last_maintenance_odometer'])) {
                $overview[$vehicleId]['last_maintenance_odometer'] = $record['odometerReading'];
            }
            if (in_array($record['maintenanceStatus'], ['completed', 'done', 'recorded'], true)
                && $record['maintenanceDate'] !== null
                && ($overview[$vehicleId]['last_maintenance_date'] === null
                    || $record['maintenanceDate'] > $overview[$vehicleId]['last_maintenance_date'])) {
                $overview[$vehicleId]['last_maintenance_date'] = $record['maintenanceDate'];
            }
        }

        foreach ($vehicles as &$vehicle) {
            $maintenance = $overview[(int) $vehicle['id']] ?? [];
            $vehicle += [
                'last_maintenance_date' => $maintenance['last_maintenance_date'] ?? null,
                'last_maintenance_odometer' => $maintenance['last_maintenance_odometer'] ?? null,
                'last_maintenance_type' => $maintenance['last_maintenance_type'] ?? null,
                'next_scheduled_date' => $maintenance['next_scheduled_date'] ?? null,
                'maintenance_count' => $maintenance['maintenance_count'] ?? 0,
            ];
        }
        unset($vehicle);

        return $vehicles;
    }

    public function getVehicles()
    {
        $this->ensureSchema();

        return $this->db->table('cars')
            ->select('id, make, model, plate_number, current_mileage')
            ->order_by('make', 'ASC')
            ->order_by('model', 'ASC')
            ->get_all();
    }

    public function getPredictions()
    {
        $this->ensureSchema();
        return $this->db->table('tblai_maintenance_predictions')
            ->select('tblai_maintenance_predictions.predictionID, tblai_maintenance_predictions.vehicleID, tblai_maintenance_predictions.predictedFor, tblai_maintenance_predictions.predictedMaintenanceType, tblai_maintenance_predictions.predictedIssue, tblai_maintenance_predictions.riskLevel, tblai_maintenance_predictions.recommendedAction, tblai_maintenance_predictions.predictionDate, tblai_maintenance_predictions.nextMaintenanceDate, tblai_maintenance_predictions.confidenceScore, tblai_maintenance_predictions.created_at, tblai_maintenance_predictions.updated_at, cars.make, cars.model, cars.plate_number, cars.current_mileage')
            ->inner_join('cars', 'cars.id = tblai_maintenance_predictions.vehicleID')
            ->order_by('predictedFor', 'ASC')
            ->order_by('predictionID', 'DESC')
            ->get_all();
    }

    public function findRecord($id)
    {
        $this->ensureSchema();

        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->limit(1)
            ->get();
    }

    public function updateRecord($id, array $input)
    {
        $this->ensureSchema();
        $record = $this->findRecord($id);
        if (!$record) {
            return null;
        }

        $update = [];
        foreach (['maintenanceType', 'description', 'maintenanceDate', 'cost', 'maintenanceStatus', 'nextMaintenanceDate', 'odometerReading'] as $field) {
            if (array_key_exists($field, $input)) {
                $update[$field] = $field === 'cost'
                    ? number_format((float) $input[$field], 2, '.', '')
                    : $input[$field];
            }
        }
        if (!$update) {
            return ['record' => $record, 'updated' => false];
        }

        $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->update($update + ['updated_at' => date('Y-m-d H:i:s')]);

        if (isset($input['odometerReading']) && $input['odometerReading'] !== '') {
            $this->updateMileage((int) $record['vehicleID'], (int) $input['odometerReading']);
        }

        return ['record' => $this->findRecord($id), 'updated' => true];
    }

    public function createRecord(array $data)
    {
        $this->ensureSchema();
        $record = [
            'vehicleID' => (int) ($data['vehicleID'] ?? 0),
            'maintenanceType' => trim((string) ($data['maintenanceType'] ?? 'general-inspection')),
            'description' => trim((string) ($data['description'] ?? '')),
            'maintenanceDate' => $data['maintenanceDate'] ?? date('Y-m-d'),
            'cost' => isset($data['cost']) ? (float) $data['cost'] : 0.0,
            'maintenanceStatus' => trim((string) ($data['maintenanceStatus'] ?? 'completed')),
            'nextMaintenanceDate' => $data['nextMaintenanceDate'] ?? null,
            'odometerReading' => isset($data['odometerReading']) ? (int) $data['odometerReading'] : null,
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

        $id = $this->db->table($this->table)->insert([
            'vehicleID' => $record['vehicleID'],
            'maintenanceType' => $record['maintenanceType'],
            'description' => $record['description'],
            'maintenanceDate' => $record['maintenanceDate'],
            'cost' => number_format($record['cost'], 2, '.', ''),
            'maintenanceStatus' => $record['maintenanceStatus'],
            'nextMaintenanceDate' => $record['nextMaintenanceDate'],
            'odometerReading' => $record['odometerReading'],
        ]);

        if ($record['odometerReading'] !== null) {
            $this->db->table('cars')
                ->where('id', $record['vehicleID'])
                ->update(['current_mileage' => (int) $record['odometerReading']]);
        }

        return $this->findRecord($id);
    }

    public function updateMileage($vehicleId, $mileage)
    {
        $this->ensureSchema();
        $mileage = (int) $mileage;
        if ($vehicleId < 1 || $mileage < 0) {
            throw new InvalidArgumentException('A valid mileage value is required.');
        }

        $this->db->table('cars')
            ->where('id', (int) $vehicleId)
            ->update(['current_mileage' => $mileage]);

        return $this->db->table('cars')
            ->select('id, make, model, plate_number, current_mileage')
            ->where('id', (int) $vehicleId)
            ->limit(1)
            ->get();
    }

    public function generatePredictions()
    {
        $this->ensureSchema();
        $vehicles = $this->getVehicles();
        $maintenanceRecords = $this->db->table($this->table)
            ->order_by('maintenanceDate', 'DESC')
            ->order_by('maintenanceID', 'DESC')
            ->get_all();
        $maintenanceByVehicle = [];
        foreach ($maintenanceRecords as $record) {
            $vehicleId = (int) $record['vehicleID'];
            if (!isset($maintenanceByVehicle[$vehicleId])) {
                $maintenanceByVehicle[$vehicleId] = [
                    'last_maintenance_date' => null,
                    'last_maintenance_odometer' => null,
                    'last_maintenance_type' => $record['maintenanceType'],
                    'last_next_maintenance_date' => $record['nextMaintenanceDate'],
                ];
            }
            if ($record['odometerReading'] !== null
                && ($maintenanceByVehicle[$vehicleId]['last_maintenance_odometer'] === null
                    || (int) $record['odometerReading'] > (int) $maintenanceByVehicle[$vehicleId]['last_maintenance_odometer'])) {
                $maintenanceByVehicle[$vehicleId]['last_maintenance_odometer'] = $record['odometerReading'];
            }
            if (in_array($record['maintenanceStatus'], ['completed', 'done', 'recorded'], true)
                && $record['maintenanceDate'] !== null
                && ($maintenanceByVehicle[$vehicleId]['last_maintenance_date'] === null
                    || $record['maintenanceDate'] > $maintenanceByVehicle[$vehicleId]['last_maintenance_date'])) {
                $maintenanceByVehicle[$vehicleId]['last_maintenance_date'] = $record['maintenanceDate'];
            }
        }

        $this->db->table('tblai_maintenance_predictions')->delete();

        $rows = [];
        $today = new DateTimeImmutable('today');
        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            $maintenance = $maintenanceByVehicle[$vehicleId] ?? [];
            $lastDate = $maintenance['last_maintenance_date'] ?? null;
            $lastMileage = $maintenance['last_maintenance_odometer'] ?? null;
            $currentMileage = $vehicle['current_mileage'];
            $maintenanceType = ($maintenance['last_maintenance_type'] ?? null) ?: 'oil-change';
            $nextDueDate = $maintenance['last_next_maintenance_date'] ?? null;

            $nextMaintenanceDate = null;
            $estimatedMileage = null;
            $issue = 'No maintenance concern detected.';
            $action = 'Continue regular fleet checks and inspect the vehicle during its next scheduled service window.';
            $riskLevel = 'low';
            $confidence = 0.85;

            if ($lastDate !== null) {
                $dueDate = new DateTimeImmutable($lastDate);
                $dueDate = $dueDate->modify('+180 days');
                $nextMaintenanceDate = $dueDate->format('Y-m-d');
                $estimatedMileage = ($lastMileage !== null ? (int) $lastMileage + 5000 : null);
                $issue = 'Service interval is approaching based on the last recorded maintenance.';
                $action = 'Schedule maintenance review and verify fluid, filter, and brake condition.';
                $riskLevel = 'low';
                $confidence = 0.9;

                if ($currentMileage !== null && $estimatedMileage !== null && $currentMileage >= $estimatedMileage) {
                    $riskLevel = 'high';
                    $issue = 'The vehicle has exceeded the recommended service interval based on recorded mileage.';
                    $action = 'Schedule an inspection immediately and record the exact maintenance work performed.';
                    $confidence = 0.96;
                } elseif ($today->diff(new DateTimeImmutable($nextMaintenanceDate))->days <= 30) {
                    $riskLevel = 'medium';
                    $issue = 'The next maintenance window is within 30 days.';
                    $action = 'Plan the next maintenance service within the current inspection cycle.';
                    $confidence = 0.88;
                } elseif ($nextDueDate !== null && $today > new DateTimeImmutable($nextDueDate)) {
                    $riskLevel = 'high';
                    $issue = 'The scheduled maintenance date has already passed.';
                    $action = 'Review overdue service tasks and complete the next maintenance check without delay.';
                    $confidence = 0.94;
                }
            } elseif ($currentMileage !== null) {
                $riskLevel = 'medium';
                $issue = 'No maintenance history exists, so a reliable service estimate cannot be calculated yet.';
                $action = 'Record the vehicle’s last maintenance date and odometer reading to produce a service estimate.';
                $confidence = 0.65;
            } else {
                $riskLevel = 'insufficient';
                $issue = 'Maintenance history and current mileage are missing.';
                $action = 'Add the last service date and mileage before generating maintenance forecasts.';
                $confidence = 0.4;
            }

            $predictedFor = $nextMaintenanceDate ?? $today->format('Y-m-d');
            $this->db->table('tblai_maintenance_predictions')->insert([
                'vehicleID' => $vehicleId,
                'predictedFor' => $predictedFor,
                'predictedMaintenanceType' => $maintenanceType,
                'predictedIssue' => $issue,
                'riskLevel' => $riskLevel,
                'recommendedAction' => $action,
                'predictionDate' => $today->format('Y-m-d H:i:s'),
                'nextMaintenanceDate' => $nextMaintenanceDate,
                'confidenceScore' => number_format($confidence, 2, '.', ''),
            ]);

            $rows[] = [
                'vehicleID' => $vehicleId,
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
                'plate_number' => $vehicle['plate_number'],
                'predictedFor' => $predictedFor,
                'predictedMaintenanceType' => $maintenanceType,
                'predictedIssue' => $issue,
                'riskLevel' => $riskLevel,
                'recommendedAction' => $action,
                'nextMaintenanceDate' => $nextMaintenanceDate,
                'confidenceScore' => $confidence,
                'current_mileage' => $currentMileage,
            ];
        }

        return $rows;
    }
}
