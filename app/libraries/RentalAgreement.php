<?php

class RentalAgreement
{
    const VERSION = '1.0';

    public static function generate(array $reservation, array $car, array $renter, $acceptedAt)
    {
        $name = trim((string) ($renter['full_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($renter['username'] ?? 'Customer');
        }

        $vehicle = trim(implode(' ', array_filter([
            $car['year'] ?? null,
            $car['make'] ?? null,
            $car['model'] ?? null,
        ])));
        $color = trim((string) ($car['variant_color'] ?? ''));
        if ($color !== '') {
            // A colour can have several units, so the listing's plate may not be the unit handed over.
            $vehicle .= ' (' . $color . ')';
        }
        $plate = trim((string) ($car['plate_number'] ?? ''));
        if ($plate !== '' && $color === '') {
            $vehicle .= ' (plate ' . $plate . ')';
        }

        $dailyRate = number_format((float) $reservation['daily_rate'], 2);
        $total = number_format((float) $reservation['total_amount'], 2);
        $deposit = number_format((float) ($car['security_deposit'] ?? 0), 2);
        $accepted = (new DateTimeImmutable($acceptedAt))->format('F j, Y g:i A');

        return implode("\n", [
            'DRIVERENT VEHICLE RENTAL AGREEMENT',
            'Agreement number: DR-' . str_pad((string) $reservation['id'], 8, '0', STR_PAD_LEFT),
            'Agreement version: ' . self::VERSION,
            '',
            'RENTER',
            $name . ' (' . $renter['email'] . ')',
            '',
            'VEHICLE',
            $vehicle,
            '',
            'RENTAL PERIOD',
            'Pickup: ' . (new DateTimeImmutable($reservation['start_date']))->format('F j, Y'),
            'Return: ' . (new DateTimeImmutable($reservation['end_date']))->format('F j, Y'),
            'Rental days: ' . (int) $reservation['rental_days'],
            '',
            'CHARGES',
            'Daily rental rate: PHP ' . $dailyRate,
            'Rental total: PHP ' . $total,
            'Security deposit, if applicable: PHP ' . $deposit,
            'Payment method: GCash through PayMongo',
            '',
            'RESERVATION TERMS',
            '1. This agreement records the vehicle, rental dates, and charges requested by the renter. The reservation is subject to administrator approval.',
            '2. Payment must be completed and verified before vehicle handover. A payment receipt does not replace administrator approval.',
            '3. The vehicle must be returned on the stated return date. Any extension requires administrator approval and an updated agreement.',
            '4. The renter must present a valid driver license and follow the rental rules provided by DriveRent before pickup.',
            '5. By submitting this reservation, the renter accepts the dates, charges, and terms shown in this agreement.',
            '',
            'Accepted electronically on ' . $accepted . '.',
        ]);
    }
}
