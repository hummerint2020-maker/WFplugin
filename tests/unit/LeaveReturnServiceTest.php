<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\LeaveReturnPorts;
use WorkforceOne\Leave\LeaveReturnService;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\People;
use WorkforceOne\Platform\Transaction;
use WorkforceOne\Schedule\ScheduleDayService;
use WorkforceOne\Schedule\ScheduleNotifier;
use WorkforceOne\Tests\Fakes\MemoryLeaveReturnStore;
use WorkforceOne\Tests\Fakes\MemoryScheduleDayStore;

require_once __DIR__ . '/Fakes/MemoryScheduleDayStore.php';
require_once __DIR__ . '/Fakes/MemoryLeaveReturnStore.php';

/**
 * Leave return and cancellations moved into LeaveReturnService (3.31.164, docs/tasks/leave-return-layers.md): the same results,
 * writes, audit rows and transaction order as the trait had, with fake stores and ports.
 */
final class LeaveReturnServiceTest extends TestCase
{
    /** @var MemoryLeaveReturnStore */
    private $store;
    /** @var MemoryScheduleDayStore */
    private $sched;
    /** @var object */
    private $site;
    /** @var object */
    private $tx;
    /** @var object */
    private $audit;
    /** @var object */
    private $emp;

    protected function setUp(): void
    {
        $this->store = new MemoryLeaveReturnStore();
        $this->sched = new MemoryScheduleDayStore();
        $this->emp = (object) ['id' => '3', 'name' => 'Sara', 'wp_user_id' => '9'];
        // Sun 4 – Thu 8 Oct 2026, five days; the days had Office before the leave.
        $this->store->addLeave(['id' => 7, 'employee_id' => '3', 'leave_type_id' => '2', 'balance_id' => '1', 'start_date' => '2026-10-04', 'end_date' => '2026-10-08',
            'requested_days' => '5.00', 'status' => 'Approved', 'cancellation_status' => 'Pending', 'cancel_from' => '2026-10-06']);
        foreach (['04', '05', '06', '07', '08'] as $i => $d) {
            $sid = $this->sched->add(3, '2026-10-' . $d, 'Vacation', 'Approved leave #7');
            $this->store->addDay(100 + $i, 7, 3, '2026-10-' . $d, $sid, 'Office');
        }
        $this->store->balances[1] = (object) ['id' => '1', 'employee_id' => '3', 'leave_type_id' => '2', 'leave_year' => '2026', 'entitlement' => 21.0, 'used' => 5.0, 'pending' => 0.0, 'carried' => 0.0];
        $this->site = new class($this->store) implements LeaveReturnPorts {
            public $store; public $closed = []; public $busy = false; public $closeOpen = true; public $closeWithdrawn = true; public $decided = false; public $round = 55;
            public $locks = []; public $weeks = []; public $calls = []; public $follow = true;
            public function __construct($store) { $this->store = $store; }
            public function cancelFromDay(object $leave): ?string { $a = (string) ($leave->cancel_from ?? ''); return $a !== '' && $a > (string) $leave->start_date ? $a : null; }
            public function today(): string { return '2026-10-05'; }
            public function closedMonths(string $from, string $to): array { return $this->closed; }
            public function workingDaysCount(string $start, string $end) { return (int) ((strtotime($end) - strtotime($start)) / 86400) + 1; }
            public function balance(object $leave): ?object { return $this->store->balanceById((int) ($leave->balance_id ?? 0)); }
            public function substitutesSyncAtShutdown(): void { $this->calls[] = 'substitutes'; }
            public function followLeaveEnd(int $leaveId, string $start, string $end): bool { $this->calls[] = 'follow:' . $end; return $this->follow; }
            public function weekLockRecord(int $employeeId, array $days, string $source, string $ref): void { $this->weeks[] = [$source, $days, $ref]; }
            public function withEmployeeLock(int $employeeId, callable $fn) { $this->locks[] = $employeeId; return $this->busy ? 'busy' : $fn(); }
            public function closeOpenApproval(int $leaveId) { $this->calls[] = 'closeOpen'; return $this->closeOpen; }
            public function closeWithdrawnApproval(int $leaveId) { return $this->closeWithdrawn; }
            public function approvalDecided(int $leaveId): bool { return $this->decided; }
            public function startRound(int $leaveId, int $employeeId): ?int { return $this->round; }
            public function actorId(): int { return 1; }
            public function returnOn(): bool { return true; }
            public function actorName(): string { return 'Hana'; }
        };
        $this->tx = new class implements Transaction {
            public $log = [];
            public function begin(): bool { $this->log[] = 'begin'; return true; }
            public function commit(): bool { $this->log[] = 'commit'; return true; }
            public function rollback(): void { $this->log[] = 'rollback'; }
        };
        $this->audit = new class implements AuditLog {
            public $rows = [];
            public function record(string $action, string $entity, int $entityId, string $details): void { $this->rows[] = [$action, $details]; }
        };
    }

