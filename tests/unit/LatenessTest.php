<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\Lateness as L;
use WorkforceOne\Attendance\ShiftDay;
use WorkforceOne\Reports\DayMetrics;

/**
 * "Late" has one rule (src/Attendance/Lateness.php). These tests pin it to the behaviour of 3.31.44:
 * `legacyClassify()` is a verbatim copy of the old sign_in_classification() body, and
 * `legacyDayMetricsLate()` of the old DayMetrics comparison.
 */
final class LatenessTest extends TestCase
{
    /** The pre-3.31.45 trait-work-time.php sign_in_classification(), working_hours() given as $cfg. */
    private static function legacyClassify(int $ts, array $cfg, int $grace): string
    {
        $date = date('Y-m-d', $ts);
        $start = strtotime($date . ' ' . $cfg['start']);
        if (!empty($cfg['overnight']) && $cfg['start'] > $cfg['end'] && date('H:i', $ts) <= $cfg['end']) $start = strtotime(date('Y-m-d', strtotime($date . ' -1 day')) . ' ' . $cfg['start']);
        $grace_end = $start + ($grace * 60);
        return $ts <= $grace_end ? 'On Time' : 'Late Arrival';
    }

    /** The pre-3.31.45 DayMetrics comparison (without the late_sign_in flag). */
    private static function legacyDayMetricsLate(int $in, string $workDate, string $shiftStart, int $grace): bool
    {
        $start = strtotime($workDate . ' ' . $shiftStart . ':00');
        return $in > $start + $grace * 60;
    }

    /** @return array<string,array{0:array{start:string,end:string,overnight:int},1:int}> */
    public static function shifts(): array
    {
        return [
            'day 08:00-16:00, grace 10' => [['start' => '08:00', 'end' => '16:00', 'overnight' => 0], 10],
            'day 08:00-16:00, no grace' => [['start' => '08:00', 'end' => '16:00', 'overnight' => 0], 0],
            'overnight 22:00-06:00, grace 15' => [['start' => '22:00', 'end' => '06:00', 'overnight' => 1], 15],
            'overnight times but overnight off' => [['start' => '22:00', 'end' => '06:00', 'overnight' => 0], 10],
            'midnight start 00:00-08:00' => [['start' => '00:00', 'end' => '08:00', 'overnight' => 0], 5],
            'evening 16:00-23:59' => [['start' => '16:00', 'end' => '23:59', 'overnight' => 1], 30],
        ];
    }

    /** @dataProvider shifts */
    public function testSameAsBeforeEveryFiveMinutesOverTwoDays(array $cfg, int $grace): void
    {
        $from = strtotime('2026-09-02 00:00:00');
        for ($ts = $from; $ts < $from + 2 * 86400; $ts += 300) {
            $this->assertSame(self::legacyClassify($ts, $cfg, $grace), L::classify($ts, $cfg['start'], $cfg['end'], (bool) $cfg['overnight'], $grace), date('Y-m-d H:i', $ts));
        }
    }

    /** @dataProvider shifts */
    public function testSameAsBeforeAtTheBoundaries(array $cfg, int $grace): void
    {
        foreach (['2026-09-02', '2026-09-03'] as $day) {
            $start = strtotime($day . ' ' . $cfg['start'] . ':00');
            foreach ([-1, 0, 1, $grace * 60 - 1, $grace * 60, $grace * 60 + 1, $grace * 60 + 60] as $offset) {
                $ts = $start + $offset;
                $this->assertSame(self::legacyClassify($ts, $cfg, $grace), L::classify($ts, $cfg['start'], $cfg['end'], (bool) $cfg['overnight'], $grace), date('Y-m-d H:i:s', $ts));
            }
        }
    }

