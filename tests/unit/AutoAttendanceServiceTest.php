<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\AutoAttendanceService;
use WorkforceOne\Tests\Fakes\RecordsWorld;

require_once __DIR__ . '/Fakes/RecordsWorld.php';

/** AutoAttendanceService (3.31.138): the tick, one automatic event, the bulk Sign Out and the rules' saves, in the order the old trait ran them. */
final class AutoAttendanceServiceTest extends TestCase
{
    /** @var RecordsWorld */
    private $w;
    private const DAY = '2026-10-30';

    protected function setUp(): void
    {
        $this->w = new RecordsWorld();
        $this->w->employee(7, 'Omar', 70);
        $this->w->days->add(7, self::DAY, 'Office');
    }

    private function svc(): AutoAttendanceService
    {
        $w = $this->w;
        return new AutoAttendanceService($w->rules, $w->logs, $w->breaks, $w->lock(), $w->dayService(), $w->peoplePort(), $w->site(), $w->auditPort(), $w->clock());
    }

    private function sch(): object { return (object) ['status' => 'Office']; }

    public function testAnAutomaticSignInIsInsertedUnderTheLockChecksTheFirstRowAndIsAudited(): void
    {
        $r = $this->svc()->event(7, self::DAY, 'sign_in', '09:00', $this->sch(), 4);
        $this->assertSame(['ok' => true, 'id' => 100], $r);
        $this->assertSame(['lock 7 5', 'attendance? 7', 'unlock 7'], $this->w->log);
        $this->assertSame(['insertAuto sign_in', 'firstEventId sign_in,late_sign_in'], array_values(array_filter($this->w->logs->calls, function ($c) { return strpos($c, 'liveDay') !== 0; })));
        $this->assertSame([['auto_sign_in', 'time_log', 100, 'Omar / Office / 2026-10-30 09:00:00 / source=Auto / rule=4']], $this->w->audits);
        $row = $this->w->logs->rows[0];
        $this->assertSame('70', (string) $row->user_id);
        $this->assertSame('auto', $row->source);
        $this->assertNull($row->latitude);
    }

    public function testEveryReasonAnEventIsRefusedForAndTheLockIsGivenBack(): void
    {
        $this->w->logs->add(1, 7, self::DAY, 'sign_in', self::DAY . ' 09:30:00');
        $this->assertSame('already_signed_in', $this->svc()->event(7, self::DAY, 'sign_in', '09:00', $this->sch(), 1)['reason']);
        $this->assertSame('sign_in_after_target', $this->svc()->event(7, self::DAY, 'sign_out', '09:00', $this->sch(), 1)['reason']);
        $this->w->logs->add(2, 7, self::DAY, 'sign_out', self::DAY . ' 17:00:00');
        $this->assertSame('already_signed_out', $this->svc()->event(7, self::DAY, 'sign_out', '18:00', $this->sch(), 1)['reason']);
        $this->assertSame('no_sign_in', $this->svc()->event(8, self::DAY, 'sign_out', '18:00', $this->sch(), 1)['reason']);
        $this->assertSame('employee_missing', $this->svc()->event(8, self::DAY, 'sign_in', '09:00', $this->sch(), 1)['reason']);
        $this->w->employee(9, 'Off');
        $this->w->attendanceOff[9] = true;
        $this->assertSame('attendance_disabled', $this->svc()->event(9, self::DAY, 'sign_in', '09:00', $this->sch(), 1)['reason']);
        $locks = count(array_filter($this->w->log, function ($l) { return strpos($l, 'lock ') === 0; }));
        $unlocks = count(array_filter($this->w->log, function ($l) { return strpos($l, 'unlock ') === 0; }));
        $this->assertSame($locks, $unlocks);
        $this->assertSame(6, $locks);
        $this->assertSame([], $this->w->audits);
    }

    public function testAnOvernightSignOutIsRecordedOnTheNextCalendarDayOfTheShiftDay(): void
    {
        $this->w->logs->add(1, 7, '2026-10-29', 'sign_in', '2026-10-29 22:00:00');
        $r = $this->svc()->event(7, '2026-10-29', 'sign_out', '06:00', $this->sch(), 3, self::DAY);
        $this->assertTrue($r['ok']);
        $out = array_values(array_filter($this->w->logs->rows, function ($x) { return $x->event_type === 'sign_out'; }))[0];
        $this->assertSame('2026-10-29', $out->work_date);
        $this->assertSame('2026-10-30 06:00:00', $out->event_at);
    }

