<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Payroll\PayCalculator as C;
use WorkforceOne\Payroll\PayRules as R;

final class PayrollTest extends TestCase
{
    private const RULES = ['day_divisor' => 30, 'day_base' => 'gross', 'absence_days' => 1.0, 'overtime_rate' => 1.35, 'overtime_rate_off' => 2.0, 'max_deduction_days' => 0.0];
    private const RATE = ['basic' => 9000.0, 'allowances' => [['name' => 'Transport', 'amount' => 600.0], ['name' => 'Meals', 'amount' => 900.0]], 'effective_from' => '2026-01-01'];

    /** @param array<string,mixed> $f */
    private static function day(string $date, array $f = []): array
    {
        return $f + ['date' => $date, 'result' => 'Present', 'bucket' => 'Present', 'expected' => true, 'late_minutes' => 0, 'early_minutes' => 0,
            'net_minutes' => 480, 'missing_sign_out' => false, 'ot_actual' => 0, 'approved_early' => 0, 'sign_in' => '08:00', 'sign_out' => '16:00'];
    }

    public function testAFullMonthWithEveryKindOfDay(): void
    {
        $days = [
            self::day('2026-09-01'),
            self::day('2026-09-02', ['result' => 'Absent', 'bucket' => 'Absent', 'net_minutes' => 0]),
            self::day('2026-09-03', ['result' => 'Late', 'late_minutes' => 12, 'sign_in' => '08:12']),
            self::day('2026-09-06', ['early_minutes' => 20]),
            self::day('2026-09-07', ['early_minutes' => 30, 'approved_early' => 30]),
            self::day('2026-09-08', ['missing_sign_out' => true, 'net_minutes' => 0]),
            self::day('2026-09-09', ['result' => 'Leave', 'bucket' => 'Leave', 'expected' => false, 'net_minutes' => 0, 'leave_type' => 'Unpaid Leave', 'leave_paid' => 0]),
            self::day('2026-09-10', ['result' => 'Leave', 'bucket' => 'Leave', 'expected' => false, 'net_minutes' => 0, 'leave_type' => 'Sick Leave', 'leave_paid' => 50]),
            self::day('2026-09-13', ['result' => 'Leave', 'bucket' => 'Leave', 'expected' => false, 'net_minutes' => 0]),
            self::day('2026-09-14', ['ot_actual' => 90]),
            self::day('2026-09-11', ['result' => 'Off Day', 'bucket' => 'Off Day', 'expected' => false, 'ot_actual' => 240]),
        ];
        $p = C::month(self::RATE, self::RULES, $days, 480, '2026-09');
        $this->assertSame(350.0, $p['day_value']);
        $this->assertSame(10500.0, $p['earned']);
        $this->assertSame(350.0, $p['absence']['amount']);
        $this->assertSame([['date' => '2026-09-03', 'minutes' => 12, 'sign_in' => '08:12', 'amount' => 8.75]], $p['late']['days']);
        $this->assertSame(14.58, $p['early']['amount'], 'approved early leave is not deducted');
        $this->assertSame(['Unpaid Leave', 'Sick Leave'], array_keys($p['leave']['types']), 'leave without a request (planned) is paid');
        $this->assertSame(525.0, $p['leave']['amount']);
        $this->assertSame([88.59, 350.0], array_column($p['overtime']['days'], 'amount'), '× 1.35 on work days, × 2 on a day off');
        $this->assertSame([['date' => '2026-09-08', 'reason' => 'No Sign Out']], $p['review']);
        $this->assertSame(round(10500 + 438.59 - 350 - 8.75 - 14.58 - 525, 2), $p['net']);
        $this->assertSame(1, $p['stats']['absent_days']);
    }

    public function testRulesChangeTheFigures(): void
    {
        $days = [self::day('2026-09-02', ['result' => 'Absent', 'bucket' => 'Absent'])];
        $basicOnly = C::month(self::RATE, ['day_base' => 'basic'] + self::RULES, $days, 480, '2026-09');
        $this->assertSame(300.0, $basicOnly['absence']['amount'], 'a day\'s pay from the basic salary only');
        $twoDays = C::month(self::RATE, ['absence_days' => 2.0] + self::RULES, $days, 480, '2026-09');
        $this->assertSame(700.0, $twoDays['absence']['amount']);
        $by26 = C::month(self::RATE, ['day_divisor' => 26] + self::RULES, $days, 480, '2026-09');
        $this->assertSame(403.85, $by26['absence']['amount']);
    }

    public function testDeductionsCanBeCapped(): void
    {
        $days = [self::day('2026-09-02', ['result' => 'Absent']), self::day('2026-09-03', ['result' => 'Absent'])];
        $p = C::month(self::RATE, ['max_deduction_days' => 1.0] + self::RULES, $days, 480, '2026-09');
        $this->assertSame(700.0, $p['deductions_before_cap']);
        $this->assertSame(350.0, $p['deductions']);
        $this->assertSame(10150.0, $p['net']);
    }

