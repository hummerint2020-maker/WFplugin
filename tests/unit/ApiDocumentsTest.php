<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Api\AttendanceToday;
use WorkforceOne\Api\Bootstrap;
use WorkforceOne\Api\Meta;
use WorkforceOne\Attendance\TodayState;

final class ApiDocumentsTest extends TestCase
{
    private function tz(): DateTimeZone
    {
        return new DateTimeZone('Asia/Riyadh');
    }

    public function testBootstrapPublishesOnlyWhatItListsAndKeepsMeUnchanged(): void
    {
        $me = ['user' => ['name' => 'Mona', 'email' => 'm@x.com', 'locale' => 'ar'], 'employee' => ['name' => 'Mona', 'email' => 'm@x.com', 'department' => 'Ops', 'attendance_enabled' => true],
            'roles' => ['employee'], 'permissions' => ['view_people'], 'device' => ['id' => 'abc', 'current' => true], 'secret' => 'never', 'password_hash' => 'never'];
        $doc = Bootstrap::document($me, ['breaks' => true, 'tasks' => 1, 'invented' => true], [['key' => 'schedule', 'label' => 'Schedule', 'order' => 20, 'url' => 'https://x/y', 'count' => 9]],
            ['timezone' => 'Asia/Riyadh', 'locale' => 'ar', 'today' => '2026-10-20', 'working_days' => [0, 1, 2, 3, 4], 'ignored' => 'x']);
        $this->assertSame(['user', 'employee', 'roles', 'permissions', 'device', 'features', 'screens', 'site', 'limits'], array_keys($doc));
        foreach (['user', 'employee', 'roles', 'permissions', 'device'] as $k) $this->assertSame($me[$k], $doc[$k], $k);
        $this->assertSame(Meta::FEATURES, array_keys($doc['features']), 'the same flags /meta publishes');
        $this->assertTrue($doc['features']['breaks']);
        $this->assertTrue($doc['features']['tasks']);
        $this->assertFalse($doc['features']['overtime']);
        $this->assertArrayNotHasKey('invented', $doc['features']);
        $this->assertSame([['key' => 'schedule', 'label' => 'Schedule', 'order' => 20]], $doc['screens'], 'no url, no badge count');
        $this->assertSame(['timezone' => 'Asia/Riyadh', 'locale' => 'ar', 'today' => '2026-10-20', 'working_days' => [0, 1, 2, 3, 4]], $doc['site']);
        $this->assertSame(['schedule_max_days' => 42], $doc['limits']);
        $none = Bootstrap::document(['user' => ['name' => 'Admin'], 'roles' => ['site_administrator'], 'permissions' => []], [], [], ['timezone' => 'UTC', 'locale' => 'en_US', 'today' => '2026-10-20', 'working_days' => []]);
        $this->assertNull($none['employee'], 'an administrator with no employee record');
    }

    private function state(array $over = []): TodayState
    {
        return new TodayState($over + ['employee' => true, 'attendance_enabled' => true, 'day' => '2026-10-19', 'status' => 'Office', 'requires_sign_in' => true, 'requires_location' => true,
            'general_leave' => false, 'face_required' => false, 'clock' => 'in', 'sign_in_at' => '2026-10-19 22:04:00', 'sign_in_class' => 'On Time', 'sign_out_at' => null,
            'window_open' => false, 'bounds' => ['start' => (int) gmmktime(22, 0, 0, 10, 19, 2026), 'cutoff' => (int) gmmktime(2, 0, 0, 10, 20, 2026)], 'break_enabled' => true,
            'actions' => ['sign_in' => 'too_late', 'sign_out' => null, 'break_start' => null, 'break_end' => 'no_open_break']]);
    }

    private function breaks(array $over = []): array
    {
        return $over + ['enabled' => true, 'per_day' => 3, 'duration_minutes' => 30, 'remaining' => 2,
            'sessions' => [['start_at' => '2026-10-20 00:10:00', 'end_at' => '2026-10-20 00:35:00', 'minutes' => 25]]];
    }