    private function service(): LeaveReturnService
    {
        $clock = new class implements Clock { public function now(): string { return '2026-10-05 10:00:00'; } };
        $days = new ScheduleDayService($this->sched,
            new class implements People {
                public function activeBrief(int $id): ?object { return (object) ['name' => 'Sara', 'wp_user_id' => '9']; }
                public function activeEmployee(int $id): ?object { return (object) ['name' => 'Sara', 'wp_user_id' => '9']; }
            },
            new class implements ScheduleNotifier {
                public function dayChanged(int $u, string $d, string $s, int $id): void {}
                public function weekFinal(int $u, array $days, string $link): void {}
            }, $this->audit, $clock);
        return new LeaveReturnService($this->store, $this->site, $days, $this->tx, $this->audit, $clock);
    }

    private function statusOf(string $day): ?string
    {
        foreach ($this->sched->rows as $r) if ($r->work_date === $day) return $r->status;
        return null;
    }

    public function testApprovedChangeGivesBackTheDaysFromTheDayBack(): void
    {
        $this->store->insertChange(['leave_request_id' => 7, 'employee_id' => 3, 'round' => 1, 'source' => 'employee', 'asked_from' => '2026-10-06', 'status' => 'Pending', 'reason' => null]);
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, $this->store->balances[1], '2026-10-05 10:00:00', true);
        $this->assertSame(true, $o['result']);
        $this->assertSame('from:2026-10-06', $o['outcome']);
        $this->assertSame(['from' => '2026-10-06', 'asked' => '2026-10-06', 'back' => 3.0, 'whole' => false, 'closed' => [], 'top' => 0.0], $o['applied']);
        $l = $this->store->leaves[7];
        $this->assertSame(['2026-10-05', 2.0, 'Approved', 'Approved', 1], [$l->end_date, $l->requested_days, $l->status, $l->cancellation_status, $l->cancelled_by]);
        $this->assertSame(2.0, $this->store->balances[1]->used);
        $this->assertSame(['Vacation', 'Office', 'Office'], [$this->statusOf('2026-10-05'), $this->statusOf('2026-10-06'), $this->statusOf('2026-10-08')]);
        $this->assertCount(2, $this->store->snaps);
        $this->assertSame(['Approved', '2026-10-06', 3.0, '2026-10-05'], [$this->store->changes[1]->status, $this->store->changes[1]->applied_from, $this->store->changes[1]->days_back, $this->store->changes[1]->end_after]);
        $this->assertSame(['substitutes', 'closeOpen', 'follow:2026-10-05'], $this->site->calls);
        $this->assertSame([['leave_cancel', ['2026-10-06', '2026-10-07', '2026-10-08'], 'leave #7 from 2026-10-06']], $this->site->weeks);
        $this->assertSame('leave_cancelled_from', $this->audit->rows[0][0]);
        $this->assertSame([], $this->tx->log);   // the caller owns the transaction
    }

    public function testAllOfItCancelsTheLeave(): void
    {
        $this->store->leaves[7]->cancel_from = null;
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, null, 'now', true);
        $this->assertSame([true, ''], [$o['result'], $o['outcome']]);
        $this->assertSame(['Cancelled', 0.0], [$this->store->leaves[7]->status, $this->store->balances[1]->used]);
        $this->assertSame([], $this->store->snaps);
    }

    public function testAManagersEditIsKept(): void
    {
        foreach ($this->sched->rows as $r) if ($r->work_date === '2026-10-07') $r->status = 'WFH';
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, null, 'now', true);
        $this->assertTrue($o['result']);
        $this->assertSame('WFH', $this->statusOf('2026-10-07'));
        $this->assertSame('leave_restore_kept', $this->audit->rows[0][0]);
        $this->assertSame(3.0, $o['applied']['back']);
    }

    public function testEveryDayInAClosedMonthEndsTheChange(): void
    {
        $this->site->closed = ['2026-10'];
        $this->store->insertChange(['leave_request_id' => 7, 'employee_id' => 3, 'round' => 1, 'source' => 'employee', 'asked_from' => '2026-10-06', 'status' => 'Pending', 'reason' => null]);
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, null, 'now', true);
        $this->assertSame([true, 'ended', ['closed_all' => true]], [$o['result'], $o['outcome'], $o['applied']]);
        $this->assertSame(['Ended', 'Ended'], [$this->store->leaves[7]->cancellation_status, $this->store->changes[1]->status]);
        $this->assertSame('leave_return_kept_closed', $this->audit->rows[0][0]);
        $this->assertCount(5, $this->store->snaps);
    }

    public function testAMovedLeaveIsDoneAndAFailedWriteIsSave(): void
    {
        $o = $this->service()->applyReturn(7, $this->emp, 'now', '2026-10-06', 'hr', 'Approved');
        $this->assertSame('done', $o['result']);
        $this->store->fail = 'lowerUsed';
        $this->assertSame('save', $this->service()->applyReturn(7, $this->emp, 'now', '2026-10-06', 'employee', 'Pending')['result']);
    }

    public function testCarriedDaysFollowALateReturn(): void
    {
        $this->store->leaves[7]->carry_mode = 'all';
        $this->store->balances[2] = (object) ['id' => '2', 'employee_id' => '3', 'leave_type_id' => '2', 'leave_year' => '2027', 'entitlement' => 37.0, 'used' => 0.0, 'pending' => 0.0, 'carried' => 16.0];
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, null, 'now', true);
        $this->assertTrue($o['result']);
        $this->assertGreaterThan(0.0, $o['applied']['top']);
        $this->assertSame(16.0 + $o['applied']['top'], $this->store->balances[2]->carried);
        $this->assertSame('leave_carry_topped_up', $this->audit->rows[0][0]);
    }

    public function testLegacyCancellationWholeAndFromAndEnded(): void
    {
        $this->store->leaves[7]->cancel_from = null;
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, $this->store->balances[1], 'now', false);
        $this->assertSame([true, null], [$o['result'], $o['outcome']]);
        $this->assertSame(['Cancelled', 0.0, 'Office'], [$this->store->leaves[7]->status, $this->store->balances[1]->used, $this->statusOf('2026-10-04')]);
        $this->assertCount(5, $this->store->snaps);   // the whole-leave cancellation keeps its snapshots, as before
        $this->assertSame('leave_cancel', $this->site->weeks[0][0]);

        $this->setUp();
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, $this->store->balances[1], 'now', false);
        $this->assertSame([true, 'from:2026-10-06'], [$o['result'], $o['outcome']]);
        $this->assertSame(['2026-10-05', 2.0], [$this->store->leaves[7]->end_date, $this->store->leaves[7]->requested_days]);
        $this->assertCount(2, $this->store->snaps);

        $this->setUp();
        $this->store->leaves[7]->cancel_from = '2026-10-09';
        $this->store->fail = 'endLegacy';
        $o = $this->service()->applyCancellation($this->store->leaves[7], $this->emp, null, 'now', false);
        $this->assertSame(['save', 'ended'], [$o['result'], $o['outcome']]);   // set before the UPDATE's result is checked, as before
    }

    public function testRejectAndMarkPending(): void
    {
        $this->store->insertChange(['leave_request_id' => 7, 'employee_id' => 3, 'round' => 1, 'status' => 'Pending']);
        $this->assertTrue($this->service()->rejectCancellation(7));
        $this->assertSame(['Rejected', 'Rejected'], [$this->store->leaves[7]->cancellation_status, $this->store->changes[1]->status]);
        $this->assertSame('done', $this->service()->rejectCancellation(7));
        $this->store->leaves[7]->cancellation_status = null;
        $this->assertSame(1, $this->service()->markPending(7, '2026-10-07'));
        $this->assertSame('2026-10-07', $this->store->leaves[7]->cancel_from);
    }

    public function testTheEmployeeAsksAgain(): void
    {
        $this->store->leaves[7]->cancellation_status = 'Rejected';
        $o = $this->service()->askLocked(7, $this->emp, '2026-10-06', true);
        $this->assertSame(['result' => true, 'approval' => 55], $o);
        $this->assertSame(['Pending', 1, 55, 'employee'], [$this->store->leaves[7]->cancellation_status, $this->store->changes[1]->round, $this->store->changes[1]->approval_request_id, $this->store->changes[1]->source]);
        // Already waiting: refused as locked.
        $this->assertSame('done', $this->service()->askLocked(7, $this->emp, '2026-10-06', true)['result']);
        // The approval could not start.
        $this->store->leaves[7]->cancellation_status = 'Withdrawn';
        $this->site->round = null;
        $this->assertSame('approval', $this->service()->askLocked(7, $this->emp, '2026-10-06', true)['result']);
    }

    public function testWithdraw(): void
    {
        $this->store->insertChange(['leave_request_id' => 7, 'employee_id' => 3, 'round' => 1, 'status' => 'Pending']);
        $this->assertTrue($this->service()->withdrawCancellation(7, 3));
        $this->assertSame(['begin', 'commit'], $this->tx->log);
        $this->assertSame(['Withdrawn', 'Withdrawn'], [$this->store->leaves[7]->cancellation_status, $this->store->changes[1]->status]);
        $this->assertSame('leave_cancellation_withdrawn', $this->audit->rows[0][0]);

        $this->setUp();
        $this->site->closeWithdrawn = false;
        $this->site->decided = true;
        $this->assertSame('done', $this->service()->withdrawCancellation(7, 3));
        $this->assertSame(['begin', 'rollback'], $this->tx->log);
        $this->site->decided = false;
        $this->store->leaves[7]->cancellation_status = 'Pending';   // the fake has no rollback
        $this->assertSame('save', $this->service()->withdrawCancellation(7, 3));
    }

    public function testHrRecordsAReturn(): void
    {
        $s = $this->service();
        $this->site->busy = true;
        $this->assertSame('busy', $s->recordReturn(7, $this->emp, '2026-10-06', 'Back early', 'now', '2026-10-06', 3)['result']);
        $this->site->busy = false;
        $this->assertSame('changed', $s->recordReturn(7, $this->emp, '2026-10-06', 'Back early', 'now', '2026-10-06', 2)['result']);
        $this->assertSame(['begin', 'rollback'], $this->tx->log);
        $this->store->insertChange(['leave_request_id' => 7, 'employee_id' => 3, 'round' => 1, 'status' => 'Pending', 'reason' => null]);
        $o = $s->recordReturn(7, $this->emp, '2026-10-06', 'Back early', 'now', '2026-10-06', 3);
        $this->assertSame([true, true], [$o['result'], $o['closed_round']]);
        $this->assertSame(3.0, $o['applied']['back']);
        $this->assertSame(['Closed', 'Recorded', 'hr', 'Back early'], [$this->store->changes[1]->status, $this->store->changes[2]->status, $this->store->changes[2]->source, $this->store->changes[2]->reason]);
        $this->assertSame(['begin', 'rollback', 'begin', 'commit'], $this->tx->log);
        $last = end($this->audit->rows);
        $this->assertSame('leave_return_recorded', $last[0]);
        $this->assertStringContainsString(' | HR: Hana | reason: Back early', $last[1]);
        $this->site->closed = ['2026-10'];
        $this->assertSame('closed_payroll', $s->recordReturn(7, $this->emp, '2026-10-06', 'x', 'now', '2026-10-06', 3)['result']);
    }

    public function testPutTheDayBack(): void
    {
        $s = $this->service();
        $this->assertSame('snapshot', $s->putBackDay(999));
        $this->assertSame('kept', $s->putBackDay(100));
        foreach ($this->sched->rows as $r) if ($r->work_date === '2026-10-04') $r->status = 'Office';
        $this->assertTrue($s->putBackDay(100));
        $this->assertSame('Vacation', $this->statusOf('2026-10-04'));
        $this->assertSame([['leave_restore', ['2026-10-04'], 'leave #7']], $this->site->weeks);
        $this->assertSame('leave_day_restored', end($this->audit->rows)[0]);
        $this->site->closed = ['2026-10'];
        $this->assertSame('closed_payroll', $s->putBackDay(100));
    }

    public function testPreview(): void
    {
        $p = $this->service()->preview($this->store->leaves[7], '2026-10-06');
        $this->assertSame(['2026-10-06', 3, false, 3.0, '2026-10-05', 2.0, true], [$p['effective'], $p['days'], $p['whole'], $p['back_days'], $p['end_after'], $p['used_after'], $p['pending_change']]);
        $this->assertTrue($this->service()->preview($this->store->leaves[7], '2026-10-04')['whole']);
    }
}
