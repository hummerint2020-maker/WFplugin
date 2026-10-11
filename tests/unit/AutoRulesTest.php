<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\AutoRules as A;

final class AutoRulesTest extends TestCase
{
    private static function rule(array $over = []): array
    {
        return array_merge(['recurrence' => 'one_time', 'run_date' => '2026-10-03', 'weekdays' => '', 'sign_in_time' => '08:00:00', 'sign_out_time' => '16:00:00'], $over);
    }

    public function testWeekdayIgnoresTimeZone(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        $this->assertSame([6, 0], [A::weekday('2026-10-03'), A::weekday('2026-10-04')]);
        date_default_timezone_set($tz);
        $this->assertTrue(A::applies('weekly', null, '1,6', '2026-10-03'));
        $this->assertFalse(A::applies('weekly', null, '0,1', '2026-10-03'));
        $this->assertTrue(A::applies('one_time', '2026-10-03', '', '2026-10-03'));
    }

    public function testDayRule(): void
    {
        $this->assertSame([], A::due(self::rule(), '2026-10-03', '2026-10-02', '07:59'));
        $this->assertSame([['type' => 'sign_in', 'work_date' => '2026-10-03', 'event_date' => '2026-10-03', 'time' => '08:00']], A::due(self::rule(), '2026-10-03', '2026-10-02', '12:00'));
        $this->assertCount(2, A::due(self::rule(), '2026-10-03', '2026-10-02', '16:00'));
        $this->assertSame([], A::due(self::rule(['run_date' => '2026-10-02']), '2026-10-03', '2026-10-02', '23:00'), 'yesterday\'s day rule does nothing today');
    }

    public function testOvernightRule(): void
    {
        $night = self::rule(['run_date' => '2026-10-02', 'sign_in_time' => '22:00', 'sign_out_time' => '06:00']);
        $this->assertTrue(A::overnight('22:00', '06:00'));
        $this->assertFalse(A::overnight('08:00', '16:00'));
        $this->assertSame([], A::due($night, '2026-10-03', '2026-10-02', '05:59'));
        $this->assertSame([['type' => 'sign_out', 'work_date' => '2026-10-02', 'event_date' => '2026-10-03', 'time' => '06:00']], A::due($night, '2026-10-03', '2026-10-02', '06:00'));
        $this->assertSame([['type' => 'sign_in', 'work_date' => '2026-10-02', 'event_date' => '2026-10-02', 'time' => '22:00']], A::due($night, '2026-10-02', '2026-10-01', '22:30'), 'on its own day only the Sign In');
        $this->assertSame(['2026-10-03', '2026-10-02'], [A::signOutDate('2026-10-02', '22:00', '06:00'), A::signOutDate('2026-10-02', '08:00', '16:00')]);
    }
}
