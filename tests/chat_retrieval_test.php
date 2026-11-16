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
          'maintenance.inspections', 'maintenance.model_inputs', 'payments.summary', 'customers.summary'] as $tool) {
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
      'meets_minimum' => true, 'minimum_days' => 3, 'special_request_required' => false, 'exceeds_maximum' => false,
      'standard_maximum_days' => 7, 'maximum_days' => 21, 'security_deposit' => 6000.0], 'quote uses daily rate x days with the deposit separate');
// The system-wide 3-day minimum (ReservationPolicy) applies even when the listing allows 1 day.
$short = run_tool('vehicles.quote', ['models' => ['Fortuner'], 'rental_days' => 2], $customer, $fixture)['data']['vehicles'][0];
check($short['quote']['meets_minimum'] === false && $short['minimum_rental_duration'] === 3, 'quote applies the 3-day system minimum');
$long = run_tool('vehicles.quote', ['models' => ['Fortuner'], 'rental_days' => 10], $customer, $fixture)['data']['vehicles'][0]['quote'];
check($long['special_request_required'] === true && $long['exceeds_maximum'] === false, '8 to 21 days needs a special-duration request');
$tooLong = run_tool('vehicles.quote', ['models' => ['Fortuner'], 'rental_days' => 30], $customer, $fixture)['data']['vehicles'][0]['quote'];
check($tooLong['exceeds_maximum'] === true, 'more than 21 days cannot be booked');
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
$specialFixture = $fixture;
$specialFixture['reservations'][] = [
    'id' => 53, 'user_id' => 2, 'car_id' => 1, 'start_date' => '2026-11-10', 'end_date' => '2026-11-20',
    'rental_days' => 10, 'daily_rate' => '1500.00', 'total_amount' => '15000.00',
    'status' => 'awaiting_approval', 'payment_status' => 'paid',
];
$specialUpcoming = run_tool('bookings.mine', ['upcoming' => true], $customer, $specialFixture);
check(array_column($specialUpcoming['data']['reservations'], 'id') === [41, 53], 'paid special reservations remain visible in upcoming bookings');
$specialSummary = run_tool('reservations.summary', ['statuses' => ['awaiting_approval']], $admin, $specialFixture);
check(array_column($specialSummary['data']['reservations'], 'id') === [53], 'administrators can filter paid special reservations awaiting approval');
$specialPeriod = run_tool('vehicles.search', ['start_date' => '2026-11-12', 'end_date' => '2026-11-15'], $customer, $specialFixture);
check(array_column(array_filter($specialPeriod['data']['vehicles'], static function ($car) { return $car['model'] === 'Vios'; }), 'available_for_period') === [false], 'special reservations block the car during their dates');
// Monthly reassessment state, for administrators only.
$assessed = $fixture + ['reassessments' => [
    ['id' => 1, 'make' => 'Toyota', 'model' => 'Vios', 'plate_number' => 'ABC 1234', 'assessed_on' => '2026-09-01',
     'reassessment' => ['status' => 'overdue', 'due_on' => '2026-10-01', 'days_until_due' => -30]],
    ['id' => 2, 'make' => 'Toyota', 'model' => 'Fortuner', 'plate_number' => 'XYZ 5678', 'assessed_on' => null,
     'reassessment' => ['status' => 'never_assessed', 'due_on' => null, 'days_until_due' => null]],
]];
$due = run_tool('assessments.due', [], $admin, $assessed);
check($due['status'] === 'ok' && $due['data']['counts'] === ['overdue' => 1, 'never_assessed' => 1], 'reassessment counts by status');
check($due['data']['vehicles'][0]['due_on'] === '2026-10-01' && $due['data']['vehicles'][0]['days_until_due'] === -30, 'reassessment due date and days');
check(run_tool('assessments.due', ['models' => ['Vios']], $admin, $assessed)['data']['counts'] === ['overdue' => 1], 'reassessment filtered by model');
check(run_tool('assessments.due', [], $customer, $assessed)['status'] === 'denied', 'customers cannot see reassessments');

