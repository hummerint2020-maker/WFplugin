<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * The words and decisions of the Sign In evidence (3.31.139), moved as they were: why an attempt was refused, the Audit Log line of one, the audit action,
 * and when the managers are told. Pure: no WordPress calls (wp-admin is in English).
 */
final class EvidenceRules
{
    /** Why an attempt was refused, in words. $distSuffix: " (850 m away)" or "" (the caller formats the distance). */
    public static function reasonLabel(string $reason, string $distSuffix): string
    {
        $map = [
            'outside_location' => 'Outside the work location' . $distSuffix, 'outside_kiosk' => 'Outside the kiosk\'s work location' . $distSuffix,
            'location_required' => 'No location from the phone', 'kiosk_no_coords' => 'The kiosk\'s location has no coordinates',
            'face_required' => 'No face check (Face Sign In is required)', 'no_employee' => 'Not linked to an employee', 'attendance_disabled' => 'Attendance tracking is off for this employee',
            'general_leave' => 'Company holiday', 'not_working_day' => 'Not a working day for them', 'too_early' => 'Too early', 'too_late' => 'Too late',
            'already_signed_in' => 'Already signed in', 'already_signed_out' => 'Already signed out', 'not_signed_in' => 'Not signed in yet', 'on_break' => 'On a break',
            'save_failed' => 'Could not be saved (server)', 'in_progress' => 'Another Sign In was being saved', 'idempotency_conflict' => 'Sent twice',
            'face_mismatch' => 'Face did not match', 'face_locked' => 'Face check locked (too many tries)', 'face_replay' => 'Face data sent again (refused)',
            'face_challenge' => 'Face check expired', 'face_not_enrolled' => 'No face enrolled', 'face_ok' => 'Face matched', 'no_network' => 'Did not reach the server (no network)',
        ];
        return $map[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
    }

    /** The event's name in the report: a face check, or Sign In / Sign Out. */
    public static function eventLabel(string $eventType): string
    {
        if ($eventType === 'face') return 'Face check';
        return $eventType === 'sign_out' ? 'Sign Out' : 'Sign In';
    }

    /** The Audit Log action of a refused or unsent attempt. */
    public static function auditAction(string $result, string $eventType): string
    {
        return $result === 'not_reached' ? 'signin_not_reached' : ($eventType === 'face' ? 'face_check_failed' : 'signin_refused');
    }

    /**
     * The Audit Log line of a refused or unsent attempt: who, what, why; for an unsent one the real time from the server's clock, the phone's own time and the gap.
     * @param array<string,mixed> $row the attempt as it was stored
     */
    public static function auditText(string $name, string $eventLabel, string $reasonLabel, array $row): string
    {
        $parts = [$name, $eventLabel, $reasonLabel];
        if (($row['result'] ?? '') === 'not_reached') {
            $parts[] = 'pressed at ' . substr((string) $row['attempted_at'], 11, 5) . (empty($row['time_sure']) ? ' (not certain)' : '');
            if (!empty($row['phone_time'])) $parts[] = 'phone said ' . substr((string) $row['phone_time'], 11, 5);
            if (isset($row['clock_skew']) && abs((int) $row['clock_skew']) > 120) $parts[] = 'phone clock off by ' . round(abs((int) $row['clock_skew']) / 60) . ' min';
        }
        $parts[] = trim((string) ($row['device'] ?? '') . ' ' . (string) ($row['ip_address'] ?? ''));
        return implode(' / ', array_filter($parts, 'strlen'));
    }

    /** The managers are told once: when the day's refusals reach exactly N (N = 0: never). */
    public static function alertDue(int $count, int $n): bool
    {
        return $n >= 1 && $count === $n;
    }
}
