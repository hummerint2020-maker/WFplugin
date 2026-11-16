<?php
namespace WorkforceOne\HrLetters;

use WorkforceOne\Employees\EmployeeDetailsRules;
use WorkforceOne\Support\HijriDate;
use WorkforceOne\Support\NumberWords;

if (!defined('ABSPATH')) exit;

/**
 * The values a letter's placeholders take (HL3, docs/tasks/hr-letter-hl3.md §4). Arabic letters use the name in Arabic when HR filled it,
 * Arabic-Indic digits, Arabic month names and the Hijri date (owner decision Q13); the reference keeps Western digits (owner decision 8).
 * A detail not filled falls back: the name, a title that fits anyone, the day the employee was added (owner decision 3). Pure.
 */
final class LetterFacts
{
    public const MONTHS_AR = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    public const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /**
     * @param object $letter id, requested_at, language, addressee, type_label, other_text
     * @param object $employee name, name_ar, gender, hire_date, created_at, job_title, department_name
     * @param array{basic:float,allowances:float,total:float}|null $salary on the day of issue, or null (not needed or not on file)
     * @param array<string,string> $letterhead LetterSettings::letterhead()
     * @param array<string,mixed> $settings LetterSettings::settings()
     * @return array<string,string>
     */
    public static function values(object $letter, object $employee, ?array $salary, array $letterhead, array $settings, string $currencyCode, string $companyFallback, string $today): array
    {
        $ar = (string) $letter->language === 'ar';
        $l = $ar ? 'ar' : 'en';
        $o = $ar ? 'en' : 'ar';
        $name = $ar && trim((string) ($employee->name_ar ?? '')) !== '' ? (string) $employee->name_ar : (string) $employee->name;
        $since = EmployeeDetailsRules::since(isset($employee->hire_date) ? (string) $employee->hire_date : null, (string) ($employee->created_at ?? ''));
        $company = $letterhead['company_' . $l] !== '' ? $letterhead['company_' . $l] : ($letterhead['company_' . $o] !== '' ? $letterhead['company_' . $o] : $companyFallback);
        $currency = (string) ($settings['currency_' . $l] ?? '') !== '' ? (string) $settings['currency_' . $l] : $currencyCode;
        $hijri = HijriDate::fromGregorian($today);
        $v = [
            'employee_name' => $name,
            'title_mr_ms' => self::title((string) ($employee->gender ?? ''), $l),
            'job_title' => self::orDash((string) ($employee->job_title ?? '')),
            'department' => self::orDash((string) ($employee->department_name ?? '')),
            'company_name' => $company,
            'addressee' => (string) $letter->addressee,
            'letter_type' => (string) $letter->type_label,
            'other_text' => (string) ($letter->other_text ?? ''),
            'hire_date' => $since !== '' ? self::date($since, $l) : '—',
            'date' => self::date($today, $l),
            'date_hijri' => $hijri ? HijriDate::format($hijri, $l) : '',
            'ref_no' => LetterRules::reference((int) $letter->id, (string) $letter->requested_at),
            'currency' => $currency,
            'salary_total' => '', 'salary_basic' => '', 'allowances' => '', 'salary_in_words' => '',
        ];
        if ($salary !== null) {
            $v['salary_total'] = self::amount((float) $salary['total'], $l);
            $v['salary_basic'] = self::amount((float) $salary['basic'], $l);
            $v['allowances'] = self::amount((float) $salary['allowances'], $l);
            $v['salary_in_words'] = self::words((float) $salary['total'], $l);
        }
        return $v;
    }

    /** السيد / السيدة, Mr. / Ms.; both while the gender is not set. */
    public static function title(string $gender, string $lang): string
    {
        $t = $lang === 'ar' ? ['male' => 'السيد', 'female' => 'السيدة', '' => 'السيد/السيدة'] : ['male' => 'Mr.', 'female' => 'Ms.', '' => 'Mr./Ms.'];
        return $t[$gender] ?? $t[''];
    }

    /** "9 November 2026", or in Arabic "٩ نوفمبر ٢٠٢٦". */
    public static function date(string $ymd, string $lang): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) return $ymd;
        $s = (int) $m[3] . ' ' . ($lang === 'ar' ? self::MONTHS_AR : self::MONTHS_EN)[(int) $m[2] - 1] . ' ' . $m[1];
        return $lang === 'ar' ? NumberWords::arabicDigits($s) : $s;
    }

    /** 18,500 or 18,500.50; in Arabic ١٨٬٥٠٠. */
    public static function amount(float $v, string $lang): string
    {
        $v = round($v, 2);
        $s = number_format($v, abs($v - floor($v)) < 0.005 ? 0 : 2, '.', ',');
        return $lang === 'ar' ? NumberWords::arabicDigits($s) : $s;
    }

    /** The amount in words; a fraction as "and 50/100" (owner decision 5). */
    public static function words(float $v, string $lang): string
    {
        $v = round($v, 2);
        $whole = (int) floor($v);
        $cents = (int) round(($v - $whole) * 100);
        if ($cents === 100) { $whole++; $cents = 0; }
        $w = $lang === 'ar' ? NumberWords::arabic($whole) : NumberWords::english($whole);
        if ($cents > 0) $w .= $lang === 'ar' ? ' و' . NumberWords::arabicDigits($cents . '/100') : ' and ' . $cents . '/100';
        return $w;
    }

    private static function orDash(string $s): string
    {
        return trim($s) !== '' ? trim($s) : '—';
    }
}
