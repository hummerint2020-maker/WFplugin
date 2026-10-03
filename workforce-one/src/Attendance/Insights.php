<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** Classification rules of the Attendance Insights page. Pure: no WordPress calls. */
final class Insights
{
    /** Rows of the planned distribution, in display order. */
    public const PLAN_ROWS = ['Office', 'WFH', 'Leave', 'Business Trip', 'Other', 'Absent', 'Not Set'];
    public const ACTUAL = ['Present', 'Late', 'Absent', 'Pending', 'Leave'];

    /**
     * Planned category of a schedule status.
     * @param string|null $rule attendance rule of the schedule type (attendance / leave / business_trip); null = unknown type
     */
    public static function planCategory(string $status, ?string $rule): string
    {
        if ($status === '' || $status === 'Not Set') return 'Not Set';
        if (in_array($status, ['Office', 'WFH', 'Absent'], true)) return $status;
        if ($status === 'Leave' || $rule === 'leave') return 'Leave';
        if ($rule === 'business_trip') return 'Business Trip';
        return $rule === null ? 'Not Set' : 'Other';
    }

    /**
     * Actual attendance on one day.
     * @param array{holiday: bool, rule: ?string, requires_sign_in: bool, classification: ?string, legacy_late: bool,
     *   date: string, today: string, past_cutoff: bool} $f classification = of the first Sign In (null = none)
     * @return string one of ACTUAL, a business-trip type name, or 'Not Scheduled'
     */
    public static function actual(array $f, string $planned): string
    {
        if ($f['holiday'] || $f['rule'] === 'leave' || $planned === 'Leave') return 'Leave';
        if ($f['rule'] === 'business_trip') return $planned;
        if (!$f['requires_sign_in']) return 'Not Scheduled';
        if ($f['classification'] !== null) return ($f['legacy_late'] || $f['classification'] === 'Late Arrival') ? 'Late' : 'Present';
        if ($f['date'] < $f['today'] || ($f['date'] === $f['today'] && $f['past_cutoff'])) return 'Absent';
        return 'Pending';
    }

    /**
     * Today's workforce snapshot (employee app dashboard): Office / WFH / Leave + missions.
     * On a company holiday nobody is in the Office or working from home.
     * @param array<int,array{0:string,1:?string}> $plans [status, attendance rule] per employee
     * @return array{office:int,wfh:int,away:int}
     */
    public static function snapshot(array $plans, bool $holiday): array
    {
        $out = ['office' => 0, 'wfh' => 0, 'away' => 0];
        foreach ($plans as [$status, $rule]) {
            $category = self::planCategory($status, $rule);
            if ($category === 'Leave' || $category === 'Business Trip') $out['away']++;
            elseif ($holiday) continue;
            elseif ($category === 'Office') $out['office']++;
            elseif ($category === 'WFH') $out['wfh']++;
        }
        return $out;
    }

    /** A real Y-m-d date (2026-02-31 is not), otherwise $fallback. */
    public static function validDate(string $raw, string $fallback): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $raw : $fallback;
    }

    /** Share of working days attended (Present + Late) out of Present + Late + Absent, in %. */
    public static function rate(int $present, int $late, int $absent): int
    {
        $total = $present + $late + $absent;
        return $total ? (int) round(($present + $late) / $total * 100) : 0;
    }
}