    public function testAFailedInsertIsAuditedWithTheDatabaseError(): void
    {
        $this->w->logs->failAuto = true;
        $this->w->logs->failInsert = true;
        $r = $this->svc()->event(7, self::DAY, 'sign_in', '09:00', $this->sch(), 5);
        $this->assertSame('db_error', $r['reason']);
        $this->assertSame([['auto_sign_in_failed', 'time_log', 0, 'Omar / Rule #5 / Table is full']], $this->w->audits);
    }

    public function testAnEarlierRowThatSlippedInMakesThisOneRemoveItself(): void
    {
        $this->w->logs->add(5, 7, self::DAY, 'sign_in', self::DAY . ' 08:59:59');
        $this->w->logs->add(6, 7, self::DAY, 'sign_out', self::DAY . ' 09:00:00', ['corrects_id' => '5']);   // row 5 is replaced: hidden from the live check, still seen by the id check
        $r = $this->svc()->event(7, self::DAY, 'sign_in', '09:00', $this->sch(), 1);
        $this->assertSame('already_signed_in', $r['reason']);
        $this->assertContains('delete 100', $this->w->logs->calls);
        $this->assertSame([], array_filter($this->w->logs->rows, function ($row) { return (int) $row->id === 100; }));
        $this->assertSame([], $this->w->audits);
    }

    public function testABusyLockGoesOnUnlockedAndIsNeverReleased(): void
    {
        $this->w->lockBusy = true;
        $this->assertTrue($this->svc()->event(7, self::DAY, 'sign_in', '09:00', $this->sch(), 1)['ok']);
        $this->assertNotContains('unlock 7', $this->w->log);
    }

    public function testTheTickRecordsWhatIsDueAndDisablesAOneTimeRuleOnceItsActionsAreDone(): void
    {
        $this->w->rules->add(7, 'one_time', self::DAY, '09:00', '17:00');
        $this->svc()->tick(self::DAY, '2026-10-29', '17:30');
        $types = array_map(function ($r) { return $r->event_type; }, $this->w->logs->rows);
        $this->assertSame(['sign_in', 'sign_out'], $types);
        $this->assertSame('0', $this->w->rules->rows[1]->enabled);
        $this->assertSame(['dueRules', 'disable 1'], $this->w->rules->calls);
    }

    public function testTheTickKeepsAOneTimeRuleWhoseTimeHasNotComeAndOneOnALeaveDay(): void
    {
        $this->w->rules->add(7, 'one_time', self::DAY, '23:00', null);
        $this->w->employee(8, 'Leave');
        $this->w->days->add(8, self::DAY, 'Leave');
        $this->w->rules->add(8, 'one_time', self::DAY, '09:00', null);
        $this->svc()->tick(self::DAY, '2026-10-29', '10:00');
        $this->assertSame(['1' => '1', '2' => '1'], ['1' => $this->w->rules->rows[1]->enabled, '2' => $this->w->rules->rows[2]->enabled]);
        $this->assertSame([], $this->w->logs->rows);
    }

    public function testTheTickSkipsAHolidayAndAnEmployeeWithNoPlan(): void
    {
        $this->w->holidays[self::DAY] = true;
        $this->w->rules->add(7, 'weekly', null, '09:00', null);
        $this->w->employee(9, 'NoPlan');
        $this->w->rules->add(9, 'weekly', null, '09:00', null);
        $this->svc()->tick(self::DAY, '2026-10-29', '10:00');
        $this->assertSame([], $this->w->logs->rows);
    }

