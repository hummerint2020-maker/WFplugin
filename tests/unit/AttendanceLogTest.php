<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\AttendanceLog;
use WorkforceOne\Tests\Fakes\MemoryBreakStore;
use WorkforceOne\Tests\Fakes\MemoryTimeLogStore;

require_once __DIR__ . '/Fakes/MemoryAttendanceStores.php';

/** AttendanceLog (3.31.136): today's events by type (a corrected row hidden), the shift state, the last location, and the break reads. */
final class AttendanceLogTest extends TestCase
{
    /** @var MemoryTimeLogStore */
    private $logs;
    /** @var MemoryBreakStore */
    private $breaks;
    /** @var AttendanceLog */
    private $log;

    protected function setUp(): void
    {
        $this->logs = new MemoryTimeLogStore();
        $this->breaks = new MemoryBreakStore();
        $this->log = new AttendanceLog($this->logs, $this->breaks);
    }

    public function testTodayEventsAreKeyedByTypeAndTheLaterRowOfATypeWins(): void
    {
        $this->logs->add(1, 7, '2026-10-29', 'sign_in', '2026-10-29 08:00:00');
        $this->logs->add(2, 7, '2026-10-29', 'sign_out', '2026-10-29 16:00:00');
        $this->logs->add(3, 7, '2026-10-29', 'sign_out', '2026-10-29 17:00:00');
        $this->logs->add(4, 8, '2026-10-29', 'sign_in', '2026-10-29 08:30:00');
        $e = $this->log->todayEvents(7, '2026-10-29');
        $this->assertSame(['sign_in', 'sign_out'], array_keys($e));
        $this->assertSame('3', $e['sign_out']->id);
    }

    public function testACorrectedRowIsHiddenAndNobodyElsesRowsShowUp(): void
    {
        $this->logs->add(1, 7, '2026-10-29', 'sign_in', '2026-10-29 09:30:00');
        $this->logs->add(2, 7, '2026-10-29', 'sign_in', '2026-10-29 08:05:00', ['corrects_id' => '1']);
        $this->assertSame('2', $this->log->todayEvents(7, '2026-10-29')['sign_in']->id);
        $this->assertSame([], $this->log->todayEvents(9, '2026-10-29'));
        $this->assertSame([], $this->log->todayEvents(7, '2026-10-28'));
    }

    public function testTheShiftIsOpenWhenThereIsASignInAndNoSignOut(): void
    {
        $this->logs->add(1, 7, '2026-10-28', 'late_sign_in', '2026-10-28 22:10:00');
        $this->assertTrue($this->log->shiftOpenOn(7, '2026-10-28'));
        $this->logs->add(2, 7, '2026-10-28', 'sign_out', '2026-10-28 23:50:00');
        $this->assertFalse($this->log->shiftOpenOn(7, '2026-10-28'));
        $this->assertFalse($this->log->shiftOpenOn(8, '2026-10-28'));
    }

    public function testASignOutThatWasCorrectedAwayLeavesTheShiftOpen(): void
    {
        $this->logs->add(1, 7, '2026-10-28', 'sign_in', '2026-10-28 22:00:00');
        $this->logs->add(2, 7, '2026-10-28', 'sign_out', '2026-10-28 23:00:00');
        $this->logs->add(3, 7, '2026-10-28', 'sign_in', '2026-10-28 22:00:00', ['corrects_id' => '2']);
        $this->assertTrue($this->log->shiftOpenOn(7, '2026-10-28'));
    }

    public function testLiveInOutLeavesOutOtherEventTypes(): void
    {
        $this->logs->add(1, 7, '2026-10-29', 'sign_in', '2026-10-29 08:00:00');
        $this->logs->add(2, 7, '2026-10-29', 'break_note', '2026-10-29 09:00:00');
        $this->logs->add(3, 7, '2026-10-29', 'sign_out', '2026-10-29 17:00:00');
        $this->assertSame(['sign_in', 'sign_out'], array_map(function ($r) { return $r->event_type; }, $this->log->liveInOut(7, '2026-10-29')));
    }

    public function testTheLastDeviceLocationIsTheNewestEventWithCoordinates(): void
    {
        $this->logs->add(1, 7, '2026-10-27', 'sign_in', '2026-10-27 08:00:00', ['latitude' => '30.1', 'longitude' => '31.2', 'location_timestamp' => '10']);
        $this->logs->add(2, 7, '2026-10-28', 'sign_in', '2026-10-28 08:00:00', ['latitude' => '30.5', 'longitude' => '31.6', 'location_timestamp' => '20']);
        $this->logs->add(3, 7, '2026-10-29', 'sign_in', '2026-10-29 08:00:00');
        $this->assertSame(['latitude' => '30.5', 'longitude' => '31.6', 'location_timestamp' => '20'], $this->log->lastDeviceLocation(7));
        $this->assertNull($this->log->lastDeviceLocation(8));
    }

    public function testBreakReads(): void
    {
        $this->breaks->add(1, 7, '2026-10-29', 'Closed');
        $this->breaks->add(2, 7, '2026-10-29', 'Open');
        $this->breaks->add(3, 7, '2026-10-28', 'Open');
        $this->assertCount(2, $this->log->breakSessions(7, '2026-10-29'));
        $this->assertSame('2', $this->log->openBreak(7, '2026-10-29')->id);
        $this->assertNull($this->log->openBreak(8, '2026-10-29'));
    }
}
