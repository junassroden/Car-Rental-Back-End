<?php

/**
 * Read-only retrieval for the chat assistant.
 *
 * The AI service may only ask for the tools in TOOLS, with the arguments each tool
 * accepts; it never sends SQL. Every request is validated here, checked against the
 * viewer's role, and run with the viewer's identity from the session, never from the
 * message. Results are trimmed to the fields that role may see. Prices are computed
 * with the same rule ReservationModel::create_reservation uses (daily rate x days).
 *
 * Pure PHP with no database access: data comes from a ChatDataSource, so
 * tests/chat_retrieval_test.php and the chatbot evaluation run these rules on fixtures.
 */
interface ChatDataSource
{
    /** Car rows the viewer may see: every car for an admin, only available ones otherwise. */
    public function vehicles($is_admin);

    /** Ids of cars with a pending or confirmed reservation overlapping [start, end). */
    public function booked_vehicle_ids($start_date, $end_date);

    /** ['total_count' => int, 'reservations' => rows] for one user, newest first. */
    public function reservations_for_user($user_id, $limit);

    /** ['total_count' => int, 'reservations' => rows with customer_name] for every user, newest first. */
    public function reservations_all($limit);

    /** ['status' => count] over all cars. */
    public function vehicle_status_counts();

    /** ['legacy_service_records' => rows, 'verified_evidence_records' => rows, 'verified_evidence_table_available' => bool]. */
    public function maintenance_records($vehicle_id, $limit);

    /** One row per car with its rule-based service status (MaintenanceModel::chat_due_overview). */
    public function maintenance_due();

    /** Component inspection rows with the car's make and model, newest first. */
    public function component_inspections($vehicle_id, $limit);

    /** Cars in the shape the AI service's maintenance model accepts. */
    public function model_feature_vehicles();

    /** Every maintenance-log row with its cost and the car's make and model, newest first. */
    public function maintenance_cost_records();
}

class ChatRetrieval
{
    public const MAX_REQUESTS = 4;
    public const MAX_RENTAL_DAYS = 365;

    /** Tool => roles allowed to use it. */
    public const TOOLS = [
        'vehicles.search'          => ['customer', 'admin'],
        'vehicles.quote'           => ['customer', 'admin'],
        'bookings.mine'            => ['customer'],
        'reservations.summary'     => ['admin'],
        'fleet.status_counts'      => ['admin'],
        'maintenance.records'      => ['admin'],
        'maintenance.due'          => ['admin'],
        'maintenance.recurring'    => ['admin'],
        'maintenance.inspections'  => ['admin'],
        'maintenance.model_inputs' => ['admin'],
        'maintenance.costs'        => ['admin'],
        'vehicles.status'          => ['admin'],
        'reservations.revenue'     => ['admin'],
        'fleet.utilization'        => ['admin'],
    ];
    public const SORTS       = ['price_asc', 'price_desc', 'year_desc', 'year_asc'];
    public const ADMIN_SORTS = ['mileage_desc', 'mileage_asc'];

    public const VEHICLE_STATUSES     = ['available', 'rented', 'maintenance', 'unavailable'];
    public const RESERVATION_STATUSES = ['pending', 'confirmed', 'cancelled', 'rejected', 'completed'];

    /** Car fields a customer may see; administrators also see ADMIN_VEHICLE_FIELDS. */
    public const PUBLIC_VEHICLE_FIELDS = [
        'id', 'make', 'model', 'year', 'category', 'color', 'description', 'daily_rental_price', 'security_deposit',
        'minimum_rental_duration', 'transmission', 'fuel_type', 'seating_capacity', 'air_conditioning',
        'gps_navigation', 'additional_features',
    ];
    public const ADMIN_VEHICLE_FIELDS = ['status', 'plate_number', 'current_mileage'];

