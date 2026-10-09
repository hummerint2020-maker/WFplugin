<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\CorrectionRules;
use WorkforceOne\Settings\CorrectionSettings;

final class CorrectionRulesTest extends TestCase
{
    /** @param array<string,mixed> $over */
    private function f(array $over = []): array
    {
        return $over + ['type' => 'out', 'target' => '', 'date' => '2026-10-07', 'today' => '2026-10-08', 'now' => '19:00', 'time_in' => '', 'time_out' => '17:30',
            'in' => '2026-10-07 08:52:00', 'out' => null, 'overnight' => false, 'source' => 'employee', 'settings' => CorrectionSettings::config([]),
            'pending' => false, 'month_count' => 1, 'reason' => 'Left after the customer meeting', 'has_photo' => false];
    }

    public function testForgotSignOut(): void
    {
        $this->assertSame('', CorrectionRules::check($this->f()));
        $p = CorrectionRules::plan($this->f());
        $this->assertSame([['type' => 'sign_out', 'at' => '2026-10-07 17:30:00']], $p['events']);
        $this->assertNull($p['supersede']);
        $this->assertFalse($p['needs_hr']);
        $this->assertSame('no_in', CorrectionRules::check($this->f(['in' => null])));
        $this->assertSame('has_out', CorrectionRules::check($this->f(['out' => '2026-10-07 17:00:00'])));
        $this->assertSame('order', CorrectionRules::check($this->f(['time_out' => '08:30'])));
        $this->assertSame('time', CorrectionRules::check($this->f(['time_out' => '25:00'])));
    }

    public function testOvernightSignOutGoesToTheNextDay(): void
    {
        $f = $this->f(['in' => '2026-10-07 22:00:00', 'time_out' => '06:00', 'overnight' => true]);
        $this->assertSame('', CorrectionRules::check($f));
        $this->assertSame('2026-10-08 06:00:00', CorrectionRules::plan($f)['events'][0]['at']);
    }

    public function testForgotSignInAndWholeDay(): void
    {
        $in = $this->f(['type' => 'in', 'in' => null, 'out' => '2026-10-07 17:05:00', 'time_in' => '08:45', 'time_out' => '']);
        $this->assertSame('', CorrectionRules::check($in));
        $this->assertSame([['type' => 'sign_in', 'at' => '2026-10-07 08:45:00']], CorrectionRules::plan($in)['events']);
        $this->assertSame('has_in', CorrectionRules::check($this->f(['type' => 'in', 'time_in' => '08:45'])));
        $this->assertSame('order', CorrectionRules::check(array_merge($in, ['time_in' => '18:00'])));
        $day = $this->f(['type' => 'day', 'in' => null, 'time_in' => '09:00', 'time_out' => '17:00', 'has_photo' => true]);
        $this->assertSame('', CorrectionRules::check($day));
        $this->assertSame(['sign_in', 'sign_out'], array_column(CorrectionRules::plan($day)['events'], 'type'));
        $this->assertSame('photo', CorrectionRules::check(array_merge($day, ['has_photo' => false])), 'the whole day needs a photo by default');
        $this->assertSame('has_events', CorrectionRules::check(array_merge($day, ['in' => '2026-10-07 09:00:00'])));
    }

    public function testWrongTimeSupersedesAndEarlierSignInNeedsHr(): void
    {
        $f = $this->f(['type' => 'time', 'target' => 'sign_in', 'in' => '2026-10-07 09:31:00', 'out' => '2026-10-07 17:05:00', 'time_in' => '08:55', 'time_out' => '']);
        $this->assertSame('', CorrectionRules::check($f));
        $p = CorrectionRules::plan($f);
        $this->assertSame('sign_in', $p['supersede']);
        $this->assertTrue($p['earlier']);
        $this->assertTrue($p['needs_hr']);
        $f['settings']['earlier_needs_hr'] = 0;
        $this->assertFalse(CorrectionRules::plan($f)['needs_hr']);
        $this->assertSame('same', CorrectionRules::check(array_merge($f, ['time_in' => '09:31'])));
        $this->assertSame('no_event', CorrectionRules::check(array_merge($f, ['target' => 'sign_out', 'out' => null, 'time_out' => '18:00'])));
        $later = CorrectionRules::plan(array_merge($f, ['time_in' => '09:45']));
        $this->assertFalse($later['earlier']);
    }

