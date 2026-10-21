<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Auto Attendance rules (wp-admin → Auto Attendance): on which days a rule runs and which Sign In /
 * Sign Out it records at a given time. A rule whose Sign Out time is earlier than its Sign In time is
 * an overnight rule: its Sign Out happens the next day and belongs to the day the shift started.
 * Pure: dates are 'Y-m-d' strings, times 'H:i'.
 */
final class AutoRules
{
    /** Day of the week of a date (0 = Sunday), from the date itself, whatever the time zone. */
    public static function weekday(string $date): int
    {
        return (int) (new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')))->format('w');
    }

    /** @param string $weekdays comma-separated weekday numbers (weekly rules) */
    public static function applies(string $recurrence, ?string $runDate, string $weekdays, string $date): bool
    {
        if ($recurrence === 'one_time') return (string) $runDate === $date;
        $days = array_map('intval', array_filter(explode(',', $weekdays), 'is_numeric'));
        return in_array(self::weekday($date), $days, true);
    }

    public static function overnight(?string $signIn, ?string $signOut): bool
    {
        return (string) $signIn !== '' && (string) $signOut !== '' && substr((string) $signOut, 0, 5) < substr((string) $signIn, 0, 5);
    }

    /**
     * What a rule records now.
     * @param array{recurrence:string,run_date:?string,weekdays:?string,sign_in_time:?string,sign_out_time:?string} $rule
     * @return array<int,array{type:string,work_date:string,event_date:string,time:string}>
     */
    public static function due(array $rule, string $today, string $yesterday, string $nowHm): array
    {
        $in = $rule['sign_in_time'] ? substr((string) $rule['sign_in_time'], 0, 5) : '';
        $out = $rule['sign_out_time'] ? substr((string) $rule['sign_out_time'], 0, 5) : '';
        $night = self::overnight($in, $out);
        $applies = function ($date) use ($rule) { return self::applies((string) $rule['recurrence'], $rule['run_date'], (string) $rule['weekdays'], $date); };
        $out_list = [];
        if ($applies($today)) {
            if ($in !== '' && $in <= $nowHm) $out_list[] = ['type' => 'sign_in', 'work_date' => $today, 'event_date' => $today, 'time' => $in];
            if ($out !== '' && !$night && $out <= $nowHm) $out_list[] = ['type' => 'sign_out', 'work_date' => $today, 'event_date' => $today, 'time' => $out];
        }
        if ($night && $out <= $nowHm && $applies($yesterday)) $out_list[] = ['type' => 'sign_out', 'work_date' => $yesterday, 'event_date' => $today, 'time' => $out];
        return $out_list;
    }

    /** The day a one-time rule's Sign Out is recorded on (the next day for an overnight rule). */
    public static function signOutDate(string $runDate, ?string $signIn, ?string $signOut): string
    {
        return self::overnight($signIn, $signOut) ? (new \DateTimeImmutable($runDate . ' 12:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') : $runDate;
    }
}
