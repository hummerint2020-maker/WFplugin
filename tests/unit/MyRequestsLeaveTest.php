<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\MyRequestsLeave as M;

/** Leave in My requests (3.31.158, docs/tasks/leave-in-requests.md). */
final class MyRequestsLeaveTest extends TestCase
{
    private const T = '2026-11-21';

    public function testItemStates(): void
    {
        $s = M::itemState('Approved', 'Not Requested', '2026-12-01', '2026-12-03', self::T);
        $this->assertSame(['approved', false, '', true, false], [$s['state'], $s['cancel_asked'], $s['cancel_decided'], $s['cancellable'], $s['started']], 'approved, not started: may be cancelled');
        $s = M::itemState('Approved', 'Pending', '2026-12-01', '2026-12-03', self::T);
        $this->assertSame(['waiting', true, false], [$s['state'], $s['cancel_asked'], $s['cancellable']], 'a cancellation waiting');
        $s = M::itemState('Approved', 'Rejected', '2026-12-01', '2026-12-03', self::T);
        $this->assertSame(['approved', 'rejected', false], [$s['state'], $s['cancel_decided'], $s['cancellable']], 'refused once: never again');
        $s = M::itemState('Cancelled', 'Approved', '2026-12-01', '2026-12-03', self::T);
        $this->assertSame(['cancelled', 'approved'], [$s['state'], $s['cancel_decided']], 'cancelled at the employee\'s request');
        $this->assertSame('withdrawn', M::itemState('Cancelled', 'Not Requested', '2026-12-01', '2026-12-03', self::T)['state'], 'cancelled before a decision = withdrawn');
        $this->assertSame('waiting', M::itemState('Pending', null, '2026-12-01', '2026-12-03', self::T)['state']);
        $this->assertSame('rejected', M::itemState('Rejected', null, '2026-12-01', '2026-12-03', self::T)['state']);
        $s = M::itemState('Approved', 'Approved', '2026-11-18', '2026-11-20', self::T);
        $this->assertSame(['approved', 'approved', false], [$s['state'], $s['cancel_decided'], $s['cancellable']], 'cancelled from a day on: the leave stands, shortened');
        foreach (['Withdrawn' => 'withdrawn', 'Ended' => 'ended'] as $c => $want) {
            $s = M::itemState('Approved', $c, '2026-12-01', '2026-12-03', self::T);
            $this->assertSame([$want, false], [$s['cancel_decided'], $s['cancellable']], "$c: a leave has one cancellation");
        }
    }

    public function testStartedAndToday(): void
    {
        $s = M::itemState('Approved', null, '2026-11-20', '2026-11-22', self::T);
        $this->assertSame([true, false], [$s['started'], $s['cancellable']], 'in progress');
        $s = M::itemState('Approved', null, self::T, self::T, self::T);
        $this->assertSame([true, false], [$s['started'], $s['cancellable']], 'starting today: no longer');
        $s = M::itemState('Approved', null, '2026-11-01', '2026-11-02', self::T);
        $this->assertSame([false, false], [$s['started'], $s['cancellable']], 'finished: not "started", not cancellable');
        $this->assertTrue(M::itemState('Approved', null, '2026-11-22', '2026-11-22', self::T)['cancellable'], 'tomorrow: still');
    }

    public function testChange(): void
    {
        $r = \WorkforceOne\Leave\CancellationRules::class;
        $this->assertNull($r::checkChange('Approved', null, '2026-11-20', '2026-11-24', self::T, self::T), 'started: back today');
        $this->assertNull($r::checkChange('Approved', null, '2026-11-20', '2026-11-24', self::T, '2026-11-24'), '...or on its last day');
        $this->assertSame($r::DAY, $r::checkChange('Approved', null, '2026-11-20', '2026-11-24', self::T, '2026-11-20'), 'not before or on its first day');
        $this->assertSame($r::DAY, $r::checkChange('Approved', null, '2026-11-18', '2026-11-24', self::T, '2026-11-20'), 'not a past day');
        $this->assertSame($r::DAY, $r::checkChange('Approved', null, '2026-11-20', '2026-11-24', self::T, '2026-11-25'), 'not after its end');
        $this->assertNull($r::checkChange('Approved', null, '2026-12-06', '2026-12-10', self::T, '2026-12-08'), 'not started: shortened');
        $this->assertNull($r::checkChange('Approved', null, '2026-12-06', '2026-12-10', self::T, null), 'not started: all of it');
        $this->assertSame($r::STARTED, $r::checkChange('Approved', null, '2026-11-20', '2026-11-24', self::T, null), 'started: not all of it');
        $this->assertSame($r::ALREADY_PENDING, $r::checkChange('Approved', 'Pending', '2026-11-20', '2026-11-24', self::T, self::T));
        $this->assertSame($r::CLOSED, $r::checkChange('Approved', 'Withdrawn', '2026-11-20', '2026-11-24', self::T, self::T), 'one change per leave');
        $this->assertSame($r::ALREADY_REJECTED, $r::checkChange('Approved', 'Rejected', '2026-11-20', '2026-11-24', self::T, self::T));
        $this->assertSame($r::NOT_APPROVED, $r::checkChange('Pending', null, '2026-11-20', '2026-11-24', self::T, self::T));
        $this->assertSame(self::T, $r::firstBackDay('2026-11-20', '2026-11-24', self::T), 'started: today');
        $this->assertSame('2026-12-07', $r::firstBackDay('2026-12-06', '2026-12-10', self::T), 'not started: the day after its first');
        $this->assertNull($r::firstBackDay(self::T, self::T, self::T), 'a one-day leave of today: none');
        $this->assertNull($r::firstBackDay('2026-11-10', '2026-11-12', self::T), 'ended: none');
    }

    public function testPageTarget(): void
    {
        $this->assertSame('vacation', M::pageTarget(true, true, true, true), 'shown: the page itself');
        $this->assertSame('requests', M::pageTarget(false, true, true, false));
        $this->assertSame('approvals', M::pageTarget(false, true, true, true), 'a decision goes back to My approvals');
        $this->assertSame('requests', M::pageTarget(false, true, false, true), 'a decision without My approvals: My requests');
        $this->assertSame('approvals', M::pageTarget(false, false, true, false), 'no employee record: My approvals');
        $this->assertSame('dashboard', M::pageTarget(false, false, false, false));
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$r, $a]) {
            foreach ([true, false] as $dec) $this->assertNotSame('vacation', M::pageTarget(false, $r, $a, $dec), 'never back to the hidden page');
        }
    }
}
