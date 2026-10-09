<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\AttendanceCommand as Cmd;
use WorkforceOne\Attendance\AttendanceContext;
use WorkforceOne\Attendance\AttendanceResult as R;
use WorkforceOne\Attendance\AttendanceService;
use WorkforceOne\Attendance\BreakRules as B;
use WorkforceOne\Attendance\LocationAssessment as L;
use WorkforceOne\Attendance\SignInRules as S;

/**
 * AttendanceService against a fake site (an in-memory time log, break table and idempotency store).
 * The Web behaviour itself is pinned end to end by tests/e2e_attendance_parity.py; these tests cover
 * the decisions and what HTTP cannot reproduce on demand (a request slipping in between the check
 * and the insert, the lock, idempotency).
 */
final class AttendanceServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private $w;

    protected function setUp(): void
    {
        $this->w = [
            'employee' => (object) ['id' => 7, 'name' => 'Emp', 'wp_user_id' => 3], 'enabled' => true, 'status' => 'Office',
            'face' => false, 'window' => true, 'bounds' => ['start' => 1000, 'cutoff' => 2000], 'local' => 1500, 'leave' => false,
            'location' => (object) ['latitude' => 30.0444, 'longitude' => 31.2357, 'radius' => 200, 'enforcement' => 1],
            'logs' => [], 'breaks' => [], 'idem' => [], 'audit' => [], 'locks' => [], 'achievements' => 0, 'events' => [], 'notified' => [],
            'breaksOn' => true, 'remaining' => 2, 'insertFails' => false, 'beforeInsert' => null, 'nextId' => 100,
        ];
    }

    private function service(): AttendanceService
    {
        $w = &$this->w;
        $day = '2026-10-05';
        $events = function () use (&$w) {
            $out = [];
            foreach ($w['logs'] as $l) $out[$l['event_type']] = (object) $l;
            return $out;
        };
        $c = [
            'employee' => function () use (&$w) { return $w['employee']; },
            'attendanceEnabled' => function () use (&$w) { return $w['enabled']; },
            'schedule' => function () use (&$w) { return $w['status'] === null ? null : (object) ['status' => $w['status']]; },
            'events' => $events,
            'attendanceDay' => function () use ($day) { return $day; },
            'generalLeave' => function () use (&$w) { return $w['leave']; },
            'requiresSignIn' => function ($s) { return in_array($s, ['Office', 'WFH'], true); },
            'requiresLocation' => function ($s) { return $s === 'Office'; },
            'faceRequired' => function () use (&$w) { return $w['face']; },
            'windowOpen' => function () use (&$w) { return $w['window']; },
            'windowBounds' => function () use (&$w) { return $w['bounds']; },
            'classify' => function () { return 'On Time'; },
            'assignedLocation' => function () use (&$w) { return $w['location']; },
            'defaultSite' => function () { return [null, null, 0.0]; },
            'lastDeviceLocation' => function () { return null; },
            'localTime' => function () use (&$w) { return $w['local']; },
            'localMysql' => function () { return '2026-10-05 08:00:00'; },
            'unixTime' => function () { return 1790000000; },
            'insertTimeLog' => function ($row) use (&$w) {
                if ($w['insertFails']) return 0;
                if ($w['beforeInsert']) { $f = $w['beforeInsert']; $w['beforeInsert'] = null; $f(); }
                $row['id'] = ++$w['nextId'];
                $w['logs'][] = $row;
                return $row['id'];
            },
            'insertError' => function () { return 'disk full'; },
            'lastInsertId' => function () use (&$w) { return $w['nextId']; },
            'firstEventId' => function ($eid, $d, $types) use (&$w) {
                $ids = [];
                foreach ($w['logs'] as $l) if (in_array($l['event_type'], $types, true)) $ids[] = $l['id'];
                return $ids ? min($ids) : 0;
            },
            'deleteTimeLog' => function ($id) use (&$w) { $w['logs'] = array_values(array_filter($w['logs'], function ($l) use ($id) { return $l['id'] !== $id; })); },
            'evaluateAchievements' => function () use (&$w) { $w['achievements']++; },
            'audit' => function ($a, $e, $id, $d) use (&$w) { $w['audit'][] = [$a, $e, $id, $d]; },
            'currentUserId' => function () { return 3; },
            'clientIp' => function () { return '10.0.0.1'; },
            'lock' => function ($eid) use (&$w) { $w['locks'][] = 'lock'; return true; },
            'unlock' => function ($eid) use (&$w) { $w['locks'][] = 'unlock'; },
            'idemFind' => function ($u, $s, $k) use (&$w) { return $w['idem']["$u|$s|$k"] ?? null; },
            'idemClaim' => function ($u, $s, $k, $req) use (&$w) {
                if (isset($w['idem']["$u|$s|$k"])) return false;
                $w['idem']["$u|$s|$k"] = ['request' => $req, 'response' => null];
                return true;
            },
            'idemSave' => function ($u, $s, $k, $resp) use (&$w) { $w['idem']["$u|$s|$k"]['response'] = $resp; },
            'idemRelease' => function ($u, $s, $k) use (&$w) { unset($w['idem']["$u|$s|$k"]); },
            'breakEnabled' => function () use (&$w) { return $w['breaksOn']; },
            'openBreak' => function () use (&$w) {
                foreach ($w['breaks'] as $b) if ($b['status'] === 'Open') return (object) $b;
                return null;
            },
            'breakRemaining' => function () use (&$w) { return $w['remaining']; },
            'insertBreak' => function ($row) use (&$w) {
                if ($w['beforeInsert']) { $f = $w['beforeInsert']; $w['beforeInsert'] = null; $f(); }
                $row['id'] = ++$w['nextId'];
                $w['breaks'][] = $row;
                return $row['id'];
            },
            'firstOpenBreakId' => function () use (&$w) {
                $ids = [];
                foreach ($w['breaks'] as $b) if ($b['status'] === 'Open') $ids[] = $b['id'];
                return $ids ? min($ids) : 0;
            },
            'deleteBreak' => function ($id) use (&$w) { $w['breaks'] = array_values(array_filter($w['breaks'], function ($b) use ($id) { return $b['id'] !== $id; })); },
            'scheduleBreakEvents' => function ($id) use (&$w) { $w['events'][] = $id; },
            'closeBreak' => function ($id, $end, $min) use (&$w) {
                foreach ($w['breaks'] as &$b) {
                    if ($b['id'] === $id && $b['status'] === 'Open') { $b['status'] = 'Completed'; $b['minutes'] = $min; return true; }
                }
                return false;
            },
            'breakEnded' => function ($emp, $id, $min) use (&$w) { $w['notified'][] = [$id, $min]; },
        ];
        // Branches (3.31.72): only when a test sets them; without them the assigned location decides, as before.
        if (array_key_exists('branches', $w)) $c['branches'] = function () use (&$w) { return $w['branches']; };
        return new AttendanceService(new AttendanceContext($c));
    }

    private function cmd(string $type, array $where = ['lat' => 30.0445, 'lng' => 31.2358]): Cmd
    {
        $c = new Cmd($type);
        $c->latitude = $where['lat'] ?? null;
        $c->longitude = $where['lng'] ?? null;
        $c->accuracy = isset($where['lat']) ? 20.0 : null;
        $c->locationTimestampMs = isset($where['lat']) ? 1790000000000 : null;
        return $c;
    }

    public function testSignInSignOutAndTheRuleOrder(): void
    {
        $r = $this->service()->record($this->cmd('sign_out'));
        $this->assertSame(S::NOT_SIGNED_IN, $r->code);
        $r = $this->service()->signIn($this->cmd('sign_in'));
        $this->assertTrue($r->ok);
        $this->assertSame(['event_id' => 101, 'event_type' => 'sign_in', 'event_at' => '2026-10-05 08:00:00', 'work_date' => '2026-10-05', 'classification' => 'On Time'], $r->details);
        $this->assertSame(['time_sign_in', 'time_log', 101, 'Emp / Office / 2026-10-05 08:00:00 / On Time / face_verified=no / source=normal'], $this->w['audit'][0]);
        $this->assertSame(['inside', 'verified', '10.0.0.1', 3], [$this->w['logs'][0]['location_status'], $this->w['logs'][0]['integrity_status'], $this->w['logs'][0]['ip_address'], $this->w['logs'][0]['user_id']]);
        $this->assertSame(S::ALREADY_SIGNED_IN, $this->service()->signIn($this->cmd('sign_in'))->code);
        $this->assertTrue($this->service()->signOut($this->cmd('sign_out'))->ok);
        $this->assertSame(1, $this->w['achievements'], 'Sign Out evaluates achievements');
        $this->assertSame(S::ALREADY_SIGNED_OUT, $this->service()->signOut($this->cmd('sign_out'))->code);
        $this->assertSame(['lock', 'unlock', 'lock', 'unlock', 'lock', 'unlock', 'lock', 'unlock', 'lock', 'unlock'], $this->w['locks'], 'every attempt holds the lock and releases it');
    }

    public function testFailuresBeforeAnyWrite(): void
    {
        $this->w['enabled'] = false;
        $this->assertSame(R::ATTENDANCE_DISABLED, $this->service()->signIn($this->cmd('sign_in'))->code);
        $this->assertSame([], $this->w['locks'], 'attendance off is answered before taking the lock');
        $this->setUp();
        $this->w['employee'] = null;
        $this->assertSame(S::NO_EMPLOYEE, $this->service()->signIn($this->cmd('sign_in'))->code);
        $this->setUp();
        $this->w['face'] = true;
        $this->w['status'] = 'Vacation';
        $this->assertSame(S::NOT_WORKING_DAY, $this->service()->signIn($this->cmd('sign_in'))->code, 'a later rule wins over Face required, as in SignInRules');
        $this->w['status'] = 'Office';
        $this->assertSame(S::FACE_REQUIRED, $this->service()->signIn($this->cmd('sign_in'))->code);
        $ok = $this->cmd('sign_in');
        $ok->faceOk = true;
        $this->assertTrue($this->service()->signIn($ok)->ok);
        $this->setUp();
        $this->w['window'] = false;
        $this->w['local'] = 900;
        $r = $this->service()->signIn($this->cmd('sign_in'));
        $this->assertSame([S::TOO_EARLY, ['start' => 1000, 'cutoff' => 2000]], [$r->code, $r->details['bounds']]);
        $this->w['local'] = 2500;
        $this->assertSame(S::TOO_LATE, $this->service()->signIn($this->cmd('sign_in'))->code);
        $this->assertSame([], $this->w['logs']);
    }

    public function testLocation(): void
    {
        $far = ['lat' => 31.2001, 'lng' => 29.9187];
        $r = $this->service()->signIn($this->cmd('sign_in', $far));
        $this->assertSame(R::OUTSIDE_LOCATION, $r->code);
        $this->assertGreaterThan(100000, $r->details['distance']);
        $this->assertSame(R::OUTSIDE_LOCATION, $this->service()->signIn($this->cmd('sign_in', []))->code, 'no GPS under enforcement (Web message: distance not available)');
        $this->w['status'] = 'WFH';
        $this->assertTrue($this->service()->signIn($this->cmd('sign_in', $far))->ok, 'WFH does not require the location');
        $this->assertSame('outside', $this->w['logs'][0]['location_status']);
        $this->setUp();
        $this->w['location']->enforcement = 0;
        $this->assertTrue($this->service()->signIn($this->cmd('sign_in', $far))->ok, 'not enforced: recorded only');
        $this->setUp();
        $this->service()->signIn($this->cmd('sign_in'));
        $this->assertTrue($this->service()->signOut($this->cmd('sign_out', $far))->ok, 'Sign Out is never blocked by the geofence');
    }

    public function testQrSignIn(): void
    {
        $qr = $this->cmd('sign_in');
        $qr->qrKiosk = ['id' => 4];
        $qr->qrLocation = ['name' => 'Cairo HQ', 'latitude' => 30.0444, 'longitude' => 31.2357, 'radius' => 200];
        $this->assertTrue($this->service()->signIn($qr)->ok);
        $this->assertStringEndsWith('source=qr_kiosk_4', $this->w['audit'][0][3]);
        $this->setUp();
        $qr->latitude = 31.2001;
        $qr->longitude = 29.9187;
        $r = $this->service()->signIn($qr);
        $this->assertSame([L::QR_OUTSIDE, 'Cairo HQ'], [$r->code, $r->details['location_name']]);
        $qr->latitude = null;
        $qr->longitude = null;
        $this->assertSame(L::QR_LOCATION_REQUIRED, $this->service()->signIn($qr)->code);
        $qr->qrLocation['latitude'] = null;
        $this->assertSame(L::QR_KIOSK_NO_COORDS, $this->service()->signIn($qr)->code);
    }

    public function testAConcurrentDuplicateRemovesItself(): void
    {
        // Another request records the Sign In after this one checked "not signed in yet" (no lock on SQLite).
        $this->w['beforeInsert'] = function () {
            $this->w['logs'][] = ['id' => 50, 'event_type' => 'sign_in'];
        };
        $r = $this->service()->signIn($this->cmd('sign_in'));
        $this->assertSame(S::ALREADY_SIGNED_IN, $r->code);
        $this->assertSame([50], array_column($this->w['logs'], 'id'), 'only the first Sign In stays');
        $this->assertSame([], $this->w['audit'], 'and only it is audited');
        $this->setUp();
        $this->w['logs'] = [['id' => 1, 'event_type' => 'sign_in']];
        $this->w['beforeInsert'] = function () {
            $this->w['logs'][] = ['id' => 60, 'event_type' => 'sign_out'];
        };
        $this->assertSame(S::ALREADY_SIGNED_OUT, $this->service()->signOut($this->cmd('sign_out'))->code);
        $this->assertSame([1, 60], array_column($this->w['logs'], 'id'));
        $this->assertSame(0, $this->w['achievements']);
    }

    public function testSaveFailure(): void
    {
        $this->w['insertFails'] = true;
        $r = $this->service()->signIn($this->cmd('sign_in'));
        $this->assertSame(R::SAVE_FAILED, $r->code);
        $this->assertSame(['time_sign_in_failed', 'time_log', 0, 'Emp / disk full'], $this->w['audit'][0]);
    }

    public function testIdempotency(): void
    {
        $a = $this->cmd('sign_in');
        $a->idempotencyKey = 'k-1';
        $first = $this->service()->signIn($a);
        $again = $this->service()->signIn(clone $a);
        $this->assertTrue($first->ok && $again->ok);
        $this->assertTrue($again->replayed);
        $this->assertSame($first->details, $again->details, 'a retry gets the first result');
        $this->assertCount(1, $this->w['logs'], 'and records nothing');
        $other = clone $a;
        $other->latitude = 30.1;
        $this->assertSame(R::IDEMPOTENCY_CONFLICT, $this->service()->signIn($other)->code, 'same key, different request');
        $b = $this->cmd('sign_in');
        $b->idempotencyKey = 'k-2';
        $this->assertSame(S::ALREADY_SIGNED_IN, $this->service()->signIn($b)->code, 'a new key is a new request');
        $this->assertSame(S::ALREADY_SIGNED_IN, $this->service()->signIn(clone $b)->code, '...and its answer is stored too');
        $this->w['idem']['3|attendance.sign_in|' . hash('sha256', 'k-3')] = ['request' => $b->fingerprint(), 'response' => null];
        $c = clone $b;
        $c->idempotencyKey = 'k-3';
        $this->assertSame(R::IN_PROGRESS, $this->service()->signIn($c)->code, 'a key still being processed');
        $this->setUp();
        $this->w['insertFails'] = true;
        $d = $this->cmd('sign_in');
        $d->idempotencyKey = 'k-4';
        $this->assertSame(R::SAVE_FAILED, $this->service()->signIn($d)->code);
        $this->assertSame([], $this->w['idem'], 'a failed save is not kept, so the retry can succeed');
        $this->w['insertFails'] = false;
        $this->assertTrue($this->service()->signIn(clone $d)->ok);
    }

    public function testTheLockIsReleasedOnAnException(): void
    {
        $this->w['beforeInsert'] = function () { throw new \RuntimeException('db gone'); };
        try {
            $this->service()->signIn($this->cmd('sign_in'));
            $this->fail('expected the exception');
        } catch (\RuntimeException $e) {
            $this->assertSame(['lock', 'unlock'], $this->w['locks']);
        }
    }

    public function testBreaks(): void
    {
        $svc = $this->service();
        $this->assertSame(B::NOT_SIGNED_IN, $svc->startBreak()->code);
        $this->service()->signIn($this->cmd('sign_in'));
        $r = $this->service()->startBreak();
        $this->assertTrue($r->ok);
        $this->assertSame([$r->details['break_id']], $this->w['events'], 'reminder and escalation scheduled');
        $this->assertSame('break_start', end($this->w['audit'])[0]);
        $this->assertSame(B::ALREADY_ON_BREAK, $this->service()->startBreak()->code);
        $this->assertSame(S::ON_BREAK, $this->service()->signOut($this->cmd('sign_out'))->code);
        $r = $this->service()->resumeBreak();
        $this->assertSame([true, 0], [$r->ok, $r->details['minutes']]);
        $this->assertCount(1, $this->w['notified']);
        $this->assertSame(B::NO_OPEN_BREAK, $this->service()->resumeBreak()->code);
        $this->w['remaining'] = 0;
        $this->assertSame(B::NO_BREAKS_LEFT, $this->service()->startBreak()->code);
        $this->w['breaksOn'] = false;
        $this->assertSame(B::BREAKS_DISABLED, $this->service()->startBreak()->code);
        $this->assertSame(B::BREAKS_DISABLED, $this->service()->resumeBreak()->code);
    }

    public function testAConcurrentSecondBreakRemovesItself(): void
    {
        $this->w['logs'] = [['id' => 1, 'event_type' => 'sign_in']];
        $this->w['beforeInsert'] = function () {
            $this->w['breaks'][] = ['id' => 40, 'status' => 'Open', 'start_at' => '2026-10-05 08:00:00'];
        };
        $this->assertSame(B::ALREADY_ON_BREAK, $this->service()->startBreak()->code);
        $this->assertSame([40], array_column($this->w['breaks'], 'id'));
        $this->assertSame([], $this->w['events'], 'no cron events for the removed one');
    }

    public function testAConcurrentSecondResumeDoesNothing(): void
    {
        $this->w['breaks'] = [['id' => 40, 'status' => 'Open', 'start_at' => '2026-10-05 07:50:00']];
        // This request finds the break open, but another one ends it just before this one does.
        $closeFirst = function () { $this->w['breaks'][0]['status'] = 'Completed'; };
        $svc = $this->service();
        $ref = new \ReflectionProperty($svc, 'ctx');
        $ref->setAccessible(true);
        $ctx = $ref->getValue($svc);
        $f = new \ReflectionProperty($ctx, 'f');
        $f->setAccessible(true);
        $callables = $f->getValue($ctx);
        $close = $callables['closeBreak'];
        $callables['closeBreak'] = function ($id, $end, $min) use ($close, $closeFirst) { $closeFirst(); return $close($id, $end, $min); };
        $f->setValue($ctx, $callables);
        $this->assertSame(B::NO_OPEN_BREAK, $svc->resumeBreak()->code);
        $this->assertSame([], $this->w['notified'], 'no second notification');
        $this->assertSame([], $this->w['audit'], 'and no second audit row');
    }

    /** Branches (3.31.72): the branch they are at is recorded; another of theirs is flagged "By the schedule". */
    public function testBranches(): void
    {
        $planned = (object) ['id' => 1, 'latitude' => 30.03, 'longitude' => 31.47, 'radius' => 300, 'enforcement' => 1];
        $other = (object) ['id' => 2, 'latitude' => 30.06, 'longitude' => 31.33, 'radius' => 300, 'enforcement' => 1];
        $this->w['branches'] = ['expected' => $planned, 'allowed' => [1 => $planned, 2 => $other], 'flag_other' => true, 'kiosk_any' => true];
        $r = $this->service()->signIn($this->cmd('sign_in', ['lat' => 30.0601, 'lng' => 31.3301]));
        $this->assertTrue($r->ok);
        $this->assertSame([2, 'other_branch', 'inside'], [$this->w['logs'][0]['location_id'], $this->w['logs'][0]['branch_flag'], $this->w['logs'][0]['location_status']]);
        $this->assertSame([2, 'other_branch'], [$r->details['branch_id'], $r->details['branch_flag']]);

        $this->w['logs'] = [];
        $r = $this->service()->signIn($this->cmd('sign_in', ['lat' => 30.0301, 'lng' => 31.4701]));
        $this->assertSame([1, null], [$this->w['logs'][0]['location_id'], $this->w['logs'][0]['branch_flag']], 'the planned branch is not flagged');

        $this->w['logs'] = [];
        $r = $this->service()->signIn($this->cmd('sign_in', ['lat' => 29.0, 'lng' => 31.0]));
        $this->assertSame(R::OUTSIDE_LOCATION, $r->code, 'none of their branches: the planned branch\'s rule decides');

        // A kiosk QR at another of their branches counts as that branch.
        $qr = $this->cmd('sign_in', ['lat' => 30.0601, 'lng' => 31.3301]);
        $qr->qrKiosk = ['id' => 9];
        $qr->qrLocation = ['id' => 2, 'name' => 'Nasr City', 'latitude' => 30.06, 'longitude' => 31.33, 'radius' => 300];
        $this->assertTrue($this->service()->signIn($qr)->ok);
        $this->assertSame([2, 'other_branch'], [$this->w['logs'][0]['location_id'], $this->w['logs'][0]['branch_flag']]);

        // Without branches the service records what it always did (no branch columns).
        unset($this->w['branches']);
        $this->w['logs'] = [];
        $this->service()->signIn($this->cmd('sign_in'));
        $this->assertArrayNotHasKey('location_id', $this->w['logs'][0]);
    }

    public function testTheContextNeedsEveryEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AttendanceContext(['employee' => function () { return null; }]);
    }
}
