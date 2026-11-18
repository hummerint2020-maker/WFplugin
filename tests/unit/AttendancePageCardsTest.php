<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\AttendancePageCards;

final class AttendancePageCardsTest extends TestCase
{
    public function testAllShownByDefaultAsBefore(): void
    {
        $this->assertSame(['hidden' => []], AttendancePageCards::config(false));
        $this->assertSame(['people', 'present', 'late', 'absent', 'rate'], AttendancePageCards::shown(AttendancePageCards::config(null), false));
        $this->assertSame(['people', 'present', 'late', 'absent', 'leave', 'rate'], AttendancePageCards::shown(AttendancePageCards::config([]), true));
    }

    public function testUnknownAndRepeatedKeysAreDropped(): void
    {
        $this->assertSame(['hidden' => ['people', 'rate']], AttendancePageCards::config(['hidden' => ['rate', 'x', 'people', 'rate', 3]]));
        $this->assertSame(['hidden' => []], AttendancePageCards::config(['hidden' => 'people']));
    }

    public function testFromPostStoresTheUnticked(): void
    {
        $this->assertSame(['hidden' => []], AttendancePageCards::fromPost(['people', 'present', 'late', 'absent', 'leave', 'rate']));
        $this->assertSame(['hidden' => ['people', 'present']], AttendancePageCards::fromPost(['late', 'absent', 'leave', 'rate', 'bogus', ['x']]));
        $this->assertSame(['hidden' => AttendancePageCards::KEYS], AttendancePageCards::fromPost([]));
    }

    public function testShownKeepsTheOrderAndLeaveOnlyWithTheType(): void
    {
        $c = AttendancePageCards::config(['hidden' => ['people', 'present']]);
        $this->assertSame(['late', 'absent', 'leave', 'rate'], AttendancePageCards::shown($c, true));
        $this->assertSame(['late', 'absent', 'rate'], AttendancePageCards::shown($c, false));
        $this->assertSame([], AttendancePageCards::shown(AttendancePageCards::fromPost([]), true));
    }
}
