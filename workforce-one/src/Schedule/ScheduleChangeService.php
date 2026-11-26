<?php
namespace WorkforceOne\Schedule;

use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;
use WorkforceOne\Schedule\Data\ScheduleChangeStore;

if (!defined('ABSPATH')) exit;

/**
 * Schedule change requests (3.31.165, week lock release 2; docs/tasks/schedule-change-request.md, owner answers 2026-10-22):
 * a manager asks HR to change days of a final week, with a reason; HR approves or rejects the whole request on the approvals
 * engine; on the last approval every day still as it was asked is applied (a day changed meanwhile is skipped, or the whole
 * request refused by the setting); a waiting request can be withdrawn and closes by itself after expire_days or when its
 * payroll month closes.
 *
 * Lock order, the same in every path: the engine's rows (steps, then request), then the header, then its days (employee_id,
 * work_date), then the schedule rows in the same order. Only create(), decide(), withdraw() and closeOne() begin a
 * transaction; the engine is always called inside it. Everyone is told after COMMIT. No SQL here, no WordPress.
 */
final class ScheduleChangeService
{
    /** @var ScheduleChangeStore */
    private $store;
    /** @var ScheduleChangePorts */
    private $site;
    /** @var ScheduleDayService */
    private $days;
    /** @var Transaction */
    private $tx;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    /** More changes than this in one post are not even checked. */
    public const MAX_POSTED = 400;