// Assessment condition (latest ratings, trend, findings, verified outcomes) for the administrator chat.
$condition = $fixture + ['assessment_condition' => [
    ['id' => 1, 'make' => 'Toyota', 'model' => 'Vios', 'plate_number' => 'NAB 1234', 'assessed_on' => '2026-10-20', 'overall_screening' => 'critical',
     'systems' => [['category_key' => 'brakes', 'category' => 'Brakes', 'rating' => 'critical', 'rating_score' => 1, 'previous_rating' => 'fair', 'previous_score' => 3]],
     'findings' => [['category_key' => 'brakes', 'severity' => 'critical', 'is_safety' => true, 'observation' => 'Brake pedal not firm.', 'recommended_action' => 'Do not drive.']],
     'outcomes' => ['brakes' => ['confirmed_failure' => 2]]],
    ['id' => 2, 'make' => 'Toyota', 'model' => 'Fortuner', 'plate_number' => 'XYZ 5678', 'assessed_on' => '2026-10-21', 'overall_screening' => 'monitor',
     'systems' => [], 'findings' => [], 'outcomes' => []],
]];
$cond = run_tool('assessments.condition', [], $admin, $condition);
check($cond['status'] === 'ok' && count($cond['data']['vehicles']) === 2, 'assessment condition for every assessed vehicle');
check($cond['data']['vehicles'][0]['systems'][0]['previous_rating'] === 'fair', 'assessment condition keeps the previous rating for the trend');
check(count(run_tool('assessments.condition', ['models' => ['Fortuner']], $admin, $condition)['data']['vehicles']) === 1, 'assessment condition filtered by model');
check(run_tool('assessments.condition', [], $customer, $condition)['status'] === 'denied', 'customers cannot see assessment condition');
$failing = $condition + ['fail' => ['assessment_condition']];
check(run_tool('assessments.condition', [], $admin, $failing)['status'] === 'error', 'a database failure is reported, not hidden');

// Payment state reaches the chat; payment references and checkout links do not.
$paidFixture = $fixture;
foreach ($paidFixture['reservations'] as &$row) {
    if ((int) $row['id'] === 41) {
        $row += ['payment_status' => 'failed', 'payment_reference' => 'DR-SECRET-REF', 'checkout_url' => 'https://pay.example/x',
                 'checked_out_at' => null, 'returned_at' => null];
    }
}
unset($row);
$paid = run_tool('bookings.mine', ['reservation_id' => 41], $customer, $paidFixture)['data']['reservations'][0];
check($paid['payment_status'] === 'failed' && $paid['handed_over'] === false && $paid['returned'] === false, 'own booking carries its payment status');
check(!isset($paid['payment_reference']) && !isset($paid['checkout_url']), 'payment references and checkout links are not passed to the chat');
check($one[0]['payment_status'] === null, 'a booking without payment data reports no payment status');

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
// Booked value per car and per customer, over every reservation; cancelled and rejected ones carry no value.
$fullRevenue = run_tool('reservations.revenue', [], $admin, $fixture)['data'];
$bookedSum = array_sum(array_column($fullRevenue['by_vehicle'], 'booked'));
check(abs($bookedSum - $fullRevenue['booked_total']) < 0.01, 'per-vehicle booked value adds up to the booked total');
check(abs(array_sum(array_column($fullRevenue['by_customer'], 'booked')) - $fullRevenue['booked_total']) < 0.01, 'per-customer booked value adds up to the booked total');
check($fullRevenue['by_vehicle'][0]['booked'] >= end($fullRevenue['by_vehicle'])['booked'], 'per-vehicle value sorted highest first');
check($fullRevenue['paid_total'] === 0.0, 'no verified payments in the fixture');
$summary = run_tool('reservations.summary', [], $admin, $fixture)['data'];
check(array_sum(array_column($summary['by_customer'], 'reservations')) === $summary['rows_counted'], 'customer ranking counts every reservation');
$specialRevenue = run_tool('reservations.revenue', [], $admin, $specialFixture)['data'];
check($specialRevenue['by_status']['awaiting_approval']['total'] === 15000.0 && $specialRevenue['booked_total'] === 33400.0, 'paid special rentals remain in booked revenue');
$util = run_tool('fleet.utilization', ['from' => '2026-11-01', 'to' => '2026-11-30'], $admin, $fixture)['data'];
$byModel = array_column($util['vehicles'], 'booked_days', 'model');
check($util['window_days'] === 29 && $byModel['Fortuner'] === 3 && $byModel['Vios'] === 3 && $byModel['Hiace'] === 0, 'utilisation counts booked days in the window');

