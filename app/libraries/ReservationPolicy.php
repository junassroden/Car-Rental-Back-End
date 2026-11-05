<?php

class ReservationPolicy
{
    const MINIMUM_RENTAL_DAYS = 3;
    const STANDARD_MAXIMUM_DAYS = 7;
    const SPECIAL_MAXIMUM_DAYS = 21;

    public static function duration_error($days, $vehicle_minimum, $special_request)
    {
        if ($days < max(self::MINIMUM_RENTAL_DAYS, (int) $vehicle_minimum)) {
            return 'duration_too_short';
        }
        if ($days > self::SPECIAL_MAXIMUM_DAYS) {
            return 'duration_too_long';
        }
        if ($days > self::STANDARD_MAXIMUM_DAYS && !$special_request) {
            return 'special_request_required';
        }
        return null;
    }
}
