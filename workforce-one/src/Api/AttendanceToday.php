<?php
namespace WorkforceOne\Api;

use WorkforceOne\Attendance\TodayState;

if (!defined('ABSPATH')) exit;

/**
 * GET /attendance/today (3.31.113): the document built from AttendanceService::today(). Pure: the plugin passes the extra
 * facts (shift, branch, break sessions) it already computes for the Web; the rules' answers come from the state.
 *
 * Times are ISO 8601 with the site offset. No durations are published (a night when clocks change is not 8 hours long).
 */
final class AttendanceToday
{
    /**
     * @param array<string,mixed>|null $shift as ScheduleDays builds it, or null
     * @param array{id:int,name:string,planned:bool}|null $branch
     * @param array{enabled:bool,per_day:int,duration_minutes:int,remaining:int,sessions:list<array{start_at:string,end_at:?string,minutes:?int}>} $breaks
     * @param array<string,string> $labels status / classification => the text shown
     * @return array<string,mixed>
     */
    public static function document(TodayState $s, string $siteToday, \DateTimeZone $tz, ?array $shift, ?array $branch, array $breaks, array $labels = []): array
    {
        $day = (string) $s->get('day');
        $enabled = (bool) $s->get('attendance_enabled');
        $requires = (bool) $s->get('requires_sign_in');
        $general = (bool) $s->get('general_leave');
        if (!$enabled) $type = 'attendance_disabled';
        elseif ($general) $type = 'general_leave';
        elseif (!$requires) $type = 'not_working';
        else $type = 'working';

        $bounds = (array) $s->get('bounds');
        $window = null;
        if ($requires && !$general && !empty($bounds['start']) && !empty($bounds['cutoff'])) {
            $window = ['opens_at' => LocalTime::isoFromWall((int) $bounds['start'], $tz), 'closes_at' => LocalTime::isoFromWall((int) $bounds['cutoff'], $tz), 'open' => (bool) $s->get('window_open')];
        }
        $signIn = null;
        if ($s->get('sign_in_at')) {
            $late = $s->get('sign_in_class') === 'Late Arrival';
            $signIn = ['at' => LocalTime::iso((string) $s->get('sign_in_at'), $tz), 'result' => $late ? 'late' : 'on_time', 'label' => $labels[$late ? 'Late Arrival' : 'On Time'] ?? ($late ? 'Late Arrival' : 'On Time')];
        }
        $signOut = $s->get('sign_out_at') ? ['at' => LocalTime::iso((string) $s->get('sign_out_at'), $tz)] : null;

        $sessions = [];
        $open = null;
        foreach ($breaks['sessions'] as $b) {
            $sessions[] = ['started_at' => LocalTime::iso($b['start_at'], $tz), 'ended_at' => $b['end_at'] ? LocalTime::iso($b['end_at'], $tz) : null, 'minutes' => $b['minutes']];
            if (!$b['end_at']) $open = ['started_at' => LocalTime::iso($b['start_at'], $tz)];
        }
        $br = ['enabled' => (bool) $breaks['enabled']];
        if ($br['enabled']) {
            $br += ['per_day' => (int) $breaks['per_day'], 'duration_minutes' => (int) $breaks['duration_minutes'], 'used' => count($sessions),
                'remaining' => (int) $breaks['remaining'], 'open' => $open, 'sessions' => $sessions];
        }

        $status = $s->get('status');
        $actions = [];
        foreach ((array) $s->get('actions') as $name => $reason) {
            $actions[$name] = ['allowed' => $reason === null, 'reason' => $reason === null ? null : ErrorMap::code((string) $reason)];
        }
        return [
            'today' => $siteToday, 'work_date' => $day, 'carried_over' => $day !== $siteToday, 'timezone' => $tz->getName(),
            'attendance_enabled' => $enabled, 'day_type' => $type, 'clock' => (string) $s->get('clock'),
            'schedule' => $status === null ? null : ['status' => (string) $status, 'label' => $labels[(string) $status] ?? (string) $status, 'requires_sign_in' => $requires, 'requires_location' => (bool) $s->get('requires_location')],
            'shift' => $shift, 'sign_in_window' => $window, 'branch' => $branch,
            'sign_in' => $signIn, 'sign_out' => $signOut, 'breaks' => $br,
            'requirements' => ['face' => (bool) $s->get('face_required'), 'location' => (bool) $s->get('requires_location')],
            'actions' => $actions,
        ];
    }
}
