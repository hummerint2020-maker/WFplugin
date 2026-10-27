<?php
namespace WorkforceOne\Schedule;

use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\OnceClaim;
use WorkforceOne\Schedule\Data\ScheduleDayStore;

if (!defined('ABSPATH')) exit;

/**
 * Week lock's cutoff notification (3.31.127, moved from trait-week-lock.php; the rules are WeekLock): once per cutoff,
 * everyone with a schedule that week is told their days. Two overlapping runs tell people once (OnceClaim).
 */
final class WeekLockService
{
    public const CLAIM = 'ews_week_lock_notified';

    /** @var ScheduleDayStore */
    private $store;
    /** @var ScheduleNotifier */
    private $notifier;
    /** @var AuditLog */
    private $audit;
    /** @var OnceClaim */
    private $claim;

    public function __construct(ScheduleDayStore $store, ScheduleNotifier $notifier, AuditLog $audit, OnceClaim $claim)
    {
        $this->store = $store;
        $this->notifier = $notifier;
        $this->audit = $audit;
        $this->claim = $claim;
    }

    /**
     * The week whose cutoff just passed and that this run claimed, or ''. Only a cutoff of the last day counts: switching
     * the notice on, or a site that was down for a week, does not send an old one.
     * @param array<string,mixed> $cfg WeekLockSettings::config() @param int[] $workingDays
     */
    public function dueWeek(array $cfg, string $now, array $workingDays): string
    {
        if (!$cfg['notify']) return '';
        $last = WeekLock::latest($now, $workingDays, (int) $cfg['cutoff_day'], (string) $cfg['cutoff_time']);
        if ($last['week'] === '' || strtotime($now) - strtotime($last['at']) > 86400) return '';
        if ($this->claim->current(self::CLAIM) === $last['week']) return '';
        return $this->claim->claim(self::CLAIM, $last['week']) ? $last['week'] : '';
    }

    /**
     * "Next week's schedule is final", with each person's days. @param string[] $dates the week's days
     * @param array<int,int> $users active employee id => WordPress user @return int people told
     */
    public function notifyWeek(array $dates, array $users, string $link): int
    {
        if (!$dates) return 0;
        $by = [];
        foreach ($this->store->rowsOnDates($dates) as $r) if (!empty($users[(int) $r->employee_id])) $by[$users[(int) $r->employee_id]][(string) $r->work_date] = (string) $r->status;
        foreach ($by as $uid => $days) $this->notifier->weekFinal((int) $uid, $days, $link);
        $this->audit->record('week_lock_notified', 'schedule', 0, $dates[0] . ': ' . count($by) . ' people');
        return count($by);
    }
}
