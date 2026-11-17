<?php

/**
 * `php lava reservations expire`: releases unpaid reservations whose payment hold has run out
 * and expires their open Xendit invoices. Schedule it every few minutes (cron or Windows Task
 * Scheduler). Availability never depends on it running on time: an unpaid reservation stops
 * blocking its dates the moment its hold ends. The job only tidies up statuses and invoices.
 */
class Reservations
{
    public static $command = 'reservations';
    public static $description = 'Reservation maintenance jobs';
    public static $arguments = [
        '[action]' => 'Action: expire',
    ];

    protected static $route_map = [
        'expire' => 'reservations/expire',
    ];

    public function handle($action = null, array $flags = [])
    {
        $action = $action ?? 'expire';
        if (!isset(static::$route_map[$action])) {
            echo danger("Unknown reservations action: \"{$action}\"");
            echo 'Available actions: ' . implode(', ', array_keys(static::$route_map)) . PHP_EOL;
            exit(1);
        }

        $index = PUBLIC_DIR . 'index.php';
        if (!file_exists($index)) {
            echo danger("index.php not found at: {$index}");
            exit(1);
        }
        passthru(sprintf('php %s %s', escapeshellarg($index), escapeshellarg(static::$route_map[$action])), $exitCode);
        exit($exitCode);
    }
}
