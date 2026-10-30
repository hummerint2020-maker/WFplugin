<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Attendance\AttendanceSite;
use WorkforceOne\Attendance\EmployeeLock;
use WorkforceOne\Attendance\ManualRecordSite;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\People;
use WorkforceOne\Schedule\ScheduleDayService;
use WorkforceOne\Schedule\ScheduleNotifier;

require_once __DIR__ . '/MemoryAttendanceStores.php';
require_once __DIR__ . '/MemoryScheduleDayStore.php';

/** One in-memory site for the Auto Attendance and manual-record services' unit tests (3.31.138): the stores, the lock, people, the site, the audit log, the clock. */
final class RecordsWorld
{
    /** @var MemoryTimeLogStore */
    public $logs;
    /** @var MemoryBreakStore */
    public $breaks;
    /** @var MemoryAutoRuleStore */
    public $rules;
    /** @var MemoryScheduleDayStore */
    public $days;
    /** @var array<int,object> active employees by id */
    public $people = [];
    /** @var array<int,object> every employee by id (archived ones too) */
    public $everyone = [];
    /** @var array<string,bool> days that are company holidays */
    public $holidays = [];
    /** @var array<int,bool> employees whose attendance is off */
    public $attendanceOff = [];
    /** @var bool breaks are switched on */
    public $breaksOn = true;
    /** @var array<int,bool> the employees the current user may manage (empty = all) */
    public $scope = [];
    /** @var list<string> everything the lock, the site and the audit were asked, in order */
    public $log = [];
    /** @var list<array{0:string,1:string,2:int,3:string}> */
    public $audits = [];
    /** @var bool the lock cannot be taken */
    public $lockBusy = false;
    /** @var string */
    public $now = '2026-10-30 10:00:00';

    public function __construct()
    {
        $this->logs = new MemoryTimeLogStore();
        $this->breaks = new MemoryBreakStore();
        $this->rules = new MemoryAutoRuleStore();
        $this->days = new MemoryScheduleDayStore();
    }

    public function employee(int $id, string $name = 'Emp', int $user = 0, bool $active = true): void
    {
        $e = (object) ['id' => $id, 'name' => $name, 'wp_user_id' => $user ? (string) $user : '0'];
        $this->everyone[$id] = $e;
        if ($active) $this->people[$id] = $e;
    }

    public function lock(): EmployeeLock
    {
        $w = $this;
        return new class($w) implements EmployeeLock {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function lock(int $employeeId, int $waitSeconds = 5): bool { $this->w->log[] = "lock $employeeId $waitSeconds"; return !$this->w->lockBusy; }
            public function unlock(int $employeeId): void { $this->w->log[] = "unlock $employeeId"; }
        };
    }

    public function peoplePort(): People
    {
        $w = $this;
        return new class($w) implements People {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function activeBrief(int $id): ?object { return $this->w->people[$id] ?? null; }
            public function activeEmployee(int $id): ?object { return $this->w->people[$id] ?? null; }
        };
    }

    public function auditPort(): AuditLog
    {
        $w = $this;
        return new class($w) implements AuditLog {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function record(string $a, string $e, int $id, string $d): void { $this->w->audits[] = [$a, $e, $id, $d]; }
        };
    }

    public function clock(): Clock
    {
        $w = $this;
        return new class($w) implements Clock {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function now(): string { return $this->w->now; }
        };
    }

    public function dayService(): ScheduleDayService
    {
        return new ScheduleDayService($this->days, $this->peoplePort(), new class implements ScheduleNotifier {
            public function dayChanged(int $u, string $d, string $s, int $id): void {}
            public function weekFinal(int $u, array $days, string $link): void {}
        }, $this->auditPort(), $this->clock());
    }

    public function site(): AttendanceSite
    {
        $w = $this;
        return new class($w) implements AttendanceSite {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function scheduleBreakEvents(int $breakId): void {}
            public function breakEnded(object $employee, int $breakId, int $minutes): void {}
            public function breakDurationMinutes(): int { return 15; }
            public function breakTimeEnded(int $userId, int $breakId, string $message): void {}
            public function extendedBreak(int $userId, int $breakId, string $message): void {}
            public function attendanceEnabled(int $employeeId): bool { $this->w->log[] = "attendance? $employeeId"; return empty($this->w->attendanceOff[$employeeId]); }
            public function employee(int $employeeId): ?object { return $this->w->everyone[$employeeId] ?? null; }
            public function minutesSince(string $startAtLocal): int { return 0; }
            public function managerUserIds(): array { return []; }
            public function generalLeave(string $day): bool { return !empty($this->w->holidays[$day]); }
            public function requiresSignIn(string $status): bool { return in_array($status, ['Office', 'WFH'], true); }
            public function breakEnabled(): bool { return $this->w->breaksOn; }
            public function clearBreakEvents(int $breakId): void { $this->w->log[] = "clearBreakEvents $breakId"; }
            public function ensureBreakTable(): void { $this->w->log[] = 'ensureBreakTable'; }
        };
    }

    public function manualPort(): ManualRecordSite
    {
        $w = $this;
        return new class($w) implements ManualRecordSite {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function mayManage(int $employeeId): bool { $this->w->log[] = "mayManage $employeeId"; return !$this->w->scope || !empty($this->w->scope[$employeeId]); }
            public function workingHours(int $employeeId, string $date): array { return ['start' => '09:00', 'end' => '17:00']; }
            public function workSite(int $employeeId): array { return [30.0444, 31.2357, 200.0]; }
        };
    }
}
