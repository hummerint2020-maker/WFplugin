<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\ReturnRules as R;

final class LeaveReturnRulesTest extends TestCase
{
    public function testCheckBackTheOwnersCase(): void
    {
        // 3 days 10–12, back on the 11th approved: the leave is the 10th only, cancellation Approved. Asking again for that day.
        $this->assertNull(R::checkBack('Approved', 'Approved', '2026-12-10', '2026-12-10', '2026-12-01', '2026-12-10', 1));
        // The first day of a leave today: back today = all of it.
        $this->assertNull(R::checkBack('Approved', null, '2026-12-10', '2026-12-12', '2026-12-10', '2026-12-10', 3));
        // A single remaining day that is today.
        $this->assertNull(R::checkBack('Approved', 'Approved', '2026-12-10', '2026-12-10', '2026-12-10', '2026-12-10', 1));
    }

    public function testCheckBackStates(): void
    {
        $ok = function (?string $c) { return R::checkBack('Approved', $c, '2026-12-10', '2026-12-12', '2026-12-01', '2026-12-11', 2); };
        foreach ([null, '', 'Not Requested', 'Approved', 'Rejected', 'Withdrawn', 'Ended'] as $c) $this->assertNull($ok($c), (string) $c);
        $this->assertSame(R::ALREADY_PENDING, $ok('Pending'));
        $this->assertSame(R::NOT_APPROVED, $ok('Something'));
        foreach (['Pending', 'Rejected', 'Cancelled'] as $s) $this->assertSame(R::NOT_APPROVED, R::checkBack($s, null, '2026-12-10', '2026-12-12', '2026-12-01', '2026-12-11', 2));
    }

    public function testCheckBackDays(): void
    {
        $c = function (string $back, string $today, int $n = 2) { return R::checkBack('Approved', null, '2026-12-10', '2026-12-12', $today, $back, $n); };
        $this->assertSame(R::DAY, $c('2026-12-09', '2026-12-01'));   // before the first day
        $this->assertSame(R::DAY, $c('2026-12-13', '2026-12-01'));   // after the last
        $this->assertSame(R::DAY, $c('2026-12-10', '2026-12-11'));   // yesterday
        $this->assertNull($c('2026-12-11', '2026-12-11'));           // today
        $this->assertNull($c('2026-12-12', '2026-12-11'));
        $this->assertSame(R::DAY, $c('nope', '2026-12-01'));
        $this->assertSame(R::NO_DAYS, $c('2026-12-12', '2026-12-01', 0));
    }

    public function testBackRange(): void
    {
        $this->assertSame(['min' => '2026-12-10', 'max' => '2026-12-12', 'whole' => true], R::backRange('2026-12-10', '2026-12-12', '2026-12-01'));
        $this->assertSame(['min' => '2026-12-10', 'max' => '2026-12-12', 'whole' => true], R::backRange('2026-12-10', '2026-12-12', '2026-12-10'));
        $this->assertSame(['min' => '2026-12-11', 'max' => '2026-12-12', 'whole' => false], R::backRange('2026-12-10', '2026-12-12', '2026-12-11'));
        $this->assertNull(R::backRange('2026-12-10', '2026-12-12', '2026-12-13'));
        $this->assertNull(R::backRange('', '2026-12-12', '2026-12-01'));
    }

    public function testEffectiveFrom(): void
    {
        $this->assertSame('2026-11-28', R::effectiveFrom('2026-11-28', '2026-12-03', []));
        $this->assertSame('2026-12-01', R::effectiveFrom('2026-11-28', '2026-12-03', ['2026-11']));
        $this->assertSame('2027-01-01', R::effectiveFrom('2026-11-28', '2027-01-03', ['2026-11', '2026-12']));
        $this->assertNull(R::effectiveFrom('2026-11-28', '2026-11-30', ['2026-11']));
        $this->assertNull(R::effectiveFrom('2026-11-28', '2026-12-03', ['2026-11', '2026-12']));
        $this->assertSame('2026-11-30', R::effectiveFrom('2026-11-30', '2026-11-30', ['2026-10']));
        $this->assertNull(R::effectiveFrom('bad', '2026-11-30', []));
    }

    public function testDaysBack(): void
    {
        $this->assertSame(3.0, R::daysBack(3, 0, 3.0, true, 0));    // the whole leave
        $this->assertSame(2.0, R::daysBack(2, 1, 3.0, true, 0));    // the owner's first change
        $this->assertSame(1.0, R::daysBack(1, 0, 1.0, true, 0));    // the owner's last day
        $this->assertSame(2.0, R::daysBack(5, 1, 2.0, true, 0));    // never more than it charges
        $this->assertSame(0.0, R::daysBack(0, 2, 3.0, true, 0));    // nothing removed (another return won the race)
        $this->assertSame(2.0, R::daysBack(0, 0, 3.0, false, 2));   // a leave without days recorded: counted as before
        $this->assertSame(3.0, R::daysBack(0, 0, 3.0, false, 9));
    }

    public function testCarryTopUp(): void
    {
        $all = ['carry' => 'all', 'carry_max' => 0.0];
        $max = ['carry' => 'max', 'carry_max' => 5.0];
        $none = ['carry' => 'none', 'carry_max' => 0.0];
        // 21 days, 18 used, 3 carried; 2 back -> 5 left, 2 more carried.
        $this->assertSame(2.0, R::carryTopUp($all, 21, 16, 0, 3, 2));
        $this->assertSame(0.0, R::carryTopUp($none, 21, 16, 0, 0, 2));
        // Max 5: 4 carried, 3 back -> 7 left -> 5, 1 more.
        $this->assertSame(1.0, R::carryTopUp($max, 21, 14, 0, 4, 3));
        $this->assertSame(0.0, R::carryTopUp($max, 21, 14, 0, 5, 3));   // already at the cap
        // Never more than the days returned, even when other figures changed.
        $this->assertSame(1.0, R::carryTopUp($all, 21, 10, 0, 3, 1));
        $this->assertSame(0.0, R::carryTopUp($all, 21, 10, 0, 3, 0));
    }

    public function testHrCheck(): void
    {
        $f = ['reason' => 'Came in, forgot to ask', 'self' => false, 'status' => 'Approved', 'start' => '2026-12-10', 'end' => '2026-12-12', 'back' => '2026-12-11', 'effective' => '2026-12-11'];
        $this->assertNull(R::hrCheck($f));
        $this->assertSame(R::REASON, R::hrCheck(['reason' => '  '] + $f));
        $this->assertSame(R::SELF, R::hrCheck(['self' => true] + $f));
        $this->assertSame(R::NOT_APPROVED, R::hrCheck(['status' => 'Cancelled'] + $f));
        $this->assertSame(R::DAY, R::hrCheck(['back' => '2026-12-13'] + $f));
        $this->assertSame(R::DAY, R::hrCheck(['back' => '2026-12-09'] + $f));
        $this->assertSame(R::CLOSED_PAYROLL, R::hrCheck(['effective' => null] + $f));
        // A past day is fine for HR (an open month).
        $this->assertNull(R::hrCheck(['back' => '2026-12-10', 'effective' => '2026-12-10'] + $f));
    }

    public function testMessages(): void
    {
        foreach ([R::NOT_APPROVED, R::ALREADY_PENDING, R::DAY, R::NO_DAYS, R::CLOSED_PAYROLL, R::REASON, R::SELF, R::ENDED, 'x'] as $c) $this->assertNotSame('', R::message($c));
    }
}
