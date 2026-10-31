<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\AttendanceSite;
use WorkforceOne\Attendance\BreakCronService;
use WorkforceOne\Tests\Fakes\MemoryBreakStore;

require_once __DIR__ . '/Fakes/MemoryAttendanceStores.php';

/** BreakCronService (3.31.137): the reminder and the manager alert of a break, flags set once, on the id alone, in the old order. */
final class BreakCronServiceTest extends TestCase
{
    /** @var MemoryBreakStore */
    private $breaks;
    /** @var object */
    private $site;
    /** @var BreakCronService */
    private $svc;

    protected function setUp(): void
    {
        $this->breaks = new MemoryBreakStore();
        $this->breaks->add(1, 7, '2026-10-30', 'Open');
        $this->breaks->rows[0]->user_id = '70';
        $this->breaks->rows[0]->start_at = '2026-10-30 10:00:00';
        $this->breaks->rows[0]->duration_notified = '0';
        $this->breaks->rows[0]->escalation_notified = '0';
        $this->site = new class implements AttendanceSite {
            /** @var list<string> */
            public $log = [];
            /** @var bool */
            public $enabled = true;
            /** @var object|null */
            public $employee;
            public function __construct() { $this->employee = (object) ['id' => 7, 'name' => 'Omar']; }
            public function breakDurationMinutes(): int { $this->log[] = 'duration?'; return 15; }
            public function breakTimeEnded(int $userId, int $breakId, string $message): void { $this->log[] = "timeEnded $userId $breakId $message"; }
            public function extendedBreak(int $userId, int $breakId, string $message): void { $this->log[] = "extended $userId $breakId $message"; }
            public function attendanceEnabled(int $employeeId): bool { $this->log[] = 'enabled?'; return $this->enabled; }
            public function employee(int $employeeId): ?object { $this->log[] = 'employee?'; return $this->employee; }
            public function minutesSince(string $startAtLocal): int { return 42; }
            public function managerUserIds(): array { return [900, 901]; }
            public function generalLeave(string $day): bool { return false; }
            public function requiresSignIn(string $status): bool { return true; }
            public function breakEnabled(): bool { return true; }
            public function clearBreakEvents(int $breakId): void {}
            public function ensureBreakTable(): void {}
        };
        $this->svc = new BreakCronService($this->breaks, $this->site);
    }

    public function testTheReminderIsSentOnceAndTheFlagIsSetBeforeTheNotice(): void
    {
        $this->svc->remind(1);
        $this->svc->remind(1);
        $this->assertSame(['reminded 1'], $this->breaks->calls);
        $this->assertSame(['duration?', 'timeEnded 70 1 Your 15-minute break has ended. Please resume work.'], $this->site->log);
    }

    public function testNoReminderForAnUnknownOrClosedBreak(): void
    {
        $this->svc->remind(99);
        $this->breaks->rows[0]->status = 'Completed';
        $this->svc->remind(1);
        $this->assertSame([], $this->site->log);
        $this->assertSame([], $this->breaks->calls);
    }

    public function testTheEscalationGoesToEveryManagerOnce(): void
    {
        $this->svc->escalate(1);
        $this->svc->escalate(1);
        $this->assertSame(['enabled?', 'employee?', 'extended 900 1 Omar has been on break for 42 minutes and has not resumed work.', 'extended 901 1 Omar has been on break for 42 minutes and has not resumed work.'], $this->site->log);
        $this->assertSame(['escalated 1'], $this->breaks->calls);
    }

    public function testAttendanceOffStopsTheEscalationBeforeTheFlag(): void
    {
        $this->site->enabled = false;
        $this->svc->escalate(1);
        $this->assertSame([], $this->breaks->calls);
        $this->assertSame(['enabled?'], $this->site->log);
    }

    public function testAnEmployeeWhoIsGoneStillGetsTheFlagSetFirst(): void
    {
        $this->site->employee = null;
        $this->svc->escalate(1);
        $this->assertSame(['escalated 1'], $this->breaks->calls);
        $this->assertSame(['enabled?', 'employee?'], $this->site->log);
    }
}
