<?php

require_once __DIR__ . '/support/ChatFixtureSource.php';

$failures = 0;
$fixture  = json_decode(file_get_contents(__DIR__ . '/fixtures/chat_fixture_db.json'), true);
$customer = ['role' => 'customer', 'user_id' => 2];
$other    = ['role' => 'customer', 'user_id' => 3];
$admin    = ['role' => 'admin', 'user_id' => 1];
$today    = '2026-10-31';

function check($condition, $message)
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function run_tool($tool, array $args, array $viewer, array $db, $today = '2026-10-31')
{
    return ChatRetrieval::execute(['retrievals' => [['tool' => $tool, 'args' => $args]]], $viewer, new ChatFixtureSource($db), $today)[0];
}

function names(array $result)
{
    return array_map(static function ($v) { return $v['make'] . ' ' . $v['model']; }, $result['data']['vehicles']);
}

// --- Permissions -------------------------------------------------------------
foreach (['reservations.summary', 'fleet.status_counts', 'maintenance.records', 'maintenance.due', 'maintenance.recurring',
          'maintenance.inspections', 'maintenance.model_inputs'] as $tool) {
    $result = run_tool($tool, [], $customer, $fixture);
    check($result['status'] === 'denied' && !isset($result['data']), "customer must be denied {$tool}");
    check(run_tool($tool, [], $admin, $fixture)['status'] === 'ok', "admin may use {$tool}");
}
check(run_tool('bookings.mine', [], $admin, $fixture)['status'] === 'denied', 'admins have no personal bookings');
check(run_tool('db.query', ['sql' => 'SELECT * FROM users'], $admin, $fixture)['reason'] === 'unknown_tool', 'unknown tools are rejected');
$many = ChatRetrieval::execute(['retrievals' => array_fill(0, 9, ['tool' => 'vehicles.search', 'args' => []])], $customer, new ChatFixtureSource($fixture), $today);
check(count($many) === ChatRetrieval::MAX_REQUESTS, 'at most MAX_REQUESTS retrievals run per message');
check(ChatRetrieval::execute('not a plan', $customer, new ChatFixtureSource($fixture), $today) === [], 'a malformed plan runs nothing');

// --- Customer vehicle visibility --------------------------------------------
$all = run_tool('vehicles.search', [], $customer, $fixture);
check(names($all) === ['Toyota Fortuner', 'Toyota Vios'], 'customers see available cars only: ' . json_encode(names($all)));
check(!array_key_exists('status', $all['data']['vehicles'][0]) && !array_key_exists('plate_number', $all['data']['vehicles'][0]), 'customers get no status or plate number');
check(run_tool('vehicles.search', ['statuses' => ['maintenance']], $customer, $fixture)['data']['total_matching'] === 2, 'customers cannot filter by internal status');
check(run_tool('vehicles.search', ['models' => ['City']], $customer, $fixture)['data']['total_matching'] === 0, 'a rented car is not listed to customers');
$adminAll = run_tool('vehicles.search', ['statuses' => ['maintenance', 'rented']], $admin, $fixture);
check(names($adminAll) === ['Toyota Hiace', 'Honda City'] && $adminAll['data']['vehicles'][0]['plate_number'] === 'NGH 3456', 'admins can list rented and maintenance cars with plates');

// --- Filters, sorting and pricing --------------------------------------------
$toyota = run_tool('vehicles.search', ['makes' => ['toyota'], 'sort' => 'price_asc'], $customer, $fixture);
check(names($toyota) === ['Toyota Vios', 'Toyota Fortuner'], 'make filter is case-insensitive and sorts by price');
check($toyota['data']['vehicles'][0]['daily_rental_price'] === 1500.0, 'prices are typed floats from the stored rate');
check(names(run_tool('vehicles.search', ['min_seats' => 7], $customer, $fixture)) === ['Toyota Fortuner'], 'seat filter');
check(names(run_tool('vehicles.search', ['max_daily_rate' => 2000], $customer, $fixture)) === ['Toyota Vios'], 'budget filter');
$quote = run_tool('vehicles.quote', ['models' => ['Fortuner'], 'rental_days' => 3], $customer, $fixture);
check($quote['data']['vehicles'][0]['quote'] === ['rental_days' => 3, 'daily_rate' => 3500.0, 'base_total' => 10500.0,
      'meets_minimum' => true, 'minimum_days' => 1, 'security_deposit' => 6000.0], 'quote uses daily rate x days with the deposit separate');
