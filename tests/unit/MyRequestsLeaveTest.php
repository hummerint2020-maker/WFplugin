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
