<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * The period a schedule request asks for, and the parameters an employee must never send (3.31.113). Pure.
 *
 * Dates are the site's calendar days (Y-m-d). Without parameters the period is the Web's current week; with only "from" it
 * is that day and the six after it; "to" alone is refused. At most MAX_DAYS days, between 2000-01-01 and 2099-12-31.
 */
final class Period
{
    public const MAX_DAYS = 42;
    /** The employee is the one of the session: these never select another. */
    public const IDENTITY_PARAMS = ['employee_id', 'employee', 'user_id', 'user', 'eid'];

    /**
     * @param array<string,mixed> $params the query parameters
     * @return array<string,string> field => code, empty when none is sent
     */
    public static function identityErrors(array $params): array
    {
        $errors = [];
        foreach (self::IDENTITY_PARAMS as $name) if (array_key_exists($name, $params)) $errors[$name] = 'not_allowed';
        return $errors;
    }

    /**
     * @param array<string,mixed> $params
     * @param string $weekFirst the first day of the Web's current week (Y-m-d) and $weekDays how many days it has
     * @return array{0:array{from:string,to:string,dates:list<string>}|null,1:array<string,mixed>} [period, errors]
     */
    public static function parse(array $params, string $weekFirst, int $weekDays = 7, int $maxDays = self::MAX_DAYS): array
    {
        $from = isset($params['from']) ? (string) $params['from'] : '';
        $to = isset($params['to']) ? (string) $params['to'] : '';
        $errors = [];
        if ($from === '' && $to !== '') $errors['from'] = 'required';
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if ($value !== '' && !self::validDate($value)) $errors[$field] = 'invalid';
        }
        if ($errors) return [null, $errors];
        if ($from === '') {
            $from = $weekFirst;
            $to = self::addDays($from, max(1, $weekDays) - 1);
        } elseif ($to === '') {
            $to = self::addDays($from, 6);
        }
        if (!self::validDate($to)) return [null, ['to' => 'invalid']];
        if ($to < $from) return [null, ['to' => 'before_from']];
        $days = self::diffDays($from, $to) + 1;
        if ($days > $maxDays) return [null, ['to' => 'too_long', 'max_days' => $maxDays]];
        $dates = [];
        for ($i = 0; $i < $days; $i++) $dates[] = self::addDays($from, $i);
        return [['from' => $from, 'to' => $to, 'dates' => $dates], []];
    }

    public static function validDate(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        if ((int) $m[1] < 2000 || (int) $m[1] > 2099) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function addDays(string $date, int $n): string
    {
        return gmdate('Y-m-d', (int) strtotime($date . ' 12:00:00 UTC') + $n * 86400);
    }

    private static function diffDays(string $a, string $b): int
    {
        return (int) round(((int) strtotime($b . ' 12:00:00 UTC') - (int) strtotime($a . ' 12:00:00 UTC')) / 86400);
    }
}