$hiace = run_tool('vehicles.quote', ['models' => ['Hiace'], 'rental_days' => 1], $admin, $fixture);
check($hiace['data']['vehicles'][0]['quote']['meets_minimum'] === false, 'quote flags a period below the minimum duration');
check(run_tool('vehicles.quote', ['models' => ['Vios']], $customer, $fixture)['reason'] === 'rental_days_required', 'a quote needs a duration');

// --- Periods and availability ------------------------------------------------
$period = run_tool('vehicles.search', ['start_date' => '2026-11-21', 'end_date' => '2026-11-24'], $customer, $fixture);
$flags  = array_column($period['data']['vehicles'], 'available_for_period', 'model');
check($flags === ['Fortuner' => false, 'Vios' => true], 'overlapping reservations make a car unavailable for the period');
check($period['data']['rental_days'] === 3 && $period['data']['vehicles'][0]['quote']['base_total'] === 10500.0, 'a period sets the rental days for quotes');
check(run_tool('vehicles.search', ['start_date' => '2026-10-01', 'end_date' => '2026-10-03'], $customer, $fixture)['reason'] === 'invalid_dates', 'past periods are rejected');
check(run_tool('vehicles.search', ['start_date' => '2026-11-05', 'end_date' => '2026-11-05'], $customer, $fixture)['reason'] === 'invalid_dates', 'empty periods are rejected');
check(run_tool('vehicles.search', ['start_date' => '2026-02-30', 'end_date' => '2026-03-02'], $customer, $fixture)['reason'] === 'invalid_dates', 'impossible dates are rejected');

// --- Booking ownership -------------------------------------------------------
$mine = run_tool('bookings.mine', ['user_id' => 3, 'customer_id' => 3], $customer, $fixture);
check(array_column($mine['data']['reservations'], 'id') === [41, 17], 'bookings come from the session user only, ignoring ids in the request');
check(!array_key_exists('customer_name', $mine['data']['reservations'][0]) && !array_key_exists('user_id', $mine['data']['reservations'][0]), 'no customer identity fields in own bookings');
check(array_column(run_tool('bookings.mine', [], $other, $fixture)['data']['reservations'], 'id') === [52], 'another customer sees only their own booking');
$upcoming = run_tool('bookings.mine', ['upcoming' => true], $customer, $fixture);
check(array_column($upcoming['data']['reservations'], 'id') === [41], 'upcoming means pending or confirmed and not yet ended');
check(run_tool('bookings.mine', [], ['role' => 'customer', 'user_id' => 99], $fixture)['data']['total_count'] === 0, 'a customer with no bookings gets an empty list');
$summary = run_tool('reservations.summary', [], $admin, $fixture);
check($summary['data']['counts_by_status'] === ['confirmed' => 1, 'pending' => 1, 'completed' => 1], 'admin reservation counts');
check($summary['data']['reservations'][0]['customer_name'] === 'Ben Cruz' && !array_key_exists('email', $summary['data']['reservations'][0]), 'admins see customer names but no contact details');

// --- Maintenance -------------------------------------------------------------
$recurring = run_tool('maintenance.recurring', [], $admin, $fixture)['data']['issues'];
check(count($recurring) === 1 && $recurring[0]['model'] === 'City' && $recurring[0]['count'] === 2, 'recurring service types are detected');
check(run_tool('maintenance.records', ['vehicle_ids' => [4]], $admin, $fixture)['data']['legacy_service_records'][0]['cost'] === '6800.00', 'records include cost');
check(array_column(run_tool('maintenance.due', ['vehicle_ids' => [3]], $admin, $fixture)['data']['vehicles'], 'service_status') === ['overdue'], 'due status per vehicle');

