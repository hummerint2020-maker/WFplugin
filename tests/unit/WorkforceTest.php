<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\Workforce as W;

final class WorkforceTest extends TestCase
{
    public function testEveryTypeHolidaysAndAbsences(): void
    {
        $d = static function ($planned, $rule, $result, $date = '2026-09-30') { return ['date' => $date, 'planned' => $planned, 'rule' => $rule, 'result' => $result]; };
        $by = W::byDay([
            $d('Office', 'attendance', 'Present'), $d('Office', 'attendance', 'Absent'), $d('WFH', 'attendance', 'Late'),
            $d('Vacation', 'leave', 'Leave'), $d('Training Course', 'business_trip', 'Training Course'), $d('Field Visit', 'attendance', 'Present'),
            $d('Not Set', null, 'Not Scheduled'), $d('Office', 'attendance', 'Holiday', '2026-10-01'),
        ]);
        $this->assertSame(['office' => 2, 'wfh' => 1, 'leave' => 1, 'trip' => 1, 'other' => 1, 'not_set' => 1, 'holiday' => 0, 'absent' => 1], $by['2026-09-30']);
        $this->assertSame(1, $by['2026-10-01']['holiday']);
        $this->assertSame(0, $by['2026-10-01']['office']);
        $this->assertSame(2, W::totals($by)['office']);
    }
}
