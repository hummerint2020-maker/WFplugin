<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\DayMetrics;
use WorkforceOne\Reports\LeaveReport;
use WorkforceOne\Reports\OvertimeReport;
use WorkforceOne\Reports\SavedViews;
use WorkforceOne\Reports\Trend;

final class ReportsPhase2Test extends TestCase
{
    private static function day(int $id, string $name, array $over = []): array
    {
        return array_merge(['employee_id' => $id, 'employee' => $name, 'domain' => strtolower($name), 'date' => '2026-01-05', 'result' => 'Present', 'bucket' => 'Present', 'planned' => 'Office',
            'ot_approved' => 0, 'ot_actual' => 0, 'ot_extra' => 0], $over);
    }

    public function testOffDayOvertimeCountsTheWholeWindow(): void
    {
        $o = DayMetrics::offDayOvertime('2026-01-09', '2026-01-09 10:00:00', '2026-01-09 13:00:00', [['start' => '10:00', 'end' => '14:00']]);
        $this->assertSame([240, 180, 0], [$o['approved'], $o['actual'], $o['extra']]);
        $o = DayMetrics::offDayOvertime('2026-01-09', '2026-01-09 09:00:00', '2026-01-09 15:00:00', [['start' => '10:00', 'end' => '14:00']]);
        $this->assertSame([240, 240, 120], [$o['approved'], $o['actual'], $o['extra']], 'presence outside the window is unapproved');
        $o = DayMetrics::offDayOvertime('2026-01-09', '2026-01-09 10:00:00', null, [['start' => '10:00', 'end' => '14:00']]);
        $this->assertSame([240, 0, 0], [$o['approved'], $o['actual'], $o['extra']], 'no Sign Out: nothing proven');
        $o = DayMetrics::offDayOvertime('2026-01-09', '2026-01-09 10:00:00', '2026-01-09 12:00:00', []);
        $this->assertSame([0, 0, 120], [$o['approved'], $o['actual'], $o['extra']]);
    }

    public function testOvertimeByEmployee(): void
    {
        $p = OvertimeReport::byEmployee([
            self::day(1, 'Omar', ['ot_approved' => 120, 'ot_actual' => 120]),
            self::day(1, 'Omar', ['ot_approved' => 240, 'ot_actual' => 180, 'date' => '2026-01-09']),
            self::day(2, 'Lina', ['ot_extra' => 90]),
            self::day(3, 'Vera'),
        ], [
            ['employee_id' => 1, 'status' => 'Approved'], ['employee_id' => 1, 'status' => 'Approved'], ['employee_id' => 1, 'status' => 'Rejected'],
            ['employee_id' => 2, 'status' => 'Pending'], ['employee_id' => 99, 'status' => 'Pending'],
        ]);
        $this->assertSame([2, 1], array_keys($p), 'by name; no-overtime and out-of-scope employees left out');
        $this->assertSame([3, 2, 0, 1, 360, 300, 0, 83, 2], [$p[1]['requests'], $p[1]['approved'], $p[1]['pending'], $p[1]['rejected'], $p[1]['approved_minutes'], $p[1]['worked_minutes'], $p[1]['extra_minutes'], $p[1]['utilisation'], $p[1]['days']]);
        $this->assertNull($p[2]['utilisation']);
        $t = OvertimeReport::totals($p);
        $this->assertSame([2, 4, 1, 360, 300, 90, 83], [$t['employees'], $t['requests'], $t['pending'], $t['approved_minutes'], $t['worked_minutes'], $t['extra_minutes'], $t['utilisation']]);
    }