    public function testAFirstSalaryStartingMidMonthPaysFromItsStart(): void
    {
        $rate = ['basic' => 6000.0, 'allowances' => [], 'effective_from' => '2026-09-16'];
        $days = [self::day('2026-09-02', ['result' => 'Absent']), self::day('2026-09-17', ['result' => 'Late', 'late_minutes' => 15])];
        $p = C::month($rate, self::RULES, $days, 480, '2026-09');
        $this->assertSame(['from' => '2026-09-16', 'days' => 15, 'amount' => 3000.0], $p['prorated']);
        $this->assertSame([], $p['absence']['days'], 'days before the salary started are not counted');
        $this->assertSame(1, count($p['late']['days']));
        $this->assertSame(6000.0, C::month(['effective_from' => '2026-09-01'] + $rate, self::RULES, [], 480, '2026-09')['earned']);
        $feb = C::month(['basic' => 3000.0, 'allowances' => [], 'effective_from' => '2026-01-01'], self::RULES, [], 480, '2026-02');
        $this->assertSame(3000.0, $feb['earned'], 'a full month is the monthly pay, whatever its length');
    }

    public function testBonusesAndManualDeductionsAreOutsideTheCap(): void
    {
        $days = [self::day('2026-09-02', ['result' => 'Absent'])];
        $adj = [['id' => 1, 'kind' => 'bonus', 'amount' => 500.0, 'reason' => 'Project'], ['id' => 2, 'kind' => 'deduction', 'amount' => 100.0, 'reason' => 'Advance']];
        $p = C::month(self::RATE, ['max_deduction_days' => 0.5] + self::RULES, $days, 480, '2026-09', $adj);
        $this->assertSame(175.0, $p['deductions'], 'the cap applies to attendance');
        $this->assertSame(100.0, $p['manual']['amount']);
        $this->assertSame(275.0, $p['total_deductions']);
        $this->assertSame([['id' => 1, 'reason' => 'Project', 'amount' => 500.0]], $p['bonuses']['items']);
        $this->assertSame(10500.0 - 175 + 500 - 100, $p['net']);
        [$a, $e] = R::adjustment(['kind' => 'bonus', 'amount' => '50', 'reason' => ' Thanks ']);
        $this->assertSame(['kind' => 'bonus', 'amount' => 50.0, 'reason' => 'Thanks'], $a);
        $this->assertSame('adjust_amount', R::adjustment(['kind' => 'bonus', 'amount' => '0', 'reason' => 'x'])[1]);
        $this->assertSame('adjust_reason', R::adjustment(['kind' => 'deduction', 'amount' => '5', 'reason' => ' '])[1]);
        $this->assertSame('adjust_kind', R::adjustment(['kind' => 'gift', 'amount' => '5', 'reason' => 'x'])[1]);
    }

    public function testSalaryForm(): void
    {
        [$r, $e] = R::rate(['basic' => '9000', 'effective_from' => '2026-09-01', 'allowance_name' => ['Transport', '', 'Meals'], 'allowance_amount' => ['600', '', '900.5'], 'note' => 'x']);
        $this->assertNull($e);
        $this->assertSame([['name' => 'Transport', 'amount' => 600.0], ['name' => 'Meals', 'amount' => 900.5]], $r['allowances']);
        $this->assertSame('basic', R::rate(['basic' => '-1', 'effective_from' => '2026-09-01'])[1]);
        $this->assertSame('basic', R::rate(['basic' => 'abc', 'effective_from' => '2026-09-01'])[1]);
        $this->assertSame('date', R::rate(['basic' => '1', 'effective_from' => '2026-02-30'])[1]);
        $this->assertSame('allowance_name', R::rate(['basic' => '1', 'effective_from' => '2026-09-01', 'allowance_name' => [''], 'allowance_amount' => ['5']])[1]);
        $this->assertSame('allowance_amount', R::rate(['basic' => '1', 'effective_from' => '2026-09-01', 'allowance_name' => ['Bus'], 'allowance_amount' => ['-5']])[1]);
        $this->assertNotSame('', R::message('basic'));
    }

    public function testRulesForm(): void
    {
        $ok = ['currency' => 'egp', 'day_divisor' => '30', 'day_base' => 'gross', 'absence_days' => '1', 'overtime_rate' => '1.35', 'overtime_rate_off' => '2', 'max_deduction_days' => '0'];
        [$r, $e] = R::rules($ok);
        $this->assertNull($e);
        $this->assertSame('EGP', $r['currency']);
        foreach (['currency' => 'EG', 'day_divisor' => '0', 'day_base' => 'net', 'absence_days' => '9', 'overtime_rate' => '0.5', 'max_deduction_days' => '-1'] as $k => $bad) {
            $this->assertNotNull(R::rules([$k => $bad] + $ok)[1], $k);
        }
        $this->assertSame('day_divisor', array_keys(R::DEFAULTS)[1]);
        $this->assertSame('9,690.26', R::money(9690.26));
    }
}
