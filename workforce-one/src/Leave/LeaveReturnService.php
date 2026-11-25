<?php
namespace WorkforceOne\Leave;

use WorkforceOne\Leave\Data\LeaveReturnStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;
use WorkforceOne\Schedule\ScheduleDayService;

if (!defined('ABSPATH')) exit;

/**
 * Leave return and cancellations (3.31.161, moved here as they were in 3.31.164: docs/tasks/leave-return-layers.md). The same
 * reads and writes in the same order, the same locks, audit rows and results as the trait had. The controllers (includes/
 * trait-leave.php, trait-leave-admin.php) check the switch, the capability and the nonce, show the messages and send the
 * notifications.
 *
 * Transactions: applyCancellation(), applyReturn(), rejectCancellation(), lockLeave() and askLocked() run inside the caller's
 * transaction and never open one (a nested START TRANSACTION would commit the caller's). recordReturn(), putBackDay() and
 * withdrawCancellation() own theirs.
 *
 * Results that set the trait's state (the last cancellation's outcome, the last return applied) come back as 'outcome' and
 * 'applied', null when they were not set.
 */
final class LeaveReturnService
{
    /** @var LeaveReturnStore */
    private $store;
    /** @var LeaveReturnPorts */
    private $site;
    /** @var ScheduleDayService */
    private $days;
    /** @var Transaction */
    private $tx;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(LeaveReturnStore $store, LeaveReturnPorts $site, ScheduleDayService $days, Transaction $tx, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->site = $site;
        $this->days = $days;
        $this->tx = $tx;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /* ------------------------------------------------------------------ reads */

    /** The leave with its type, locked until the transaction ends (not on SQLite). */
    public function lockLeave(int $id): ?object
    {
        return $this->store->lockLeave($id);
    }

    /** The current (newest) change round of a leave, or null. */
    public function currentChange(int $leaveId): ?object
    {
        $c = $this->store->changes($leaveId, 1);
        return $c ? $c[0] : null;
    }

    /** Snapshot days of a leave on or after a day. */
    public function daysFrom(int $leaveId, string $from): int
    {
        return $this->store->daysFrom($leaveId, $from);
    }

    /** The leave's days from a day on: its snapshot days, or for a leave kept without any (very old data) its working days. */
    public function returnDaysFrom(object $r, string $from): int
    {
        if ($this->store->daysFrom((int) $r->id, '0000-00-00') > 0) return $this->store->daysFrom((int) $r->id, $from);
        return $from <= (string) $r->end_date ? (int) $this->site->workingDaysCount(max($from, (string) $r->start_date), (string) $r->end_date) : 0;
    }

    /** The employee's own leave request, or null. @param int|string $employeeId */
    public function findOwn(int $id, $employeeId): ?object
    {
        return $this->store->findOwn($id, $employeeId);
    }

    /** An employee's approved leaves, newest first (HR's "Back at work"). @return object[] */
    public function approvedLeaves(int $employeeId): array
    {
        return $this->store->approvedLeaves($employeeId);
    }

    /**
     * What a return from $back would do now (the preview, and the handler's check): the first day returned once closed payroll
     * months stay leave, the days, whether all of it goes, the balance after and a change still waiting.
     * @return array{asked:string,effective:?string,closed:string[],days:int,whole:bool,back_days:float,end_after:string,used_after:?float,entitlement:?float,pending_change:bool}
     */
    public function preview(object $r, string $back): array
    {
        $closed = $this->site->closedMonths($back, (string) $r->end_date);
        $eff = ReturnRules::effectiveFrom($back, (string) $r->end_date, $closed);
        $days = $eff === null ? 0 : $this->returnDaysFrom($r, $eff);
        $all = $this->returnDaysFrom($r, (string) $r->start_date);
        $whole = $eff !== null && $days > 0 && ($days === $all || $eff <= (string) $r->start_date);
        $bal = (int) $r->deduct_balance ? $this->site->balance($r) : null;
        $backDays = $days ? min((float) $r->requested_days, (float) $days) : 0.0;
        if ($whole) $backDays = (float) $r->requested_days;
        return ['asked' => $back, 'effective' => $eff, 'closed' => $closed, 'days' => $days, 'whole' => $whole, 'back_days' => $backDays,
            'end_after' => $eff !== null && !$whole ? gmdate('Y-m-d', (int) strtotime($eff . ' -1 day')) : '',
            'used_after' => $bal ? max(0.0, (float) $bal->used - $backDays) : null, 'entitlement' => $bal ? (float) $bal->entitlement : null,
            'pending_change' => (string) ($r->cancellation_status ?? '') === 'Pending'];
    }

    /* ------------------------------------------------------------------ change rounds */

    /** A new change round. @return int its id, 0 on a database error */
    private function insertChange(object $r, string $source, string $asked, string $reason, int $approvalId): int
    {
        $round = 1 + $this->store->changeCount((int) $r->id);
        $row = ['leave_request_id' => (int) $r->id, 'employee_id' => (int) $r->employee_id, 'round' => $round, 'source' => $source, 'asked_from' => $asked,
            'status' => $source === 'hr' ? 'Recorded' : 'Pending', 'end_before' => (string) $r->end_date, 'reason' => $reason !== '' ? $reason : null,
            'requested_by' => $this->site->actorId(), 'requested_at' => $this->clock->now()];
        if ($approvalId) $row['approval_request_id'] = $approvalId;
        return $this->store->insertChange($row);
    }

    /** The current round ends (Rejected, Withdrawn, Closed, Ended…). Nothing when the leave has no round (asked before 3.31.161). */
    private function closeChange(int $leaveId, string $status): bool
    {
        $c = $this->currentChange($leaveId);
        if (!$c || !in_array((string) $c->status, ['Pending'], true)) return true;
        return $this->store->closeChange((int) $c->id, ['status' => $status, 'decided_by' => $this->site->actorId(), 'decided_at' => $this->clock->now()]);
    }

    /* ------------------------------------------------------------------ schedule and balance */

    /** The schedule of these snapshot days back as before the leave (and, $drop, the snapshots gone: 3.31.158). */
    private function restoreDays(array $rows, object $emp, string $now, bool $drop = false): bool
    {
        foreach ($rows as $x) {
            if ((int) $x->had_schedule && $x->schedule_id) $q = $this->days->restoreRow((int) $x->schedule_id, $x->status, $x->note, $this->site->actorId(), $now);
            else $q = $this->days->removeDay((int) $emp->id, (string) $x->work_date);
            if ($q === false) return false;
            if ($drop && $this->store->deleteLeaveDay((int) $x->id) === false) return false;
        }
        return true;
    }

    /**
     * One snapshot day back as it was before the leave, unless a manager changed it meanwhile (owner, 2026-11-24: their status is
     * kept, and the Audit Log says so). @return bool false on a database error
     */
    public function restoreDay(object $x, object $emp, string $now, int $leaveId): bool
    {
        $cur = $this->days->day((int) $emp->id, (string) $x->work_date);
        if (!$cur || (string) $cur->status !== 'Vacation') {
            $this->audit->record('leave_restore_kept', 'leave_request', $leaveId, (string) $x->work_date . ' kept as ' . ($cur ? (string) $cur->status : 'no schedule') . ' (changed while on leave)');
            return true;
        }
        return $this->restoreDays([$x], $emp, $now);
    }

    /**
     * Next year's carried days follow a late return (owner, 2026-11-24): when days go back to a year whose next year's balance
     * already exists, it grows by what the carry rule now allows, never more than the days returned.
     * @param mixed $back @return float|false the days added
     */
    public function carryTopUp(object $r, ?object $bal, $back, string $now)
    {
        if ($back <= 0 || !$bal) return 0.0;
        $next = $this->store->balanceFor((int) $bal->employee_id, (int) $bal->leave_type_id, (int) $bal->leave_year + 1);
        if (!$next) return 0.0;   // it does not exist yet: it carries the corrected figures when it is made
        $year = $this->store->balanceById((int) $bal->id);
        if (!$year) return false;
        $add = ReturnRules::carryTopUp(TypeRules::config($r), (float) $year->entitlement, (float) $year->used, (float) $year->pending, (float) ($next->carried ?? 0), (float) $back);
        if ($add <= 0) return 0.0;
        if ($this->store->addCarried((int) $next->id, $add, $now) === false) return false;
        $this->audit->record('leave_carry_topped_up', 'leave_balance', (int) $next->id, 'leave #' . (int) $r->id . ': ' . (int) $bal->leave_year . ' → ' . ((int) $bal->leave_year + 1) . ' +' . $add . ' carried');
        return $add;
    }

    /* ------------------------------------------------------------------ decisions (in the caller's transaction) */

    /** The day the current change asks to cancel from: the day back at work, or the first day (all of it). */
    private function askedDay(object $r): string
    {
        $from = $this->site->cancelFromDay($r);
        return $from !== null ? $from : (string) $r->start_date;
    }

    /** @param true|string $result @param array<string,mixed>|null $applied @return array{result:true|string,outcome:?string,applied:?array} */
    private static function out($result, ?string $outcome = null, ?array $applied = null): array
    {
        return ['result' => $result, 'outcome' => $outcome, 'applied' => $applied];
    }

    /**
     * Cancellation approved: return used days, restore the snapshotted schedule, mark Cancelled. With Leave return on, the return
     * itself. @return array{result:true|string,outcome:?string,applied:?array}
     */
    public function applyCancellation(object $r, object $emp, ?object $bal, string $now, bool $returnOn): array
    {
        // Leave return (3.31.161): under the leave's row lock, from the days actually removed, closed payroll months kept.
        if ($returnOn) return $this->applyReturn((int) $r->id, $emp, $now, $this->askedDay($r), 'employee', 'Pending');
        $this->site->substitutesSyncAtShutdown();   // a manager's substitute follows the leave (3.31.107)
        // With Leave in My requests on (3.31.158, owner): a cancellation decided once the leave has started cancels the days from
        // today on only; the days taken stay leave. Once it has ended, nothing is left to cancel.
        $from = $this->site->cancelFromDay($r);
        if ($from !== null) return $this->applyCancellationFrom($r, $emp, $bal, $now, $from);
        if ((int) $r->deduct_balance) {
            $q = $this->store->lowerUsed($bal ? $bal->id : null, $r->requested_days, $now);
            if ($q === false) return self::out('save');
        }
        $rows = $this->store->leaveDays((int) $r->id);
        if ($rows === null) return self::out('save');
        if (!$this->restoreDays($rows, $emp, $now)) return self::out('save');
        $q = $this->store->cancelWhole((int) $r->id, $this->site->actorId(), $now);
        if ($q !== 1) return self::out($q === false ? 'save' : 'done');
        $this->site->weekLockRecord((int) $emp->id, array_map(static function ($x) { return (string) $x->work_date; }, $rows), 'leave_cancel', 'leave #' . (int) $r->id);
        return self::out(true);
    }

    /**
     * A started leave's cancellation (3.31.158): the days from $from on go back to the balance and to the schedule as before the
     * leave; the leave now ends the day before. Past its end, the cancellation is closed as Ended with nothing changed.
     * @return array{result:true|string,outcome:?string,applied:?array}
     */
    private function applyCancellationFrom(object $r, object $emp, ?object $bal, string $now, string $from): array
    {
        if ($from > (string) $r->end_date) {
            $q = $this->store->endLegacy((int) $r->id, $this->site->actorId(), $now);
            return self::out($q === 1 ? true : ($q === false ? 'save' : 'done'), 'ended');
        }
        $rows = $this->store->leaveDaysFrom((int) $r->id, $from);
        if ($rows === null) return self::out('save');
        // The days given back: the leave's days from then on (its snapshots); a leave kept without snapshots counts its working days.
        $any = $rows ? 1 : $this->store->countLeaveDays((int) $r->id);
        $back = min((float) $r->requested_days, (float) ($any ? count($rows) : $this->site->workingDaysCount($from, (string) $r->end_date)));
        if (!$this->restoreDays($rows, $emp, $now, true)) return self::out('save');
        if ((int) $r->deduct_balance && $back > 0) {
            $q = $this->store->lowerUsed($bal ? $bal->id : null, $back, $now);
            if ($q === false) return self::out('save');
        }
        $end = gmdate('Y-m-d', (int) strtotime($from . ' -1 day'));
        $q = $this->store->shorten((int) $r->id, $end, max(0.0, (float) $r->requested_days - $back), $this->site->actorId(), $now);
        if ($q !== 1) return self::out($q === false ? 'save' : 'done');
        // A manager's substitute ends when the leave now ends (review 2).
        if (!$this->site->followLeaveEnd((int) $r->id, (string) $r->start_date, $end)) return self::out('save');
        $this->site->weekLockRecord((int) $emp->id, array_map(static function ($x) { return (string) $x->work_date; }, $rows), 'leave_cancel', 'leave #' . (int) $r->id . ' from ' . $from);
        $this->audit->record('leave_cancelled_from', 'leave_request', (int) $r->id, $r->type_name . ' ' . $r->start_date . ' – ' . $r->end_date . ': cancelled from ' . $from . ', ' . $back . ' day(s) back');
        return self::out(true, 'from:' . $from);
    }

    /**
     * The return itself (Leave return on), for an approved change and for a return HR records; the caller owns the transaction.
     * Under the leave's row lock: the days from $asked on (days in a closed payroll month stay leave) go back to the schedule and
     * the balance, counted from the leave days actually removed, so a day is never returned twice.
     * $expect: the cancellation state the caller read ('Pending' for a decision; HR: whatever it found).
     * @return array{result:true|string,outcome:?string,applied:?array}
     */
    public function applyReturn(int $id, object $emp, string $now, string $asked, string $source, string $expect): array
    {
        $this->site->substitutesSyncAtShutdown();
        $r = $this->store->lockLeave($id);
        if (!$r || (string) $r->status !== 'Approved' || (string) ($r->cancellation_status ?? '') !== $expect) return self::out('done');
        if ($source === 'employee') {
            $asked = $this->askedDay($r);   // the round as locked, not as read before (review)
            // A level left open (approvals switched off meanwhile) would block every later round: closed now (review).
            if ($this->site->closeOpenApproval((int) $r->id) === false) return self::out('save');
        }
        $end = (string) $r->end_date;
        $closed = $this->site->closedMonths($asked, $end);
        $eff = ReturnRules::effectiveFrom($asked, $end, $closed);
        if ($eff === null) {
            if ($source === 'hr') return self::out('closed_payroll');
            $q = $this->store->endPending((int) $r->id, $this->site->actorId(), $now);
            if ($q !== 1) return self::out($q === false ? 'save' : 'done');
            if (!$this->closeChange((int) $r->id, 'Ended')) return self::out('save');
            if ($closed) $this->audit->record('leave_return_kept_closed', 'leave_request', (int) $r->id, 'nothing returned: payroll closed for ' . implode(', ', $closed));
            return self::out(true, 'ended', ['closed_all' => (bool) $closed]);
        }
        $rows = $this->store->lockLeaveDaysFrom((int) $r->id, $eff);
        if ($rows === null) return self::out('save');
        $deleted = 0;
        $dates = [];
        foreach ($rows as $x) {
            if (!$this->restoreDay($x, $emp, $now, (int) $r->id)) return self::out('save');
            $n = $this->store->deleteLeaveDay((int) $x->id);
            if ($n === false) return self::out('save');
            if ($n === 1) { $deleted++; $dates[] = (string) $x->work_date; }
        }
        $remaining = $this->store->countLeaveDays((int) $r->id);
        $has = ($deleted + $remaining) > 0;
        $back = ReturnRules::daysBack($deleted, $remaining, (float) $r->requested_days, $has, $has ? 0 : $this->site->workingDaysCount($eff, $end));
        $bal = $this->site->balance($r);
        $top = 0.0;
        if ((int) $r->deduct_balance && $back > 0) {
            if (!$bal) return self::out('save');
            if ($this->store->lowerUsed((int) $bal->id, $back, $now) === false) return self::out('save');
            $top = $this->carryTopUp($r, $bal, $back, $now);
            if ($top === false) return self::out('save');
        }
        $whole = $has ? $remaining === 0 : $eff <= (string) $r->start_date;
        $newEnd = $whole ? $end : gmdate('Y-m-d', (int) strtotime($eff . ' -1 day'));
        $set = $whole ? ['status' => 'Cancelled', 'cancellation_status' => 'Approved'] : ['end_date' => $newEnd, 'requested_days' => max(0, (float) $r->requested_days - $back), 'cancellation_status' => 'Approved'];
        $set += ['cancelled_by' => $this->site->actorId(), 'cancelled_at' => $now, 'cancel_from' => $asked];
        $was = $r->cancellation_status;   // as locked: the update changes the leave only if it is still so
        $q = $this->store->applyReturn((int) $r->id, $set, $was === null ? null : (string) $was);
        if ($q !== 1) return self::out($q === false ? 'save' : 'done');
        if (!$whole && !$this->site->followLeaveEnd((int) $r->id, (string) $r->start_date, $newEnd)) return self::out('save');
        // The round's history row: what was asked, what was applied (3.31.161).
        $c = $this->currentChange((int) $r->id);
        if ($c && in_array((string) $c->status, ['Pending', 'Recorded'], true) && !$this->store->finishChange((int) $c->id, ['status' => $source === 'hr' ? 'Recorded' : 'Approved', 'applied_from' => $eff, 'days_back' => $back, 'carry_top_up' => (float) $top, 'end_after' => $whole ? null : $newEnd, 'decided_by' => $this->site->actorId(), 'decided_at' => $now])) return self::out('save');
        $applied = ['from' => $eff, 'asked' => $asked, 'back' => $back, 'whole' => $whole, 'closed' => $closed, 'top' => (float) $top];
        $this->site->weekLockRecord((int) $emp->id, $dates, 'leave_cancel', 'leave #' . (int) $r->id . ($whole ? '' : ' from ' . $eff));
        $this->audit->record($source === 'hr' ? 'leave_return_recorded' : 'leave_cancelled_from', 'leave_request', (int) $r->id, $r->type_name . ' ' . $r->start_date . ' – ' . $end . ': ' . ($whole ? 'cancelled' : 'cancelled from ' . $eff) . ', ' . $back . ' day(s) back' . ($eff > $asked ? ' (asked from ' . $asked . '; payroll closed for ' . implode(', ', $closed) . ')' : '') . ($top > 0 ? ', next year +' . $top . ' carried' : '') . ($source === 'hr' && $c && (string) $c->reason !== '' ? ' | HR: ' . $this->site->actorName() . ' | reason: ' . $c->reason : ''));
        return self::out(true, $whole ? '' : 'from:' . $eff, $applied);
    }

    /** The cancellation refused. @return true|string leave_error */
    public function rejectCancellation(int $id, bool $returnOn)
    {
        $q = $this->store->rejectCancellation($id);
        if ($q !== 1) return $q === false ? 'save' : 'done';
        if ($returnOn && !$this->closeChange($id, 'Rejected')) return 'save';   // the round's history (3.31.161)
        if ($returnOn && $this->site->closeOpenApproval($id) === false) return 'save';
        return true;
    }

    /** An approved leave's cancellation marked as requested ("I'm back early", 3.31.158: also its first day). @return int|false */
    public function markPending(int $id, ?string $from)
    {
        return $this->store->markPending($id, $from);
    }

    /**
     * The employee's change (Leave return), inside the caller's transaction: checked again as locked, the leave Pending again, a
     * new approval round (with levels) and the round's row.
     * @return array{result:true|string,approval:int}
     */
    public function askLocked(int $id, object $emp, string $back, bool $levels): array
    {
        $approval = 0;
        $locked = $this->store->lockLeave($id);
        if (!$locked) return ['result' => 'done', 'approval' => $approval];
        // Checked again as locked: HR may have shortened it meanwhile (review).
        if (ReturnRules::checkBack((string) $locked->status, $locked->cancellation_status, (string) $locked->start_date, (string) $locked->end_date, $this->site->today(), $back, $this->returnDaysFrom($locked, $back)))
            return ['result' => 'done', 'approval' => $approval];
        $ok = $this->store->askAgain($id, $back);
        if ($ok !== 1) return ['result' => $ok === false ? 'save' : 'done', 'approval' => $approval];
        if ($levels) {
            $started = $this->site->startRound($id, (int) $emp->id);
            if ($started === null) return ['result' => 'approval', 'approval' => $approval];
            $approval = $started;
        }
        return ['result' => $this->insertChange($locked, 'employee', $back, '', $levels ? $approval : 0) ? true : 'save', 'approval' => $approval];
    }

    /* ------------------------------------------------------------------ own transactions */

    /**
     * The employee withdraws a cancellation still waiting (My requests, 3.31.158): the leave stays as it was.
     * @return true|string
     */
    public function withdrawCancellation(int $id, int $employeeId, bool $returnOn)
    {
        if (!$this->tx->begin()) return 'save';
        $ok = $this->store->withdrawCancellation($id, $employeeId);
        if ($ok !== 1) { $this->tx->rollback(); return $ok === false ? 'save' : 'done'; }
        $closed = $this->site->closeWithdrawnApproval($id);
        if ($closed !== true) {
            $this->tx->rollback();
            if ($closed !== false) return 'done';   // 'moved': decided meanwhile
            return $this->site->approvalDecided($id) ? 'done' : 'save';
        }
        if ($returnOn && !$this->closeChange($id, 'Withdrawn')) { $this->tx->rollback(); return 'save'; }   // 3.31.161
        if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
        $this->audit->record('leave_cancellation_withdrawn', 'leave_request', $id, 'cancellation withdrawn');
        return true;
    }

    /**
     * HR records that the employee was back at work on a day (owner, 2026-11-24): under the employee's lock and in its own
     * transaction. A change still waiting is closed first; the days from then on go back.
     * @return array{result:true|string,applied:?array,closed_round:bool}
     */
    public function recordReturn(int $id, object $emp, string $back, string $reason, string $now, string $expFrom, int $expDays): array
    {
        $applied = null;
        $closedRound = false;
        $result = $this->site->withEmployeeLock((int) $emp->id, function () use ($id, $emp, $back, $reason, $now, $expFrom, $expDays, &$applied, &$closedRound) {
            if (!$this->tx->begin()) return 'save';
            $res = $this->recordLocked($id, $emp, $back, $reason, $now, $expFrom, $expDays, $closedRound);
            if ($res['result'] !== true) { $this->tx->rollback(); return $res['result']; }
            if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
            $applied = $res['applied'];
            return true;
        });
        return ['result' => $result === true ? true : (string) $result, 'applied' => $applied, 'closed_round' => $closedRound];
    }

    /** @return array{result:true|string,outcome:?string,applied:?array} */
    private function recordLocked(int $id, object $emp, string $back, string $reason, string $now, string $expFrom, int $expDays, bool &$closedRound): array
    {
        $l = $this->store->lockLeave($id);
        if (!$l || (string) $l->status !== 'Approved') return self::out('done');
        $eff = ReturnRules::effectiveFrom($back, (string) $l->end_date, $this->site->closedMonths($back, (string) $l->end_date));
        if ($eff === null) return self::out(ReturnRules::CLOSED_PAYROLL);
        if ($eff !== $expFrom || $this->returnDaysFrom($l, $eff) !== $expDays) return self::out('changed');
        $was = (string) ($l->cancellation_status ?? '');
        if ($was === 'Pending') {
            $c = $this->site->closeOpenApproval($id);
            if ($c === false) return self::out('save');
            if ($c !== true) return self::out('moved');
            if (!$this->closeChange($id, 'Closed')) return self::out('save');
            $closedRound = true;
        }
        if (!$this->insertChange($l, 'hr', $back, $reason, 0)) return self::out('save');
        return $this->applyReturn($id, $emp, $now, $back, 'hr', $was);
    }

    /**
     * "Put the day back to leave" (the report, owner 2026-11-24): a past day of an approved leave that a manager changed is leave
     * again in the schedule. Open payroll months only; the leave and the balance do not change. @return true|string an error code
     */
    public function putBackDay(int $snapId)
    {
        $s = $this->store->snapshot($snapId);
        if (!$s) return 'snapshot';
        if ($this->site->closedMonths((string) $s->work_date, (string) $s->work_date)) return ReturnRules::CLOSED_PAYROLL;
        $result = $this->site->withEmployeeLock((int) $s->employee_id, function () use ($snapId, $s) {
            if (!$this->tx->begin()) return 'save';
            $l = $this->store->lockLeave((int) $s->leave_request_id);
            $still = $this->store->snapshotId($snapId);
            $cur = $this->days->day((int) $s->employee_id, (string) $s->work_date);
            $res = true;
            if (!$l || (string) $l->status !== 'Approved') $res = 'done';
            elseif (!$still) $res = 'snapshot';
            elseif ($cur && (string) $cur->status === 'Vacation') $res = 'kept';
            elseif (!$this->days->save((int) $s->employee_id, (string) $s->work_date, 'Vacation', 'Approved leave #' . (int) $l->id, $this->site->actorId())) $res = 'save';
            if ($res !== true) { $this->tx->rollback(); return $res; }
            if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
            $this->site->weekLockRecord((int) $s->employee_id, [(string) $s->work_date], 'leave_restore', 'leave #' . (int) $l->id);
            $this->audit->record('leave_day_restored', 'leave_request', (int) $l->id, (string) $s->work_date . ': ' . ($cur ? (string) $cur->status : 'no schedule') . ' → Vacation');
            return true;
        });
        return $result === true ? true : (string) $result;
    }
}
