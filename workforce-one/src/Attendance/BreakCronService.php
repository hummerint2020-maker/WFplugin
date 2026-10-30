<?php
namespace WorkforceOne\Attendance;

use WorkforceOne\Attendance\Data\BreakStore;

if (!defined('ABSPATH')) exit;

/**
 * The two cron jobs of a break (3.31.137): the reminder to the employee when the break time is over, and the alert to the managers
 * when it is long past. The old texts and the old order of reads and writes: the flag is set after a read, on the id alone.
 */
final class BreakCronService
{
    /** @var BreakStore */
    private $breaks;
    /** @var AttendanceSite */
    private $site;

    public function __construct(BreakStore $breaks, AttendanceSite $site)
    {
        $this->breaks = $breaks;
        $this->site = $site;
    }

    public function remind(int $sessionId): void
    {
        $s = $this->breaks->byId($sessionId);
        if (!$s || $s->status !== 'Open' || (int) $s->duration_notified === 1) return;
        $this->breaks->markReminded((int) $s->id);
        $this->site->breakTimeEnded((int) $s->user_id, (int) $s->id, 'Your ' . $this->site->breakDurationMinutes() . '-minute break has ended. Please resume work.');
    }

    public function escalate(int $sessionId): void
    {
        $s = $this->breaks->byId($sessionId);
        if (!$s || $s->status !== 'Open' || (int) $s->escalation_notified === 1) return;
        if (!$this->site->attendanceEnabled((int) $s->employee_id)) return;
        $this->breaks->markEscalated((int) $s->id);
        $emp = $this->site->employee((int) $s->employee_id);
        if (!$emp) return;
        $message = $emp->name . ' has been on break for ' . $this->site->minutesSince((string) $s->start_at) . ' minutes and has not resumed work.';
        foreach ($this->site->managerUserIds() as $uid) $this->site->extendedBreak((int) $uid, (int) $s->id, $message);
    }
}
