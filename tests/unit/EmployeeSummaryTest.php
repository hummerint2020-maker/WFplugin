<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\EmployeeSummary as E;

final class EmployeeSummaryTest extends TestCase
{
    private static function day(int $id, string $name, string $bucket, array $over = []): array
    {
        return array_merge(['employee_id' => $id, 'employee' => $name, 'domain' => strtolower($name), 'bucket' => $bucket, 'expected' => in_array($bucket, ['Present', 'Late', 'Absent', 'Pending'], true),
            'late_minutes' => 0, 'early_minutes' => 0, 'missing_sign_out' => false, 'net_minutes' => 0, 'expected_minutes' => in_array($bucket, ['Present', 'Late', 'Absent'], true) ? 450 : 0, 'sign_in' => ''], $over);
    }

    public function testPerEmployeeTotalsAndRates(): void
    {
        $days = [
            self::day(2, 'Zed', 'Present', ['net_minutes' => 450, 'sign_in' => '09:00']),
            self::day(2, 'Zed', 'Late', ['late_minutes' => 40, 'net_minutes' => 410, 'sign_in' => '09:40']),
            self::day(2, 'Zed', 'Absent'),
            self::day(2, 'Zed', 'Holiday'),
            self::day(1, 'Amy', 'Present', ['net_minutes' => 460, 'sign_in' => '08:55', 'missing_sign_out' => false]),
            self::day(1, 'Amy', 'Leave'),
        ];
        $by = E::byEmployee($days);
        $this->assertSame([1, 2], array_keys($by));
        $zed = $by[2];
        $this->assertSame([3, 1, 1, 1, 1, 40, 67, 50, '09:20', 860, 1350, -490],
            [$zed['expected_days'], $zed['present'], $zed['late'], $zed['absent'], $zed['holiday'], $zed['late_minutes'], $zed['attendance_rate'], $zed['punctuality_rate'], $zed['avg_first_in'], $zed['net_minutes'], $zed['expected_minutes'], $zed['balance_minutes']]);
        $this->assertSame([100, 100, 1], [$by[1]['attendance_rate'], $by[1]['punctuality_rate'], $by[1]['leave']]);
        $t = E::totals($days);
        $this->assertSame([2, 1, 1, 75], [$t['present'], $t['late'], $t['absent'], $t['attendance_rate']]);
    }

    public function testNoAttendedDaysHasNoAverageSignIn(): void
    {
        $t = E::totals([self::day(1, 'A', 'Absent')]);
        $this->assertSame(['', 0, 0], [$t['avg_first_in'], $t['attendance_rate'], $t['punctuality_rate']]);
    }

    public function testPreviousPeriodHasTheSameLength(): void
    {
        $this->assertSame(['2026-08-01', '2026-08-31'], E::previousPeriod('2026-09-01', '2026-10-01'));
        $this->assertSame(['2026-09-30', '2026-09-30'], E::previousPeriod('2026-10-01', '2026-10-01'));
        // Across the October daylight-saving change.
        $this->assertSame(['2026-10-18', '2026-10-24'], E::previousPeriod('2026-10-25', '2026-10-31'));
    }
}
