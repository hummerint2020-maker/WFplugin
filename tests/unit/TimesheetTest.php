<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\Timesheet as T;

final class TimesheetTest extends TestCase
{
    private static function day(string $bucket, array $over = []): array
    {
        return array_merge(['employee_id' => 1, 'employee' => 'Amy', 'domain' => 'amy', 'bucket' => $bucket, 'planned' => 'Office', 'net_minutes' => 0, 'expected_minutes' => 0,
            'ot_approved' => 0, 'ot_actual' => 0, 'ot_extra' => 0, 'late_minutes' => 0, 'early_minutes' => 0], $over);
    }

    public function testTotalsAndLeaveBreakdown(): void
    {
        $t = T::byEmployee([
            self::day('Present', ['net_minutes' => 430, 'expected_minutes' => 450]),
            self::day('Late', ['net_minutes' => 500, 'expected_minutes' => 450, 'late_minutes' => 40, 'ot_approved' => 60, 'ot_actual' => 60, 'ot_extra' => 10]),
            self::day('Absent', ['expected_minutes' => 450]),
            self::day('Leave', ['planned' => 'Vacation']),
            self::day('Leave', ['planned' => 'Sick Leave']),
            self::day('Leave', ['planned' => 'Vacation']),
            self::day('Holiday'),
        ])[1];
        $this->assertSame([2, 930, 1350, -420, 60, 60, 10, 40, 1, 3, 1], [$t['worked_days'], $t['net_minutes'], $t['expected_minutes'], $t['balance_minutes'], $t['ot_approved'], $t['ot_actual'], $t['ot_extra'], $t['late_minutes'], $t['absent'], $t['leave_days'], $t['holidays']]);
        $this->assertSame('Sick Leave 1; Vacation 2', $t['leave_breakdown']);
    }

    public function testDecimalHours(): void
    {
        $this->assertSame(['7.17', '8.00', '0.00'], [T::decimalHours(430), T::decimalHours(480), T::decimalHours(0)]);
    }
}
