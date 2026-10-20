<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\ScheduleDays as D;

final class ScheduleDaysTest extends TestCase
{
    private const NIGHT = ['start' => '22:00', 'end' => '06:00', 'grace' => 10, 'overnight' => 1, 'shift_id' => 88, 'shift_name' => 'Night'];
    private const DAY = ['start' => '08:00', 'end' => '17:00', 'grace' => 5, 'overnight' => 0, 'shift_id' => 1, 'shift_name' => 'Standard'];

    private function build(array $dates, array $status, array $leave = [], array $hours = [], array $branch = []): array
    {
        return D::build($dates, $status, $leave, ['Office' => true, 'WFH' => true, 'Leave' => false], $hours, $branch, [0, 1, 2, 3, 4], new DateTimeZone('Asia/Riyadh'),
            ['Not Set' => 'غير محدد', 'General Leave' => 'إجازة عامة']);
    }

    public function testEveryCalendarDayIsListedInOrder(): void
    {
        $days = $this->build(['2026-10-23', '2026-10-24', '2026-10-25'], []);
        $this->assertSame(['2026-10-23', '2026-10-24', '2026-10-25'], array_column($days, 'date'));
        $this->assertSame([5, 6, 0], array_column($days, 'weekday'));
        $this->assertSame([false, false, true], array_column($days, 'working_weekday'), 'Friday and Saturday are not working days here; Sunday is');
        $this->assertSame(['Not Set', 'Not Set', 'Not Set'], array_column($days, 'status'), 'an employee with no schedule');
        $this->assertSame([null, null, null], array_column($days, 'shift'));
        $this->assertSame('غير محدد', $days[0]['label']);
        $this->assertSame([false, false, false], array_column($days, 'requires_sign_in'));
    }

    public function testAHolidayWinsOverTheScheduleRow(): void
    {
        $days = $this->build(['2026-10-20', '2026-10-21'], ['2026-10-20' => 'Office', '2026-10-21' => 'Office'], ['2026-10-20' => 'Armed Forces Day'], ['2026-10-20' => self::DAY, '2026-10-21' => self::DAY]);
        $this->assertSame('General Leave', $days[0]['status']);
        $this->assertTrue($days[0]['general_leave']);
        $this->assertNull($days[0]['shift'], 'no shift on a holiday');
        $this->assertSame('إجازة عامة', $days[0]['label']);
        $this->assertSame('Office', $days[1]['status']);
        $this->assertSame('08:00', $days[1]['shift']['start']);
    }

    public function testAShiftIsGivenOnlyWhereSignInIsRequired(): void
    {
        $days = $this->build(['2026-10-20', '2026-10-21', '2026-10-22'], ['2026-10-20' => 'Office', '2026-10-21' => 'Leave', '2026-10-22' => 'WFH'],
            [], ['2026-10-20' => self::DAY, '2026-10-21' => self::DAY, '2026-10-22' => self::DAY]);
        $this->assertSame([true, false, true], array_column($days, 'requires_sign_in'));
        $this->assertNotNull($days[0]['shift']);
        $this->assertNull($days[1]['shift']);
        $this->assertNotNull($days[2]['shift']);
        $this->assertSame(['id' => 1, 'name' => 'Standard', 'start' => '08:00', 'end' => '17:00', 'overnight' => false, 'grace_minutes' => 5,
            'starts_at' => '2026-10-20T08:00:00+03:00', 'ends_at' => '2026-10-20T17:00:00+03:00'], $days[0]['shift']);
    }

    public function testAnOvernightShiftEndsTheNextDayAndShiftHistoryGivesEachDayItsOwn(): void
    {
        $days = $this->build(['2026-10-19', '2026-10-20'], ['2026-10-19' => 'Office', '2026-10-20' => 'Office'], [], ['2026-10-19' => self::NIGHT, '2026-10-20' => self::DAY]);
        $this->assertTrue($days[0]['shift']['overnight']);
        $this->assertSame('2026-10-20T06:00:00+03:00', $days[0]['shift']['ends_at']);
        $this->assertSame('Night', $days[0]['shift']['name']);
        $this->assertFalse($days[1]['shift']['overnight']);
        $this->assertSame('Standard', $days[1]['shift']['name'], 'a different shift on another day');
        $night = self::NIGHT;
        $night['overnight'] = 0;
        $days = $this->build(['2026-10-19'], ['2026-10-19' => 'Office'], [], ['2026-10-19' => $night]);
        $this->assertFalse($days[0]['shift']['overnight'], 'a shift whose "overnight" is off does not cross midnight');
    }

    public function testTheBranchOnlyAppearsWhereTheWebShowsOne(): void
    {
        $b = ['id' => 3, 'name' => 'Nasr City', 'planned' => true];
        $days = $this->build(['2026-10-20', '2026-10-21'], ['2026-10-20' => 'Office', '2026-10-21' => 'Leave'], [], [], ['2026-10-20' => $b, '2026-10-21' => $b]);
        $this->assertSame($b, $days[0]['branch']);
        $this->assertNull($days[1]['branch'], 'not on a day without Sign In');
        $days = $this->build(['2026-10-20'], ['2026-10-20' => 'Office']);
        $this->assertNull($days[0]['branch']);
    }
}
