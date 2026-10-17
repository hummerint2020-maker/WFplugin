<?php
namespace WorkforceOne\Payroll;

if (!defined('ABSPATH')) exit;

/**
 * Salary advances (3.31.109, approvals stage 5; owner decisions 2026-10-17, docs/tasks/approvals.md,
 * mockup docs/mockups/new-types/): the limits, the checks on a request, and the instalments taken off
 * the pay. Pure: no WordPress calls.
 */
final class Advances
{
    public const DEFAULTS = [
        'months_salary' => 1.0,   // the most per advance, in months of salary (0 = no limit by salary)
        'max_amount' => 0.0,      // a fixed most per advance (0 = none)
        'max_instalments' => 12,
        'one_open' => 1,          // one open advance at a time (owner decision 1)
        'min_service_months' => 0,
    ];

    /** @param mixed $saved @return array{months_salary:float,max_amount:float,max_instalments:int,one_open:int,min_service_months:int} */
    public static function settings($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $num = static function ($v, float $d, float $min, float $max): float { return is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : $d; };
        return [
            'months_salary' => $num($s['months_salary'] ?? null, self::DEFAULTS['months_salary'], 0, 24),
            'max_amount' => $num($s['max_amount'] ?? null, self::DEFAULTS['max_amount'], 0, 10000000),
            'max_instalments' => (int) $num($s['max_instalments'] ?? null, self::DEFAULTS['max_instalments'], 1, 60),
            'one_open' => array_key_exists('one_open', $s) ? (empty($s['one_open']) ? 0 : 1) : self::DEFAULTS['one_open'],
            'min_service_months' => (int) $num($s['min_service_months'] ?? null, self::DEFAULTS['min_service_months'], 0, 120),
        ];
    }

    /**
     * The most one employee may ask for (INF = no limit), or null when it cannot be known: the limit is in
     * months of salary, the salary is not on file and there is no fixed most.
     */
    public static function limit(?float $salary, array $settings): ?float
    {
        $fixed = $settings['max_amount'] > 0 ? (float) $settings['max_amount'] : INF;
        if ($settings['months_salary'] <= 0) return $fixed;
        if ($salary === null || $salary <= 0) return is_finite($fixed) ? $fixed : null;
        return min(round($salary * $settings['months_salary'], 2), $fixed);
    }

    /**
     * Why a request cannot be sent, or null.
     * @param array{amount:float,instalments:int,limit:?float,open:bool,service_months:int} $f
     */
    public static function check(array $f, array $settings): ?string
    {
        if (!($f['amount'] > 0)) return 'amount';
        if ($f['instalments'] < 1 || $f['instalments'] > $settings['max_instalments']) return 'instalments';
        if ($f['limit'] === null) return 'no_salary';
        if ($f['amount'] > $f['limit'] + 0.004) return 'limit';
        if ($settings['one_open'] && $f['open']) return 'open';
        if ($f['service_months'] < $settings['min_service_months']) return 'service';
        return null;
    }

    /** Equal instalments; the last one takes the rounding so they add up to the amount. @return list<float> */
    public static function split(float $amount, int $instalments): array
    {
        $instalments = max(1, $instalments);
        $each = floor($amount / $instalments * 100) / 100;
        $out = array_fill(0, $instalments, $each);
        $out[$instalments - 1] = round($amount - $each * ($instalments - 1), 2);
        return $out;
    }

    /** The months from the one after $paid_date (Y-m-d), one per instalment. @return list<string> Y-m */
    public static function months(string $paid_date, int $instalments): array
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $paid_date) ?: new \DateTimeImmutable('today');
        $d = $d->modify('first day of next month');
        $out = [];
        for ($i = 0; $i < max(1, $instalments); $i++) $out[] = $d->modify('+' . $i . ' month')->format('Y-m');
        return $out;
    }

    /** The month after the last of these months (where a skipped instalment goes). @param list<string> $months */
    public static function after(array $months): string
    {
        sort($months);
        $last = $months ? end($months) : gmdate('Y-m');
        return (new \DateTimeImmutable($last . '-01'))->modify('+1 month')->format('Y-m');
    }

    /** Whole months between two days (service). */
    public static function serviceMonths(string $since, string $today): int
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($since, 0, 10));
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if (!$a || !$b || $a > $b) return 0;
        $diff = $a->diff($b);
        return $diff->y * 12 + $diff->m;
    }
}
