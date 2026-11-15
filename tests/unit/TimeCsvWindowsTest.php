<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\TimeCsvWindows;

final class TimeCsvWindowsTest extends TestCase
{
    public function testNothingOrEverythingFitsIsOneWindowWithTheSameBounds(): void
    {
        $this->assertSame([['2026-01-01', '2026-12-31']], TimeCsvWindows::pack([], '2026-01-01', '2026-12-31', 5000));
        $this->assertSame([['2026-01-01', '2026-01-31']], TimeCsvWindows::pack(['2026-01-05' => 10, '2026-01-20' => 4990], '2026-01-01', '2026-01-31', 5000));
    }

    public function testWindowsCoverThePeriodWithoutGapOrOverlap(): void
    {
        $counts = ['2026-01-01' => 2, '2026-01-02' => 2, '2026-01-04' => 1, '2026-01-07' => 3, '2026-01-31' => 1];
        $w = TimeCsvWindows::pack($counts, '2026-01-01', '2026-02-02', 3);
        $this->assertSame([['2026-01-01', '2026-01-01'], ['2026-01-02', '2026-01-04'], ['2026-01-05', '2026-01-07'], ['2026-01-08', '2026-02-02']], $w);
        $this->assertSame('2026-01-01', $w[0][0]);
        $this->assertSame('2026-02-02', end($w)[1]);
        for ($i = 1; $i < count($w); $i++) $this->assertSame(gmdate('Y-m-d', strtotime($w[$i - 1][1] . ' UTC') + 86400), $w[$i][0]);
    }

    public function testADayOverTheBudgetIsItsOwnWindow(): void
    {
        $w = TimeCsvWindows::pack(['2026-03-01' => 1, '2026-03-02' => 50, '2026-03-03' => 1], '2026-03-01', '2026-03-03', 10);
        $this->assertSame([['2026-03-01', '2026-03-01'], ['2026-03-02', '2026-03-02'], ['2026-03-03', '2026-03-03']], $w);
        $this->assertSame([['2026-03-01', '2026-03-03']], TimeCsvWindows::pack(['2026-03-02' => 50], '2026-03-01', '2026-03-03', 10));
    }

    public function testMonthAndYearEndsAndABudgetBelowOne(): void
    {
        $w = TimeCsvWindows::pack(['2025-12-31' => 1, '2026-01-01' => 1, '2026-02-28' => 1, '2026-03-01' => 1], '2025-12-31', '2026-03-01', 0);
        $this->assertSame([['2025-12-31', '2025-12-31'], ['2026-01-01', '2026-01-01'], ['2026-01-02', '2026-02-28'], ['2026-03-01', '2026-03-01']], $w);
    }
}