    public function testLeaveByEmployee(): void
    {
        $people = [['employee_id' => 1, 'employee' => 'Vera', 'domain' => 'vera', 'teams' => []], ['employee_id' => 2, 'employee' => 'Omar', 'domain' => 'omar', 'teams' => []]];
        $p = LeaveReport::byEmployee($people, [
            self::day(1, 'Vera', ['bucket' => 'Leave', 'planned' => 'Vacation']),
            self::day(1, 'Vera', ['bucket' => 'Leave', 'planned' => 'Sick Leave']),
            self::day(1, 'Vera', ['bucket' => 'Leave', 'planned' => 'Vacation']),
            self::day(2, 'Omar'),
        ], [
            ['employee_id' => 1, 'status' => 'Approved', 'requested_days' => '2.00'], ['employee_id' => 1, 'status' => 'Pending', 'requested_days' => '0.5'],
            ['employee_id' => 1, 'status' => 'Rejected', 'requested_days' => '4'], ['employee_id' => 7, 'status' => 'Approved', 'requested_days' => '1'],
        ], [
            ['employee_id' => 1, 'type' => 'Annual Leave', 'entitlement' => '21', 'used' => '3', 'pending' => '2'],
            ['employee_id' => 2, 'type' => 'Annual Leave', 'entitlement' => '1', 'used' => '2', 'pending' => '0'],
        ]);
        $this->assertSame([3, 'Sick Leave 1; Vacation 2', 1, 2.0, 1, 0.5], [$p[1]['leave_days'], $p[1]['leave_breakdown'], $p[1]['approved_requests'], $p[1]['approved_days'], $p[1]['pending_requests'], $p[1]['pending_days']]);
        $this->assertSame(16.0, $p[1]['balances']['Annual Leave']['remaining']);
        $this->assertSame(['on_leave' => 1, 'leave_days' => 3, 'approved_requests' => 1, 'pending_requests' => 1, 'overdrawn' => 1], LeaveReport::totals($p));
        $this->assertSame(['16', '2.5', 'annual-leave'], [LeaveReport::days(16.0), LeaveReport::days(2.5), LeaveReport::typeKey(' Annual Leave ')]);
    }

    public function testTrend(): void
    {
        $t = Trend::daily([
            self::day(1, 'A', ['date' => '2026-01-06', 'result' => 'Absent']),
            self::day(1, 'A'), self::day(2, 'B', ['result' => 'Late']), self::day(3, 'C', ['result' => 'Absent']),
            self::day(4, 'D', ['date' => '2026-01-06', 'result' => 'Leave']),
        ]);
        $this->assertSame(['2026-01-05', '2026-01-06'], array_keys($t));
        $this->assertSame([67, 0], [$t['2026-01-05']['rate'], $t['2026-01-06']['rate']]);
        $this->assertNull(Trend::daily([self::day(1, 'A', ['result' => 'Leave'])])['2026-01-05']['rate']);
    }

    public function testSavedViews(): void
    {
        $f = ['report_type' => 'overtime', 'team' => 'all', 'employee' => '0', 'employee_status' => 'all', 'status' => 'all', 'start' => '2026-01-01', 'end' => '2026-01-09'];
        $v = SavedViews::add([], '  Ops   weekly ', $f, '');
        $this->assertSame('Ops weekly', $v[0]['name']);
        $this->assertSame(['report_type' => 'overtime', 'team' => 'all', 'employee' => '0', 'employee_status' => 'all', 'status' => 'all', 'start' => '2026-01-01', 'end' => '2026-01-09'], SavedViews::args($v[0], []));
        $v = SavedViews::add($v, 'Month', $f, 'this_month');
        $this->assertArrayNotHasKey('start', $v[1]);
        $this->assertSame(['2026-10-01', '2026-10-03'], array_values(array_intersect_key(SavedViews::args($v[1], ['this_month' => ['2026-10-01', '2026-10-03']]), ['start' => 1, 'end' => 1])));
        $v = SavedViews::add($v, 'ops weekly', ['report_type' => 'leave'] + $f, '');
        $this->assertSame(['Month', 'ops weekly'], array_column($v, 'name'), 'same name (any case) replaces');
        $this->assertSame(['ops weekly'], array_column(SavedViews::remove($v, SavedViews::id('Month')), 'name'));
        $this->assertSame($v, SavedViews::add($v, '   ', $f, ''), 'a blank name saves nothing');
        for ($i = 0; $i < 20; $i++) $v = SavedViews::add($v, 'V' . $i, $f, '');
        $this->assertCount(SavedViews::MAX, $v);
        $this->assertSame('V19', end($v)['name']);
    }
}
