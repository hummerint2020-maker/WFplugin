<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\People;
use WorkforceOne\Platform\Transaction;
use WorkforceOne\Schedule\ScheduleChangePorts;
use WorkforceOne\Schedule\ScheduleChangeRules;
use WorkforceOne\Schedule\ScheduleChangeService;
use WorkforceOne\Schedule\ScheduleChangeSettings;
use WorkforceOne\Schedule\ScheduleDayService;
use WorkforceOne\Schedule\ScheduleNotifier;
use WorkforceOne\Tests\Fakes\MemoryScheduleChangeStore;
use WorkforceOne\Tests\Fakes\MemoryScheduleDayStore;

require_once __DIR__ . '/Fakes/MemoryScheduleDayStore.php';
require_once __DIR__ . '/Fakes/MemoryScheduleChangeStore.php';

/**
 * Schedule change requests (3.31.167, docs/tasks/schedule-change-request.md): asking, deciding, applying with the guard,
 * withdrawing and closing, on in-memory stores (no WordPress, no database). The transaction fake rolls both stores back.
 */
final class ScheduleChangeServiceTest extends TestCase
{
    /** @var MemoryScheduleChangeStore */
    public $store;
    /** @var MemoryScheduleDayStore */
    public $dayStore;
    /** @var array<int,array<int,mixed>> */
    public $log = [];
    /** @var array<int,array<int,mixed>> */
    public $audits = [];
    public $on = true;
    public $cfg = [];
    public $final = ['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'];
    public $scope = [7 => true, 8 => true, 9 => false];
    public $active = [7 => true, 8 => true];
    public $leave = [];
    public $closed = [];
    public $approval = null;          // the engine's request: status
    public $actResult = '';
    public $afterAct = 'APPROVED';
    public $holidays = [];
    public $now = '2026-10-10 09:00:00';

    protected function setUp(): void
    {
        $this->store = new MemoryScheduleChangeStore();
        $this->dayStore = new MemoryScheduleDayStore();
        $this->cfg = ScheduleChangeSettings::config([], ['Office', 'WFH', 'Vacation', 'Absent']);
    }

    private function service(): ScheduleChangeService
    {
        $t = $this;
        $days = new ScheduleDayService($this->dayStore,
            new class implements People { public function activeBrief(int $id): ?object { return null; } public function activeEmployee(int $id): ?object { return null; } },
            new class implements ScheduleNotifier { public function dayChanged(int $u, string $d, string $s, int $id): void {} public function weekFinal(int $u, array $days, string $l): void {} },
            $this->audit(), new class($t) implements Clock { private $t; public function __construct($t) { $this->t = $t; } public function now(): string { return $this->t->now; } });
        $this->dayStore->calendar = &$this->holidays;
        return new ScheduleChangeService($this->store, $this->ports(), $days, $this->tx(), $this->audit(),
            new class($t) implements Clock { private $t; public function __construct($t) { $this->t = $t; } public function now(): string { return $this->t->now; } });
    }

    private function audit(): AuditLog
    {
        $t = $this;
        return new class($t) implements AuditLog {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function record(string $a, string $e, int $id, string $d): void { $this->t->audits[] = [$a, $e, $id, $d]; }
        };
    }

    private function tx(): Transaction
    {
        $t = $this;
        return new class($t) implements Transaction {
            private $t; private $snap;
            public function __construct($t) { $this->t = $t; }
            private static function copy(array $rows): array { return array_map(function ($o) { return clone $o; }, $rows); }
            public function begin(): bool { $this->t->log[] = ['begin']; $this->snap = [self::copy($this->t->store->requests), self::copy($this->t->store->days), self::copy($this->t->dayStore->rows)]; return true; }
            public function commit(): bool { $this->t->log[] = ['commit']; return true; }
            public function rollback(): void { $this->t->log[] = ['rollback']; [$this->t->store->requests, $this->t->store->days, $this->t->dayStore->rows] = $this->snap; }
        };
    }