    /**
     * Makes, models and categories of the vehicles the viewer may see, so the AI
     * service can recognise names that only exist in this database.
     */
    public static function vocabulary(array $vehicles)
    {
        $makes = $categories = $models = [];
        foreach ($vehicles as $vehicle) {
            $make  = trim((string) ($vehicle['make'] ?? ''));
            $model = trim((string) ($vehicle['model'] ?? ''));
            $category = trim((string) ($vehicle['category'] ?? ''));
            if ($make !== '') {
                $makes[strtolower($make)] = $make;
            }
            if ($category !== '') {
                $categories[strtolower($category)] = $category;
            }
            if ($model !== '') {
                $key = strtolower($make . '|' . $model);
                $models[$key] = ['make' => $make, 'model' => $model];
            }
        }
        return [
            'makes'      => array_values($makes),
            'models'     => array_values($models),
            'categories' => array_values($categories),
        ];
    }

    /**
     * Runs a retrieval plan from the AI service for one viewer.
     *
     * @param array $plan   ['retrievals' => [['tool' => string, 'args' => array], ...]]
     * @param array $viewer ['role' => 'customer'|'admin', 'user_id' => int] from the session
     * @return array one result per request: tool, status (ok|denied|rejected|error), data or reason
     */
    public static function execute($plan, array $viewer, ChatDataSource $source, $today = null)
    {
        $today    = $today ?: date('Y-m-d');
        $requests = is_array($plan) && is_array($plan['retrievals'] ?? null) ? $plan['retrievals'] : [];
        $results  = [];

        foreach (array_slice($requests, 0, self::MAX_REQUESTS) as $request) {
            $tool = is_array($request) && is_string($request['tool'] ?? null) ? $request['tool'] : '';
            $args = is_array($request['args'] ?? null) ? $request['args'] : [];

            if (!isset(self::TOOLS[$tool])) {
                $results[] = ['tool' => $tool, 'status' => 'rejected', 'reason' => 'unknown_tool'];
                continue;
            }
            if (!in_array($viewer['role'], self::TOOLS[$tool], true)) {
                $results[] = ['tool' => $tool, 'status' => 'denied', 'reason' => 'not_permitted_for_role'];
                continue;
            }

            try {
                $clean = self::validate_args($tool, $args, $viewer['role'], $today);
                if (isset($clean['error'])) {
                    $results[] = ['tool' => $tool, 'status' => 'rejected', 'reason' => $clean['error']];
                    continue;
                }
                $results[] = ['tool' => $tool, 'status' => 'ok', 'args' => $clean,
                              'data' => self::run($tool, $clean, $viewer, $source, $today)];
            } catch (Exception $exception) {
                error_log('Chat retrieval ' . $tool . ' failed: ' . $exception->getMessage());
                $results[] = ['tool' => $tool, 'status' => 'error', 'reason' => 'data_unavailable'];
            }
        }

        return $results;
    }