    public function __construct(ScheduleChangeStore $store, ScheduleChangePorts $site, ScheduleDayService $days, Transaction $tx, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->site = $site;
        $this->days = $days;
        $this->tx = $tx;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /**
     * The manager's changes become one request (the days that may be asked for), or nothing.
     * @param list<array{employee:int,date:string,status:string,branch?:int|null}> $changes
     * @return array{id:int,error:string,sent:int,refused:list<array{employee:int,date:string,code:string}>}
     *         error: '' | access | employee | reason | approval | too_many | none | pending | save
     */
    public function create(?object $requester, int $userId, array $changes, string $reason, string $source): array
    {
        $out = ['id' => 0, 'error' => '', 'sent' => 0, 'refused' => []];
        if (!$this->site->enabled()) return ['error' => 'access'] + $out;
        if (!$requester || !(int) $requester->id) return ['error' => 'employee'] + $out;
        $cfg = $this->site->settings();
        $reason = trim($reason);
        if (mb_strlen($reason) < (int) $cfg['reason_min']) return ['error' => 'reason'] + $out;
        if (count($changes) > self::MAX_POSTED) return ['error' => 'too_many'] + $out;
        // One change per person and day: the last one wins.
        $by = [];
        foreach ($changes as $c) {
            $eid = (int) ($c['employee'] ?? 0);
            $date = (string) ($c['date'] ?? '');
            if (!$eid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $by[$eid . '|' . $date] = ['employee' => $eid, 'date' => $date, 'status' => (string) ($c['status'] ?? ''),
                'branch' => array_key_exists('branch', $c) && $c['branch'] !== null ? (int) $c['branch'] : null];
        }
        if (!$by) return ['error' => 'none'] + $out;
        $dates = array_column($by, 'date');
        $from = min($dates);
        $to = max($dates);
        $eids = array_values(array_unique(array_column($by, 'employee')));
        $open = [];
        foreach ($this->store->openOn($eids, $from, $to) as $o) $open[(int) $o->employee_id][(string) $o->work_date] = true;
        $holidays = [];
        foreach ($this->days->companyHolidays($from, $to) as $e) $holidays[(string) $e->event_date] = true;
        $closed = array_flip($this->site->closedMonths($from, $to));
        $today = $this->site->today();
        $branchMode = $this->site->branchMode();
        $scope = [];
        $ok = [];
        foreach ($by as $c) {
            $eid = $c['employee'];
            $date = $c['date'];
            $status = $c['status'];
            if (!isset($scope[$eid])) $scope[$eid] = $this->site->inScope($eid);
            $cur = $scope[$eid] ? $this->days->day($eid, $date) : null;
            $rule = $this->site->statusRule($status);
            $setBranch = $branchMode && $c['branch'] !== null;
            $oldLoc = $cur && isset($cur->location_id) ? (int) $cur->location_id : 0;
            $f = ['scope' => $scope[$eid], 'final' => $scope[$eid] && $this->site->weekFinalFor($date), 'status' => $status, 'rule' => $rule,
                'old' => $cur ? (string) $cur->status : null, 'branch_change' => $setBranch && $oldLoc !== (int) $c['branch'],
                'holiday' => isset($holidays[$date]), 'date' => $date, 'today' => $today, 'closed' => isset($closed[substr($date, 0, 7)]),
                'pending' => isset($open[$eid][$date])];
            // The reads that cost a query only when the cheaper checks passed.
            $code = ScheduleChangeRules::check($f, $cfg);
            if ($code === '') {
                $f['leave'] = $this->site->leaveOn($eid, $date);
                $f['attendance'] = $rule === 'leave' && $this->site->hasAttendance($eid, $date);
                if ($setBranch) $f['branch_ok'] = $this->site->branchAllowed($eid, (int) $c['branch'], $status, true);
                $code = ScheduleChangeRules::check($f, $cfg);
            }
            if ($code !== '') { $out['refused'][] = ['employee' => $eid, 'date' => $date, 'code' => $code]; continue; }
            $ok[] = ['employee_id' => $eid, 'work_date' => $date, 'had_row' => $cur ? 1 : 0, 'schedule_id' => $cur ? (int) $cur->id : null,
                'old_status' => $cur ? (string) $cur->status : null, 'new_status' => $status, 'branch_set' => $setBranch ? 1 : 0,
                'old_location_id' => $cur && isset($cur->location_id) ? (int) $cur->location_id : null,
                'new_location_id' => $setBranch && (int) $c['branch'] ? (int) $c['branch'] : null,
                'open_key' => ScheduleChangeRules::openKey($eid, $date)];
        }
        if (count($ok) > (int) $cfg['max_days']) return ['error' => 'too_many'] + $out;
        if (!$ok) return ['error' => 'none'] + $out;
        usort($ok, function ($a, $b) { return [$a['employee_id'], $a['work_date']] <=> [$b['employee_id'], $b['work_date']]; });
        $first = min(array_column($ok, 'work_date'));
        $last = max(array_column($ok, 'work_date'));
        // The workflow is set up in its own transaction, never inside ours.
        if (!$this->site->workflowReady()) return ['error' => 'approval'] + $out;
        $now = $this->clock->now();
        if (!$this->tx->begin()) return ['error' => 'save'] + $out;
        $id = $this->store->insertRequest(['requester_employee_id' => (int) $requester->id, 'requested_by' => $userId, 'source' => $source, 'reason' => mb_substr($reason, 0, 2000),
            'status' => 'pending', 'days' => count($ok), 'applied' => 0, 'first_date' => $first, 'last_date' => $last, 'requested_at' => $now, 'decision_note' => '']);
        if (!$id) { $this->tx->rollback(); return ['error' => 'save'] + $out; }
        foreach ($ok as $d) {
            // Never go on after a failed statement: SQLite has already ended the whole transaction.
            if (!$this->store->insertDay(['request_id' => $id] + $d)) {
                $dup = $this->store->lastWasDuplicate();
                $this->tx->rollback();
                return ['error' => $dup ? 'pending' : 'save'] + $out;
            }
        }
        if (!$this->site->startApproval($id, (int) $requester->id)) { $this->tx->rollback(); return ['error' => 'approval'] + $out; }
        $ar = $this->site->findApproval($id);
        if (!$ar || !self::waitingStatus((string) $ar->status)) { $this->tx->rollback(); return ['error' => 'approval'] + $out; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return ['error' => 'save'] + $out; }
        $this->audit->record('schedule_change_request', 'schedule_change', $id, sprintf('Request #%d · %d day(s) · %s → %s · "%s"', $id, count($ok), $first, $last, mb_substr($reason, 0, 300)));
        $r = $this->store->find($id);
        if ($r) $this->site->notifyApprover($r);
        if (!empty($cfg['notify_on_request'])) {
            $per = [];
            foreach ($ok as $d) $per[$d['employee_id']][$d['work_date']] = $d['new_status'];
            foreach ($per as $eid => $list) $this->site->notifyRequested((int) $eid, $list, $id);
        }
        return ['id' => $id, 'sent' => count($ok)] + $out;
    }

    /**
     * A level's decision (My approvals; the Requests Hub with the administrators' override).
     * @param int|null $posted the level the page showed
     * @return array{result:string,applied:list<array<string,mixed>>,skipped:list<array<string,mixed>>}
     *         result: advanced | approved | partial | rejected | access | done | moved | note | approval | changed | save
     */
    public function decide(int $id, string $decision, string $note, $posted, bool $override): array
    {
        $res = function (string $r, array $applied = [], array $skipped = []): array { return ['result' => $r, 'applied' => $applied, 'skipped' => $skipped]; };
        if (!$this->site->enabled() || !in_array($decision, ['approve', 'reject'], true)) return $res('access');
        $r = $this->store->find($id);
        if (!$r || (string) $r->status !== 'pending') return $res('done');
        $ar = $this->site->findApproval($id);
        if (!$ar) return $res('access');
        $open = self::waitingStatus((string) $ar->status);
        if ($this->site->levelMoved($open ? (int) $ar->id : 0, $posted)) return $res('moved');
        if (!$this->site->canAct((int) $ar->id, $override)) return $res('access');
        $cfg = $this->site->settings();
        $actor = $this->site->actorId();
        $note = trim(mb_substr($note, 0, 500));
        if (!$this->tx->begin()) return $res('save');
        $acted = $this->site->act((int) $ar->id, $decision, $note, $posted, $override);   // locks the engine's rows first
        if ($acted !== '') { $this->tx->rollback(); return $res($acted); }
        $fresh = $this->site->findApproval($id);
        $status = $fresh ? (string) $fresh->status : '';
        if (self::waitingStatus($status)) {
            if (!$this->tx->commit()) { $this->tx->rollback(); return $res('save'); }
            $this->audit->record('schedule_change_level', 'schedule_change', $id, sprintf('Request #%d · a level approved; it moves to the next one', $id));
            $this->site->notifyApprover($r);
            return $res('advanced');
        }
        $locked = $this->store->lockRequest($id);
        if (!$locked || (string) $locked->status !== 'pending') { $this->tx->rollback(); return $res('done'); }
        $now = $this->clock->now();
        if ($status === 'REJECTED') {
            $n = $this->store->claim($id, 'pending', ['status' => 'rejected', 'decided_by' => $actor ?: null, 'decided_at' => $now, 'decision_note' => $note]);
            if ($n !== 1) { $this->tx->rollback(); return $res($n === false ? 'save' : 'done'); }
            if (!$this->store->releaseDays($id)) { $this->tx->rollback(); return $res('save'); }
            if (!$this->tx->commit()) { $this->tx->rollback(); return $res('save'); }
            $this->audit->record('schedule_change_rejected', 'schedule_change', $id, sprintf('Request #%d · rejected', $id) . ($note !== '' ? ': ' . $note : ''));
            $this->site->notifyRequester('rejected', $locked, ['note' => $note]);
            return $res('rejected');
        }
        if ($status !== 'APPROVED') { $this->tx->rollback(); return $res('approval'); }
        $n = $this->store->claim($id, 'pending', ['status' => 'approved', 'decided_by' => $actor ?: null, 'decided_at' => $now]);
        if ($n !== 1) { $this->tx->rollback(); return $res($n === false ? 'save' : 'done'); }
        $out = $this->applyDays($locked, $cfg, $actor, $now);
        if ($out === 'save') { $this->tx->rollback(); return $res('save'); }
        if ($out === 'refuse') { $this->tx->rollback(); return $res('changed'); }   // nothing changes: the level stays open
        [$applied, $skipped] = $out;
        $final = ScheduleChangeRules::finalStatus((int) $locked->days, count($skipped));
        if ($this->store->finish($id, $final, count($applied)) === false) { $this->tx->rollback(); return $res('save'); }
        if (!$this->tx->commit()) { $this->tx->rollback(); return $res('save'); }
        $this->afterApplied($locked, $applied, $skipped, $final);
        return $res($final, $applied, $skipped);
    }

    /**
     * Inside the caller's transaction (never commits): every day still as asked is written; the others get their reason.
     * @param array<string,mixed> $cfg
     * @return string|array{0:list<array<string,mixed>>,1:list<array<string,mixed>>} 'save' | 'refuse' | [applied, skipped]
     */
    private function applyDays(object $r, array $cfg, int $actor, string $now)
    {
        $rows = $this->store->lockDays((int) $r->id);
        if ($rows === null) return 'save';
        // A payroll month closed since the request is never changed (the tick closes such a request; this covers the minutes between).
        $closed = array_flip($this->site->closedMonths((string) $r->first_date, (string) $r->last_date));
        $applied = [];
        $skipped = [];
        foreach ($rows as $d) {
            $eid = (int) $d->employee_id;
            $date = (string) $d->work_date;
            $new = (string) $d->new_status;
            $loc = $d->new_location_id !== null ? (int) $d->new_location_id : 0;
            $nowStatus = null;
            if (isset($closed[substr($date, 0, 7)])) $result = 'closed_month';
            elseif (!$this->site->activeEmployee($eid)) $result = 'inactive';
            elseif ($this->site->statusRule($new) === null) $result = 'gone';
            elseif ($this->site->leaveOn($eid, $date)) $result = 'leave';
            elseif ((int) $d->branch_set && !$this->site->branchAllowed($eid, $loc, $new, false)) $result = 'branch';
            else {
                $a = $this->days->applyIf($eid, $date, (int) $d->had_row ? (int) $d->schedule_id : null, (int) $d->had_row ? (string) $d->old_status : null,
                    $new, (bool) (int) $d->branch_set, $loc, $actor, $now);
                if ($a['result'] === 'save') return 'save';
                $result = $a['result'];
                $nowStatus = $a['now'];
            }
            if (!in_array($result, ['applied', 'already'], true) && $cfg['changed_day'] === 'refuse') return 'refuse';
            if (!$this->store->setDayResult((int) $d->id, $result, $nowStatus)) return 'save';
            $day = ['employee' => $eid, 'date' => $date, 'old' => $d->old_status, 'new' => $new, 'result' => $result, 'now' => $nowStatus];
            if (in_array($result, ['applied', 'already'], true)) $applied[] = $day;
            else $skipped[] = $day;
        }
        return [$applied, $skipped];
    }

    /** @param list<array<string,mixed>> $applied @param list<array<string,mixed>> $skipped */
    private function afterApplied(object $r, array $applied, array $skipped, string $final): void
    {
        $id = (int) $r->id;
        $per = [];
        foreach ($applied as $d) {
            if ($d['result'] !== 'applied') continue;
            $per[$d['employee']][$d['date']] = $d['new'];
            $this->audit->record('schedule_change_applied', 'employee', (int) $d['employee'], sprintf('Request #%d · %s %s: %s → %s (final week)', $id, $this->site->employeeName((int) $d['employee']), $d['date'],
                $d['old'] !== null && $d['old'] !== '' ? $d['old'] : 'Not Set', $d['new']));
        }
        foreach ($skipped as $d) {
            $this->audit->record('schedule_change_skipped', 'employee', (int) $d['employee'], sprintf('Request #%d · %s %s: not applied (%s%s)', $id, $this->site->employeeName((int) $d['employee']), $d['date'],
                $d['result'], $d['now'] !== null ? ', now ' . $d['now'] : ''));
        }
        $this->audit->record('schedule_change_approved', 'schedule_change', $id, sprintf('Request #%d · %d day(s) · %d applied', $id, (int) $r->days, count($applied)));
        foreach ($per as $eid => $days) {
            $this->site->weekLockRecord((int) $eid, array_keys($days), 'Schedule change #' . $id);
            $this->site->notifyApplied((int) $eid, $days, $id);
        }
        $this->site->notifyRequester($final, $r, ['skipped' => $skipped]);
    }

    /** My requests → Withdraw: the requester's own waiting request. @return string '' | done | save */
    public function withdraw(int $id, int $employeeId): string
    {
        $r = $this->store->find($id);
        if (!$r || (int) $r->requester_employee_id !== $employeeId || (string) $r->status !== 'pending') return 'done';
        if (!$this->tx->begin()) return 'save';
        $c = $this->site->closeApproval($id, false);
        if ($c === false) { $this->tx->rollback(); return 'save'; }
        if ($c === 'moved') { $this->tx->rollback(); return 'done'; }
        $n = $this->store->claimOwn($id, $employeeId, ['status' => 'withdrawn', 'decided_at' => $this->clock->now()]);
        if ($n !== 1) { $this->tx->rollback(); return $n === false ? 'save' : 'done'; }
        if (!$this->store->releaseDays($id)) { $this->tx->rollback(); return 'save'; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
        $this->audit->record('schedule_change_withdrawn', 'schedule_change', $id, sprintf('Request #%d · withdrawn by the requester', $id));
        return '';
    }

    /** The five-minute tick: waiting requests older than expire_days, or with a day in a payroll month closed since. @return int closed */
    public function expireDue(): int
    {
        if (!$this->site->enabled()) return 0;
        $cfg = $this->site->settings();
        $now = $this->clock->now();
        $n = 0;
        foreach ($this->store->waiting(500) as $r) {
            $why = ScheduleChangeRules::expired((string) $r->requested_at, $now, (int) $cfg['expire_days']) ? 'expired'
                : ($this->site->closedMonths((string) $r->first_date, (string) $r->last_date) ? 'closed_month' : '');
            if ($why !== '' && $this->closeOne($r, $why)) $n++;
        }
        return $n;
    }

    /** Every waiting request closed (the switch, the week lock or My approvals went off). Runs while it is off. @return int closed */
    public function closeAll(string $why): int
    {
        $n = 0;
        foreach ($this->store->waiting(1000) as $r) if ($this->closeOne($r, $why)) $n++;
        return $n;
    }

    private function closeOne(object $r, string $why): bool
    {
        $id = (int) $r->id;
        if (!$this->tx->begin()) return false;
        $c = $this->site->closeApproval($id, true);
        if ($c === false || $c === 'moved') { $this->tx->rollback(); return false; }   // decided meanwhile: left as it is
        $n = $this->store->claim($id, 'pending', ['status' => $why === 'expired' ? 'expired' : 'closed', 'decided_at' => $this->clock->now(), 'decision_note' => $why]);
        if ($n !== 1 || !$this->store->releaseDays($id)) { $this->tx->rollback(); return false; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return false; }
        $this->audit->record($why === 'expired' ? 'schedule_change_expired' : 'schedule_change_closed', 'schedule_change', $id, sprintf('Request #%d · closed automatically (%s)', $id, $why));
        $this->site->notifyRequester('closed', $r, ['why' => $why]);
        return true;
    }

    public function openCount(): int
    {
        return $this->store->openCount();
    }

    /**
     * The grid's badges: the days of these people waiting in a request. @param int[] $employeeIds
     * @return array<int,array<string,object>> employee id => Y-m-d => (new_status, request_id, requester_employee_id)
     */
    public function openOn(array $employeeIds, string $from, string $to): array
    {
        $out = [];
        foreach ($this->store->openOn($employeeIds, $from, $to) as $o) $out[(int) $o->employee_id][(string) $o->work_date] = $o;
        return $out;
    }

    /** @return list<array{request:object,days:object[]}> the requester's requests, newest first */
    public function mine(int $employeeId, int $limit): array
    {
        return $this->withDays($this->store->forRequester($employeeId, $limit));
    }

    /** @return list<array{request:object,days:object[]}> the waiting requests, oldest first (the Requests Hub) */
    public function waiting(int $limit): array
    {
        return $this->withDays($this->store->waiting($limit));
    }

    /**
     * The card HR decides on: each day with what it is now ("changed since the request" when it no longer is what was asked
     * from, or a leave now covers it), and the payroll months closed meanwhile.
     * @return array{request:object,days:list<array<string,mixed>>,closed:string[]}|null null unless it waits
     */
    public function card(int $id): ?array
    {
        $r = $this->store->find($id);
        if (!$r || (string) $r->status !== 'pending') return null;
        $days = [];
        foreach ($this->store->days($id) as $d) {
            $cur = $this->days->day((int) $d->employee_id, (string) $d->work_date);
            $now = $cur ? (string) $cur->status : null;
            $asked = (string) $d->new_status;
            $was = $d->old_status !== null ? (string) $d->old_status : null;
            $leave = $this->site->leaveOn((int) $d->employee_id, (string) $d->work_date);
            $days[] = ['employee' => (int) $d->employee_id, 'date' => (string) $d->work_date, 'old' => $was, 'new' => $asked, 'now' => $now,
                'changed' => $leave || ($now !== $was && $now !== $asked), 'leave' => $leave];
        }
        return ['request' => $r, 'days' => $days, 'closed' => $this->site->closedMonths((string) $r->first_date, (string) $r->last_date)];
    }

    /** @param object[] $rows @return list<array{request:object,days:object[]}> */
    private function withDays(array $rows): array
    {
        $days = $this->store->daysOf(array_map(function ($r) { return (int) $r->id; }, $rows));
        $out = [];
        foreach ($rows as $r) $out[] = ['request' => $r, 'days' => $days[(int) $r->id] ?? []];
        return $out;
    }

    /** The engine's request still waits for a level (WAITING_FOR_LEVEL_n, or any open state). */
    private static function waitingStatus(string $status): bool
    {
        return $status !== '' && !in_array($status, ['APPROVED', 'REJECTED', 'CANCELLED'], true);
    }
}
