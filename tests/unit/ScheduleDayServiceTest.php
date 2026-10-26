<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\OnceClaim;
use WorkforceOne\Platform\People;
use WorkforceOne\Schedule\ScheduleDayService;
use WorkforceOne\Schedule\ScheduleNotifier;
use WorkforceOne\Schedule\WeekLockService;
use WorkforceOne\Tests\Fakes\MemoryScheduleDayStore;

require_once __DIR__ . '/Fakes/MemoryScheduleDayStore.php';

/** The first layered slice (3.31.127): Schedule days and Week lock, on an in-memory store (no WordPress, no database). */
final class ScheduleDayServiceTest extends TestCase
{
    /** @var MemoryScheduleDayStore */
    private $store;
    /** @var array<int,array<int,mixed>> */
    public $told = [];
    /** @var array<int,array<int,mixed>> */
    public $audits = [];
    /** @var array<int,object|null> */
    public $people = [];

    protected function setUp(): void
    {
        $this->store = new MemoryScheduleDayStore();
        $this->people = [7 => (object) ['name' => 'Omar', 'wp_user_id' => '70'], 8 => (object) ['name' => 'No login', 'wp_user_id' => '0']];
    }

    private function service(): ScheduleDayService
    {
        $t = $this;
        return new ScheduleDayService($this->store,
            new class($t) implements People {
                private $t;
                public function __construct($t) { $this->t = $t; }
                public function activeBrief(int $id): ?object { return $this->t->people[$id] ?? null; }
                public function activeEmployee(int $id): ?object { return $this->t->people[$id] ?? null; }
            },
            $this->notifier(), $this->audit(),
            new class implements Clock { public function now(): string { return '2026-10-26 09:00:00'; } });
    }