    /** Whitelisted, typed arguments, or ['error' => reason]. */
    private static function validate_args($tool, array $args, $role, $today)
    {
        $clean = [];
        $clean['limit'] = self::int_in($args['limit'] ?? null, 1, 50) ?? 20;

        foreach (['makes', 'models', 'categories'] as $key) {
            $clean[$key] = self::strings($args[$key] ?? []);
        }
        $clean['vehicle_ids'] = array_values(array_filter(array_map(static function ($id) {
            return self::int_in($id, 1, PHP_INT_MAX);
        }, array_slice((array) ($args['vehicle_ids'] ?? []), 0, 20))));

        if (in_array($tool, ['vehicles.search', 'vehicles.quote', 'vehicles.status'], true)) {
            // Customers only ever see available cars, so a status filter is an administrator option.
            $clean['statuses'] = $role === 'admin'
                ? array_values(array_intersect(self::strings($args['statuses'] ?? []), self::VEHICLE_STATUSES))
                : [];
            $clean['min_seats']      = self::int_in($args['min_seats'] ?? null, 1, 60);
            $clean['max_daily_rate'] = isset($args['max_daily_rate']) && is_numeric($args['max_daily_rate']) && $args['max_daily_rate'] >= 0
                ? (float) $args['max_daily_rate'] : null;
            $sorts = $role === 'admin' ? array_merge(self::SORTS, self::ADMIN_SORTS) : self::SORTS;
            $clean['sort'] = in_array($args['sort'] ?? null, $sorts, true) ? $args['sort'] : null;
            foreach (['fuel_types', 'transmissions', 'colors'] as $key) {
                $clean[$key] = self::strings($args[$key] ?? []);
            }
            $clean['min_year'] = self::int_in($args['min_year'] ?? null, 1950, 2100);
            $clean['max_year'] = self::int_in($args['max_year'] ?? null, 1950, 2100);
            foreach (['has_gps', 'has_aircon'] as $key) {
                $clean[$key] = is_bool($args[$key] ?? null) ? $args[$key] : null;
            }
            $clean['rental_days'] = self::int_in($args['rental_days'] ?? null, 1, self::MAX_RENTAL_DAYS);

            $period = self::period($args['start_date'] ?? null, $args['end_date'] ?? null, $today);
            if (isset($period['error'])) {
                return $period;
            }
            $clean['period'] = $period;
            if ($period !== null) {
                $clean['rental_days'] = $period['rental_days'];
            }
            if ($tool === 'vehicles.quote' && $clean['rental_days'] === null) {
                return ['error' => 'rental_days_required'];
            }
        }

        if ($tool === 'bookings.mine' || $tool === 'reservations.summary') {
            $clean['statuses'] = array_values(array_intersect(self::strings($args['statuses'] ?? []), self::RESERVATION_STATUSES));
            $clean['upcoming'] = ($args['upcoming'] ?? false) === true;
            $clean['active']   = ($args['active'] ?? false) === true;
            $clean['reservation_id'] = self::int_in($args['reservation_id'] ?? null, 1, PHP_INT_MAX);
            foreach (['pickup_on', 'return_on', 'from', 'to'] as $key) {
                $clean[$key] = self::date_or_null($args[$key] ?? null);
            }
            // Searching by customer name is an administrator option only.
            $name = $role === 'admin' && is_string($args['customer_name'] ?? null) ? trim($args['customer_name']) : '';
            $clean['customer_name'] = $name !== '' && strlen($name) <= 60 ? $name : null;
        }

        if (in_array($tool, ['maintenance.costs', 'reservations.revenue', 'fleet.utilization'], true)) {
            $clean['from'] = self::date_or_null($args['from'] ?? null);
            $clean['to']   = self::date_or_null($args['to'] ?? null);
            if ($clean['from'] !== null && $clean['to'] !== null && $clean['to'] < $clean['from']) {
                return ['error' => 'invalid_dates'];
            }
        }

        return $clean;
    }

    private static function run($tool, array $args, array $viewer, ChatDataSource $source, $today)
    {
        $is_admin = $viewer['role'] === 'admin';

        switch ($tool) {
            case 'vehicles.search':
            case 'vehicles.quote':
                return self::vehicles($tool, $args, $is_admin, $source);

            case 'bookings.mine':
                // The identity is the session's user id; nothing in the message can change it.
                $data = $source->reservations_for_user((int) $viewer['user_id'], 50);
                return self::reservation_rows($data, $args, $today, false);

            case 'reservations.summary':
                $data = $source->reservations_all(200);
                $result = self::reservation_rows($data, $args, $today, true);
                $result['counts_by_status'] = array_count_values(array_map(static function ($row) {
                    return (string) $row['status'];
                }, $data['reservations']));
                return $result;

            case 'fleet.status_counts':
                $counts = $source->vehicle_status_counts();
                return ['counts' => $counts, 'total' => array_sum($counts)];

            case 'maintenance.records':
                return self::maintenance_records($args, $source, min($args['limit'], 40));

            case 'maintenance.due':
                return ['vehicles' => self::for_vehicles($source->maintenance_due(), $args, 'id'),
                        'rule' => 'Rule-based estimate: due after 180 days or 5,000 km since the last completed service, or when a recorded next-maintenance date has passed; approaching within 30 days.'];

            case 'maintenance.recurring':
                return ['issues' => self::recurring(self::maintenance_records($args, $source, 40))];

            case 'maintenance.inspections':
                $id = count($args['vehicle_ids']) === 1 ? $args['vehicle_ids'][0] : null;
                return ['inspections' => self::for_vehicles($source->component_inspections($id, min($args['limit'], 40)), $args, 'vehicle_id')];

            case 'maintenance.model_inputs':
                return ['vehicles' => array_slice($source->model_feature_vehicles(), 0, 200)];

            case 'maintenance.costs':
                return self::maintenance_costs($args, $source);

            case 'vehicles.status':
                return self::vehicle_status($args, $source, $today);

            case 'reservations.revenue':
                return self::revenue($args, $source);

            case 'fleet.utilization':
                return self::utilization($args, $source, $today);
        }

        return [];
    }

