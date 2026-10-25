<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\CardLists as C;
use WorkforceOne\Attendance\Lateness as L;

/** The lists behind the Attendance page's cards (3.31.125, docs/tasks/attendance-cards.md). */
final class AttendanceCardsTest extends TestCase
{
    private static function t(string $at): int { return (int) strtotime($at); }

    public function testMinutesLateCountFromTheShiftStart(): void
    {
        $this->assertSame(0, L::minutesLate(self::t('2026-10-18 08:55'), '09:00', '17:00', false), 'early is 0, not negative');
        $this->assertSame(0, L::minutesLate(self::t('2026-10-18 09:00'), '09:00', '17:00', false));
        $this->assertSame(10, L::minutesLate(self::t('2026-10-18 09:10'), '09:00', '17:00', false), 'the end of the grace is 10 minutes, not 0');
        $this->assertSame(23, L::minutesLate(self::t('2026-10-18 09:23'), '09:00', '17:00', false));
        $this->assertSame(62, L::minutesLate(self::t('2026-10-18 10:02:59'), '09:00', '17:00', false), 'whole minutes');
    }

    public function testMinutesLateOnAnOvernightShift(): void
    {
        $this->assertSame(35, L::minutesLate(self::t('2026-10-18 22:35'), '22:00', '06:00', true));
        $this->assertSame(135, L::minutesLate(self::t('2026-10-19 00:15'), '22:00', '06:00', true), 'after midnight: against the shift that started the evening before');
        $this->assertSame(0, L::minutesLate(self::t('2026-10-18 21:58'), '22:00', '06:00', true));
        $this->assertSame(L::LATE, L::classify(self::t('2026-10-19 00:15'), '22:00', '06:00', true, 10), 'and the same Sign In is Late by the one rule');
        $this->assertSame(480, L::minutesLate(self::t('2026-10-19 06:00'), '22:00', '06:00', true), 'exactly at the end: the shift of the evening before');
        $this->assertSame(0, L::minutesLate(self::t('2026-10-19 06:01'), '22:00', '06:00', true), 'after the end: the next shift, not started');
        $this->assertSame(5, L::minutesLate(self::t('2026-10-18 09:05'), '09:00', '09:00', false), 'start = end (not overnight)');
    }

    public function testEachListTakesItsOwnResult(): void
    {
        $this->assertTrue(C::accepts('present', 'Present'));
        $this->assertFalse(C::accepts('present', 'Late'), 'Present is on time only; late days have their own card');
        $this->assertTrue(C::accepts('late', 'Late'));
        $this->assertTrue(C::accepts('absent', 'Absent'));
        foreach (['Pending', 'Leave', 'Not Scheduled', 'Business Trip'] as $other) foreach (['present', 'late', 'absent'] as $k) $this->assertFalse(C::accepts($k, $other));
        $this->assertFalse(C::accepts('people', 'Present'));
        $this->assertFalse(C::accepts('rate', 'Present'));
    }

    public function testOrderByDayAndByEmployee(): void
    {
        $k = [['pos' => 2, 'date' => '2026-10-19', 'sort' => -23], ['pos' => 0, 'date' => '2026-10-18', 'sort' => -5], ['pos' => 1, 'date' => '2026-10-18', 'sort' => -40],
              ['pos' => 3, 'date' => '2026-10-18', 'sort' => -5], ['pos' => 0, 'date' => '2026-10-19', 'sort' => -62]];
        $day = array_map(function ($r) { return $r['date'] . '/' . $r['pos']; }, C::order($k, 'day'));
        $this->assertSame(['2026-10-18/1', '2026-10-18/0', '2026-10-18/3', '2026-10-19/0', '2026-10-19/2'], $day, 'by day, the most minutes late first, then the grid order');
        $emp = array_map(function ($r) { return $r['pos'] . '/' . $r['date']; }, C::order($k, 'employee'));
        $this->assertSame(['0/2026-10-18', '0/2026-10-19', '1/2026-10-18', '2/2026-10-19', '3/2026-10-18'], $emp);
    }

    public function testPaging(): void
    {
        $rows = range(1, 45);
        $p = C::page($rows, 0);
        $this->assertSame([20, 45, 0, 20], [count($p['rows']), $p['total'], $p['offset'], $p['next']]);
        $p = C::page($rows, 40);
        $this->assertSame([5, null, 41], [count($p['rows']), $p['next'], $p['rows'][0]]);
        $p = C::page($rows, 999);
        $this->assertSame([0, 45, null], [count($p['rows']), $p['offset'], $p['next']], 'past the end is clamped');
        $this->assertNull(C::page(range(1, 20), 0)['next'], 'exactly one page');
        $this->assertSame([0, 0, null], [C::page([], 0)['total'], count(C::page([], 0)['rows']), C::page([], 0)['next']]);
        $this->assertSame(0, C::page($rows, -5)['offset']);
    }

