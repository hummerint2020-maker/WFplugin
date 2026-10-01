<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\Balance;
use WorkforceOne\Leave\CancellationRules as C;
use WorkforceOne\Leave\RequestRules as R;
use WorkforceOne\Leave\WorkingDays;

final class LeaveRulesTest extends TestCase
{
    private const SUN_THU = [0, 1, 2, 3, 4];

    public function testWorkingDays(): void
    {
        // 2026-10-02 is a Friday.
        $this->assertSame(['2026-10-04', '2026-10-05'], WorkingDays::dates('2026-10-02', '2026-10-05', self::SUN_THU));
        $this->assertSame(0, WorkingDays::count('2026-10-02', '2026-10-03', self::SUN_THU));
        $this->assertSame(7, WorkingDays::count('2026-10-01', '2026-10-07', [0, 1, 2, 3, 4, 5, 6]));
        $this->assertSame(1, WorkingDays::count('2026-10-05', '2026-10-05', self::SUN_THU));
        $this->assertSame([], WorkingDays::dates('2026-10-05', '2026-10-04', self::SUN_THU));
        $this->assertSame([], WorkingDays::dates('nope', '2026-10-04', self::SUN_THU));
        // Across a month and DST-free year boundary.
        $this->assertSame(3, WorkingDays::count('2026-12-30', '2027-01-01', [0, 1, 2, 3, 4, 5, 6]));
    }

    public function testBalance(): void
    {
        $this->assertSame(16.0, Balance::remaining(21, 3, 2));
        $this->assertSame(-1.0, Balance::remaining(1, 1, 1));
        $this->assertSame(0.0, Balance::available(1, 1, 1));
    }

    private function f(array $over = []): array
    {
        return array_merge(['type_active' => true, 'start' => '2026-10-05', 'end' => '2026-10-07', 'today' => '2026-10-01',
            'working_days' => 3, 'overlaps' => false, 'deducts' => true, 'has_balance' => true, 'remaining' => 21.0], $over);
    }

    public function testRequestRules(): void
    {
        $this->assertNull(R::check($this->f()));
        $this->assertSame(R::DATE, R::check($this->f(['type_active' => false])));
        $this->assertSame(R::DATE, R::check($this->f(['start' => '2026-10-01'])), 'today is not in the future');
        $this->assertSame(R::DATE, R::check($this->f(['start' => '2026-10-08'])), 'end before start');
        $this->assertSame(R::DATE, R::check($this->f(['end' => '10/07/2026'])));
        $this->assertSame(R::NO_WORKING_DAYS, R::check($this->f(['working_days' => 0])));
        $this->assertSame(R::OVERLAP, R::check($this->f(['overlaps' => true, 'remaining' => 0.0])), 'overlap is reported before balance');
        $this->assertSame(R::BALANCE, R::check($this->f(['remaining' => 2.0])));
        $this->assertNull(R::check($this->f(['remaining' => 3.0])), 'exactly enough');
        $this->assertNull(R::check($this->f(['remaining' => 0.0, 'deducts' => false])), 'non-deducting types ignore the balance');
        $this->assertSame(R::BALANCE, R::check($this->f(['has_balance' => false, 'deducts' => false])));
    }

    public function testCancellationRules(): void
    {
        $today = '2026-10-01';
        $this->assertNull(C::check('Approved', null, '2026-10-02', $today));
        $this->assertNull(C::check('Approved', 'Not Requested', '2026-10-02', $today));
        $this->assertSame(C::NOT_APPROVED, C::check('Pending', null, '2026-10-02', $today));
        $this->assertSame(C::NOT_APPROVED, C::check('Cancelled', 'Approved', '2026-10-02', $today));
        $this->assertSame(C::ALREADY_PENDING, C::check('Approved', 'Pending', '2026-10-02', $today));
        $this->assertSame(C::ALREADY_REJECTED, C::check('Approved', 'rejected', '2026-10-02', $today));
        $this->assertSame(C::ALREADY_CANCELLED, C::check('Approved', 'Approved', '2026-10-02', $today));
        $this->assertSame(C::STARTED, C::check('Approved', null, '2026-10-01', $today), 'starting today');
        $this->assertSame(C::STARTED, C::check('Approved', null, null, $today));
    }
}