    /** Vehicle search or price quote over the cars the viewer may see. */
    private static function vehicles($tool, array $args, $is_admin, ChatDataSource $source)
    {
        $rows     = $source->vehicles($is_admin);
        $period   = $args['period'];
        $booked   = $period ? array_map('intval', $source->booked_vehicle_ids($period['start_date'], $period['end_date'])) : [];
        $lower    = static function ($values) { return array_map('strtolower', $values); };
        $makes    = $lower($args['makes']);
        $models   = $lower($args['models']);
        $cats     = $lower($args['categories']);

        $matches = array_values(array_filter($rows, static function ($row) use ($args, $makes, $models, $cats) {
            if ($args['vehicle_ids'] && !in_array((int) $row['id'], $args['vehicle_ids'], true)) {
                return false;
            }
            if ($makes && !in_array(strtolower((string) $row['make']), $makes, true)) {
                return false;
            }
            if ($models && !in_array(strtolower((string) $row['model']), $models, true)) {
                return false;
            }
            if ($cats && !in_array(strtolower((string) $row['category']), $cats, true)) {
                return false;
            }
            if ($args['statuses'] && !in_array((string) ($row['status'] ?? ''), $args['statuses'], true)) {
                return false;
            }
            if ($args['min_seats'] !== null && (int) $row['seating_capacity'] < $args['min_seats']) {
                return false;
            }
            if ($args['max_daily_rate'] !== null && (float) $row['daily_rental_price'] > $args['max_daily_rate']) {
                return false;
            }
            foreach (['fuel_types' => 'fuel_type', 'transmissions' => 'transmission', 'colors' => 'color'] as $key => $column) {
                if ($args[$key] && !in_array(strtolower((string) ($row[$column] ?? '')), array_map('strtolower', $args[$key]), true)) {
                    return false;
                }
            }
            if ($args['min_year'] !== null && (int) $row['year'] < $args['min_year']) {
                return false;
            }
            if ($args['max_year'] !== null && (int) $row['year'] > $args['max_year']) {
                return false;
            }
            if ($args['has_gps'] !== null && (bool) $row['gps_navigation'] !== $args['has_gps']) {
                return false;
            }
            if ($args['has_aircon'] !== null && (bool) $row['air_conditioning'] !== $args['has_aircon']) {
                return false;
            }
            return true;
        }));

        if ($args['sort'] !== null) {
            $column = ['price' => 'daily_rental_price', 'year' => 'year', 'mileage' => 'current_mileage'][explode('_', $args['sort'])[0]];
            $ascending = substr($args['sort'], -4) === '_asc';
            usort($matches, static function ($a, $b) use ($column, $ascending) {
                $order = (float) ($a[$column] ?? 0) <=> (float) ($b[$column] ?? 0);
                return $ascending ? $order : -$order;
            });
        }

        $total   = count($matches);
        $matches = array_slice($matches, 0, $args['limit']);
        $fields  = $is_admin ? array_merge(self::PUBLIC_VEHICLE_FIELDS, self::ADMIN_VEHICLE_FIELDS) : self::PUBLIC_VEHICLE_FIELDS;
        $vehicles = array_map(static function ($row) use ($fields, $period, $booked, $tool, $args) {
            $vehicle = self::typed_vehicle(array_intersect_key($row, array_flip($fields)));
            if ($period !== null) {
                $vehicle['available_for_period'] = ($row['status'] ?? 'available') === 'available'
                    && !in_array((int) $row['id'], $booked, true);
            }
            if ($tool === 'vehicles.quote' || $args['rental_days'] !== null) {
                $vehicle['quote'] = self::quote($vehicle, $args['rental_days']);
            }
            return $vehicle;
        }, $matches);

        return [
            'vehicles'       => $vehicles,
            'total_matching' => $total,
            'truncated'      => $total > count($vehicles),
            'visibility'     => $is_admin ? 'all_vehicles' : 'available_vehicles_only',
            'period'         => $period,
            'rental_days'    => $args['rental_days'],
            'currency'       => 'PHP',
            'pricing_rule'   => 'Rental total = stored daily rate x number of rental days (pickup date inclusive, return date exclusive). The security deposit is listed separately and is not added to the total.',
        ];
    }