    private function notifier(): ScheduleNotifier
    {
        $t = $this;
        return new class($t) implements ScheduleNotifier {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function dayChanged(int $u, string $d, string $s, int $id): void { $this->t->told[] = ['day', $u, $d, $s, $id]; }
            public function weekFinal(int $u, array $days, string $link): void { $this->t->told[] = ['week', $u, $days, $link]; }
        };
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

    public function testGridInsertsAnEmptyDayAndTellsThePerson(): void
    {
        $r = $this->service()->saveIfUnchanged(7, '2026-10-27', 'WFH', null, 3);
        $this->assertSame(['ok' => true, 'conflict' => false, 'id' => 1, 'changed' => true, 'old_status' => null], $r);
        $this->assertSame('3', (string) $this->store->rows[1]->updated_by);
        $this->assertSame('', $this->store->rows[1]->note);
        $this->assertSame([['day', 70, '2026-10-27', 'WFH', 1]], $this->told);
    }

    public function testGridConflictWhenTheEmptyDayIsTakenOrTheInsertLosesTheRace(): void
    {
        $id = $this->store->add(7, '2026-10-27', 'Office');
        $this->assertSame(['ok' => false, 'conflict' => true, 'id' => $id], $this->service()->saveIfUnchanged(7, '2026-10-27', 'WFH', null, 3));
        $this->store->raceRow = (object) ['id' => '9', 'employee_id' => '7', 'work_date' => '2026-10-28', 'status' => 'Office', 'note' => ''];
        $this->assertSame(['ok' => false, 'conflict' => true, 'id' => 9], $this->service()->saveIfUnchanged(7, '2026-10-28', 'WFH', null, 3));
        $this->assertSame([], $this->audits, 'a lost race is a conflict, not a database failure');
        $this->assertSame([], $this->told);
    }

    public function testGridInsertFailureIsAudited(): void
    {
        $this->store->failInsert = true;
        $this->assertSame(['ok' => false, 'conflict' => false, 'id' => 0], $this->service()->saveIfUnchanged(7, '2026-10-27', 'WFH', null, 3));
        $this->assertSame([['schedule_insert_failed', 'schedule', 0, 'disk full']], $this->audits);
    }

    public function testGridUpdateKeepsTheNoteAndRefusesAStaleStatus(): void
    {
        $id = $this->store->add(7, '2026-10-27', 'Office', 'bring laptop');
        $this->assertSame(['ok' => false, 'conflict' => true, 'id' => $id], $this->service()->saveIfUnchanged(7, '2026-10-27', 'WFH', 'Leave', 3));
        $r = $this->service()->saveIfUnchanged(7, '2026-10-27', 'WFH', 'Office', 3);
        $this->assertSame(['ok' => true, 'conflict' => false, 'id' => $id, 'changed' => true, 'old_status' => 'Office'], $r);
        $this->assertSame('bring laptop', $this->store->rows[$id]->note);
        $this->assertCount(1, $this->told);
    }

    public function testGridSameValueIsFineAndAConcurrentChangeIsAConflict(): void
    {
        $id = $this->store->add(7, '2026-10-27', 'WFH');
        $this->assertSame(['ok' => true, 'conflict' => false, 'id' => $id, 'changed' => false, 'old_status' => 'WFH'], $this->service()->saveIfUnchanged(7, '2026-10-27', 'WFH', 'WFH', 3));
        $this->store->raceStatus = 'Leave';
        $this->assertSame(['ok' => false, 'conflict' => true, 'id' => $id], $this->service()->saveIfUnchanged(7, '2026-10-27', 'Office', 'WFH', 3));
        $this->assertSame([], $this->told);
    }

    public function testGridUpdateFailureIsAudited(): void
    {
        $id = $this->store->add(7, '2026-10-27', 'WFH');
        $this->store->failUpdate = true;
        $this->assertSame(['ok' => false, 'conflict' => false, 'id' => $id], $this->service()->saveIfUnchanged(7, '2026-10-27', 'Office', 'WFH', 3));
        $this->assertSame([['schedule_update_failed', 'schedule', $id, 'lock wait timeout']], $this->audits);
    }

    public function testNobodyIsToldWithoutALoginOrWhenArchived(): void
    {
        $this->service()->saveIfUnchanged(8, '2026-10-27', 'WFH', null, 3);
        $this->service()->saveIfUnchanged(99, '2026-10-27', 'WFH', null, 0);
        $this->assertSame([], $this->told);
        $this->assertSame('0', (string) $this->store->rows[2]->updated_by, 'a job saves as user 0');
    }

    public function testFreeSaveTellsOnlyWhenSomethingChanged(): void
    {
        $s = $this->service();
        $id = $s->save(7, '2026-10-27', 'Office', 'a', 3);
        $this->assertSame(1, $id);
        $this->assertSame('1', $s->save(7, '2026-10-27', 'Office', 'a', 3), 'an existing day keeps the id as the database gives it');
        $s->save(7, '2026-10-27', 'Office', 'b', 3);
        $s->save(7, '2026-10-27', 'WFH', 'b', 3);
        $this->assertSame([['day', 70, '2026-10-27', 'Office', 1], ['day', 70, '2026-10-27', 'Office', 1], ['day', 70, '2026-10-27', 'WFH', 1]], $this->told);
        $this->store->failUpdate = true;
        $this->assertSame(0, $s->save(7, '2026-10-27', 'Leave', 'b', 3));
        $this->assertSame('schedule_update_failed', $this->audits[0][0]);
    }

    public function testWeekLockClaimsEachCutoffOnceAndTellsEachPersonTheirDays(): void
    {
        $claims = [];
        $claim = new class($claims) implements OnceClaim {
            public $v = [];
            public function __construct(array $v) { $this->v = $v; }
            public function current(string $n): string { return $this->v[$n] ?? ''; }
            public function claim(string $n, string $value): bool { if (($this->v[$n] ?? '') === $value) return false; $this->v[$n] = $value; return true; }
        };
        $w = new WeekLockService($this->store, $this->notifier(), $this->audit(), $claim);
        $cfg = ['cutoff_day' => 4, 'cutoff_time' => '17:00', 'notify' => 1];
        $days = [0, 1, 2, 3, 4];
        $week = $w->dueWeek($cfg, '2026-10-29 17:05:00', $days);
        $this->assertNotSame('', $week, 'Thursday 17:05, five minutes after the cutoff');
        $this->assertSame('', $w->dueWeek($cfg, '2026-10-29 17:10:00', $days), 'claimed once');
        $this->assertSame('', $w->dueWeek(['notify' => 0] + $cfg, '2026-11-05 17:05:00', $days), 'the notice is off');
        $this->assertSame('', (new WeekLockService($this->store, $this->notifier(), $this->audit(), $claim))->dueWeek($cfg, '2026-11-07 17:05:00', $days), 'more than a day late: not sent');
        $this->store->add(7, '2026-11-01', 'Office');
        $this->store->add(7, '2026-11-02', 'WFH');
        $this->store->add(8, '2026-11-01', 'Office');
        $this->assertSame(1, $w->notifyWeek(['2026-11-01', '2026-11-02'], [7 => 70], '/app/?ews_view=schedule'));
        $this->assertSame([['week', 70, ['2026-11-01' => 'Office', '2026-11-02' => 'WFH'], '/app/?ews_view=schedule']], $this->told);
        $this->assertSame(['week_lock_notified', 'schedule', 0, '2026-11-01: 1 people'], end($this->audits));
    }
}
