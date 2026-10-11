<?php
namespace WorkforceOne\Payroll;

if (!defined('ABSPATH')) exit;

/** Checks of the Payroll forms: a salary and the payroll rules. Pure: no WordPress calls. */
final class PayRules
{
    public const DEFAULTS = [
        'currency' => 'EGP', 'day_divisor' => 30, 'day_base' => 'gross', 'absence_days' => 1.0,
        'overtime_rate' => 1.35, 'overtime_rate_off' => 2.0, 'max_deduction_days' => 0.0, 'employee_view' => false,
        'late_mode' => 'minute', 'late_tiers' => [],
    ];

    /** Error code → message shown on the Payroll page (codes travel in the redirect, never text). */
    public const ERRORS = [
        'basic' => 'The basic salary must be zero or more.',
        'basic_large' => 'The basic salary is too large.',
        'date' => 'Choose a valid date for when the salary starts.',
        'allowance_name' => 'Each allowance needs a name.',
        'currency' => 'The currency is a 3-letter code such as EGP.',
        'divisor' => 'Days per month must be a whole number from 1 to 31.',
        'base' => 'Choose what a day\'s value is based on.',
        'absence' => 'An absent day deducts from 0 to 5 days\' pay.',
        'overtime' => 'Overtime rates are from 1 to 5 times the hourly pay.',
        'cap' => 'The deduction limit is from 0 (no limit) to 31 days.',
        'allowance_amount' => 'Each allowance must be zero or more.',
        'not_found' => 'That salary entry no longer exists.',
        'employee' => 'Choose an employee.',
        'adjust_amount' => 'The amount must be more than zero.',
        'adjust_reason' => 'Write the reason; the employee sees it on their payslip.',
        'adjust_kind' => 'Choose a bonus or a deduction.',
        'closed' => 'This month is closed. Reopen it to change it.',
        'review' => 'Some days still need review (a missing Sign Out). Fix them in the Sign In / Out report, then close the month.',
        'not_ended' => 'A month can be closed only after it ends.',
        'already_closed' => 'This month is already closed.',
        'not_closed' => 'This month is not closed.',
        'nothing' => 'No employee has a salary for this month.',
        'month' => 'Choose a month.',
        'tiers' => 'Late arrival tiers: give at least one row, each with minutes (0-600, each once) and a share of a day (0-2).',
    ];

    public static function message(string $code): string
    {
        return self::ERRORS[$code] ?? '';
    }

    /**
     * A salary from the form: basic, allowances (name + amount rows; empty rows ignored) and the
     * date it takes effect.
     * @param array<string,mixed> $post
     * @return array{0:?array{basic:float,allowances:array<int,array{name:string,amount:float}>,effective_from:string,note:string},1:?string} [salary, error code]
     */
    public static function rate(array $post): array
    {
        $basic = trim((string) ($post['basic'] ?? ''));
        if ($basic === '' || !is_numeric($basic) || (float) $basic < 0) return [null, 'basic'];
        if ((float) $basic > 100000000) return [null, 'basic_large'];
        $date = trim((string) ($post['effective_from'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return [null, 'date'];
        $names = array_values((array) ($post['allowance_name'] ?? []));
        $amounts = array_values((array) ($post['allowance_amount'] ?? []));
        $allowances = [];
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            $amount = trim((string) ($amounts[$i] ?? ''));
            if ($name === '' && $amount === '') continue;
            if ($name === '') return [null, 'allowance_name'];
            if ($amount === '' || !is_numeric($amount) || (float) $amount < 0) return [null, 'allowance_amount'];
            $allowances[] = ['name' => mb_substr($name, 0, 60), 'amount' => round((float) $amount, 2)];
        }
        return [['basic' => round((float) $basic, 2), 'allowances' => $allowances, 'effective_from' => $date, 'note' => mb_substr(trim((string) ($post['note'] ?? '')), 0, 190)], null];
    }

    /**
     * The payroll rules from the form.
     * @param array<string,mixed> $post
     * @return array{0:?array<string,mixed>,1:?string} [rules, error code]
     */
    public static function rules(array $post): array
    {
        $currency = strtoupper(trim((string) ($post['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) return [null, 'currency'];
        $num = static function ($key) use ($post) { $v = trim((string) ($post[$key] ?? '')); return is_numeric($v) ? (float) $v : null; };
        $divisor = $num('day_divisor');
        if ($divisor === null || $divisor < 1 || $divisor > 31 || floor($divisor) !== $divisor) return [null, 'divisor'];
        $base = (string) ($post['day_base'] ?? '');
        if (!in_array($base, ['gross', 'basic'], true)) return [null, 'base'];
        $absence = $num('absence_days');
        if ($absence === null || $absence < 0 || $absence > 5) return [null, 'absence'];
        $rate = $num('overtime_rate');
        $off = $num('overtime_rate_off');
        if ($rate === null || $off === null || $rate < 1 || $rate > 5 || $off < 1 || $off > 5) return [null, 'overtime'];
        $cap = $num('max_deduction_days');
        if ($cap === null || $cap < 0 || $cap > 31) return [null, 'cap'];
        $mode = ($post['late_mode'] ?? 'minute') === 'tiers' ? 'tiers' : 'minute';
        $tiers = [];
        $mins = array_values((array) ($post['late_tier_minutes'] ?? []));
        $days = array_values((array) ($post['late_tier_days'] ?? []));
        foreach ($mins as $i => $m) {
            $m = trim((string) $m);
            $d = trim((string) ($days[$i] ?? ''));
            if ($m === '' && $d === '') continue;
            if (!is_numeric($m) || !is_numeric($d) || (int) $m < 0 || (int) $m > 600 || (float) $d < 0 || (float) $d > 2 || isset($tiers[(int) $m])) return [null, 'tiers'];
            $tiers[(int) $m] = ['after' => (int) $m, 'days' => round((float) $d, 4)];
        }
        ksort($tiers);
        $tiers = array_values($tiers);
        if ($mode === 'tiers' && !$tiers) return [null, 'tiers'];
        return [['currency' => $currency, 'day_divisor' => (int) $divisor, 'day_base' => $base, 'absence_days' => $absence,
            'overtime_rate' => $rate, 'overtime_rate_off' => $off, 'max_deduction_days' => $cap, 'employee_view' => !empty($post['employee_view']),
            'late_mode' => $mode, 'late_tiers' => $tiers], null];
    }

    /**
     * A bonus or deduction added by hand: kind, amount (> 0) and the reason the employee will see.
     * @param array{kind:string,amount:string,reason:string} $post
     * @return array{0:?array{kind:string,amount:float,reason:string},1:?string}
     */
    public static function adjustment(array $post): array
    {
        if (!in_array($post['kind'], ['bonus', 'deduction'], true)) return [null, 'adjust_kind'];
        $amount = trim($post['amount']);
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 100000000) return [null, 'adjust_amount'];
        $reason = trim($post['reason']);
        if ($reason === '') return [null, 'adjust_reason'];
        return [['kind' => $post['kind'], 'amount' => round((float) $amount, 2), 'reason' => mb_substr($reason, 0, 190)], null];
    }

    /** 1234.5 → "1,234.50". */
    public static function money(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }
}