    /** Maintenance records, narrowed to the vehicles the request names by id, make or model. */
    private static function maintenance_records(array $args, ChatDataSource $source, $limit)
    {
        $id      = count($args['vehicle_ids']) === 1 ? $args['vehicle_ids'][0] : null;
        $narrow  = $args['makes'] || $args['models'] || count($args['vehicle_ids']) > 1;
        $records = $source->maintenance_records($id, $narrow ? 40 : $limit);
        $records['legacy_service_records']    = array_slice(self::for_vehicles($records['legacy_service_records'] ?? [], $args, 'vehicleID'), 0, $limit);
        $records['verified_evidence_records'] = array_slice(self::for_vehicles($records['verified_evidence_records'] ?? [], $args, 'vehicle_id'), 0, $limit);
        return $records;
    }

    /** Rows whose car matches the request's vehicle ids, makes and models (all optional). */
    private static function for_vehicles(array $rows, array $args, $id_key)
    {
        $makes  = array_map('strtolower', $args['makes']);
        $models = array_map('strtolower', $args['models']);
        return array_values(array_filter($rows, static function ($row) use ($args, $id_key, $makes, $models) {
            return (!$args['vehicle_ids'] || in_array((int) ($row[$id_key] ?? 0), $args['vehicle_ids'], true))
                && (!$makes || in_array(strtolower((string) ($row['make'] ?? '')), $makes, true))
                && (!$models || in_array(strtolower((string) ($row['model'] ?? '')), $models, true));
        }));
    }

    /** Same rule as ReservationModel::create_reservation. */
    private static function quote(array $vehicle, $days)
    {
        if ($days === null) {
            return null;
        }
        $rate    = round((float) $vehicle['daily_rental_price'], 2);
        $minimum = (int) $vehicle['minimum_rental_duration'];
        return [
            'rental_days'      => $days,
            'daily_rate'       => $rate,
            'base_total'       => round($rate * $days, 2),
            'meets_minimum'    => $days >= $minimum,
            'minimum_days'     => $minimum,
            'security_deposit' => round((float) $vehicle['security_deposit'], 2),
        ];
    }

    private static function typed_vehicle(array $vehicle)
    {
        foreach (['daily_rental_price', 'security_deposit'] as $key) {
            if (array_key_exists($key, $vehicle)) {
                $vehicle[$key] = (float) $vehicle[$key];
            }
        }
        foreach (['id', 'year', 'minimum_rental_duration', 'seating_capacity', 'current_mileage'] as $key) {
            if (isset($vehicle[$key])) {
                $vehicle[$key] = (int) $vehicle[$key];
            }
        }
        foreach (['air_conditioning', 'gps_navigation'] as $key) {
            if (array_key_exists($key, $vehicle)) {
                $vehicle[$key] = (bool) $vehicle[$key];
            }
        }
        if (isset($vehicle['additional_features']) && is_string($vehicle['additional_features'])) {
            $decoded = json_decode($vehicle['additional_features'], true);
            $vehicle['additional_features'] = is_array($decoded) ? array_slice($decoded, 0, 20) : [];
        }
        if (isset($vehicle['description'])) {
            $vehicle['description'] = substr((string) $vehicle['description'], 0, 240);
        }
        return $vehicle;
    }

