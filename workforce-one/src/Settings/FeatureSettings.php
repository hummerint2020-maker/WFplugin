<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Normalises the values of the Feature Configuration page. Pure: no WordPress calls.
 * Option names are kept by the caller.
 */
final class FeatureSettings
{
    public const FACE_DEFAULTS = [
        'baseline_samples' => 15, 'sample_interval_ms' => 100, 'eye_drop_threshold' => 0.90,
        'blink_min_drop' => 0.94, 'blink_min_ms' => 60, 'challenge_timeout_sec' => 12,
        'head_move_px' => 8, 'head_move_ratio' => 0.018, 'face_match_threshold' => 0.60,
        'enrollment_samples' => 5, 'enrollment_interval_ms' => 650,
        'detector_score_threshold' => 0.35, 'detector_input_size' => 320,
    ];

    /** [label, min, max, step] of each face tuning field (detector_input_size is a choice). */
    public const FACE_FIELDS = [
        'baseline_samples' => ['Open-eye calibration samples', 5, 60, 1],
        'sample_interval_ms' => ['Calibration interval (ms)', 50, 500, 1],
        'eye_drop_threshold' => ['Blink start ratio', 0.70, 0.99, 0.01],
        'blink_min_drop' => ['Blink confirm ratio', 0.70, 0.99, 0.01],
        'blink_min_ms' => ['Minimum blink duration (ms)', 30, 300, 1],
        'challenge_timeout_sec' => ['Liveness timeout (sec)', 5, 30, 1],
        'head_move_px' => ['Head movement minimum (px)', 2, 40, 1],
        'head_move_ratio' => ['Head movement ratio', 0.005, 0.08, 0.001],
        'face_match_threshold' => ['Face match distance', 0.30, 0.90, 0.01],
        'enrollment_samples' => ['Enrollment samples', 3, 10, 1],
        'enrollment_interval_ms' => ['Enrollment interval (ms)', 200, 2000, 1],
        'detector_score_threshold' => ['Face detector confidence', 0.10, 0.90, 0.01],
    ];

    /** Input sizes supported by the face detector model. */
    public const DETECTOR_SIZES = [160, 224, 320, 416, 512];

    public const SPLASH_DEFAULTS = [
        'enabled' => 1, 'duration_ms' => 650, 'title' => 'Workforce One', 'subtitle' => 'Workforce Management Platform',
        'background' => '#f7f7fb', 'accent' => '#6125c9', 'logo' => '',
    ];

    /** Actions that can ask for confirmation, with their labels. All default to on. */
    public const CONFIRM_ACTIONS = [
        'swap_cancel' => 'Cancel Shift Swap', 'swap_reject' => 'Reject Shift Swap',
        'leave_cancel' => 'Cancel Leave Request', 'leave_cancel_reject' => 'Reject Leave Cancellation',
        'overtime_reject' => 'Reject Overtime Request', 'early_leave_reject' => 'Reject Early Leave Request',
        'attendance_reset' => 'Reset Attendance Day', 'general_leave_delete' => 'Delete General Leave',
        'employee_delete' => 'Delete Employee', 'feature_disable' => 'Disable Feature',
    ];

    /** @return array<string, int|float> stored values merged over the defaults, each clamped to its range. */
    public static function face($posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        $out = [];
        foreach (self::FACE_DEFAULTS as $key => $default) {
            if ($key === 'detector_input_size') {
                $size = (int) ($posted[$key] ?? $default);
                $out[$key] = in_array($size, self::DETECTOR_SIZES, true) ? $size : $default;
                continue;
            }
            [, $min, $max] = self::FACE_FIELDS[$key];
            $value = is_float($default) ? (float) ($posted[$key] ?? $default) : (int) ($posted[$key] ?? $default);
            $out[$key] = max($min, min($max, $value));
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $posted title/subtitle already sanitised; colours as typed.
     * @return array<string, mixed>
     */
    public static function splash($posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        $d = self::SPLASH_DEFAULTS;
        $text = static function ($value, string $fallback, int $max): string {
            $value = trim((string) $value);
            return $value === '' ? $fallback : (function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max));
        };
        return [
            'enabled' => !empty($posted['enabled']) ? 1 : 0,
            'duration_ms' => max(0, min(3000, (int) ($posted['duration_ms'] ?? $d['duration_ms']))),
            'title' => $text($posted['title'] ?? '', $d['title'], 60),
            'subtitle' => $text($posted['subtitle'] ?? '', $d['subtitle'], 100),
            'background' => self::hex($posted['background'] ?? '', $d['background']),
            'accent' => self::hex($posted['accent'] ?? '', $d['accent']),
            'logo' => (string) ($posted['logo'] ?? ''),
        ];
    }

    /** @return array{per_day: int, duration: int, escalation: int} the manager alert always comes after the break ends. */
    public static function breaks($perDay, $duration, $escalation): array
    {
        $duration = max(1, min(480, (int) $duration));
        return ['per_day' => max(1, min(20, (int) $perDay)), 'duration' => $duration, 'escalation' => max($duration + 1, min(1440, (int) $escalation))];
    }

    /** @return array{0: array{max: int, monthly: int}, 1: ?string} values and an error code */
    public static function earlyLeave($max, $monthly): array
    {
        $max = max(1, min(480, (int) $max));
        $monthly = max(1, min(7440, (int) $monthly));
        return [['max' => $max, 'monthly' => $monthly], $monthly < $max ? 'early_leave_monthly' : null];
    }

    /** @return array{mode: string, limit: int} */
    public static function recognition($mode, $limit): array
    {
        return ['mode' => in_array($mode, ['limited', 'unlimited'], true) ? $mode : 'limited', 'limit' => max(1, min(1000, (int) $limit))];
    }

    /** @return array<string, int> every action, on or off */
    /** Minutes an employee has to answer a presence verification request: 1 to 60. @param mixed $value */
    public static function presenceMinutes($value): int
    {
        return max(1, min(60, (int) $value));
    }

    public static function confirmActions($posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        $out = [];
        foreach (array_keys(self::CONFIRM_ACTIONS) as $key) $out[$key] = !empty($posted[$key]) ? 1 : 0;
        return $out;
    }

    /** Stored confirmation settings with unset actions on (the default). @return array<string, int> */
    public static function confirmState($stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $out = [];
        foreach (array_keys(self::CONFIRM_ACTIONS) as $key) $out[$key] = array_key_exists($key, $stored) ? (int) !empty($stored[$key]) : 1;
        return $out;
    }

    public static function errorMessage(string $code): string
    {
        $om = [
            'om_min' => 'Office Minimum: enter a minimum of 1 or more people. Nothing was saved.',
            'om_day' => 'Office Minimum: a weekday\'s number must be 0 or more. Nothing was saved.',
            'om_team' => 'Office Minimum: a team\'s fixed number must be 0 or more. Nothing was saved.',
            'om_statuses' => 'Office Minimum: tick at least one status that counts as in the office. Nothing was saved.',
            'om_time' => 'Office Minimum: the reminder time is not valid. Nothing was saved.',
        ];
        if (isset($om[$code])) return $om[$code];
        return $code === 'early_leave_monthly'
            ? 'Early Leave: the Monthly allowance cannot be lower than the maximum per request. Nothing was saved.'
            : 'The configuration could not be saved.';
    }

    private static function hex($value, string $fallback): string
    {
        $value = trim((string) $value);
        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) ? strtolower($value) : $fallback;
    }
}
