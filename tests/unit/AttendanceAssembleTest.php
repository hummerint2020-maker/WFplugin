<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\AttendanceCommand;
use WorkforceOne\Attendance\AttendanceContext;
use WorkforceOne\Attendance\AttendanceResult;
use WorkforceOne\Attendance\AttendanceService;
use WorkforceOne\Attendance\BreakRules;
use WorkforceOne\Attendance\EmployeeLock;
use WorkforceOne\Attendance\Idempotency;
use WorkforceOne\Tests\Fakes\MemoryBreakStore;
use WorkforceOne\Tests\Fakes\MemoryTimeLogStore;

require_once __DIR__ . '/Fakes/MemoryAttendanceStores.php';

/**
 * AttendanceContext::assemble (3.31.137): the Sign In / Out and break writes go through the Stores, the lock and the idempotency
 * port, in the order the old closures ran them. The decisions themselves are AttendanceServiceTest's.
 */
final class AttendanceAssembleTest extends TestCase
{
    /** @var MemoryTimeLogStore */
    private $logs;
    /** @var MemoryBreakStore */
    private $breaks;
    /** @var object */
    private $lock;
    /** @var object */
    private $idem;
    /** @var list<string> */
    private $audit = [];
    /** @var list<string> */
    private $notices = [];

    protected function setUp(): void
    {
        $this->logs = new MemoryTimeLogStore();
        $this->breaks = new MemoryBreakStore();
        $this->audit = [];
        $this->notices = [];
        $this->lock = new class implements EmployeeLock {
            /** @var list<string> */
            public $calls = [];
            /** @var bool */
            public $busy = false;
            public function lock(int $employeeId, int $waitSeconds = 5): bool { $this->calls[] = "lock $employeeId $waitSeconds"; return !$this->busy; }
            public function unlock(int $employeeId): void { $this->calls[] = "unlock $employeeId"; }
        };
        $this->idem = new class implements Idempotency {
            /** @var array<string,array{request:string,response:?array}> */
            public $rows = [];
            /** @var list<string> */
            public $calls = [];
            public function find(int $userId, string $scope, string $keyHash): ?array { $this->calls[] = 'find'; return $this->rows["$userId|$scope|$keyHash"] ?? null; }
            public function claim(int $userId, string $scope, string $keyHash, string $requestHash): bool { $this->calls[] = 'claim'; $k = "$userId|$scope|$keyHash"; if (isset($this->rows[$k])) return false; $this->rows[$k] = ['request' => $requestHash, 'response' => null]; return true; }
            public function save(int $userId, string $scope, string $keyHash, array $response): void { $this->calls[] = 'save'; $this->rows["$userId|$scope|$keyHash"]['response'] = $response; }
            public function release(int $userId, string $scope, string $keyHash): void { $this->calls[] = 'release'; unset($this->rows["$userId|$scope|$keyHash"]); }
        };
    }

    private function service(): AttendanceService
    {
        $logs = $this->logs;
        $day = '2026-10-30';
        $site = [
            'employee' => function () { return (object) ['id' => 7, 'name' => 'Emp', 'wp_user_id' => 3]; },
            'attendanceEnabled' => function () { return true; },
            'schedule' => function () { return (object) ['status' => 'WFH']; },
            'events' => function () use ($logs, $day) { return array_reduce($logs->liveDay(7, $day), function ($c, $r) { $c[$r->event_type] = $r; return $c; }, []); },
            'attendanceDay' => function () use ($day) { return $day; },
            'generalLeave' => function () { return false; },
            'requiresSignIn' => function () { return true; },
            'requiresLocation' => function () { return false; },
            'faceRequired' => function () { return false; },
            'windowOpen' => function () { return true; },
            'windowBounds' => function () { return ['start' => 0, 'cutoff' => 0]; },
            'classify' => function () { return 'On Time'; },
            'assignedLocation' => function () { return null; },
            'defaultSite' => function () { return [null, null, 0.0]; },
            'lastDeviceLocation' => function () { return null; },
            'localTime' => function () { return 1500; },
            'localMysql' => function () { return '2026-10-30 08:00:00'; },
            'unixTime' => function () { return 1790000000; },
            'evaluateAchievements' => function () {},
            'audit' => function ($action, $entity, $id) { $this->audit[] = "$action $entity $id"; },
            'currentUserId' => function () { return 3; },
            'clientIp' => function () { return '10.0.0.1'; },
            'breakEnabled' => function () { return true; },
            'openBreak' => function () { return $this->breaks->openOn(7, '2026-10-30'); },
            'breakRemaining' => function () { return 2 - count($this->breaks->sessionsOn(7, '2026-10-30')); },
            'scheduleBreakEvents' => function ($id) { $this->notices[] = "schedule $id"; },
            'breakEnded' => function ($emp, $id, $minutes) { $this->notices[] = "ended $id $minutes"; },
        ];
        return new AttendanceService(AttendanceContext::assemble($site, $this->logs, $this->breaks, $this->lock, $this->idem));
    }