    private static function reservation_rows(array $data, array $args, $today, $with_customer)
    {
        $columns = ['id', 'make', 'model', 'start_date', 'end_date', 'rental_days', 'daily_rate', 'total_amount', 'status'];
        if ($with_customer) {
            $columns[] = 'customer_name';
        }
        $rows = array_values(array_filter($data['reservations'], static function ($row) use ($args, $today) {
            $status = (string) $row['status'];
            if ($args['statuses'] && !in_array($status, $args['statuses'], true)) {
                return false;
            }
            if ($args['reservation_id'] !== null && (int) $row['id'] !== $args['reservation_id']) {
                return false;
            }
            $blocking = in_array($status, ['pending', 'confirmed'], true);
            if ($args['upcoming'] && !($blocking && (string) $row['end_date'] > $today)) {
                return false;
            }
            if ($args['active'] && !($blocking && $row['start_date'] <= $today && $row['end_date'] > $today)) {
                return false;
            }
            if ($args['pickup_on'] !== null && $row['start_date'] !== $args['pickup_on']) {
                return false;
            }
            if ($args['return_on'] !== null && $row['end_date'] !== $args['return_on']) {
                return false;
            }
            // Reservations overlapping [from, to].
            if ($args['from'] !== null && $row['end_date'] < $args['from']) {
                return false;
            }
            if ($args['to'] !== null && $row['start_date'] > $args['to']) {
                return false;
            }
            return $args['customer_name'] === null
                || stripos((string) ($row['customer_name'] ?? ''), $args['customer_name']) !== false;
        }));
        $total  = count($rows);
        $totals = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $totals[$status] = round(($totals[$status] ?? 0) + (is_numeric($row['total_amount'] ?? null) ? (float) $row['total_amount'] : 0), 2);
        }
        $rows = array_map(static function ($row) use ($columns, $today) {
            $out = array_intersect_key($row, array_flip($columns));
            foreach (['daily_rate', 'total_amount'] as $key) {
                $out[$key] = isset($out[$key]) && is_numeric($out[$key]) ? (float) $out[$key] : null;
            }
            $start = new DateTimeImmutable((string) $row['start_date']);
            $end   = new DateTimeImmutable((string) $row['end_date']);
            $now   = new DateTimeImmutable($today);
            $blocking = in_array((string) $row['status'], ['pending', 'confirmed'], true);
            $out['is_active'] = $blocking && $start <= $now && $end > $now;
            $out['days_until_pickup'] = $blocking && $start > $now ? (int) $now->diff($start)->format('%a') : null;
            $out['days_remaining'] = $out['is_active'] ? (int) $now->diff($end)->format('%a') : null;
            return $out;
        }, array_slice($rows, 0, $args['limit']));

