<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\ManagerMinimum as M;

final class ManagerMinimumTest extends TestCase
{
    public function testMinimumForAWeekday(): void
    {
        $cfg = ['mgr_min' => 2, 'mgr_days' => [4 => 1, 1 => 0]];
        $this->assertSame(1, M::minimumFor($cfg, '2026-10-15'));   // Thursday
        $this->assertSame(0, M::minimumFor($cfg, '2026-10-12'));   // Monday: none
        $this->assertSame(2, M::minimumFor($cfg, '2026-10-13'));
    }

    public function testADayCountsOnlyTheOffice(): void
    {
        $d = M::day(2, [5 => ['kind' => 'wfh', 'status' => 'WFH'], 3 => ['kind' => 'office', 'status' => 'Office'], 9 => ['kind' => 'other', 'status' => 'Vacation'],
            7 => ['kind' => 'wfh', 'status' => 'WFH'], 8 => ['kind' => 'other', 'status' => '']], [5 => 'Zeina', 3 => 'Omar', 9 => 'Adel', 7 => 'Badr', 8 => 'Ali']);
        $this->assertSame([2, 1, 1], [$d['min'], $d['office'], $d['short']]);
        $this->assertSame([3, 7, 5, 9, 8], array_column($d['rows'], 'id'), 'in the office, then working from home, then the others; by name');
        $this->assertSame([7, 5], $d['can'], 'only those working from home could come in');
        $this->assertSame(0, M::day(1, [1 => ['kind' => 'office', 'status' => 'Office'], 2 => ['kind' => 'office', 'status' => 'Office']], [])['short'], 'never below 0');
        $this->assertSame(3, M::day(3, [], [])['short'], 'a minimum above the number of managers is always short');
    }

    public function testWorstDrop(): void
    {
        $b = ['a' => ['office' => 2, 'short' => 0], 'b' => ['office' => 1, 'short' => 1], 'c' => null, 'd' => ['office' => 0, 'short' => 2]];
        $a = ['a' => ['office' => 1, 'short' => 1], 'b' => ['office' => 0, 'short' => 2], 'c' => null, 'd' => ['office' => 0, 'short' => 2]];
        $this->assertSame('b', M::worstDrop($b, $a, ['a', 'b', 'c', 'd']), 'the largest shortfall among the days the manager leaves the office');
        $this->assertNull(M::worstDrop(['x' => ['office' => 0, 'short' => 2]], ['x' => ['office' => 0, 'short' => 2]], ['x']), 'not in the office that day anyway');
        $this->assertNull(M::worstDrop(['x' => ['office' => 3, 'short' => 0]], ['x' => ['office' => 2, 'short' => 0]], ['x']), 'still at the minimum');
    }

    public function testAManagerOfTwoTeamsOnce(): void
    {
        $this->assertSame([4 => ['Accounts', 'IT'], 6 => ['Sales']], M::managers([4 => ['IT', 'Accounts', 'IT'], 5 => [], 6 => ['Sales', ''], 7 => null]));
    }
}