// --- Fresh data, failures and hostile input -----------------------------------
$changed = $fixture;
$changed['cars'][0]['daily_rental_price'] = '1650.00';
$changed['cars'][] = ['id' => 9, 'make' => 'Mitsubishi', 'model' => 'Xpander', 'year' => 2024, 'category' => 'MPV', 'plate_number' => 'NXP 1',
                      'color' => 'Gray', 'description' => '', 'daily_rental_price' => '2600.00', 'security_deposit' => '5000.00',
                      'minimum_rental_duration' => 1, 'transmission' => 'Automatic', 'fuel_type' => 'Gasoline', 'seating_capacity' => 7,
                      'air_conditioning' => 1, 'gps_navigation' => 1, 'additional_features' => '[]', 'status' => 'available', 'current_mileage' => 100];
check(run_tool('vehicles.search', ['models' => ['Vios']], $customer, $changed)['data']['vehicles'][0]['daily_rental_price'] === 1650.0, 'a changed price is returned on the next request');
check(names(run_tool('vehicles.search', ['makes' => ['Mitsubishi']], $customer, $changed)) === ['Mitsubishi Xpander'], 'a new car is found without any code change');
check(in_array(['make' => 'Mitsubishi', 'model' => 'Xpander'], ChatRetrieval::vocabulary((new ChatFixtureSource($changed))->vehicles(false))['models'], true), 'vocabulary includes new cars');
$broken = $fixture + ['fail' => ['vehicles']];
$error  = run_tool('vehicles.search', [], $customer, $broken);
check($error['status'] === 'error' && $error['reason'] === 'data_unavailable' && !isset($error['data']), 'a database failure is reported without data');
check(run_tool('vehicles.search', ['makes' => ["Toyota' OR '1'='1"]], $customer, $fixture)['data']['total_matching'] === 0, 'hostile filter values match nothing');
check(run_tool('vehicles.search', ['limit' => 100000], $customer, $fixture)['args']['limit'] === 20, 'out-of-range limits fall back to the default');

// --- Feature filters and sorting ------------------------------------------------
check(names(run_tool('vehicles.search', ['fuel_types' => ['diesel']], $customer, $fixture)) === ['Toyota Fortuner'], 'fuel filter');
check(names(run_tool('vehicles.search', ['has_gps' => true], $customer, $fixture)) === ['Toyota Fortuner'], 'GPS filter');
check(names(run_tool('vehicles.search', ['has_gps' => false], $customer, $fixture)) === ['Toyota Vios'], 'no-GPS filter');
check(names(run_tool('vehicles.search', ['transmissions' => ['manual']], $admin, $fixture)) === ['Toyota Hiace'], 'transmission filter (admin sees all cars)');
check(names(run_tool('vehicles.search', ['colors' => ['black']], $customer, $fixture)) === ['Toyota Fortuner'], 'colour filter');
check(names(run_tool('vehicles.search', ['min_year' => 2022], $customer, $fixture)) === ['Toyota Vios'], 'year filter');
check(names(run_tool('vehicles.search', ['sort' => 'mileage_desc'], $admin, $fixture))[0] === 'Toyota Hiace', 'admins can sort by mileage');
check(run_tool('vehicles.search', ['sort' => 'mileage_desc'], $customer, $fixture)['args']['sort'] === null, 'customers cannot sort by internal mileage');
check(names(run_tool('vehicles.search', ['sort' => 'year_desc'], $customer, $fixture)) === ['Toyota Vios', 'Toyota Fortuner'], 'sort by year');

// --- Reservation filters and customer booking details ------------------------------
$pending = run_tool('reservations.summary', ['statuses' => ['pending']], $admin, $fixture);
check(array_column($pending['data']['reservations'], 'id') === [41], 'pending reservations');
check(array_column(run_tool('reservations.summary', ['pickup_on' => '2026-11-20'], $admin, $fixture)['data']['reservations'], 'id') === [52], 'pickups on a date');
check(array_column(run_tool('reservations.summary', ['return_on' => '2026-11-08'], $admin, $fixture)['data']['reservations'], 'id') === [41], 'returns on a date');
check(array_column(run_tool('reservations.summary', ['from' => '2026-11-01', 'to' => '2026-11-10'], $admin, $fixture)['data']['reservations'], 'id') === [41], 'reservations overlapping a range');
check(array_column(run_tool('reservations.summary', ['customer_name' => 'ben'], $admin, $fixture)['data']['reservations'], 'id') === [52], 'admin search by customer name');
check(run_tool('bookings.mine', ['customer_name' => 'Ben'], $customer, $fixture)['args']['customer_name'] === null, 'customers cannot search by name');
$one = run_tool('bookings.mine', ['reservation_id' => 41], $customer, $fixture)['data']['reservations'];
check(count($one) === 1 && $one[0]['days_until_pickup'] === 5 && $one[0]['is_active'] === false, 'own reservation details with days until pickup');
check(run_tool('bookings.mine', ['reservation_id' => 52], $customer, $fixture)['data']['total_count'] === 0, 'another customer\'s reservation id returns nothing');
$active = run_tool('bookings.mine', ['active' => true], $customer, $fixture, '2026-11-06')['data']['reservations'];
check(count($active) === 1 && $active[0]['days_remaining'] === 2, 'an active rental shows the days remaining');
check(run_tool('bookings.mine', [], $customer, $fixture)['data']['totals_by_status'] === ['pending' => 4500.0, 'completed' => 3400.0], 'recorded totals by status');