        return ['reservations' => $rows, 'total_count' => $total, 'all_records_count' => (int) $data['total_count'],
                'totals_by_status' => $totals, 'currency' => 'PHP', 'today' => $today];
    }

    /** Recorded maintenance costs, overall and per car, optionally within [from, to] by maintenance date. */
    private static function maintenance_costs(array $args, ChatDataSource $source)
    {
        $rows = self::for_vehicles($source->maintenance_cost_records(), $args, 'vehicleID');
        $rows = array_values(array_filter($rows, static function ($row) use ($args) {
            $date = (string) $row['maintenanceDate'];
            return ($args['from'] === null || $date >= $args['from']) && ($args['to'] === null || $date <= $args['to']);
        }));
        $per_vehicle = [];
        $total = 0.0;
        $missing = 0;
        foreach ($rows as $row) {
            $id = (int) $row['vehicleID'];
            $per_vehicle[$id] = $per_vehicle[$id] ?? ['vehicle_id' => $id, 'make' => $row['make'], 'model' => $row['model'],
                                                     'plate_number' => $row['plate_number'] ?? null, 'records' => 0, 'total_cost' => 0.0];
            $per_vehicle[$id]['records']++;
            if (is_numeric($row['cost'] ?? null)) {
                $per_vehicle[$id]['total_cost'] = round($per_vehicle[$id]['total_cost'] + (float) $row['cost'], 2);
                $total = round($total + (float) $row['cost'], 2);
            } else {
                $missing++;
            }
        }
        usort($per_vehicle, static function ($a, $b) { return $b['total_cost'] <=> $a['total_cost']; });
        return ['total_cost' => $total, 'records' => count($rows), 'records_without_cost' => $missing,
                'per_vehicle' => array_values($per_vehicle), 'from' => $args['from'], 'to' => $args['to'], 'currency' => 'PHP'];
    }

    /** One car's status, mileage, service status, last service and current or next booking (administrators). */
    private static function vehicle_status(array $args, ChatDataSource $source, $today)
    {
        $cars = self::vehicles('vehicles.search', $args + ['period' => null, 'rental_days' => null], true, $source)['vehicles'];
        $due = [];
        foreach ($source->maintenance_due() as $row) {
            $due[(int) $row['id']] = $row;
        }
        $records = $source->maintenance_cost_records();
        $reservations = $source->reservations_all(200)['reservations'];
        $out = [];
        foreach ($cars as $car) {
            $id = (int) $car['id'];
            $last = null;
            foreach ($records as $record) {
                if ((int) $record['vehicleID'] === $id) {
                    $last = array_intersect_key($record, array_flip(['maintenanceID', 'maintenanceType', 'maintenanceDate', 'maintenanceStatus', 'cost', 'odometerReading']));
                    break;
                }
            }
            $current = $next = null;
            foreach ($reservations as $row) {
                if ((int) ($row['car_id'] ?? 0) !== $id || !in_array((string) $row['status'], ['pending', 'confirmed'], true)) {
                    continue;
                }
                $summary = array_intersect_key($row, array_flip(['id', 'start_date', 'end_date', 'status', 'customer_name']));
                if ($row['start_date'] <= $today && $row['end_date'] > $today) {
                    $current = $summary;
                } elseif ($row['start_date'] > $today && ($next === null || $row['start_date'] < $next['start_date'])) {
                    $next = $summary;
                }
            }
            $out[] = $car + ['service' => $due[$id] ?? null, 'last_service' => $last, 'current_reservation' => $current, 'next_reservation' => $next];
        }
        return ['vehicles' => $out, 'today' => $today, 'currency' => 'PHP'];
    }

    /**
     * Recorded reservation totals (daily rate x days) by status, optionally for reservations starting in
     * [from, to]. Payments are not recorded, so this is booked value, not money received.
     */
    private static function revenue(array $args, ChatDataSource $source)
    {
        $by_status = [];
        foreach ($source->reservations_all(200)['reservations'] as $row) {
            if (($args['from'] !== null && $row['start_date'] < $args['from']) || ($args['to'] !== null && $row['start_date'] > $args['to'])) {
                continue;
            }
            $status = (string) $row['status'];
            $by_status[$status] = $by_status[$status] ?? ['reservations' => 0, 'total' => 0.0];
            $by_status[$status]['reservations']++;
            $by_status[$status]['total'] = round($by_status[$status]['total'] + (float) $row['total_amount'], 2);
        }
        ksort($by_status);
        $booked = 0.0;
        foreach (['pending', 'confirmed', 'completed'] as $status) {
            $booked = round($booked + ($by_status[$status]['total'] ?? 0), 2);
        }
        return ['by_status' => $by_status, 'booked_total' => $booked, 'from' => $args['from'], 'to' => $args['to'], 'currency' => 'PHP',
                'basis' => 'Stored reservation totals; the system records no payments.'];
    }

    /** Booked days per car within [from, to] (default: the 30 days up to today) from pending, confirmed and completed reservations. */
    private static function utilization(array $args, ChatDataSource $source, $today)
    {
        $to   = new DateTimeImmutable($args['to'] ?? $today);
        $from = new DateTimeImmutable($args['from'] ?? $to->modify('-30 days')->format('Y-m-d'));
        $window = max(1, (int) $from->diff($to)->format('%a'));
        $booked = [];
        foreach ($source->reservations_all(200)['reservations'] as $row) {
            if (!in_array((string) $row['status'], ['pending', 'confirmed', 'completed'], true)) {
                continue;
            }
            $start = max($from, new DateTimeImmutable((string) $row['start_date']));
            $end   = min($to, new DateTimeImmutable((string) $row['end_date']));
            if ($end > $start) {
                $id = (int) $row['car_id'];
                $booked[$id] = ($booked[$id] ?? 0) + (int) $start->diff($end)->format('%a');
            }
        }
        $cars = array_map(static function ($car) use ($booked, $window) {
            $days = $booked[(int) $car['id']] ?? 0;
            return ['id' => (int) $car['id'], 'make' => $car['make'], 'model' => $car['model'], 'year' => (int) $car['year'],
                    'plate_number' => $car['plate_number'] ?? null, 'booked_days' => $days, 'window_days' => $window,
                    'utilization_percent' => round(100 * min($days, $window) / $window, 1)];
        }, $source->vehicles(true));
        usort($cars, static function ($a, $b) { return $b['utilization_percent'] <=> $a['utilization_percent']; });
        return ['vehicles' => $cars, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'window_days' => $window];
    }

    /** Service types recorded two or more times for the same car. */
    private static function recurring(array $records)
    {
        $groups = [];
        foreach ($records['legacy_service_records'] ?? [] as $row) {
            $type = strtolower(trim((string) $row['maintenanceType']));
            if ($type === '') {
                continue;
            }
            $key = $row['vehicleID'] . '|' . $type;
            $groups[$key] = $groups[$key] ?? ['vehicle_id' => (int) $row['vehicleID'], 'make' => $row['make'], 'model' => $row['model'],
                                               'maintenance_type' => $row['maintenanceType'], 'count' => 0, 'last_date' => null];
            $groups[$key]['count']++;
            if ($groups[$key]['last_date'] === null || $row['maintenanceDate'] > $groups[$key]['last_date']) {
                $groups[$key]['last_date'] = $row['maintenanceDate'];
            }
        }
        return array_values(array_filter($groups, static function ($group) {
            return $group['count'] >= 2;
        }));
    }

    /** Validated [start, end) period, null when no dates were given, or ['error' => reason]. */
    private static function period($start, $end, $today)
    {
        if ($start === null && $end === null) {
            return null;
        }
        $valid = static function ($value) {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                && ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) && $date->format('Y-m-d') === $value;
        };
        if (!$valid($start) || !$valid($end)) {
            return ['error' => 'invalid_dates'];
        }
        if ($start < $today || $end <= $start) {
            return ['error' => 'invalid_dates'];
        }
        $days = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->format('%a');
        if ($days > self::MAX_RENTAL_DAYS) {
            return ['error' => 'invalid_dates'];
        }
        return ['start_date' => $start, 'end_date' => $end, 'rental_days' => $days];
    }

    private static function date_or_null($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function strings($values)
    {
        $values = is_array($values) ? $values : [];
        $clean  = [];
        foreach (array_slice($values, 0, 10) as $value) {
            if (is_string($value) && trim($value) !== '' && strlen($value) <= 60) {
                $clean[] = trim($value);
            }
        }
        return $clean;
    }

    private static function int_in($value, $min, $max)
    {
        if (is_bool($value) || $value === null) {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $int === false ? null : (int) $int;
    }
}
