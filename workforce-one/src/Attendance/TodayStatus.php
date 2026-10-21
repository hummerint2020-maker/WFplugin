<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** Today's attendance state of an expected employee, for the admin dashboard. Pure: no WordPress calls. */
final class TodayStatus
{
    public const ON_TIME = 'sign_in';
    public const LATE = 'late_arrival';
    public const NO_SHOW = 'no_show';

    /**
     * @param string|null $firstType       event type of the first Sign In (sign_in / late_sign_in), null if none
     * @param string|null $classification  'On Time' / 'Late Arrival' of that Sign In by the employee's shift
     */
    public static function status(?string $firstType, ?string $classification): string
    {
        if ($firstType === null) return self::NO_SHOW;
        if ($firstType === 'late_sign_in' || $classification === 'Late Arrival') return self::LATE;
        return self::ON_TIME;
    }

    public static function label(string $status): string
    {
        return [self::ON_TIME => 'On Time', self::LATE => 'Late Arrival'][$status] ?? 'No Show';
    }

    /** @param string[] $statuses @return array{sign_in: int, late_arrival: int, no_show: int} */
    public static function counts(array $statuses): array
    {
        $c = [self::ON_TIME => 0, self::LATE => 0, self::NO_SHOW => 0];
        foreach ($statuses as $s) $c[isset($c[$s]) ? $s : self::NO_SHOW]++;
        return $c;
    }
}
