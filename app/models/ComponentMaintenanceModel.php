<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'libraries/ComponentMaintenanceRules.php';

/**
 * Component-level inspections, configured service intervals, and the
 * per-vehicle component status built from them.
 * Tables: maintenance_component_types, vehicle_component_inspections,
 * maintenance_service_intervals (database/component_maintenance.sql).
 */
class ComponentMaintenanceModel extends Model
{
    const TYPES       = 'maintenance_component_types';
    const INSPECTIONS = 'vehicle_component_inspections';
    const INTERVALS   = 'maintenance_service_intervals';

    const INSPECTION_COLUMNS = 'vehicle_component_inspections.id, vehicle_component_inspections.vehicle_id, vehicle_component_inspections.component_key, vehicle_component_inspections.inspected_on, vehicle_component_inspections.odometer_km, vehicle_component_inspections.condition_rating, vehicle_component_inspections.reported_symptoms, vehicle_component_inspections.dtc_codes, vehicle_component_inspections.findings, vehicle_component_inspections.confirmed_fault, vehicle_component_inspections.service_performed, vehicle_component_inspections.service_date, vehicle_component_inspections.work_performed, vehicle_component_inspections.parts_replaced, vehicle_component_inspections.recommended_action, vehicle_component_inspections.next_due_date, vehicle_component_inspections.next_due_km, vehicle_component_inspections.technician, vehicle_component_inspections.created_at, users.full_name AS recorded_by_full_name, users.username AS recorded_by_username';

    /** The car row, or NULL. */
    public function vehicle($vehicle_id)
    {
        return $this->db->table('cars')
            ->select('id, make, model, year, category, plate_number, color, status, current_mileage, primary_image')
            ->where('id', (int) $vehicle_id)
            ->limit(1)
            ->get() ?: null;
    }

    /**
     * Active components that apply to the body type, in display order. With no
     * body type (an unmapped category) only components every body type has are
     * offered, so e.g. a chain drive is never listed for an unknown vehicle.
     */
    public function component_types($body_type)
    {
        $types = $this->db->table(self::TYPES)
            ->select('component_key, display_name, body_types')
            ->where('is_active', 1)
            ->order_by('sort_order', 'ASC')
            ->get_all();

        return array_values(array_filter($types, static function ($type) use ($body_type) {
            return self::applies($type, $body_type);
        }));
    }

    public static function applies(array $type, $body_type)
    {
        $types = explode(',', $type['body_types']);
        return $body_type === null
            ? !array_diff(ComponentMaintenanceRules::BODY_TYPES, $types)
            : in_array($body_type, $types, true);
    }

    public function component_type($component_key)
    {
        return $this->db->table(self::TYPES)
            ->where('component_key', (string) $component_key)
            ->where('is_active', 1)
            ->limit(1)
            ->get() ?: null;
    }

    /** Inspections for one vehicle, grouped by component key, newest first. */
    public function inspections_by_component($vehicle_id)
    {
        $grouped = [];
        foreach ($this->inspection_query($vehicle_id)->get_all() as $row) {
            $grouped[$row['component_key']][] = self::present($row);
        }
        return $grouped;
    }

    public function history($vehicle_id, $component_key, $limit = 50)
    {
        return array_map([self::class, 'present'], $this->inspection_query($vehicle_id)
            ->where('vehicle_component_inspections.component_key', (string) $component_key)
            ->limit(max(1, min((int) $limit, 100)))
            ->get_all());
    }

    /** Applicable rules keyed by component: a vehicle-specific rule wins over a body-type rule. */
    public function intervals($vehicle_id, $body_type)
    {
        $rows = $this->db->table(self::INTERVALS)
            ->grouped(function ($query) use ($vehicle_id, $body_type) {
                $query->where('vehicle_id', (int) $vehicle_id)
                    ->or_where('body_type', (string) $body_type);
            })
            ->get_all();

        $rules = [];
        foreach ($rows as $row) {
            $key = $row['component_key'];
            if ($row['vehicle_id'] !== null && (int) $row['vehicle_id'] !== (int) $vehicle_id) {
                continue;
            }
            $scope = $row['vehicle_id'] !== null ? 'vehicle' : 'body_type';
            if (isset($rules[$key]) && $rules[$key]['scope'] === 'vehicle') {
                continue;
            }
            $rules[$key] = [
                'id'            => (int) $row['id'],
                'scope'         => $scope,
                'interval_days' => $row['interval_days'] === null ? null : (int) $row['interval_days'],
                'interval_km'   => $row['interval_km'] === null ? null : (int) $row['interval_km'],
                'due_soon_days' => (int) $row['due_soon_days'],
                'due_soon_km'   => (int) $row['due_soon_km'],
                'basis'         => $row['basis'],
                'updated_at'    => $row['updated_at'] ?? $row['created_at'],
            ];
        }
        return $rules;
    }

