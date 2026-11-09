<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/**
 * A letter type's template (HL3): plain text with placeholders in braces; a blank line starts a paragraph. The list of placeholders is
 * closed, so a mistyped one is refused when the template is saved. Wording that depends on the employee's gender is written as
 * {g:male words|female words} (owner decision 2); while the gender is not set, both are shown as "male/female" (owner decision 3).
 * Pure: no WordPress calls.
 */
final class LetterTemplate
{
    public const PLACEHOLDERS = [
        'employee_name', 'title_mr_ms', 'job_title', 'department', 'company_name', 'addressee', 'letter_type', 'other_text',
        'hire_date', 'date', 'date_hijri', 'ref_no', 'salary_total', 'salary_basic', 'allowances', 'salary_in_words', 'currency',
    ];
    /** The placeholders that put the salary in a letter: such a template is saved, previewed and issued directly only with Manage Payroll. */
    public const SALARY = ['salary_total', 'salary_basic', 'allowances', 'salary_in_words'];

    private const TOKEN = '/\{(g:[^{}|]*\|[^{}]*|[a-z_]+)\}/u';

    /** The placeholders a template uses that are not in the list (a gender pair is always fine). @return list<string> "{name}" */
    public static function validate(string $text): array
    {
        $bad = [];
        if (!preg_match_all('/\{([^{}]*)\}/u', $text, $m)) return [];
        foreach ($m[1] as $t) {
            if (preg_match('/^g:[^|]*\|[^|]*$/u', $t)) continue;
            if (!in_array($t, self::PLACEHOLDERS, true)) $bad[] = '{' . $t . '}';
        }
        return array_values(array_unique($bad));
    }

    public static function usesSalary(string $text): bool
    {
        foreach (self::SALARY as $s) if (strpos($text, '{' . $s . '}') !== false) return true;
        return false;
    }

    /**
     * The letter's paragraphs with the values in place.
     * @param array<string,string> $values placeholder => text
     * @param string $gender male | female | '' (not set)
     * @return list<string>
     */
    public static function render(string $text, array $values, string $gender): array
    {
        $out = (string) preg_replace_callback(self::TOKEN, static function ($m) use ($values, $gender) {
            $t = $m[1];
            if (strncmp($t, 'g:', 2) === 0) {
                [$male, $female] = explode('|', substr($t, 2), 2);
                if ($gender === 'male') return $male;
                if ($gender === 'female') return $female;
                return $male === $female ? $male : $male . '/' . $female;
            }
            return array_key_exists($t, $values) ? (string) $values[$t] : $m[0];
        }, str_replace(["\r\n", "\r"], "\n", $text));
        $paras = [];
        foreach (preg_split('/\n\s*\n/u', trim($out)) ?: [] as $p) {
            $p = trim((string) preg_replace('/[ \t]*\n[ \t]*/u', ' ', $p));
            if ($p !== '') $paras[] = $p;
        }
        return $paras;
    }
}