// --- Payments and customers (administrators) ------------------------------------------
$billing = $fixture;
$billing['payments'] = [
    ['id' => 1, 'reservation_id' => 41, 'reference_number' => 'INV-1', 'amount' => '4500.00', 'status' => 'expired', 'created_at' => '2026-10-29 09:00:00'],
    ['id' => 2, 'reservation_id' => 41, 'reference_number' => 'INV-2', 'amount' => '4500.00', 'status' => 'pending', 'created_at' => '2026-10-30 10:00:00'],
    ['id' => 3, 'reservation_id' => 52, 'reference_number' => 'INV-3', 'amount' => '10500.00', 'paid_amount' => '10500.00', 'status' => 'paid',
     'created_at' => '2026-10-30 11:00:00', 'paid_at' => '2026-10-30 11:05:00'],
    ['id' => 4, 'reservation_id' => 17, 'reference_number' => 'INV-4', 'amount' => '3400.00', 'paid_amount' => '3400.00', 'status' => 'paid',
     'created_at' => '2026-09-01 08:00:00', 'paid_at' => '2026-09-01 08:02:00', 'review_status' => 'needs_review',
     'review_reason' => 'Paid after the reservation was cancelled. Refund the customer.'],
];
$billing['users'][1] += ['created_at' => '2026-10-20 08:00:00', 'has_licence' => true, 'has_photo' => true];
$billing['users'][2] += ['created_at' => '2026-09-01 08:00:00', 'has_licence' => false, 'has_photo' => false, 'email' => 'ben@example.com'];
$pay = run_tool('payments.summary', [], $admin, $billing)['data'];
check($pay['counts_by_status'] === ['pending' => 1, 'paid' => 2, 'expired' => 1] && $pay['paid_total'] === 13900.0, 'payment counts and amount received');
check($pay['needing_review_count'] === 1 && $pay['needing_review'][0]['reservation_id'] === 17, 'payments needing review are listed');
check($pay['matching'][0]['id'] === 4 && $pay['matching'][0]['customer_name'] === 'Ana Santos', 'attempts come newest first with the customer name');
$window = run_tool('payments.summary', ['from' => '2026-10-30', 'to' => '2026-10-30', 'statuses' => ['paid']], $admin, $billing)['data'];
check($window['attempts'] === 2 && $window['matching_count'] === 1 && $window['paid_total'] === 10500.0, 'payments narrowed to a day and a status');
check(run_tool('payments.summary', ['reservation_id' => 41], $admin, $billing)['data']['attempts'] === 2, 'payments of one reservation');
check(run_tool('payments.summary', ['statuses' => ['refunded']], $admin, $billing)['data']['matching_count'] === 4, 'an unknown payment status is ignored');
check(!array_key_exists('checkout_url', $pay['matching'][0]), 'checkout links are not sent to the chat');

$people = run_tool('customers.summary', [], $admin, $billing)['data'];
check($people['total_customers'] === 2 && $people['with_bookings'] === 2, 'customer totals leave out administrators');
check($people['customers'][0]['name'] === 'Ana Santos' && $people['customers'][0]['reservations'] === 2, 'customers ranked by bookings');
check($people['without_verified_licence'] === 1, 'customers without a licence and photo on file are counted');
$spend = run_tool('customers.summary', ['sort' => 'spend'], $admin, $billing)['data']['customers'];
check($spend[0]['name'] === 'Ben Cruz' && $spend[0]['paid_total'] === 10500.0, 'customers ranked by verified payments');
$new = run_tool('customers.summary', ['from' => '2026-10-01', 'to' => '2026-10-31'], $admin, $billing)['data'];
check($new['joined_in_period'] === 1 && $new['customers'][0]['name'] === 'Ana Santos', 'customers who joined in a period');
check(array_column(run_tool('customers.summary', ['missing_licence' => true], $admin, $billing)['data']['customers'], 'name') === ['Ben Cruz'],
      'customers missing a licence are listed');
check(strpos(json_encode($people), 'ben@example.com') === false, 'customer contact details never reach the chat');

