<?php
namespace WorkforceOne\Schedule;

use WorkforceOne\Api\LocalTime;
use WorkforceOne\Api\Period;

if (!defined('ABSPATH')) exit;

/**
 * One employee's schedule over a list of days, as the app shows it (3.31.113, GET /me/schedule). Pure: the plugin passes the
 * facts of that one employee; every calendar day is listed, in order.
 *
 * A day's status is the one the Web shows: a company holiday ("General Leave") first, then the schedule row's status, else
 * "Not Set". A shift is given only for a status that requires Sign In on a day that is not a company holiday.
 */
final class ScheduleDays
{
    public const GENERAL_LEAVE = 'General Leave';
    public const NOT_SET = 'Not Set';

    /**
     * @param list<string> $dates
     * @param array<string,string> $statusByDate the schedule rows of this employee: date => status
     * @param array<string,string> $companyLeave date => holiday title
     * @param array<string,bool> $requiresSignIn schedule type name => requires Sign In
     * @param array<string,array<string,mixed>|null> $hoursByDate date => working_hours() of that day (start, end, grace, overnight, shift_id, shift_name)
     * @param array<string,array{id:int,name:string,planned:bool}|null> $branchByDate date => the branch shown that day
     * @param list<int> $workingWeekdays 0 = Sunday … 6
     * @param array<string,string> $labels status => the text shown (built-in names translated; others as typed)
     * @return list<array<string,mixed>>
     */
    public static function build(array $dates, array $statusByDate, array $companyLeave, array $requiresSignIn, array $hoursByDate, array $branchByDate, array $workingWeekdays, \DateTimeZone $tz, array $labels = []): array
    {
        $out = [];
        foreach ($dates as $date) {
            $general = isset($companyLeave[$date]);
            $status = $general ? self::GENERAL_LEAVE : ($statusByDate[$date] ?? self::NOT_SET);
            $requires = !$general && $status !== self::NOT_SET && !empty($requiresSignIn[$status]);
            $weekday = (int) gmdate('w', (int) strtotime($date . ' 12:00:00 UTC'));
            $h = $requires ? ($hoursByDate[$date] ?? null) : null;
            $shift = null;
            if (is_array($h) && !empty($h['start']) && !empty($h['end'])) {
                $overnight = !empty($h['overnight']) && (string) $h['start'] > (string) $h['end'];
                $shift = [
                    'id' => (int) ($h['shift_id'] ?? 0), 'name' => (string) ($h['shift_name'] ?? ''), 'start' => (string) $h['start'], 'end' => (string) $h['end'],
                    'overnight' => $overnight, 'grace_minutes' => (int) ($h['grace'] ?? 0),
                ] + LocalTime::shiftSpan($date, (string) $h['start'], (string) $h['end'], $overnight, $tz);
            }
            $out[] = [
                'date' => $date, 'weekday' => $weekday, 'working_weekday' => in_array($weekday, $workingWeekdays, true),
                'status' => $status, 'label' => $labels[$status] ?? $status, 'requires_sign_in' => $requires, 'general_leave' => $general,
                'shift' => $shift, 'branch' => $requires ? ($branchByDate[$date] ?? null) : null,
            ];
        }
        return $out;
    }
}
