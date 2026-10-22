<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\ShiftPeriods as P;

/** Shift history (3.31.108): which period and which times apply on a day, and where a new period goes. */
final class ShiftPeriodsTest extends TestCase
{
    private function periods(): array
    {
        return [
            ['id' => 1, 'from' => '2000-01-01', 'to' => '2026-02-28', 'shift_id' => 0],
            ['id' => 2, 'from' => '2026-03-01', 'to' => '2026-10-31', 'shift_id' => 101],
            ['id' => 3, 'from' => '2026-11-01', 'to' => null, 'shift_id' => 102],
        ];
    }

    public function testOn(): void
    {
        $this->assertSame(0, P::on($this->periods(), '2026-01-10')['shift_id']);
        $this->assertSame(101, P::on($this->periods(), '2026-03-01')['shift_id'], 'first day');
        $this->assertSame(101, P::on($this->periods(), '2026-10-31')['shift_id'], 'last day');
        $this->assertSame(102, P::on($this->periods(), '2030-01-01')['shift_id'], 'the open one');
        $this->assertNull(P::on($this->periods(), '1999-12-31'));
    }

    public function testVersion(): void
    {
        $v = [['from' => '2000-01-01', 'start' => '09:00'], ['from' => '2026-11-01', 'start' => '08:30']];
        $this->assertSame('09:00', P::version($v, '2026-10-31')['start']);
        $this->assertSame('08:30', P::version($v, '2026-11-01')['start']);
        $this->assertNull(P::version($v, '1990-01-01'));
    }

    public function testInsertAtTheEnd(): void
    {
        $this->assertSame(['replace' => 0, 'close' => 3, 'close_to' => '2026-11-30', 'to' => null], P::insert($this->periods(), '2026-12-01'));
    }

    public function testInsertInTheMiddleEndsBeforeTheNextOne(): void
    {
        $this->assertSame(['replace' => 0, 'close' => 2, 'close_to' => '2026-05-31', 'to' => '2026-10-31'], P::insert($this->periods(), '2026-06-01'));
    }

    public function testInsertOnTheSameDayReplaces(): void
    {
        $this->assertSame(3, P::insert($this->periods(), '2026-11-01')['replace']);
    }

    public function testAfterRemove(): void
    {
        $this->assertSame(['extend' => 2, 'to' => null], P::afterRemove($this->periods(), 3));
        $this->assertSame(['extend' => 1, 'to' => '2026-10-31'], P::afterRemove($this->periods(), 2));
    }

    public function testCheck(): void
    {
        $this->assertNull(P::check('2026-10-17', '2026-10-17', false, true));
        $this->assertNull(P::check('2026-11-01', '2026-10-17', false, true), 'a later day: anyone who manages employees');
        $this->assertSame('past', P::check('2026-10-16', '2026-10-17', false, true));
        $this->assertNull(P::check('2026-10-16', '2026-10-17', true, true), 'HR fixes a mistake');
        $this->assertSame('date', P::check('2026-02-30', '2026-10-17', true, true));
        $this->assertSame('date', P::check('1999-12-31', '2026-10-17', true, true));
        $this->assertSame('shift', P::check('2026-11-01', '2026-10-17', true, false));
    }

    public function testIsLatest(): void
    {
        $p = $this->periods();
        $this->assertTrue(P::isLatest($p, $p[2]));
        $this->assertFalse(P::isLatest($p, $p[1]), 'a later period exists: this one is history');
    }

    public function testTimesChanged(): void
    {
        $a = ['start' => '09:00', 'end' => '17:00', 'grace' => 10, 'sign_in_cutoff_minutes' => 240, 'overnight' => 0, 'name' => 'Morning'];
        $this->assertFalse(P::timesChanged($a, ['name' => 'Renamed'] + $a), 'a new name is not new times');
        $this->assertTrue(P::timesChanged($a, ['grace' => 15] + $a));
    }

    /** 3.31.116: what is done with the dated times before new ones are saved (docs/tasks/payroll-integrity.md). */
    public function testSettle(): void
    {
        $t = ['start' => '09:00', 'end' => '17:00', 'grace' => 10, 'sign_in_cutoff_minutes' => 240, 'overnight' => 0];
        $this->assertSame(['action' => 'beginning'], P::settle([], '2026-10-21', $t), 'no history yet: the live times from the start');
        $v = [['from' => P::BEGINNING] + $t, ['from' => '2026-09-01'] + $t];
        $this->assertSame(['action' => 'none'], P::settle($v, '2026-10-21', $t), 'the newest version already has the live values');
        $this->assertSame(['action' => 'rewrite', 'from' => '2026-09-01'], P::settle($v, '2026-10-21', ['grace' => 20] + $t), 'changed without a date (an import): the newest version keeps what it counts with today');
        $this->assertSame(['action' => 'none'], P::settle($v, '2026-09-01', ['grace' => 20] + $t), 'saved again on the same day: that version is replaced');
        $this->assertSame(['action' => 'later', 'from' => '2026-09-01'], P::settle($v, '2026-08-15', $t), 'a change already saved for a later day');
        $this->assertTrue(P::timesChanged($t, ['grace' => 15] + $t), 'grace alone is a change');
        $this->assertTrue(P::timesChanged($t, ['overnight' => 1] + $t), 'overnight alone is a change');
    }

    /** 3.31.119: the upgrade check finds the periods a half-done save left. */
    public function testProblems(): void
    {
        $p = function ($from, $to) { return ['from' => $from, 'to' => $to]; };
        $this->assertSame([], P::problems([]));
        $this->assertSame([], P::problems([$p('2000-01-01', '2026-03-31'), $p('2026-04-01', null)]));
        $this->assertSame(['last_closed'], P::problems([$p('2000-01-01', '2026-03-31')]), 'the new period was never written');
        $this->assertSame(['gap'], P::problems([$p('2000-01-01', '2026-03-30'), $p('2026-04-01', null)]));
        $this->assertSame(['overlap'], P::problems([$p('2000-01-01', null), $p('2026-04-01', null)]));
        $this->assertSame(['overlap'], P::problems([$p('2000-01-01', '2026-04-05'), $p('2026-04-01', null)]));
        $this->assertSame(['same_start'], P::problems([$p('2000-01-01', '2026-03-31'), $p('2026-04-01', null), $p('2026-04-01', null)]));
        $this->assertSame([], P::problems([$p('2026-04-01', null), $p('2000-01-01', '2026-03-31')]), 'any order');
    }
}