    private function ports(): ScheduleChangePorts
    {
        $t = $this;
        return new class($t) implements ScheduleChangePorts {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function enabled(): bool { return $this->t->on; }
            public function settings(): array { return $this->t->cfg; }
            public function today(): string { return substr($this->t->now, 0, 10); }
            public function weekFinalFor(string $date): bool { return in_array($date, $this->t->final, true); }
            public function statusRule(string $status): ?string { return ['Office' => 'attendance', 'WFH' => 'attendance', 'Vacation' => 'leave', 'Absent' => 'absent'][$status] ?? null; }
            public function inScope(int $e): bool { return $this->t->scope[$e] ?? false; }
            public function activeEmployee(int $e): ?object { return !empty($this->t->active[$e]) ? (object) ['id' => $e, 'wp_user_id' => $e * 10] : null; }
            public function leaveOn(int $e, string $d): bool { return isset($this->t->leave[$e . '|' . $d]); }
            public function hasAttendance(int $e, string $d): bool { return false; }
            public function closedMonths(string $f, string $to): array { return array_values(array_filter($this->t->closed, function ($m) use ($f, $to) { return $m >= substr($f, 0, 7) && $m <= substr($to, 0, 7); })); }
            public function branchMode(): bool { return false; }
            public function branchAllowed(int $e, int $l, string $s, bool $r): bool { return true; }
            public function workflowReady(): bool { $this->t->log[] = ['workflowReady']; return true; }
            public function startApproval(int $id, int $req): bool { $this->t->log[] = ['start', $id]; $this->t->approval = (object) ['id' => 50 + $id, 'status' => 'WAITING_FOR_LEVEL_1']; return true; }
            public function findApproval(int $id): ?object { return $this->t->approval ? clone $this->t->approval : null; }
            public function levelMoved(int $ar, $posted): bool { return $posted !== null && (int) $posted === 999; }
            public function canAct(int $ar, bool $override): bool { return true; }
            public function act(int $ar, string $decision, string $note, $posted, bool $override): string {
                $this->t->log[] = ['act', $decision];
                if ($this->t->actResult !== '') return $this->t->actResult;
                $this->t->approval->status = $decision === 'reject' ? 'REJECTED' : $this->t->afterAct;
                return '';
            }
            public function closeApproval(int $id, bool $closed) { $this->t->log[] = ['closeApproval', $id, $closed]; return true; }
            public function weekLockRecord(int $e, array $days, string $ref): void { $this->t->log[] = ['weekLock', $e, $days, $ref]; }
            public function notifyApprover(object $r): void { $this->t->log[] = ['notifyApprover', (int) $r->id]; }
            public function notifyRequester(string $event, object $r, array $detail): void { $this->t->log[] = ['notifyRequester', $event, (int) $r->id, $detail['why'] ?? count($detail['skipped'] ?? [])]; }
            public function notifyApplied(int $e, array $days, int $id): void { $this->t->log[] = ['applied', $e, $days]; }
            public function notifyRequested(int $e, array $days, int $id): void { $this->t->log[] = ['requested', $e, $days]; }
            public function employeeName(int $e): string { return 'E' . $e; }
            public function actorId(): int { return 3; }
        };
    }

    private function ask(array $changes, string $reason = 'A client visits on Sunday'): array
    {
        return $this->service()->create((object) ['id' => 1], 2, $changes, $reason, 'grid');
    }

    private function events(string $name): array
    {
        return array_values(array_filter($this->log, function ($l) use ($name) { return $l[0] === $name; }));
    }

    public function testCreateSendsTheDaysThatMayBeAskedAndRefusesTheOthersWithTheirReason(): void
    {
        $this->dayStore->add(7, '2026-10-11', 'WFH');
        $this->dayStore->add(8, '2026-10-12', 'Office');
        $this->leave['7|2026-10-13'] = true;
        $r = $this->ask([
            ['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office'],
            ['employee' => 8, 'date' => '2026-10-12', 'status' => 'Office'],     // already so
            ['employee' => 7, 'date' => '2026-10-13', 'status' => 'Office'],     // a leave
            ['employee' => 9, 'date' => '2026-10-11', 'status' => 'Office'],     // not theirs
            ['employee' => 7, 'date' => '2026-10-18', 'status' => 'Office'],     // not a final week
            ['employee' => 8, 'date' => '2026-10-14', 'status' => 'Vacation'],   // leave types are off
            ['employee' => 8, 'date' => '2026-10-15', 'status' => 'Gone'],
        ]);
        $this->assertSame('', $r['error']);
        $this->assertSame(1, $r['sent']);
        $this->assertSame(['same', 'leave_day', 'scope', 'not_locked', 'leave_status', 'status'], array_column($r['refused'], 'code'));
        $req = $this->store->requests[$r['id']];
        $this->assertSame(['pending', '1', '2026-10-11'], [$req->status, (string) $req->days, $req->first_date]);
        $d = array_values($this->store->days)[0];
        $this->assertSame([1, 'WFH', 'Office', '7|2026-10-11'], [(int) $d->had_row, $d->old_status, $d->new_status, $d->open_key]);
        $this->assertSame('WFH', $this->dayStore->rows[1]->status, 'nothing changes before HR approves');
        $this->assertSame(['workflowReady', 'begin', 'start', 'commit'], array_column(array_filter($this->log, function ($l) { return in_array($l[0], ['workflowReady', 'begin', 'start', 'commit', 'rollback'], true); }), 0));
        $this->assertCount(1, $this->events('notifyApprover'));
        $this->assertSame('schedule_change_request', $this->audits[0][0]);
    }