    public function testBulkSignOutClosesAnOpenBreakAndCountsEveryCase(): void
    {
        $this->w->logs->add(1, 7, self::DAY, 'sign_in', self::DAY . ' 08:00:00', ['user_id' => '70', 'scheduled_status' => 'Office']);
        $this->w->breaks->add(1, 7, self::DAY, 'Open');
        $this->w->breaks->rows[0]->start_at = self::DAY . ' 09:00:00';
        $this->w->employee(8, 'Off');
        $this->w->attendanceOff[8] = true;
        $this->w->logs->add(2, 8, self::DAY, 'sign_in', self::DAY . ' 08:00:00', ['user_id' => '0', 'scheduled_status' => 'Office']);
        $r = $this->svc()->bulkSignOut(self::DAY, self::DAY . ' 17:00:00');
        $this->assertSame(['signed_out' => 1, 'skipped' => 1, 'failed' => 0], $r);
        $this->assertContains('clearBreakEvents 1', $this->w->log);
        $this->assertContains('lock 7 2', $this->w->log);
        $this->assertSame('Completed', $this->w->breaks->rows[0]->status);
        $this->assertSame('480', (string) $this->w->breaks->rows[0]->actual_minutes);
        $signOut = array_values(array_filter($this->w->logs->rows, function ($r) { return $r->event_type === 'sign_out'; }))[0];
        $this->assertSame([self::DAY . ' 17:00:00', self::DAY . ' 17:00:00', '70', 'Office', 'auto'], [$signOut->event_at, $signOut->created_at, (string) $signOut->user_id, $signOut->scheduled_status, $signOut->source]);
        $actions = array_map(function ($a) { return $a[0]; }, $this->w->audits);
        $this->assertSame(['auto_sign_out', 'break_auto_closed', 'auto_sign_out_bulk'], $actions);
        $this->assertSame('Date=2026-10-30 / signed_out=1 / skipped=1 / failed=0', $this->w->audits[2][3]);
    }

    public function testBulkSignOutLeavesAnOpenBreakWhenBreaksAreOff(): void
    {
        $this->w->breaksOn = false;
        $this->w->logs->add(1, 7, self::DAY, 'sign_in', self::DAY . ' 08:00:00', ['user_id' => '70', 'scheduled_status' => 'Office']);
        $this->w->breaks->add(1, 7, self::DAY, 'Open');
        $this->svc()->bulkSignOut(self::DAY, self::DAY . ' 17:00:00');
        $this->assertSame('Open', $this->w->breaks->rows[0]->status);
    }

    public function testBulkSignOutCountsAFailedInsertAndAMissingSignInRow(): void
    {
        $this->w->logs->add(1, 7, self::DAY, 'sign_in', self::DAY . ' 08:00:00', ['user_id' => '70', 'scheduled_status' => 'Office']);
        $this->w->logs->failAuto = true;
        $r = $this->svc()->bulkSignOut(self::DAY, self::DAY . ' 17:00:00');
        $this->assertSame(['signed_out' => 0, 'skipped' => 0, 'failed' => 1], $r);
        $this->assertSame(['lock 7 2', 'unlock 7'], array_values(array_filter($this->w->log, function ($l) { return strpos($l, 'lock') !== false; })));
    }

    public function testSavingARuleInsertsOrUpdatesAndAuditsOnlyAfterAGoodWrite(): void
    {
        $r = $this->svc()->saveRule(0, ['employee_id' => 7, 'enabled' => 1], ['created_by' => 1, 'created_at' => 'T'], 'Employee #7');
        $this->assertSame(['ok' => true, 'id' => 1, 'error' => ''], $r);
        $this->assertSame('T', $this->w->rules->rows[1]->created_at);
        $this->assertSame(['auto_attendance_rule_create', 'auto_attendance_rule', 1, 'Employee #7'], $this->w->audits[0]);
        $this->svc()->saveRule(1, ['enabled' => 0], null, 'again');
        $this->assertSame('auto_attendance_rule_update', $this->w->audits[1][0]);
        $this->w->rules->fail = true;
        $bad = $this->svc()->saveRule(0, ['employee_id' => 7], ['created_by' => 1, 'created_at' => 'T'], 'x');
        $this->assertSame(['ok' => false, 'id' => 0, 'error' => 'Disk full'], $bad);
        $this->assertCount(2, $this->w->audits);
    }

    public function testAnUpdateOfARuleThatIsGoneIsStillAuditedAsSaved(): void
    {
        $r = $this->svc()->saveRule(99, ['enabled' => 1], null, 'ghost');
        $this->assertTrue($r['ok']);
        $this->assertSame('auto_attendance_rule_update', $this->w->audits[0][0]);
    }

    public function testToggleAndDelete(): void
    {
        $id = $this->w->rules->add(7, 'weekly', null, '09:00', null);
        $this->assertSame(0, $this->svc()->toggleRule($id));
        $this->assertSame(1, $this->svc()->toggleRule($id));
        $this->assertNull($this->svc()->toggleRule(99));
        $this->svc()->deleteRule($id);
        $this->svc()->deleteRule($id);
        $actions = array_map(function ($a) { return $a[0] . ' ' . $a[3]; }, $this->w->audits);
        $this->assertSame(['auto_attendance_rule_toggle Enabled=no', 'auto_attendance_rule_toggle Enabled=yes', 'auto_attendance_rule_delete Rule deleted'], $actions);
    }
}
