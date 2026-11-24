<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\Data\LeaveHolidayStore;
use WorkforceOne\Leave\HolidayRefundPorts;
use WorkforceOne\Leave\HolidayRefundService;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

/** Company holidays charged as leave (3.31.162): the report and giving back, with a fake Store and fake ports. */
final class HolidayRefundServiceTest extends TestCase
{
    /** @var object */
    private $store;
    /** @var object */
    private $ports;
    /** @var object */
    private $tx;
    /** @var object */
    private $audit;

    private function service(): HolidayRefundService
    {
        $leave = (object) ['id' => 7, 'employee_id' => 3, 'start_date' => '2026-10-04', 'end_date' => '2026-10-08', 'requested_days' => 5.0,
            'status' => 'Approved', 'cancellation_status' => null, 'type_name' => 'Annual', 'deduct_balance' => 1];
        $days = [];
        foreach (['04', '05', '06', '07', '08'] as $i => $d) $days[] = (object) ['id' => 100 + $i, 'work_date' => '2026-10-' . $d];
        $this->store = new class($leave, $days) implements LeaveHolidayStore {
            public $l; public $d; public $ref = []; public $used = 5.0; public $lostCas = false;
            public function __construct($l, $d) { $this->l = $l; $this->d = $d; }
            public function leavesOnDays(array $days, int $fromYear): array { return [7]; }
            public function leaves(array $ids): array { return [clone $this->l]; }
            public function leaveDays(array $ids): array { return [7 => array_map(static function ($x) { return $x->work_date; }, $this->d)]; }
            public function lockLeave(int $id): ?object { return clone $this->l; }
            public function lockLeaveDays(int $id): array { return $this->d; }
            public function refundedDays(int $leaveId): array { return array_keys($this->ref); }
            public function addRefund(int $leaveId, int $employeeId, string $day, int $balanceId, string $holiday, int $by, string $at): bool { if (isset($this->ref[$day])) return false; $this->ref[$day] = $holiday; return true; }
            public function removeLeaveDay(int $snapshotId): bool { $n = count($this->d); $this->d = array_values(array_filter($this->d, static function ($x) use ($snapshotId) { return $x->id !== $snapshotId; })); return count($this->d) < $n; }
            public function setRequestedDays(int $leaveId, float $before, float $after) { if ($this->lostCas || $this->l->requested_days != $before) return 0; $this->l->requested_days = $after; return 1; }
            public function lowerUsed(int $balanceId, float $days, string $at): bool { $this->used -= $days; return true; }
        };
        $this->ports = new class implements HolidayRefundPorts {
            public $notes = []; public $busy = false;
            public function holidays(string $start, string $end): array { return ['2026-10-06' => 'National Day']; }
            public function leaveYear(): int { return 2026; }
            public function employee(int $employeeId): ?object { return (object) ['id' => 3, 'name' => 'Sara', 'wp_user_id' => 9]; }
            public function withEmployeeLock(int $employeeId, callable $fn) { return $this->busy ? 'busy' : $fn(); }
            public function balance(object $leave): ?object { return (object) ['id' => 1, 'leave_year' => 2026]; }
            public function restoreDay(object $leaveDay, object $employee, string $now, int $leaveId): bool { return true; }
            public function carryTopUp(object $leave, object $balance, float $days, string $now) { return 0.0; }
            public function weekLockRecord(int $employeeId, array $days, string $ref): void {}
            public function notify(int $wpUserId, string $title, string $message, int $leaveId): void { $this->notes[] = $message; }
            public function actorId(): int { return 1; }
        };
        $this->tx = new class implements Transaction {
            public $log = [];
            public function begin(): bool { $this->log[] = 'begin'; return true; }
            public function commit(): bool { $this->log[] = 'commit'; return true; }
            public function rollback(): void { $this->log[] = 'rollback'; }
        };
        $this->audit = new class implements AuditLog {
            public $rows = [];
            public function record(string $action, string $entity, int $entityId, string $details): void { $this->rows[] = $action; }
        };
        $clock = new class implements Clock { public function now(): string { return '2026-11-24 10:00:00'; } };
        return new HolidayRefundService($this->store, $this->ports, $this->tx, $this->audit, $clock);
    }

    public function testReportAndGiveBackOnce(): void
    {
        $s = $this->service();
        $r = $s->report(2026);
        $this->assertSame([1, 4.0, true], [$r[0]['back'], $r[0]['should'], $r[0]['restorable']]);
        $this->assertSame(['days' => 1, 'leaves' => 1, 'failed' => 0, 'busy' => 0], $s->giveBack([7]));
        $this->assertSame([4.0, 4.0, 4], [$this->store->l->requested_days, $this->store->used, count($this->store->d)]);
        $this->assertSame(['leave_holiday_refund'], $this->audit->rows);
        $this->assertCount(1, $this->ports->notes);
        // Twice: nothing more, rolled back, no second audit or notification.
        $this->assertSame(['days' => 0, 'leaves' => 0, 'failed' => 1, 'busy' => 0], $s->giveBack([7]));
        $this->assertSame(['begin', 'commit', 'begin', 'rollback'], $this->tx->log);
        $this->assertCount(1, $this->audit->rows);
        $this->assertSame([], $s->report(2026));
    }

    public function testBusyAndLostCompareAndSet(): void
    {
        $s = $this->service();
        $this->ports->busy = true;
        $this->assertSame(['days' => 0, 'leaves' => 0, 'failed' => 1, 'busy' => 1], $s->giveBack([7]));
        $this->ports->busy = false;
        $this->store->lostCas = true;
        $this->assertSame(['days' => 0, 'leaves' => 0, 'failed' => 1, 'busy' => 0], $s->giveBack([7]));
        $this->assertSame(['begin', 'rollback'], $this->tx->log);
        $this->assertSame([], $this->audit->rows);
        $this->assertSame(5.0, $this->store->used);
    }
}