    public function testCreateChecksTheReasonTheLimitAndAnOpenRequestOfTheSameDay(): void
    {
        $this->assertSame('reason', $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']], 'short')['error']);
        $this->cfg['max_days'] = 1;
        $this->assertSame('too_many', $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office'], ['employee' => 7, 'date' => '2026-10-12', 'status' => 'Office']])['error']);
        $this->cfg['max_days'] = 25;
        $this->assertSame('', $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['error']);
        $again = $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'WFH']]);
        $this->assertSame(['none', 'pending'], [$again['error'], $again['refused'][0]['code']], 'one open request per person and day');
        $this->on = false;
        $this->assertSame('access', $this->ask([['employee' => 7, 'date' => '2026-10-12', 'status' => 'Office']])['error']);
    }

    public function testCreateRollsBackEverythingWhenADayCannotBeSaved(): void
    {
        $this->store->failDay = true;
        $this->assertSame('save', $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['error']);
        $this->assertSame([], $this->store->requests);
        $this->assertSame([], $this->events('notifyApprover'));
    }

    public function testApprovedDaysAreAppliedAndADayChangedMeanwhileIsSkipped(): void
    {
        $this->dayStore->add(7, '2026-10-11', 'WFH');
        $this->dayStore->add(8, '2026-10-12', 'WFH');
        $id = $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office'], ['employee' => 8, 'date' => '2026-10-12', 'status' => 'Office'],
            ['employee' => 8, 'date' => '2026-10-13', 'status' => 'Absent']])['id'];
        $this->dayStore->rows[2]->status = 'Absent';   // another write after the request
        $card = $this->service()->card($id);
        $this->assertSame([false, true, false], array_column($card['days'], 'changed'));
        $r = $this->service()->decide($id, 'approve', '', null, false);
        $this->assertSame('partial', $r['result']);
        $this->assertSame('Office', $this->dayStore->rows[1]->status);
        $this->assertSame('Absent', $this->dayStore->rows[2]->status, 'the newer value is kept');
        $this->assertSame('Absent', $this->dayStore->find(8, '2026-10-13')->status, 'an empty day is created');
        $this->assertSame(['partial', 2], [$this->store->requests[$id]->status, (int) $this->store->requests[$id]->applied]);
        $this->assertSame(['changed', 'Absent'], [array_values($this->store->days)[1]->result, array_values($this->store->days)[1]->now_status]);
        $this->assertSame([null, null, null], array_column(array_map('get_object_vars', $this->store->days), 'open_key'), 'the days are free again');
        $this->assertSame([['applied', 7, ['2026-10-11' => 'Office']], ['applied', 8, ['2026-10-13' => 'Absent']]], $this->events('applied'), 'each employee told once');
        $this->assertSame([['notifyRequester', 'partial', $id, 1]], $this->events('notifyRequester'));
        $this->assertContains('schedule_change_skipped', array_column($this->audits, 0));
    }

    public function testRefuseModeChangesNothingAndLeavesTheLevelOpen(): void
    {
        $this->cfg['changed_day'] = 'refuse';
        $this->dayStore->add(7, '2026-10-11', 'WFH');
        $this->dayStore->add(7, '2026-10-12', 'WFH');
        $id = $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office'], ['employee' => 7, 'date' => '2026-10-12', 'status' => 'Office']])['id'];
        $this->dayStore->rows[2]->status = 'Absent';
        $this->assertSame('changed', $this->service()->decide($id, 'approve', '', null, false)['result']);
        $this->assertSame(['WFH', 'pending'], [$this->dayStore->rows[1]->status, $this->store->requests[$id]->status], 'rolled back');
        $this->assertSame([], $this->events('applied'));
    }

    public function testAClosedPayrollMonthIsNeverChanged(): void
    {
        $this->dayStore->add(7, '2026-10-11', 'WFH');
        $id = $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['id'];
        $this->closed = ['2026-10'];
        $r = $this->service()->decide($id, 'approve', '', null, false);
        $this->assertSame(['partial', 'WFH'], [$r['result'], $this->dayStore->rows[1]->status]);
        $this->assertSame('closed_month', array_values($this->store->days)[0]->result);
    }

    /** The days-back limit holds at the decision too (owner, 2026-11-26), also in "refuse" mode, which would otherwise wait forever. */
    public function testADayNowTooFarBackIsNotAppliedAtTheDecision(): void
    {
        $this->cfg['changed_day'] = 'refuse';
        $this->final[] = '2026-10-01';
        $this->dayStore->add(7, '2026-10-01', 'WFH');
        $this->dayStore->add(7, '2026-10-11', 'WFH');
        $id = $this->ask([['employee' => 7, 'date' => '2026-10-01', 'status' => 'Office'], ['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['id'];
        $this->now = '2026-10-16 09:00:00';   // 2026-10-01 is now 15 days back
        $r = $this->service()->decide($id, 'approve', '', null, false);
        $this->assertSame(['partial', 'WFH', 'Office'], [$r['result'], $this->dayStore->rows[1]->status, $this->dayStore->rows[2]->status]);
        $this->assertSame(['past', 'applied'], array_column(array_map('get_object_vars', array_values($this->store->days)), 'result'));
    }

    public function testRejectAdvanceMovedAndAnEngineError(): void
    {
        $id = $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['id'];
        $this->assertSame('moved', $this->service()->decide($id, 'approve', '', 999, false)['result']);
        $this->actResult = 'note';
        $this->assertSame('note', $this->service()->decide($id, 'reject', '', null, false)['result']);
        $this->actResult = '';
        $this->afterAct = 'WAITING_FOR_LEVEL_2';
        $this->assertSame('advanced', $this->service()->decide($id, 'approve', '', null, false)['result']);
        $this->assertSame('pending', $this->store->requests[$id]->status);
        $this->assertSame('rejected', $this->service()->decide($id, 'reject', 'Below the office minimum', null, false)['result']);
        $this->assertSame(['rejected', 'Below the office minimum', 3], [$this->store->requests[$id]->status, $this->store->requests[$id]->decision_note, $this->store->requests[$id]->decided_by]);
        $this->assertNull(array_values($this->store->days)[0]->open_key);
        $this->assertNull($this->dayStore->find(7, '2026-10-11'), 'nothing applied');
        $this->assertSame('done', $this->service()->decide($id, 'approve', '', null, false)['result']);
    }

    public function testWithdrawOnlyTheRequestersOwnWaitingOne(): void
    {
        $id = $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['id'];
        $this->assertSame('done', $this->service()->withdraw($id, 99));
        $this->assertSame('', $this->service()->withdraw($id, 1));
        $this->assertSame('withdrawn', $this->store->requests[$id]->status);
        $this->assertSame([['closeApproval', $id, false]], $this->events('closeApproval'));
        $this->assertSame('done', $this->service()->withdraw($id, 1));
        $this->assertSame('', $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['error'], 'the day can be asked again');
    }

    public function testExpireAfterTheDaysOrWhenTheMonthClosesAndCloseAll(): void
    {
        $a = $this->ask([['employee' => 7, 'date' => '2026-10-11', 'status' => 'Office']])['id'];
        $this->now = '2026-10-20 09:00:00';
        $b = $this->ask([['employee' => 8, 'date' => '2026-10-12', 'status' => 'Office']])['id'];
        $this->now = '2026-10-24 09:00:00';
        $this->assertSame(1, $this->service()->expireDue());
        $this->assertSame(['expired', 'expired', 'pending'], [$this->store->requests[$a]->status, $this->store->requests[$a]->decision_note, $this->store->requests[$b]->status]);
        $this->closed = ['2026-10'];
        $this->assertSame(1, $this->service()->expireDue());
        $this->assertSame(['closed', 'closed_month'], [$this->store->requests[$b]->status, $this->store->requests[$b]->decision_note]);
        $c = $this->ask([['employee' => 7, 'date' => '2026-10-14', 'status' => 'Office']]);
        $this->assertSame('closed_month', $c['refused'][0]['code'], 'a closed month cannot be asked for');
        $this->closed = [];
        $this->now = '2026-10-10 09:00:00';
        $d = $this->ask([['employee' => 7, 'date' => '2026-10-14', 'status' => 'Office']])['id'];
        $this->on = false;
        $this->assertSame(0, $this->service()->expireDue(), 'nothing while off');
        $this->assertSame(1, $this->service()->closeAll('off'));
        $this->assertSame(['closed', 'off'], [$this->store->requests[$d]->status, $this->store->requests[$d]->decision_note]);
        $this->assertContains(['notifyRequester', 'closed', $d, 'off'], $this->log);
    }

    public function testRulesAndSettings(): void
    {
        $cfg = ScheduleChangeSettings::config([], ['Office', 'WFH']);
        $f = ['scope' => true, 'final' => true, 'status' => 'Office', 'rule' => 'attendance', 'old' => 'WFH', 'date' => '2026-10-11', 'today' => '2026-10-10'];
        $this->assertSame('', ScheduleChangeRules::check($f, $cfg));
        $this->assertSame('past', ScheduleChangeRules::check(['date' => '2026-09-01'] + $f, $cfg));
        $this->assertSame('holiday', ScheduleChangeRules::check(['holiday' => true] + $f, $cfg));
        $this->assertSame('status', ScheduleChangeRules::check($f, ['statuses' => ['WFH']] + $cfg));
        $this->assertSame('', ScheduleChangeRules::check(['status' => 'Vacation', 'rule' => 'leave'] + $f, ['allow_leave' => 1] + $cfg));
        $this->assertTrue(ScheduleChangeRules::expired('2026-10-01 09:00:00', '2026-10-15 09:00:00', 14));
        $this->assertFalse(ScheduleChangeRules::expired('2026-10-01 09:00:00', '2026-10-30 09:00:00', 0), '0 = never');
        $this->assertSame([[], 'statuses'], ScheduleChangeSettings::fromPost(['sc_reason_min' => '10', 'sc_max_days' => '25', 'sc_past_days' => '14', 'sc_expire_days' => '14', 'sc_allow_leave' => '1'], ['Office']));
        $this->assertSame('max_days', ScheduleChangeSettings::fromPost(['sc_reason_min' => '10', 'sc_max_days' => '0', 'sc_past_days' => '14', 'sc_expire_days' => '14'], ['Office'])[1]);
        [$s] = ScheduleChangeSettings::fromPost(['sc_reason_min' => '5', 'sc_max_days' => '3', 'sc_past_days' => '0', 'sc_expire_days' => '0', 'sc_statuses' => ['Office', 'Nope'], 'sc_changed_day' => 'refuse'], ['Office', 'WFH']);
        $this->assertSame([['Office'], 'refuse', 0], [$s['statuses'], $s['changed_day'], $s['import']]);
        $post = ['sc_reason_min' => '10', 'sc_max_days' => '25', 'sc_past_days' => '14', 'sc_expire_days' => '14', 'sc_statuses' => ['Office', 'WFH']];
        $this->assertSame([], ScheduleChangeSettings::fromPost($post, ['Office', 'WFH', 'Vacation'], ['Office', 'WFH'])[0]['statuses'], 'every offered status ticked: stored as "all"');
        $this->assertSame(['Office'], ScheduleChangeSettings::fromPost(['sc_statuses' => ['Office']] + $post, ['Office', 'WFH'], ['Office', 'WFH'])[0]['statuses'], 'some: the list');
        $this->assertSame(ScheduleChangeSettings::DEFAULTS, ScheduleChangeSettings::config(ScheduleChangeSettings::fromPost($post + ['sc_import' => '1'], ['Office', 'WFH'], ['Office', 'WFH'])[0], ['Office', 'WFH']), 'an untouched card saves the defaults it read');
        $this->assertSame(ScheduleChangeSettings::DEFAULTS, ScheduleChangeSettings::config(['max_days' => 999, 'changed_day' => 'x'], []));
    }
}