    public function testTheEdgesBySpec(): void
    {
        $t = static function ($s) { return (int) strtotime($s); };
        $this->assertSame(L::ON_TIME, L::classify($t('2026-09-02 08:00:00'), '08:00', '16:00', false, 10), 'exactly at the shift start');
        $this->assertSame(L::ON_TIME, L::classify($t('2026-09-02 08:09:59'), '08:00', '16:00', false, 10), 'inside the grace');
        $this->assertSame(L::ON_TIME, L::classify($t('2026-09-02 08:10:00'), '08:00', '16:00', false, 10), 'exactly at the end of the grace');
        $this->assertSame(L::LATE, L::classify($t('2026-09-02 08:10:01'), '08:00', '16:00', false, 10), 'first second after the grace');
        $this->assertSame(L::LATE, L::classify($t('2026-09-02 08:11:00'), '08:00', '16:00', false, 10), 'first minute after the grace');
        $this->assertSame(L::ON_TIME, L::classify($t('2026-09-02 21:55:00'), '22:00', '06:00', true, 10), 'overnight: before the start');
        $this->assertSame(L::LATE, L::classify($t('2026-09-03 00:30:00'), '22:00', '06:00', true, 10), 'overnight: after midnight counts against the evening before');
        $this->assertSame(L::ON_TIME, L::classify($t('2026-09-03 07:00:00'), '22:00', '06:00', true, 10), 'overnight: after the shift end, early for tonight');
    }

    /**
     * A Sign In made in the app gets its work day from ShiftDay, so the reports (work day) and the
     * dashboard (time only) agree for every such Sign In.
     * @dataProvider shifts
     */
    public function testReportsAndDashboardAgreeForSignInsMadeInTheApp(array $cfg, int $grace): void
    {
        $from = strtotime('2026-09-02 00:00:00');
        $overnight = !empty($cfg['overnight']) && $cfg['start'] > $cfg['end'];
        for ($ts = $from; $ts < $from + 2 * 86400; $ts += 300) {
            $workDay = ShiftDay::resolve(date('Y-m-d', $ts), date('H:i', $ts), $cfg['start'], $cfg['end'], $overnight, false);
            $report = L::isLate($ts, L::shiftStart($workDay, $cfg['start']), $grace);
            $this->assertSame(self::legacyDayMetricsLate($ts, $workDay, $cfg['start'], $grace), $report, 'reports unchanged at ' . date('Y-m-d H:i', $ts));
            $this->assertSame($report ? L::LATE : L::ON_TIME, L::classify($ts, $cfg['start'], $cfg['end'], (bool) $cfg['overnight'], $grace), 'dashboard = reports at ' . date('Y-m-d H:i', $ts));
        }
    }

    public function testDayMetricsUsesTheRule(): void
    {
        $base = ['date' => '2026-09-02', 'today' => '2026-09-03', 'now' => '2026-09-03 12:00:00', 'holiday' => null, 'planned' => 'Office', 'rule' => 'attendance', 'requires_sign_in' => true,
            'shift_start' => '08:00', 'shift_end' => '16:00', 'normal_until' => '10:00', 'grace' => 10, 'overnight' => false, 'break_allowance' => 0,
            'first_in_legacy_late' => false, 'last_out' => '2026-09-02 16:00:00', 'break_minutes' => 0];
        $this->assertSame('Present', DayMetrics::compute(['first_in' => '2026-09-02 08:10:00'] + $base)['result'], 'exactly at the end of the grace');
        $late = DayMetrics::compute(['first_in' => '2026-09-02 08:10:01'] + $base);
        $this->assertSame(['Late', 10], [$late['result'], $late['late_minutes']], 'late minutes count from the shift start');
        $this->assertSame('Absent', DayMetrics::compute(['first_in' => null, 'last_out' => null] + $base)['result'], 'missing Sign In');
        $this->assertSame('Late', DayMetrics::compute(['first_in' => '2026-09-02 07:50:00', 'first_in_legacy_late' => true] + $base)['result'], 'a stored late Sign In stays late');
        $this->assertSame('Leave', DayMetrics::compute(['first_in' => null, 'last_out' => null, 'planned' => 'Vacation', 'rule' => 'leave', 'requires_sign_in' => false] + $base)['result'], 'leave is not late or absent');
        $wfh = DayMetrics::compute(['first_in' => '2026-09-02 08:30:00', 'planned' => 'WFH'] + $base);
        $this->assertSame('Late', $wfh['result'], 'WFH days follow the same rule');
        $night = DayMetrics::compute(['date' => '2026-09-02', 'shift_start' => '22:00', 'shift_end' => '06:00', 'normal_until' => '23:59', 'overnight' => true, 'first_in' => '2026-09-03 00:30:00', 'last_out' => '2026-09-03 06:00:00'] + $base);
        $this->assertSame(['Late', 150], [$night['result'], $night['late_minutes']], 'overnight Sign In after midnight');
    }
}