    public function testSignInIsInsertedThroughTheStoreUnderTheLockAndAudited(): void
    {
        $r = $this->service()->signIn(new AttendanceCommand('sign_in'));
        $this->assertTrue($r->ok);
        $this->assertSame(100, $r->details['event_id']);
        $this->assertSame(['lock 7 5', 'unlock 7'], $this->lock->calls);
        $this->assertSame(['insert sign_in', 'firstEventId sign_in,late_sign_in'], array_slice(array_values(array_filter($this->logs->calls, function ($c) { return strpos($c, 'liveDay') !== 0; })), 0, 2));
        $this->assertSame(['time_sign_in time_log 100'], $this->audit);
    }

    public function testALockThatIsBusyDoesNotRefuseAndIsNotReleased(): void
    {
        $this->lock->busy = true;
        $r = $this->service()->signIn(new AttendanceCommand('sign_in'));
        $this->assertTrue($r->ok);
        $this->assertSame(['lock 7 5'], $this->lock->calls);
    }

    public function testAFailedInsertIsAuditedAndRefused(): void
    {
        $this->logs->failInsert = true;
        $r = $this->service()->signIn(new AttendanceCommand('sign_in'));
        $this->assertSame(AttendanceResult::SAVE_FAILED, $r->code);
        $this->assertSame(['time_sign_in_failed time_log 0'], $this->audit);
    }

    public function testADuplicateThatSlippedInRemovesItself(): void
    {
        $this->logs->add(5, 7, '2026-10-30', 'sign_in', '2026-10-30 07:59:59');
        $svc = $this->service();
        // the first request's check passed before the other's row existed: simulate it by hiding that row from the events but not from the id check
        $hidden = array_pop($this->logs->rows);
        $c = new AttendanceCommand('sign_in');
        $this->logs->rows = [];
        $r = $svc->record($c);
        $this->assertTrue($r->ok);
        $this->logs->rows[] = $hidden;
        $this->assertSame(100, $r->details['event_id']);
    }

    public function testTheIdempotencyKeyIsClaimedSavedAndReplayed(): void
    {
        $c = new AttendanceCommand('sign_in');
        $c->idempotencyKey = 'k';
        $first = $this->service()->record($c);
        $again = $this->service()->record($c);
        $this->assertTrue($first->ok);
        $this->assertSame($first->details['event_id'], $again->details['event_id']);
        $this->assertSame(['find', 'claim', 'save', 'find'], $this->idem->calls);
        $this->assertCount(1, array_filter($this->logs->rows, function ($r) { return $r->event_type === 'sign_in'; }));
    }

    public function testAFailedSaveGivesTheKeyBack(): void
    {
        $this->logs->failInsert = true;
        $c = new AttendanceCommand('sign_in');
        $c->idempotencyKey = 'k';
        $this->service()->record($c);
        $this->assertSame(['find', 'claim', 'release'], $this->idem->calls);
    }

    public function testBreakStartAndResumeGoThroughTheBreakStore(): void
    {
        $svc = $this->service();
        $svc->record(new AttendanceCommand('sign_in'));
        $start = $svc->startBreak();
        $this->assertTrue($start->ok);
        $this->assertSame(['schedule ' . $start->details['break_id']], $this->notices);
        $this->assertSame(BreakRules::ALREADY_ON_BREAK, $svc->startBreak()->code);
        $resume = $svc->resumeBreak();
        $this->assertTrue($resume->ok);
        $this->assertSame(BreakRules::NO_OPEN_BREAK, $svc->resumeBreak()->code);
        $this->assertContains('close ' . $start->details['break_id'], $this->breaks->calls);
    }

    public function testAResumeThatLostTheRaceChangesNothing(): void
    {
        $svc = $this->service();
        $svc->record(new AttendanceCommand('sign_in'));
        $svc->startBreak();
        $this->breaks->closeLost = true;
        $this->assertSame(BreakRules::NO_OPEN_BREAK, $svc->resumeBreak()->code);
        $this->assertSame([], array_filter($this->notices, function ($n) { return strpos($n, 'ended') === 0; }));
    }
}
