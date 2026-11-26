<?php
namespace WorkforceOne\Leave;

use WorkforceOne\Leave\Data\LeaveHolidayStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/**
 * Company holidays charged as leave (3.31.162, docs/tasks/leave-holidays.md): the report and giving the days back. The
 * controller (includes/trait-leave-holidays.php) checks the switch, the capability and the nonce; this service owns the
 * transaction of each leave, under the employee's leave lock.
 */
final class HolidayRefundService
{
    /** @var LeaveHolidayStore */
    private $store;
    /** @var HolidayRefundPorts */
    private $site;
    /** @var Transaction */
    private $tx;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(LeaveHolidayStore $store, HolidayRefundPorts $site, Transaction $tx, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->site = $site;
        $this->tx = $tx;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /**
     * The report's leaves: approved, charged to the balance of $year or later (a leave already asked for next year; never a past
     * year, owner 2026-11-24), with a leave day on a company holiday. One row per leave, by employee name.
     * @return array<int,array<string,mixed>>
     */
    public function report(int $year): array
    {
        $hol = $this->site->holidays(sprintf('%04d-01-01', $year - 1), sprintf('%04d-12-31', $year + 2));
        if (!$hol) return [];
        $ids = $this->store->leavesOnDays(array_map('strval', array_keys($hol)), $year);
        if (!$ids) return [];
        $days = $this->store->leaveDays($ids);
        $list = [];
        foreach ($this->store->leaves($ids) as $l) {
            $d = $days[(int) $l->id] ?? [];
            $back = HolidayRefund::candidates($d, array_map('strval', array_keys($hol)), [], (float) $l->requested_days);
            if (!$back) continue;
            $emp = $this->site->employee((int) $l->employee_id);
            $list[] = ['leave_id' => (int) $l->id, 'employee_id' => (int) $l->employee_id, 'employee' => $emp ? (string) $emp->name : '#' . (int) $l->employee_id,
                'type' => (string) $l->type_name, 'start' => (string) $l->start_date, 'end' => (string) $l->end_date,
                'days' => array_map(static function ($x) use ($hol) { return ['day' => $x, 'holiday' => isset($hol[$x]) ? (string) $hol[$x] : null]; }, $d),
                'charged' => (float) $l->requested_days, 'back' => count($back), 'should' => max(0, (float) $l->requested_days - count($back)),
                'partly' => (string) $l->cancellation_status === 'Approved',
                'restorable' => HolidayRefund::restorable((string) $l->status, (bool) (int) $l->deduct_balance, $l->cancellation_status !== null ? (string) $l->cancellation_status : null)];
        }
        usort($list, static function ($a, $b) { return [$a['employee'], $a['start']] <=> [$b['employee'], $b['start']]; });
        return $list;
    }

    /**
     * HR gives back the company holidays charged on these leaves, each leave on its own (its lock, its transaction). Each leave given
     * back is audited and its employee told.
     * @param int[] $ids
     * @return array{days:int,leaves:int,failed:int,busy:int}
     */
    public function giveBack(array $ids): array
    {
        $out = ['days' => 0, 'leaves' => 0, 'failed' => 0, 'busy' => 0];
        foreach ($ids as $id) {
            $id = (int) $id;
            $r = $this->store->leaves([$id])[0] ?? null;
            $emp = $r ? $this->site->employee((int) $r->employee_id) : null;
            if (!$r || !$emp) { $out['failed']++; continue; }
            $done = [];
            $res = $this->site->withEmployeeLock((int) $r->employee_id, function () use ($id, $emp, &$done) {
                if (!$this->tx->begin()) return 'save';
                $res = $this->restoreLocked($id, $emp, $this->clock->now(), $done);
                if ($res !== true) { $this->tx->rollback(); return $res; }
                if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
                return true;
            });
            if ($res === 'busy') $out['busy']++;
            if ($res !== true || !$done) { $out['failed']++; continue; }
            $n = count($done['days']);
            $out['days'] += $n;
            $out['leaves']++;
            $this->audit->record('leave_holiday_refund', 'leave_request', $id, $done['type'] . ' ' . $done['start'] . ' – ' . $done['end'] . ': ' . $n . ' company holiday(s) given back ('
                . implode(', ', $done['days']) . '), ' . $done['before'] . ' → ' . $done['after'] . ' day(s)' . ($done['top'] > 0 ? ', next year +' . $done['top'] . ' carried' : ''));
            if (!empty($emp->wp_user_id)) {
                $this->site->notifyGivenBack((int) $emp->wp_user_id, ['days' => $n, 'type' => (string) $done['type'], 'start' => (string) $done['start'],
                    'end' => (string) $done['end'], 'holidays' => array_map('strval', $done['days']), 'after' => (string) $done['after']], $id);
            }
        }
        return $out;
    }

    /**
     * One leave's company holidays given back, under the employee's lock and in this service's transaction, the leave row locked.
     * @param array<string,mixed> $done filled with what was given back
     * @return true|string an error code ('done' = nothing to give back any more)
     */
    private function restoreLocked(int $id, object $emp, string $now, array &$done)
    {
        $r = $this->store->lockLeave($id);
        if (!$r || !HolidayRefund::restorable((string) $r->status, (bool) (int) $r->deduct_balance, $r->cancellation_status !== null ? (string) $r->cancellation_status : null)) return 'done';
        $bal = $this->site->balance($r);
        if (!$bal || (int) $bal->leave_year < $this->site->leaveYear()) return 'done';   // owner: not a past leave year
        $rows = $this->store->lockLeaveDays($id);
        $hol = $this->site->holidays((string) $r->start_date, (string) $r->end_date);
        $days = HolidayRefund::candidates(array_map(static function ($x) { return (string) $x->work_date; }, $rows), array_map('strval', array_keys($hol)),
            $this->store->refundedDays($id), (float) $r->requested_days);
        if (!$days) return 'done';
        $by = [];
        foreach ($rows as $x) $by[(string) $x->work_date] = $x;
        foreach ($days as $day) {
            if (!$this->store->addRefund($id, (int) $r->employee_id, $day, (int) $bal->id, (string) $hol[$day], $this->site->actorId(), $now)) return 'done';   // given back meanwhile (the unique key)
            if (!$this->site->restoreDay($by[$day], $emp, $now, $id)) return 'save';
            if (!$this->store->removeLeaveDay((int) $by[$day]->id)) return 'save';
        }
        $n = (float) count($days);
        $before = (float) $r->requested_days;
        $after = max(0.0, $before - $n);
        $q = $this->store->setRequestedDays($id, $before, $after);
        if ($q !== 1) return $q === false ? 'save' : 'done';
        if (!$this->store->lowerUsed((int) $bal->id, $n, $now)) return 'save';
        $top = $this->site->carryTopUp($r, $bal, $n, $now);
        if ($top === false) return 'save';
        $this->site->weekLockRecord((int) $emp->id, $days, 'leave #' . $id);
        $num = static function ($x) { return rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.'); };
        $done = ['days' => $days, 'type' => (string) $r->type_name, 'start' => (string) $r->start_date, 'end' => (string) $r->end_date,
            'before' => $num($before), 'after' => $num($after), 'top' => (float) $top];
        return true;
    }
}
