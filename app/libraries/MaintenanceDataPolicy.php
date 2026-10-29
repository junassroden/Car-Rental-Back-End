<?php

class MaintenanceDataPolicy
{
    public static function can_access($user)
    {
        return is_array($user)
            && (int) ($user['is_active'] ?? 0) === 1
            && ($user['role'] ?? '') === 'admin';
    }

    public static function assert_transition($from, $to)
    {
        $allowed = [
            'draft' => ['awaiting_verification'],
            'awaiting_verification' => ['verified', 'rejected'],
            'verified' => ['superseded'],
            'rejected' => [],
            'superseded' => [],
        ];
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new InvalidArgumentException("Verification status transition from {$from} to {$to} is not allowed.");
        }
    }

    public static function can_verify(array $record, $user_id)
    {
        return $record['created_by'] === null
            || (int) $record['created_by'] !== (int) $user_id;
    }

    public static function confirmed_fault_label(array $record)
    {
        return ($record['verification_status'] ?? '') === 'verified'
            && ($record['record_type'] ?? '') === 'mechanic_confirmed_fault'
            && !empty($record['confirmed_cause']);
    }
}
