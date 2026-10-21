<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Api\LocalTime;

final class LocalTimeTest extends TestCase
{
    public function testTheOffsetFollowsTheSite(): void
    {
        $this->assertSame('2026-10-19T22:00:00+03:00', LocalTime::iso('2026-10-19 22:00:00', new DateTimeZone('Africa/Cairo')));
        $this->assertSame('2026-10-19T22:00:00+03:00', LocalTime::iso('2026-10-19 22:00', new DateTimeZone('Asia/Riyadh')), 'without seconds');
        $this->assertSame('2026-10-19T22:00:00+03:00', LocalTime::iso('2026-10-19 22:00:00', new DateTimeZone('+03:00')), 'a manual offset');
        $this->assertSame('2026-10-19T22:00:00+00:00', LocalTime::iso('2026-10-19 22:00:00', new DateTimeZone('UTC')));
        $this->assertSame('', LocalTime::iso('not a time', new DateTimeZone('UTC')));
    }

    public function testCairoClocksChange(): void
    {
        $cairo = new DateTimeZone('Africa/Cairo');
        $this->assertSame('2026-10-29T22:00:00+03:00', LocalTime::iso('2026-10-29 22:00:00', $cairo), 'before the clocks go back');
        $this->assertSame('2026-10-30T06:00:00+02:00', LocalTime::iso('2026-10-30 06:00:00', $cairo), 'after');
        $this->assertSame('2026-04-23T23:30:00+02:00', LocalTime::iso('2026-04-23 23:30:00', $cairo), 'before the clocks go forward');
        $this->assertSame('2026-04-24T01:30:00+03:00', LocalTime::iso('2026-04-24 00:30:00', $cairo), 'the skipped hour moves forward');
    }

    public function testAWallClockTimestampIsReadAsLocalTime(): void
    {
        $tz = new DateTimeZone('Africa/Cairo');
        $wall = (int) gmmktime(22, 4, 0, 10, 19, 2026);   // what current_time('timestamp') gives at 22:04 local
        $this->assertSame('2026-10-19T22:04:00+03:00', LocalTime::isoFromWall($wall, $tz));
    }

    public function testAnOvernightShiftEndsTheNextDay(): void
    {
        $tz = new DateTimeZone('Africa/Cairo');
        $this->assertSame(['starts_at' => '2026-10-19T22:00:00+03:00', 'ends_at' => '2026-10-20T06:00:00+03:00'], LocalTime::shiftSpan('2026-10-19', '22:00', '06:00', true, $tz));
        $this->assertSame(['starts_at' => '2026-10-29T22:00:00+03:00', 'ends_at' => '2026-10-30T06:00:00+02:00'], LocalTime::shiftSpan('2026-10-29', '22:00', '06:00', true, $tz), 'the night the clocks change: nine real hours');
        $this->assertSame(['starts_at' => '2026-10-19T08:00:00+03:00', 'ends_at' => '2026-10-19T17:00:00+03:00'], LocalTime::shiftSpan('2026-10-19', '08:00', '17:00', false, $tz));
        $this->assertSame('2026-10-19T06:00:00+03:00', LocalTime::shiftSpan('2026-10-19', '01:00', '06:00', true, $tz)['ends_at'], 'an "overnight" shift that does not cross midnight ends the same day');
    }
}