// --- Demand (administrators) -----------------------------------------------------------
// A year of generated bookings on car 1: one 3-day rental starting on the 3rd of each month from 2025-11 to 2026-10, booked
// 5 days ahead, plus one cancelled and one expired booking, and one confirmed booking next month (today is 2026-10-31).
$demand = $fixture;
$demand['reservations'] = [];
for ($i = 0; $i < 12; $i++) {
    $start = (new DateTimeImmutable('2025-11-03'))->modify("+{$i} months");
    $demand['reservations'][] = ['id' => 100 + $i, 'user_id' => 2, 'car_id' => 1, 'start_date' => $start->format('Y-m-d'),
                                 'end_date' => $start->modify('+3 days')->format('Y-m-d'), 'rental_days' => 3, 'daily_rate' => '1500.00',
                                 'total_amount' => '4500.00', 'status' => 'completed', 'created_at' => $start->modify('-5 days')->format('Y-m-d 09:00:00')];
}
$demand['reservations'][] = ['id' => 200, 'user_id' => 2, 'car_id' => 1, 'start_date' => '2026-10-20', 'end_date' => '2026-10-23', 'rental_days' => 3,
                             'daily_rate' => '1500.00', 'total_amount' => '4500.00', 'status' => 'cancelled', 'created_at' => '2026-10-10 09:00:00'];
$demand['reservations'][] = ['id' => 201, 'user_id' => 3, 'car_id' => 2, 'start_date' => '2026-10-25', 'end_date' => '2026-10-28', 'rental_days' => 3,
                             'daily_rate' => '3500.00', 'total_amount' => '10500.00', 'status' => 'expired', 'created_at' => '2026-10-24 09:00:00'];
$demand['reservations'][] = ['id' => 202, 'user_id' => 3, 'car_id' => 2, 'start_date' => '2026-11-10', 'end_date' => '2026-11-14', 'rental_days' => 4,
                             'daily_rate' => '3500.00', 'total_amount' => '14000.00', 'status' => 'confirmed', 'created_at' => '2026-10-30 09:00:00'];
$summary = run_tool('demand.summary', [], $admin, $demand)['data'];
$byMonth = array_column($summary['by_month'], null, 'month');
check(count($summary['by_month']) === 15 && $byMonth['2026-10']['booked_days'] === 3 && $byMonth['2026-11']['booked_days'] === 4,
      'booked days per month, the last 12 months and the next 3');
check($byMonth['2026-10']['cancelled'] === 1 && $byMonth['2026-10']['expired'] === 1 && $byMonth['2026-10']['bookings'] === 1,
      'cancelled and expired bookings are counted apart from demand');
// History counts completed months only: 2025-11 to 2026-09, since October is still running.
check($summary['on_the_books']['next_30_days'] === 4 && $summary['history_months'] === 11, 'days already booked ahead, and months of history');
check(array_sum($summary['pickups_by_weekday']) === 13 && $summary['lead_time_days']['median'] === 5, 'pickups per weekday and how far ahead people book');
check($summary['cancellation_rate_percent'] === 7.1 && $summary['expiry_rate_percent'] === 7.1, 'cancellation and expiry rates over past months');
check(count($summary['forecast']) === 3 && $summary['forecast'][0]['month'] === '2026-11' && $summary['forecast'][0]['expected_booked_days'] === 3
      && $summary['forecast'][0]['basis'] === 'same month in earlier years', 'a forecast from the same month last year once there is enough history');
$recent = $demand;
$recent['reservations'] = array_slice($demand['reservations'], 10);
check(run_tool('demand.summary', [], $admin, $recent)['data']['forecast'] === null, 'no forecast with under six months of history');
check(run_tool('demand.summary', ['month' => '2026-12'], $admin, $demand)['data']['asked_month']['month'] === '2026-12', 'a month asked about');
check(run_tool('demand.summary', [], $customer, $demand)['status'] === 'denied', 'customers may not read demand');

// --- Overdue returns ----------------------------------------------------------------
$late = $fixture;
foreach ($late['reservations'] as &$row) {
    if ((int) $row['id'] === 52) {
        $row = array_merge($row, ['start_date' => '2026-10-20', 'end_date' => '2026-10-27', 'checked_out_at' => '2026-10-20 09:00:00', 'returned_at' => null]);
    }
}
unset($row);
$overdue = run_tool('reservations.summary', ['overdue' => true], $admin, $late)['data'];
check($overdue['total_count'] === 1 && $overdue['reservations'][0]['id'] === 52 && $overdue['reservations'][0]['days_overdue'] === 4,
      'a handed-over rental past its return date is overdue, with the days late');
check(run_tool('reservations.summary', ['overdue' => true], $admin, $fixture)['data']['total_count'] === 0, 'no overdue rentals without a handover');

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