// --- Costs, status, revenue, utilisation -------------------------------------------
$costs = run_tool('maintenance.costs', [], $admin, $fixture)['data'];
check($costs['total_cost'] === 11700.0 && $costs['per_vehicle'][0]['model'] === 'Hiace' && $costs['per_vehicle'][1]['total_cost'] === 4900.0, 'maintenance cost totals per vehicle');
check(run_tool('maintenance.costs', ['from' => '2026-08-01', 'to' => '2026-12-31'], $admin, $fixture)['data']['total_cost'] === 9300.0, 'maintenance costs in a date range');
check(run_tool('maintenance.costs', ['from' => '2026-12-01', 'to' => '2026-01-01'], $admin, $fixture)['reason'] === 'invalid_dates', 'reversed date range is rejected');
check(run_tool('maintenance.costs', [], $customer, $fixture)['status'] === 'denied', 'customers cannot read maintenance costs');
$status = run_tool('vehicles.status', ['models' => ['Fortuner']], $admin, $fixture)['data']['vehicles'][0];
check($status['current_mileage'] === 72100 && $status['service']['service_status'] === 'approaching' && $status['next_reservation']['id'] === 52, 'per-vehicle status, service and next booking');
check(run_tool('vehicles.status', ['models' => ['Vios']], $customer, $fixture)['status'] === 'denied', 'customers cannot read vehicle status');
$revenue = run_tool('reservations.revenue', [], $admin, $fixture)['data']['by_status'];
check($revenue['confirmed']['total'] === 10500.0 && $revenue['completed']['total'] === 3400.0 && $revenue['pending']['total'] === 4500.0, 'revenue by status from stored totals');
$util = run_tool('fleet.utilization', ['from' => '2026-11-01', 'to' => '2026-11-30'], $admin, $fixture)['data'];
$byModel = array_column($util['vehicles'], 'booked_days', 'model');
check($util['window_days'] === 29 && $byModel['Fortuner'] === 3 && $byModel['Vios'] === 3 && $byModel['Hiace'] === 0, 'utilisation counts booked days in the window');

// --- Duplicate names and model names with hyphens ------------------------------------
$dupes = $fixture;
$dupes['cars'][] = array_merge($fixture['cars'][0], ['id' => 6, 'year' => 2019, 'color' => 'Silver', 'plate_number' => 'NVI 6', 'daily_rental_price' => '1400.00']);
$dupes['cars'][] = array_merge($fixture['cars'][0], ['id' => 7, 'make' => 'Honda', 'model' => 'CR-V', 'plate_number' => 'NCR 7']);
check(count(run_tool('vehicles.search', ['models' => ['Vios']], $customer, $dupes)['data']['vehicles']) === 2, 'two cars with the same name are both returned');
check(names(run_tool('vehicles.search', ['models' => ['cr-v']], $customer, $dupes)) === ['Honda CR-V'], 'model names with hyphens match');
check(in_array(['make' => 'Honda', 'model' => 'CR-V'], ChatRetrieval::vocabulary((new ChatFixtureSource($dupes))->vehicles(false))['models'], true), 'vocabulary keeps hyphenated names');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} chat retrieval assertion(s) failed.\n");
    exit(1);
}
echo "Chat retrieval tests passed.\n";
