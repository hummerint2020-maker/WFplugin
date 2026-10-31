<?php
namespace WorkforceOne\Attendance;

use WorkforceOne\Attendance\Data\AutoRuleStore;
use WorkforceOne\Attendance\Data\BreakStore;
use WorkforceOne\Attendance\Data\TimeLogStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\People;
use WorkforceOne\Schedule\ScheduleDayService;

if (!defined('ABSPATH')) exit;

/**
 * Auto Attendance (3.31.138; docs/tasks/attendance-auto-manual.md): the five-minute tick, one automatic Sign In / Sign Out, the bulk Sign Out and
 * the rules page's saves. The steps, their order and the lock are those of the old trait (quirks AT-a, AT-b, AT-f, AT-g, AT-r, AT-s, AT-t, AT-x, AT-y
 * are moved as they are). Nothing here asks the time: the controller or the Clock does.
 */
final class AutoAttendanceService
{
    /** @var AutoRuleStore */
    private $rules;
    /** @var TimeLogStore */
    private $logs;
    /** @var BreakStore */
    private $breaks;
    /** @var EmployeeLock */
    private $lock;
    /** @var ScheduleDayService */
    private $days;
    /** @var People */
    private $people;
    /** @var AttendanceSite */
    private $site;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(AutoRuleStore $rules, TimeLogStore $logs, BreakStore $breaks, EmployeeLock $lock, ScheduleDayService $days, People $people, AttendanceSite $site, AuditLog $audit, Clock $clock)
    {
        $this->rules = $rules;
        $this->logs = $logs;
        $this->breaks = $breaks;
        $this->lock = $lock;
        $this->days = $days;
        $this->people = $people;
        $this->site = $site;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /** The employee's schedule row on the day when there is something to record (not a day off, leave, a holiday or a type without Sign In), else null. */
    private function workingDay(int $employeeId, string $date): ?object
    {
        $sch = $this->days->day($employeeId, $date);
        if (!$sch) return null;
        if (!AutoRules::schedulable((string) $sch->status)) return null;
        if ($this->site->generalLeave($date)) return null;
        if (!$this->site->requiresSignIn((string) $sch->status)) return null;
        return $sch;
    }

    /** The five-minute cron: record what each enabled rule has due (AutoRules::due). */
    public function tick(string $today, string $yesterday, string $nowHm): void
    {
        foreach ($this->rules->dueRules($today, $yesterday) as $rule) {
            $eid = (int) $rule->employee_id;
            foreach (AutoRules::due((array) $rule, $today, $yesterday, $nowHm) as $a) {
                $sch = $this->workingDay($eid, $a['work_date']);
                if ($sch) $this->event($eid, $a['work_date'], $a['type'], $a['time'], $sch, (int) $rule->id, $a['event_date']);
            }
            if ($rule->recurrence !== 'one_time') continue;
            // A one-time rule stays enabled until each of its actions has reached its time and been handled (an overnight Sign Out is due the next day).
            $run = (string) $rule->run_date;
            $done = function ($type, $time, $when) use ($eid, $run, $today, $nowHm) {
                if (empty($time)) return true;
                if ($when > $today || ($when === $today && substr((string) $time, 0, 5) > $nowHm)) return false;
                return $this->logs->anyOfKinds($eid, $run, $type === 'sign_in' ? ['sign_in', 'late_sign_in'] : ['sign_out']);
            };
            $outDay = AutoRules::signOutDate($run, $rule->sign_in_time, $rule->sign_out_time);
            $missed = !$this->workingDay($eid, $run);   // nothing to record on a day off / leave
            if ($missed || ($done('sign_in', $rule->sign_in_time, $run) && $done('sign_out', $rule->sign_out_time, $outDay))) {
                if ($missed && ($run >= $today || $outDay >= $today)) continue;   // keep it until its day has passed
                $this->rules->disable((int) $rule->id, $this->clock->now());
            }
        }
    }

    /**
     * Record one automatic Sign In / Sign Out on the attendance day $date, at $targetTime on $eventDate (the next day for an overnight rule's Sign Out).
     * Never duplicates an event. Under the same per-employee lock as a Sign In / Out from the app: an automatic Sign Out at the moment the employee taps Sign Out,
     * or two overlapping runs, record one event. Without the lock (SQLite, a timeout) the check after the insert decides.
     * @return array{ok:bool,reason?:string,id?:int}
     */
    public function event(int $employeeId, string $date, string $type, string $targetTime, object $sch, int $ruleId, ?string $eventDate = null): array
    {
        $locked = $this->lock->lock($employeeId);
        try {
            return $this->eventLocked($employeeId, $date, $type, $targetTime, $sch, $ruleId, $eventDate);
        } finally {
            if ($locked) $this->lock->unlock($employeeId);
        }
    }

    /** @return array{ok:bool,reason?:string,id?:int} */
    private function eventLocked(int $employeeId, string $date, string $type, string $targetTime, object $sch, int $ruleId, ?string $eventDate): array
    {
        $eventDate = $eventDate ?: $date;
        // One indexed attendance lookup is enough for both duplicate protection and Sign Out eligibility.
        $events = [];
        foreach ($this->logs->liveDay($employeeId, $date) as $r) $events[$r->event_type] = $r;
        if ($type === 'sign_in' && (isset($events['sign_in']) || isset($events['late_sign_in']))) return ['ok' => false, 'reason' => 'already_signed_in'];
        if ($type === 'sign_out') {
            if (!isset($events['sign_in']) && !isset($events['late_sign_in'])) return ['ok' => false, 'reason' => 'no_sign_in'];
            if (isset($events['sign_out'])) return ['ok' => false, 'reason' => 'already_signed_out'];
            $signIn = isset($events['sign_in']) ? $events['sign_in'] : $events['late_sign_in'];
            if (strtotime($signIn->event_at) > strtotime($eventDate . ' ' . $targetTime)) return ['ok' => false, 'reason' => 'sign_in_after_target'];
        }
        $eventAt = $eventDate . ' ' . $targetTime . ':00';
        $now = $this->clock->now();
        $userId = 0;
        $emp = $this->people->activeEmployee($employeeId);
        if (!$emp) return ['ok' => false, 'reason' => 'employee_missing'];
        if (!$this->site->attendanceEnabled($employeeId)) return ['ok' => false, 'reason' => 'attendance_disabled'];
        if ($emp->wp_user_id) $userId = (int) $emp->wp_user_id;
        $newId = $this->logs->insertAuto([
            'employee_id' => $employeeId,
            'user_id' => $userId,
            'work_date' => $date,
            'event_type' => $type,
            'event_at' => $eventAt,
            'scheduled_status' => $sch->status,
            'ip_address' => '',
            'latitude' => null,
            'longitude' => null,
            'accuracy' => null,
            'location_status' => 'auto',
            'distance_meters' => null,
            'created_at' => $now,
            'source' => 'auto',
        ]);
        if (!$newId) {
            $this->audit->record('auto_' . $type . '_failed', 'time_log', 0, $emp->name . ' / Rule #' . $ruleId . ' / ' . $this->logs->lastError());
            return ['ok' => false, 'reason' => 'db_error'];
        }
        if (!$this->firstKept($employeeId, $date, $type, $newId)) return ['ok' => false, 'reason' => $type === 'sign_out' ? 'already_signed_out' : 'already_signed_in'];
        $this->audit->record('auto_' . $type, 'time_log', $newId, $emp->name . ' / ' . $sch->status . ' / ' . $eventAt . ' / source=Auto / rule=' . $ruleId);
        return ['ok' => true, 'id' => $newId];
    }

    /** The first event of its kind on the day is kept, as AttendanceService does: true when $id is it (a later duplicate is removed). */
    private function firstKept(int $employeeId, string $date, string $type, int $id): bool
    {
        $first = $this->logs->firstEventId($employeeId, $date, $type === 'sign_out' ? ['sign_out'] : ['sign_in', 'late_sign_in']);
        if (!$first || $first === $id) return true;
        $this->logs->delete($id);
        return false;
    }

    /**
     * Signs out everyone still signed in on $date, one employee at a time under their lock, and closes an open break.
     * @return array{signed_out:int,skipped:int,failed:int}
     */
    public function bulkSignOut(string $date, string $now): array
    {
        $signedOut = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($this->logs->openSignInsOn($date) as $row) {
            $signIn = $this->logs->byId((int) $row->sign_in_id);
            if (!$signIn) { $failed++; continue; }
            if (!$this->site->attendanceEnabled((int) $signIn->employee_id)) { $skipped++; continue; }
            // One employee at a time under their attendance lock (3.31.119), checked again under it: a Sign Out the employee taps at
            // this moment is not doubled. A short wait: a busy employee is checked after the insert instead.
            $eid = (int) $signIn->employee_id;
            $locked = $this->lock->lock($eid, 2);
            try {
                if ($this->logs->signOutIdOn((int) $row->employee_id, $date)) { $skipped++; continue; }
                $newId = $this->logs->insertAuto([
                    'employee_id' => (int) $signIn->employee_id,
                    'user_id' => (int) $signIn->user_id,
                    'work_date' => $date,
                    'event_type' => 'sign_out',
                    'event_at' => $now,
                    'scheduled_status' => (string) $signIn->scheduled_status,
                    'ip_address' => '',
                    'latitude' => null,
                    'longitude' => null,
                    'accuracy' => null,
                    'location_status' => 'auto',
                    'distance_meters' => null,
                    'created_at' => $now,
                    'source' => 'auto',
                ]);
                if (!$newId) { $failed++; continue; }
                if (!$this->firstKept($eid, $date, 'sign_out', $newId)) { $skipped++; continue; }
                $signedOut++;
                $emp = $this->site->employee((int) $signIn->employee_id);
                $name = $emp ? $emp->name : ('Employee #' . (int) $signIn->employee_id);
                $this->audit->record('auto_sign_out', 'time_log', $newId, $name . ' / ' . $signIn->scheduled_status . ' / ' . $now . ' / source=Auto / bulk=1');
                // If an employee is still on an open break, close it at the bulk sign-out time so the break state cannot remain open after attendance is closed.
                if ($this->site->breakEnabled()) {
                    $open = $this->breaks->openOn((int) $signIn->employee_id, $date);
                    if ($open) {
                        $minutes = max(0, (int) floor((strtotime($now) - strtotime($open->start_at)) / 60));
                        // Only a break still open (the employee may end it at this moment); its reminders go only when it was closed here.
                        if ($this->breaks->close((int) $open->id, $now, $minutes)) {
                            $this->site->clearBreakEvents((int) $open->id);
                            $this->audit->record('break_auto_closed', 'break', (int) $open->id, $name . ' / ' . $now . ' / ' . $minutes . 'm / bulk_sign_out');
                        }
                    }
                }
            } finally {
                if ($locked) $this->lock->unlock($eid);
            }
        }
        $this->audit->record('auto_sign_out_bulk', 'time_log', 0, 'Date=' . $date . ' / signed_out=' . $signedOut . ' / skipped=' . $skipped . ' / failed=' . $failed);
        return ['signed_out' => $signedOut, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Saves a rule: an update when $id is given, else an insert ($create = created_by and created_at, added after the columns). The audit row is written
     * only when the write did not fail (an update that changed no row is not a failure: AT-y).
     * @param array<string,mixed> $data the columns, in the order of the SQL
     * @param array<string,mixed>|null $create
     * @return array{ok:bool,id:int,error:string}
     */
    public function saveRule(int $id, array $data, ?array $create, string $detail): array
    {
        if ($id) {
            $ok = $this->rules->update($id, $data);
            $action = 'auto_attendance_rule_update';
        } else {
            $id = $this->rules->insert($data + (array) $create);
            $ok = $id > 0;
            $action = 'auto_attendance_rule_create';
        }
        if (!$ok) return ['ok' => false, 'id' => $id, 'error' => $this->rules->lastError()];
        $this->audit->record($action, 'auto_attendance_rule', $id, $detail);
        return ['ok' => true, 'id' => $id, 'error' => ''];
    }

    /** Flips a rule on or off. Null when there is no such rule. The audit row does not look at whether the write worked (AT-y). */
    public function toggleRule(int $id): ?int
    {
        $row = $this->rules->enabledOf($id);
        if (!$row) return null;
        $new = empty($row->enabled) ? 1 : 0;
        $this->rules->setEnabled($id, $new, $this->clock->now());
        $this->audit->record('auto_attendance_rule_toggle', 'auto_attendance_rule', $id, 'Enabled=' . ($new ? 'yes' : 'no'));
        return $new;
    }

    public function deleteRule(int $id): void
    {
        if ($this->rules->remove($id)) $this->audit->record('auto_attendance_rule_delete', 'auto_attendance_rule', $id, 'Rule deleted');
    }
}
