<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\ConfigRules as C;

final class ScheduleConfigRulesTest extends TestCase
{
    public function testWorkingHours(): void
    {
        $this->assertNull(C::hoursError('08:00', '17:00', false));
        $this->assertSame('hours_invalid', C::hoursError('8am', '17:00', false));
        $this->assertSame('hours_invalid', C::hoursError('24:00', '17:00', false));
        $this->assertSame('hours_same', C::hoursError('08:00', '08:00', true));
        $this->assertSame('hours_overnight', C::hoursError('22:00', '06:00', false));
        $this->assertNull(C::hoursError('22:00', '06:00', true));
        $this->assertSame(180, C::graceMinutes(400));
        $this->assertSame(0, C::graceMinutes(-5));
    }

    public function testWorkingDays(): void
    {
        $this->assertSame([0, 1, 4], C::workingDays(['4', '1', '0', '1', '9', 'x', '-1']));
        $this->assertSame([], C::workingDays(null));
    }

    public function testShiftsKeepIdsAndNeverReuseRemovedOnes(): void
    {
        [$s, $e] = C::shifts([['id' => 1, 'name' => 'Day', 'start' => '08:00', 'end' => '16:00', 'active' => 1], ['id' => 2, 'name' => '', 'start' => '16:00', 'end' => '23:00']],
            ['name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'overnight' => 1], 2);
        $this->assertNull($e);
        $this->assertSame([1, 3], array_column($s, 'id'), 'removed id 2 is not handed out again');
        $this->assertSame(1, $s[1]['overnight']);
    }

    public function testShiftErrors(): void
    {
        $day = ['id' => 1, 'name' => 'Day', 'start' => '08:00', 'end' => '16:00'];
        $this->assertSame('shift_overnight', C::shifts([['id' => 1, 'name' => 'N', 'start' => '22:00', 'end' => '06:00']], null, 1)[1]);
        $this->assertSame('shift_duplicate', C::shifts([$day], ['name' => 'day', 'start' => '09:00', 'end' => '10:00'], 1)[1]);
        $this->assertSame('shift_invalid', C::shifts([$day], ['name' => 'X', 'start' => '9', 'end' => '10:00'], 1)[1]);
        $this->assertSame('shifts_empty', C::shifts([], null, 1)[1]);
        $this->assertNull(C::shifts([$day], ['name' => '', 'start' => '', 'end' => ''], 1)[1], 'an empty new row is ignored');
    }

    public function testTypesProtectCoreAndUsedTypes(): void
    {
        $prev = [['name' => 'Office'], ['name' => 'Lab']];
        $rows = [['name' => 'Office', 'active' => 1], ['name' => 'Lab', 'active' => 1]];
        $this->assertNull(C::types($rows, $prev, ['Lab'])[1]);
        $this->assertSame('type_core', C::types([['name' => 'Onsite'], $rows[1]], $prev, [])[1]);
        $this->assertSame('type_core', C::types([['name' => ''], $rows[1]], $prev, [])[1]);
        $this->assertSame('type_in_use', C::types([$rows[0], ['name' => '']], $prev, ['Lab'])[1]);
        $this->assertSame('type_in_use', C::types([$rows[0], ['name' => 'Lab 2']], $prev, ['Lab'])[1]);
        [$t, $e] = C::types([$rows[0], ['name' => '']], $prev, []);
        $this->assertNull($e);
        $this->assertSame(['Office'], array_column($t, 'name'), 'an unused type can be removed');
        $this->assertSame('type_duplicate', C::types($rows + ['new' => ['name' => 'office']], $prev, [])[1]);
    }

    public function testTypeStyleDefaults(): void
    {
        [$t] = C::types(['new' => ['name' => 'Office', 'bg_color' => 'red', 'attendance_rule' => 'bogus', 'icon' => '']], [], []);
        $this->assertSame('#dcfce7', $t[0]['bg_color']);
        $this->assertSame('🏢', $t[0]['icon']);
        $this->assertSame('attendance', $t[0]['attendance_rule']);
    }

    public function testMessages(): void
    {
        $this->assertSame('Working days saved.', C::noticeMessage('days_saved'));
        $this->assertNull(C::noticeMessage('x'));
        $this->assertStringContainsString('Overnight', C::errorMessage('hours_overnight'));
    }
}