    /**
     * Appends an inspection. Rejects odometer readings that contradict the
     * vehicle's recorded history, and raises cars.current_mileage (never lowers it).
     */
    public function create_inspection($vehicle_id, $component_key, array $record, $user_id)
    {
        $record += [
            'vehicle_id'    => (int) $vehicle_id,
            'component_key' => (string) $component_key,
            'recorded_by'   => (int) $user_id,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        $this->db->transaction();
        try {
            $vehicle = $this->db->table('cars')
                ->select('id, current_mileage')
                ->where('id', (int) $vehicle_id)
                ->for_update()
                ->get();
            if (!$vehicle) {
                throw new InvalidArgumentException('Vehicle not found.');
            }

            if ($record['odometer_km'] !== null) {
                $this->assert_odometer_consistent((int) $vehicle_id, $record['inspected_on'], (int) $record['odometer_km']);
            }

            $id = $this->db->table(self::INSPECTIONS)->insert($record);

            if ($record['odometer_km'] !== null && ($vehicle['current_mileage'] === null || (int) $record['odometer_km'] > (int) $vehicle['current_mileage'])) {
                $this->db->table('cars')
                    ->where('id', (int) $vehicle_id)
                    ->update(['current_mileage' => (int) $record['odometer_km']]);
            }

            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }

        return self::present($this->inspection_query($vehicle_id)
            ->where('vehicle_component_inspections.id', (int) $id)
            ->limit(1)
            ->get());
    }

    /** Creates or replaces the rule for the given scope. */
    public function save_interval($vehicle_id, $body_type, $component_key, array $rule, $user_id)
    {
        $row = [
            'component_key' => (string) $component_key,
            'vehicle_id'    => $rule['scope'] === 'vehicle' ? (int) $vehicle_id : null,
            'body_type'     => $rule['scope'] === 'body_type' ? (string) $body_type : null,
            'interval_days' => $rule['interval_days'],
            'interval_km'   => $rule['interval_km'],
            'due_soon_days' => $rule['due_soon_days'],
            'due_soon_km'   => $rule['due_soon_km'],
            'basis'         => $rule['basis'],
            'updated_by'    => (int) $user_id,
        ];

        $this->db->transaction();
        try {
            $existing = $this->scope_query($vehicle_id, $body_type, $component_key, $rule['scope'])->for_update()->get();
            if ($existing) {
                $this->db->table(self::INTERVALS)
                    ->where('id', (int) $existing['id'])
                    ->update($row + ['updated_at' => date('Y-m-d H:i:s')]);
            } else {
                $this->db->table(self::INTERVALS)->insert($row + ['created_at' => date('Y-m-d H:i:s')]);
            }
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    /** Removes the rule for the given scope. Returns FALSE when none existed. */
    public function delete_interval($vehicle_id, $body_type, $component_key, $scope)
    {
        $existing = $this->scope_query($vehicle_id, $body_type, $component_key, $scope)->get();
        if (!$existing) {
            return false;
        }
        $this->db->table(self::INTERVALS)->where('id', (int) $existing['id'])->delete();
        return true;
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /** Typed inspection row with a single recorded_by_name. */
    private static function present(array $row)
    {
        foreach (['id', 'vehicle_id', 'service_performed'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['odometer_km', 'next_due_km'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['dtc_codes'] = $row['dtc_codes'] === null || $row['dtc_codes'] === '' ? [] : explode(',', $row['dtc_codes']);
        $row['recorded_by_name'] = $row['recorded_by_full_name'] ?: $row['recorded_by_username'];
        unset($row['recorded_by_full_name'], $row['recorded_by_username']);
        return $row;
    }

    private function inspection_query($vehicle_id)
    {
        return $this->db->table(self::INSPECTIONS)
            ->select(self::INSPECTION_COLUMNS)
            ->left_join('users', 'users.id = vehicle_component_inspections.recorded_by')
            ->where('vehicle_component_inspections.vehicle_id', (int) $vehicle_id)
            ->order_by('vehicle_component_inspections.inspected_on', 'DESC')
            ->order_by('vehicle_component_inspections.id', 'DESC');
    }

    private function scope_query($vehicle_id, $body_type, $component_key, $scope)
    {
        $query = $this->db->table(self::INTERVALS)->where('component_key', (string) $component_key);
        return $scope === 'vehicle'
            ? $query->where('vehicle_id', (int) $vehicle_id)
            : $query->where('body_type', (string) $body_type)->where_null('vehicle_id');
    }

    /** Odometers must not decrease over time across any component of the vehicle. */
    private function assert_odometer_consistent($vehicle_id, $date, $odometer)
    {
        $earlier = $this->db->table(self::INSPECTIONS)
            ->select_max('odometer_km', 'reading')
            ->where('vehicle_id', $vehicle_id)
            ->where('inspected_on', '<', $date)
            ->get();
        if ($earlier && $earlier['reading'] !== null && $odometer < (int) $earlier['reading']) {
            throw new InvalidArgumentException('The odometer reading is lower than ' . number_format((int) $earlier['reading']) . ' km recorded on an earlier date.');
        }

        $later = $this->db->table(self::INSPECTIONS)
            ->select_min('odometer_km', 'reading')
            ->where('vehicle_id', $vehicle_id)
            ->where('inspected_on', '>', $date)
            ->get();
        if ($later && $later['reading'] !== null && $odometer > (int) $later['reading']) {
            throw new InvalidArgumentException('The odometer reading is higher than ' . number_format((int) $later['reading']) . ' km recorded on a later date.');
        }
    }
}