    public function testDeadlineFutureAndToday(): void
    {
        $this->assertSame('deadline', CorrectionRules::check($this->f(['date' => '2026-09-28', 'in' => '2026-09-28 08:52:00'])));
        $this->assertSame('', CorrectionRules::check($this->f(['date' => '2026-09-28', 'in' => '2026-09-28 08:52:00', 'source' => 'hr'])), 'HR has no deadline');
        $this->assertSame('future', CorrectionRules::check($this->f(['date' => '2026-10-09'])));
        $this->assertSame('future_time', CorrectionRules::check($this->f(['date' => '2026-10-08', 'in' => '2026-10-08 08:47:00', 'time_out' => '20:30'])));
        $this->assertSame('', CorrectionRules::check($this->f(['date' => '2026-10-08', 'in' => '2026-10-08 08:47:00', 'time_out' => '17:30'])));
        $this->assertSame('date', CorrectionRules::check($this->f(['date' => '2026-02-30'])));
    }

    public function testMonthlyLimitBothWays(): void
    {
        $f = $this->f(['month_count' => 3]);
        $this->assertSame('', CorrectionRules::check($f));
        $p = CorrectionRules::plan($f);
        $this->assertTrue($p['above_limit']);
        $this->assertTrue($p['needs_hr'], 'above the limit goes to HR by default');
        $f['settings']['above_limit'] = 'refuse';
        $this->assertSame('limit', CorrectionRules::check($f));
        $f['settings']['monthly_limit'] = 0;
        $this->assertSame('', CorrectionRules::check($f), '0 = no limit');
        $this->assertFalse(CorrectionRules::plan($f)['above_limit']);
    }

    public function testTypeOffReasonAndPending(): void
    {
        $f = $this->f();
        $f['settings']['types']['out'] = 0;
        $this->assertSame('type', CorrectionRules::check($f));
        $this->assertSame('reason', CorrectionRules::check($this->f(['reason' => '  '])));
        $this->assertSame('pending', CorrectionRules::check($this->f(['pending' => true])));
    }

    public function testSettings(): void
    {
        $c = CorrectionSettings::config(['deadline_days' => 999, 'monthly_limit' => -2, 'above_limit' => 'x', 'reminder_time' => '7pm', 'photo' => ['out' => 'required', 'in' => 'bad']]);
        $this->assertSame(60, $c['deadline_days']);
        $this->assertSame(0, $c['monthly_limit']);
        $this->assertSame('hr', $c['above_limit']);
        $this->assertSame('19:00', $c['reminder_time']);
        $this->assertSame('required', $c['photo']['out']);
        $this->assertSame('optional', $c['photo']['in']);
        [$s, $err] = CorrectionSettings::fromPost(['cx_type_out' => 1, 'cx_deadline_days' => '5', 'cx_monthly_limit' => '0', 'cx_reminder_time' => '18:30', 'cx_above_limit' => 'refuse']);
        $this->assertSame('', $err);
        $this->assertSame(['out' => 1, 'in' => 0, 'time' => 0, 'day' => 0], $s['types']);
        $this->assertSame('refuse', $s['above_limit']);
        $this->assertSame(0, $s['reminder']);
        $this->assertSame('deadline', CorrectionSettings::fromPost(['cx_type_out' => 1, 'cx_deadline_days' => '0', 'cx_monthly_limit' => '3', 'cx_reminder_time' => '18:30'])[1]);
        $this->assertSame('types', CorrectionSettings::fromPost(['cx_deadline_days' => '7', 'cx_monthly_limit' => '3', 'cx_reminder_time' => '18:30'])[1]);
    }

    public function testDayNoteOnlyCallsADayWithASignInOnTime(): void
    {
        $this->assertSame('on_time', CorrectionRules::dayNote('Present', true, 0));
        $this->assertSame('late', CorrectionRules::dayNote('Late', true, 120));
        $this->assertSame('late', CorrectionRules::dayNote('Late', true, 0));
        // No Sign In and nothing expected: say why, never "On time" (Thu 8 Oct, schedule Not Set).
        $this->assertSame('not_scheduled', CorrectionRules::dayNote('Not Scheduled', false, 0));
        $this->assertSame('leave', CorrectionRules::dayNote('Leave', false, 0));
        $this->assertSame('holiday', CorrectionRules::dayNote('Holiday', false, 0));
        $this->assertSame('day_off', CorrectionRules::dayNote('Off Day', true, 0));
        $this->assertSame('pending', CorrectionRules::dayNote('Pending', false, 0));
        $this->assertSame('absent', CorrectionRules::dayNote('Absent', false, 0));
        $this->assertSame('planned', CorrectionRules::dayNote('Business Trip', false, 0));
        $this->assertSame('not_scheduled', CorrectionRules::dayNote('', false, 0));
    }
}