    public function testAPersonsWeekKeepsHolidaysApart(): void
    {
        $w = C::emptyWeek();
        foreach ([['Present', false], ['Late', false], ['Absent', false], ['Leave', false], ['Leave', true], ['Pending', false], ['Not Scheduled', false]] as [$a, $h]) $w = C::count($w, $a, $h);
        $this->assertSame(['present' => 1, 'late' => 1, 'absent' => 1, 'leave' => 1, 'holiday' => 1], $w);
    }

    public function testRateByDay(): void
    {
        $r = C::rateByDay(['2026-10-18' => ['present' => 30, 'late' => 8, 'absent' => 1], '2026-10-22' => ['present' => 0, 'late' => 0, 'absent' => 0]]);
        $this->assertSame(['date' => '2026-10-18', 'rate' => 97, 'attended' => 38, 'required' => 39], $r[0]);
        $this->assertNull($r[1]['rate'], 'a holiday has no rate');
    }

    public function testLowestPeople(): void
    {
        $people = [];
        $people[10] = ['pos' => 0, 'present' => 5, 'late' => 0, 'absent' => 0];   // 100%: not listed
        $people[11] = ['pos' => 1, 'present' => 2, 'late' => 1, 'absent' => 2];   // 60%
        $people[12] = ['pos' => 2, 'present' => 4, 'late' => 0, 'absent' => 1];   // 80%
        $people[13] = ['pos' => 3, 'present' => 0, 'late' => 0, 'absent' => 0];   // nothing required: not listed
        $people[14] = ['pos' => 4, 'present' => 3, 'late' => 0, 'absent' => 2];   // 60%, as many absent: grid order
        $people[15] = ['pos' => 5, 'present' => 1, 'late' => 0, 'absent' => 1];   // 50%
        $ids = array_column(C::lowest($people), 'employee_id');
        $this->assertSame([15, 11, 14, 12], $ids);
        $this->assertSame(['employee_id' => 11, 'pos' => 1, 'rate' => 60, 'attended' => 3, 'required' => 5], C::lowest($people)[1]);
        $many = [];
        for ($i = 0; $i < 30; $i++) $many[$i] = ['pos' => $i, 'present' => 1, 'late' => 0, 'absent' => 1];
        $this->assertCount(10, C::lowest($many));
        $two = [1 => ['pos' => 0, 'present' => 1, 'late' => 0, 'absent' => 1], 2 => ['pos' => 1, 'present' => 2, 'late' => 0, 'absent' => 2]];
        $this->assertSame([2, 1], array_column(C::lowest($two), 'employee_id'), 'on the same rate, more absent days first');
    }

    public function testNoSignOut(): void
    {
        $now = self::t('2026-10-25 12:00');
        $this->assertTrue(C::missingOut(true, false, '2026-10-24', '2026-10-25', $now, null), 'a past day: always');
        $this->assertFalse(C::missingOut(true, true, '2026-10-24', '2026-10-25', $now, null));
        $this->assertFalse(C::missingOut(false, false, '2026-10-24', '2026-10-25', $now, null), 'no Sign In: nothing to sign out of');
        $this->assertFalse(C::missingOut(true, false, '2026-10-25', '2026-10-25', $now, self::t('2026-10-25 17:00')), 'today, still in the shift');
        $this->assertTrue(C::missingOut(true, false, '2026-10-25', '2026-10-25', self::t('2026-10-25 17:00'), self::t('2026-10-25 17:00')), 'today, the shift has ended');
        $this->assertFalse(C::missingOut(true, false, '2026-10-25', '2026-10-25', $now, null));
        // Yesterday's night shift (22:00-06:00) at 03:00 today: still running, not "No sign-out" yet.
        $this->assertFalse(C::missingOut(true, false, '2026-10-24', '2026-10-25', self::t('2026-10-25 03:00'), self::t('2026-10-25 06:00')));
        $this->assertTrue(C::missingOut(true, false, '2026-10-24', '2026-10-25', self::t('2026-10-25 06:00'), self::t('2026-10-25 06:00')));
        $this->assertTrue(C::missingOut(true, false, '2026-10-20', '2026-10-25', $now, self::t('2026-10-20 17:00')));
    }

    public function testLateNote(): void
    {
        $this->assertSame(['start' => '09:00', 'late_after' => '09:10'], C::lateNote([['start' => '09:00', 'grace' => 10], ['start' => '09:00', 'grace' => 10]]));
        $this->assertNull(C::lateNote([['start' => '09:00', 'grace' => 10], ['start' => '22:00', 'grace' => 10]]));
        $this->assertNull(C::lateNote([['start' => '09:00', 'grace' => 10], ['start' => '09:00', 'grace' => 15]]));
        $this->assertNull(C::lateNote([]));
        $this->assertSame('00:05', C::lateNote([['start' => '23:55', 'grace' => 10]])['late_after']);
    }
}
