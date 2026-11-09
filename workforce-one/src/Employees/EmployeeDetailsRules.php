<?php
namespace WorkforceOne\Employees;

if (!defined('ABSPATH')) exit;

/**
 * An employee's details for letters (HR letters HL2, docs/tasks/employee-details.md): the name in Arabic, gender and the hiring date. All
 * optional. Cleans and checks what HR typed on the profile, in Add Employee or in a file. Pure: no WordPress calls.
 */
final class EmployeeDetailsRules
{
    public const FIELDS = ['name_ar', 'gender', 'hire_date'];
    public const GENDERS = ['male', 'female'];
    public const NAME_MAX = 190;

    /**
     * Cleans the given fields. A field that is not given is left out of the result; an empty value means "not set" (null).
     * @param array<string,mixed> $raw name_ar, gender, hire_date (any of them)
     * @param string $today Y-m-d, the site's date
     * @return array{values:array<string,?string>,error:?string} error: too_long | bad_gender | bad_date | future_date
     */
    public static function clean(array $raw, string $today): array
    {
        $values = [];
        if (array_key_exists('name_ar', $raw)) {
            $name = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', (string) $raw['name_ar']));
            if (mb_strlen($name) > self::NAME_MAX) return ['values' => [], 'error' => 'too_long'];
            $values['name_ar'] = $name === '' ? null : $name;
        }
        if (array_key_exists('gender', $raw)) {
            $g = self::gender((string) $raw['gender']);
            if ($g === false) return ['values' => [], 'error' => 'bad_gender'];
            $values['gender'] = $g;
        }
        if (array_key_exists('hire_date', $raw)) {
            $d = self::date((string) $raw['hire_date']);
            if ($d === false) return ['values' => [], 'error' => 'bad_date'];
            if ($d !== null && $d > $today) return ['values' => [], 'error' => 'future_date'];
            $values['hire_date'] = $d;
        }
        return ['values' => $values, 'error' => null];
    }

    /** male | female | null (empty), or false for anything else. Accepts the Arabic words too (owner decision Q6). @return string|null|false */
    public static function gender(string $v)
    {
        $v = trim(mb_strtolower($v));
        if ($v === '') return null;
        if (in_array($v, ['male', 'ذكر'], true)) return 'male';
        if (in_array($v, ['female', 'أنثى', 'انثى', 'أنثي', 'انثي'], true)) return 'female';
        return false;
    }

    /**
     * A date as Y-m-d, or null when empty, or false when it is not one. Accepts Y-m-d and the whole number Excel stores a date as
     * (days since 1899-12-30), since a date typed into a sheet comes back as that number. Other forms (3/1/2021) are ambiguous and refused.
     * @return string|null|false
     */
    public static function date(string $v)
    {
        $v = trim($v);
        if ($v === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : false;
        if (preg_match('/^\d{1,7}$/', $v) && (int) $v >= 1 && (int) $v <= 2958465) return gmdate('Y-m-d', (int) gmmktime(0, 0, 0, 12, 30, 1899) + (int) $v * 86400);
        return false;
    }

    /** "Employee since": the hiring date when it is set, else the day the employee was added. Y-m-d, or '' when neither is known. */
    public static function since(?string $hireDate, ?string $createdAt): string
    {
        if ($hireDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate) && $hireDate !== '0000-00-00') return $hireDate;
        $c = substr((string) $createdAt, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $c) && $c !== '0000-00-00' ? $c : '';
    }

    /**
     * What would change on an employee: the given values against the saved ones.
     * @param array<string,?string> $values cleaned values
     * @return list<array{field:string,from:?string,to:?string}>
     */
    public static function diff(object $row, array $values): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $values)) continue;
            $from = isset($row->$f) && (string) $row->$f !== '' && (string) $row->$f !== '0000-00-00' ? (string) $row->$f : null;
            if ($from !== $values[$f]) $out[] = ['field' => $f, 'from' => $from, 'to' => $values[$f]];
        }
        return $out;
    }

    /** The Audit Log text of a change: "name_ar: — → أحمد · gender: — → male". @param list<array{field:string,from:?string,to:?string}> $diff */
    public static function auditText(array $diff): string
    {
        return implode(' · ', array_map(static function ($d) { return $d['field'] . ': ' . ($d['from'] ?? '—') . ' → ' . ($d['to'] ?? '—'); }, $diff));
    }

    /** English source text of an error code (translated by the caller). */
    public static function errorMessage(string $code): string
    {
        $m = [
            'too_long' => 'The name in Arabic is too long (190 characters at most).',
            'bad_gender' => 'Gender must be male or female.',
            'bad_date' => 'The hiring date must be a date written as YYYY-MM-DD.',
            'future_date' => 'The hiring date cannot be in the future.',
        ];
        return $m[$code] ?? 'The details could not be saved.';
    }
}
