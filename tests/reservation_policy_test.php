<?php

require_once __DIR__ . '/../app/libraries/ReservationPolicy.php';
require_once __DIR__ . '/../app/libraries/RentalAgreement.php';

function assert_same_value($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assert_same_value('duration_too_short', ReservationPolicy::duration_error(2, 1, false), 'standard rentals require at least three days');
assert_same_value(null, ReservationPolicy::duration_error(3, 1, false), 'three-day standard rental is accepted');
assert_same_value(null, ReservationPolicy::duration_error(7, 1, false), 'seven-day standard rental is accepted');
assert_same_value('special_request_required', ReservationPolicy::duration_error(8, 1, false), 'long rentals require a special request');
assert_same_value(null, ReservationPolicy::duration_error(8, 1, true), 'special rental request is accepted');
assert_same_value(null, ReservationPolicy::duration_error(21, 1, true), 'special rentals may reach 21 days');
assert_same_value('duration_too_long', ReservationPolicy::duration_error(22, 1, true), 'special rentals cannot exceed 21 days');
assert_same_value('duration_too_short', ReservationPolicy::duration_error(7, 8, true), 'vehicle minimum rental duration is enforced');

$contract = RentalAgreement::generate([
    'id' => 12,
    'start_date' => '2026-11-05',
    'end_date' => '2026-11-08',
    'rental_days' => 3,
    'daily_rate' => '1500.00',
    'total_amount' => '4500.00',
], [
    'year' => 2025,
    'make' => 'Toyota',
    'model' => 'Vios',
    'plate_number' => 'ABC-1234',
    'security_deposit' => '2000.00',
], [
    'full_name' => 'Juan Dela Cruz',
    'username' => 'juan',
    'email' => 'juan@example.com',
], '2026-11-03 10:00:00');
assert_same_value(true, strpos($contract, 'Agreement number: DR-00000012') !== false, 'agreement includes its booking number');
assert_same_value(true, strpos($contract, 'PHP 4,500.00') !== false, 'agreement records the calculated rental total');
assert_same_value(true, strpos($contract, 'Any extension requires administrator approval') !== false, 'agreement describes the extension policy');

echo "Reservation policy tests passed.\n";
