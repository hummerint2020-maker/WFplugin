<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Validates a Sign In / Sign Out record added or edited by an administrator on the
 * Sign In / Out Report. Pure: no WordPress calls. Error codes are the ?time_error= values.
 */
final class ManualRecordRules
{
    public const EVENT_TYPES = ['sign_in' => 'Sign In', 'late_sign_in' => 'Late Sign In', 'sign_out' => 'Sign Out'];

    /** "2026-10-01T09:05", "2026-10-01 09:05" or with seconds → "2026-10-01 09:05:00", else null. */
    public static function eventAt(string $raw): ?string
    {
        $raw = str_replace('T', ' ', trim($raw));
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) return null;
        if ((int) $m[2] > 23 || (int) $m[3] > 59 || (int) ($m[4] ?? 0) > 59) return null;
        return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00');
    }

    public static function isSignIn(string $type): bool
    {
        return $type === 'sign_in' || $type === 'late_sign_in';
    }

    /**
     * @param array{type: string, date_valid: bool, work_date: string, event_at: ?string, employee_found?: bool,
     *   others?: array<int, array{type: string, at: string}>} $f others = the employee's other events that day
     */
    public static function check(array $f): ?string
    {
        if (!isset(self::EVENT_TYPES[$f['type']]) || empty($f['date_valid']) || $f['event_at'] === null) return 'invalid';
        if (array_key_exists('employee_found', $f) && !$f['employee_found']) return 'employee';
        if (substr($f['event_at'], 0, 10) !== $f['work_date']) return 'date_mismatch';
        $signIn = self::isSignIn($f['type']);
        foreach ($f['others'] ?? [] as $o) {
            $otherIsSignIn = self::isSignIn($o['type']);
            if ($otherIsSignIn === $signIn) return 'duplicate';
        }
        foreach ($f['others'] ?? [] as $o) {
            if ($signIn && $o['type'] === 'sign_out' && $o['at'] <= $f['event_at']) return 'order';
            if (!$signIn && self::isSignIn($o['type']) && $o['at'] >= $f['event_at']) return 'order';
        }
        return null;
    }

    public static function errorMessage(string $code): string
    {
        $messages = [
            'invalid' => 'Choose an event and enter a valid Work Date and time.',
            'employee' => 'Employee not found.',
            'date_mismatch' => 'The event time must be on the Work Date.',
            'duplicate' => 'This employee already has that event on this day. Edit the existing record instead.',
            'order' => 'Sign Out must be after Sign In.',
            'not_found' => 'Attendance record not found.',
            'save' => 'The attendance record could not be saved.',
        ];
        return $messages[$code] ?? 'The attendance record could not be saved.';
    }
}