    public function testTodayDuringAnOvernightShiftCarriedOverFromYesterday(): void
    {
        $shift = ['id' => 88, 'name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'overnight' => true, 'grace_minutes' => 10, 'starts_at' => '2026-10-19T22:00:00+03:00', 'ends_at' => '2026-10-20T06:00:00+03:00'];
        $d = AttendanceToday::document($this->state(), '2026-10-20', $this->tz(), $shift, ['id' => 3, 'name' => 'Nasr City', 'planned' => true], $this->breaks(), ['On Time' => 'في الموعد']);
        $this->assertSame('2026-10-20', $d['today']);
        $this->assertSame('2026-10-19', $d['work_date']);
        $this->assertTrue($d['carried_over']);
        $this->assertSame('working', $d['day_type']);
        $this->assertSame('in', $d['clock']);
        $this->assertSame(['opens_at' => '2026-10-19T22:00:00+03:00', 'closes_at' => '2026-10-20T02:00:00+03:00', 'open' => false], $d['sign_in_window']);
        $this->assertSame(['at' => '2026-10-19T22:04:00+03:00', 'result' => 'on_time', 'label' => 'في الموعد'], $d['sign_in']);
        $this->assertNull($d['sign_out']);
        $this->assertSame($shift, $d['shift']);
        $this->assertSame(['allowed' => false, 'reason' => 'SIGN_IN_CLOSED'], $d['actions']['sign_in']);
        $this->assertSame(['allowed' => true, 'reason' => null], $d['actions']['sign_out']);
        $this->assertSame(['allowed' => true, 'reason' => null], $d['actions']['break_start']);
        $this->assertSame(['allowed' => false, 'reason' => 'NO_OPEN_BREAK'], $d['actions']['break_end']);
        $this->assertSame(['face' => false, 'location' => true], $d['requirements']);
        $this->assertSame(1, $d['breaks']['used']);
        $this->assertNull($d['breaks']['open']);
        $this->assertSame('2026-10-20T00:10:00+03:00', $d['breaks']['sessions'][0]['started_at']);
        $this->assertSame(25, $d['breaks']['sessions'][0]['minutes']);
        $this->assertSame('Asia/Riyadh', $d['timezone']);
    }

    public function testDayTypesAndAnOpenBreak(): void
    {
        $off = AttendanceToday::document($this->state(['attendance_enabled' => false, 'actions' => ['sign_in' => 'attendance_disabled', 'sign_out' => 'attendance_disabled', 'break_start' => 'attendance_disabled', 'break_end' => 'attendance_disabled']]), '2026-10-19', $this->tz(), null, null, $this->breaks(), []);
        $this->assertSame('attendance_disabled', $off['day_type']);
        $this->assertSame('ATTENDANCE_DISABLED', $off['actions']['sign_in']['reason']);
        $this->assertFalse($off['carried_over']);
        $leave = AttendanceToday::document($this->state(['general_leave' => true, 'actions' => ['sign_in' => 'general_leave', 'sign_out' => 'general_leave', 'break_start' => 'not_working_day', 'break_end' => 'no_open_break']]), '2026-10-19', $this->tz(), null, null, $this->breaks(), []);
        $this->assertSame('general_leave', $leave['day_type']);
        $this->assertNull($leave['sign_in_window'], 'no window on a holiday');
        $this->assertSame('GENERAL_LEAVE', $leave['actions']['sign_in']['reason']);
        $none = AttendanceToday::document($this->state(['status' => null, 'requires_sign_in' => false, 'requires_location' => false, 'clock' => 'out', 'sign_in_at' => null, 'sign_in_class' => null,
            'actions' => ['sign_in' => 'not_working_day', 'sign_out' => 'not_working_day', 'break_start' => 'not_working_day', 'break_end' => 'no_open_break']]), '2026-10-19', $this->tz(), null, null, $this->breaks(['sessions' => []]), []);
        $this->assertSame('not_working', $none['day_type']);
        $this->assertNull($none['schedule']);
        $this->assertNull($none['sign_in']);
        $this->assertSame('NOT_SCHEDULED', $none['actions']['sign_in']['reason']);
        $onBreak = AttendanceToday::document($this->state(['clock' => 'break']), '2026-10-19', $this->tz(), null, null,
            $this->breaks(['sessions' => [['start_at' => '2026-10-19 23:00:00', 'end_at' => null, 'minutes' => null]]]), []);
        $this->assertSame(['started_at' => '2026-10-19T23:00:00+03:00'], $onBreak['breaks']['open']);
        $late = AttendanceToday::document($this->state(['sign_in_class' => 'Late Arrival']), '2026-10-19', $this->tz(), null, null, $this->breaks(), []);
        $this->assertSame('late', $late['sign_in']['result']);
    }

    public function testBreaksSwitchedOffAreJustSaidSo(): void
    {
        $d = AttendanceToday::document($this->state(['actions' => ['sign_in' => null, 'sign_out' => 'not_signed_in', 'break_start' => 'breaks_disabled', 'break_end' => 'breaks_disabled']]), '2026-10-19', $this->tz(), null, null, ['enabled' => false, 'per_day' => 0, 'duration_minutes' => 0, 'remaining' => 0, 'sessions' => []], []);
        $this->assertSame(['enabled' => false], $d['breaks']);
        $this->assertSame('BREAKS_DISABLED', $d['actions']['break_start']['reason']);
        $this->assertSame('BREAKS_DISABLED', $d['actions']['break_end']['reason']);
    }
}
