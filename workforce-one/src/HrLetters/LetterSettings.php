<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/**
 * HR letters' settings (HL3, docs/tasks/hr-letter-hl3.md §1): the general settings, the letter types with their templates, and the letterhead,
 * read from their options with defaults, and the checks of the three wp-admin forms. Pure: no WordPress calls.
 */
final class LetterSettings
{
    public const DELIVERIES = ['pdf', 'upload', 'none'];
    public const LANGUAGES = ['ar', 'en'];
    public const NOTE_MAX = 500;
    public const ADDRESSEE_MAX = 190;
    public const OTHER_MAX = 255;
    public const TEMPLATE_MAX = 20000;

    /**
     * @param mixed $raw the option ews_hr_letters
     * @return array{languages:list<string>,addressee_ar:string,addressee_en:string,other:int,one_open:int,new_type_delivery:string,currency_ar:string,currency_en:string}
     */
    public static function settings($raw): array
    {
        $r = is_array($raw) ? $raw : [];
        $langs = array_values(array_intersect(self::LANGUAGES, array_map('strval', (array) ($r['languages'] ?? self::LANGUAGES))));
        return [
            'languages' => $langs ?: self::LANGUAGES,
            'addressee_ar' => self::text($r['addressee_ar'] ?? '', self::ADDRESSEE_MAX) ?: 'إلى من يهمه الأمر',
            'addressee_en' => self::text($r['addressee_en'] ?? '', self::ADDRESSEE_MAX) ?: 'To whom it may concern',
            'other' => array_key_exists('other', $r) ? ((int) $r['other'] ? 1 : 0) : 1,
            'one_open' => array_key_exists('one_open', $r) ? ((int) $r['one_open'] ? 1 : 0) : 1,
            'new_type_delivery' => in_array($r['new_type_delivery'] ?? '', self::DELIVERIES, true) ? (string) $r['new_type_delivery'] : 'none',
            'currency_ar' => self::text($r['currency_ar'] ?? '', 40),
            'currency_en' => self::text($r['currency_en'] ?? '', 40),
        ];
    }

    /**
     * The letter types (the option ews_hr_letter_types, else the five starting types).
     * @param mixed $raw
     * @return list<array{key:string,name_ar:string,name_en:string,delivery:string,active:int,tpl_ar:string,tpl_en:string}>
     */
    public static function types($raw): array
    {
        if (!is_array($raw) || !$raw) return self::defaultTypes();
        $out = [];
        $seen = [];
        foreach ($raw as $t) {
            if (!is_array($t)) continue;
            $key = self::key((string) ($t['key'] ?? ''));
            if ($key === '' || $key === 'other' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [
                'key' => $key,
                'name_ar' => self::text($t['name_ar'] ?? '', 190),
                'name_en' => self::text($t['name_en'] ?? '', 190),
                'delivery' => in_array($t['delivery'] ?? '', self::DELIVERIES, true) ? (string) $t['delivery'] : 'none',
                'active' => (int) ($t['active'] ?? 1) ? 1 : 0,
                'tpl_ar' => self::template($t['tpl_ar'] ?? ''),
                'tpl_en' => self::template($t['tpl_en'] ?? ''),
            ];
        }
        return $out ?: self::defaultTypes();
    }

    /** One type by its key, or null. @param list<array<string,mixed>> $types @return array<string,mixed>|null */
    public static function type(array $types, string $key): ?array
    {
        foreach ($types as $t) if ($t['key'] === $key) return $t;
        return null;
    }

    /** The type's name in a language (the other language's when empty). @param array<string,mixed> $type */
    public static function name(array $type, string $lang): string
    {
        $a = (string) ($type['name_' . ($lang === 'ar' ? 'ar' : 'en')] ?? '');
        return $a !== '' ? $a : (string) ($type['name_' . ($lang === 'ar' ? 'en' : 'ar')] ?? $type['key'] ?? '');
    }

    /**
     * The five starting types (owner decision Q4), as the approved mockup: Employment and Salary certificates made as a PDF, Experience and
     * Embassy letters uploaded by HR, the Bank letter request only. The templates are the mockup's sample letters.
     * @return list<array{key:string,name_ar:string,name_en:string,delivery:string,active:int,tpl_ar:string,tpl_en:string}>
     */
    public static function defaultTypes(): array
    {
        $employment_ar = "تشهد {company_name} بأن {title_mr_ms} / {employee_name} {g:يعمل|تعمل} لديها بوظيفة {job_title} بقسم {department}، اعتبارًا من {hire_date} وحتى تاريخه.\n\n"
            . "وقد {g:أعطيت له|أعطيت لها} هذه الشهادة بناءً على {g:طلبه|طلبها} لتقديمها إلى {addressee}، دون أدنى مسؤولية على الشركة.";
        $employment_en = "This is to certify that {title_mr_ms} {employee_name} has been employed by {company_name} as {job_title} in the {department} department since {hire_date}, and is still in our employment.\n\n"
            . "This certificate is issued at {g:his|her} request to be presented to {addressee}, without any liability on the company.";
        $salary_ar = "تشهد {company_name} بأن {title_mr_ms} / {employee_name} {g:يعمل|تعمل} لديها بوظيفة {job_title} بقسم {department}، اعتبارًا من {hire_date} وحتى تاريخه، {g:ويتقاضى|وتتقاضى} مرتبًا شهريًا إجماليًا قدره {salary_total} {currency} ({salary_in_words}).\n\n"
            . "وقد {g:أعطيت له|أعطيت لها} هذه الشهادة بناءً على {g:طلبه|طلبها} لتقديمها إلى {addressee}، دون أدنى مسؤولية على الشركة.";
        $salary_en = "This is to certify that {title_mr_ms} {employee_name} has been employed by {company_name} as {job_title} in the {department} department since {hire_date}, and receives a gross monthly salary of {currency} {salary_total} ({salary_in_words}).\n\n"
            . "This certificate is issued at {g:his|her} request to be presented to {addressee}, without any liability on the company.";
        return [
            ['key' => 'employment', 'name_ar' => 'شهادة عمل', 'name_en' => 'Employment certificate', 'delivery' => 'pdf', 'active' => 1, 'tpl_ar' => $employment_ar, 'tpl_en' => $employment_en],
            ['key' => 'salary', 'name_ar' => 'شهادة مرتب', 'name_en' => 'Salary certificate', 'delivery' => 'pdf', 'active' => 1, 'tpl_ar' => $salary_ar, 'tpl_en' => $salary_en],
            ['key' => 'experience', 'name_ar' => 'شهادة خبرة', 'name_en' => 'Experience letter', 'delivery' => 'upload', 'active' => 1, 'tpl_ar' => '', 'tpl_en' => ''],
            ['key' => 'embassy', 'name_ar' => 'خطاب سفارة', 'name_en' => 'Embassy letter', 'delivery' => 'upload', 'active' => 1, 'tpl_ar' => '', 'tpl_en' => ''],
            ['key' => 'bank', 'name_ar' => 'خطاب بنك', 'name_en' => 'Bank letter', 'delivery' => 'none', 'active' => 1, 'tpl_ar' => '', 'tpl_en' => ''],
        ];
    }

    /**
     * @param mixed $raw the option ews_hr_letter_letterhead
     * @return array<string,string> company, address, signatory name and title, footer (each _ar and _en), and the three image keys
     */
    public static function letterhead($raw): array
    {
        $r = is_array($raw) ? $raw : [];
        $out = [];
        foreach (['company', 'address', 'signatory_name', 'signatory_title', 'footer'] as $f) {
            foreach (['ar', 'en'] as $l) $out[$f . '_' . $l] = self::text($r[$f . '_' . $l] ?? '', 255);
        }
        foreach (['logo_key', 'signature_key', 'stamp_key'] as $f) $out[$f] = preg_match('/^[A-Za-z0-9]{32}\.(png|jpg)$/', (string) ($r[$f] ?? '')) ? (string) $r[$f] : '';
        return $out;
    }

    /**
     * The general settings from the Features form.
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function fromForm(array $post): array
    {
        $langs = array_values(array_intersect(self::LANGUAGES, array_map('strval', (array) ($post['languages'] ?? []))));
        return self::settings([
            'languages' => $langs ?: self::LANGUAGES,
            'addressee_ar' => $post['addressee_ar'] ?? '', 'addressee_en' => $post['addressee_en'] ?? '',
            'other' => !empty($post['other']) ? 1 : 0, 'one_open' => !empty($post['one_open']) ? 1 : 0,
            'new_type_delivery' => $post['new_type_delivery'] ?? 'none',
            'currency_ar' => $post['currency_ar'] ?? '', 'currency_en' => $post['currency_en'] ?? '',
        ]);
    }

    /**
     * A type from the Types & templates form (a new one when $key is ''), checked.
     * @param list<array<string,mixed>> $types the types now
     * @param array<string,mixed> $post name_ar, name_en, delivery, active, tpl_ar, tpl_en
     * @return array{types:list<array<string,mixed>>,error:string,unknown:list<string>,key:string}
     *   error: name, template_tokens, template_missing (a PDF type without a template in any language)
     */
    public static function saveType(array $types, string $key, array $post, string $newDelivery): array
    {
        $type = [
            'key' => $key,
            'name_ar' => self::text($post['name_ar'] ?? '', 190),
            'name_en' => self::text($post['name_en'] ?? '', 190),
            'delivery' => in_array($post['delivery'] ?? '', self::DELIVERIES, true) ? (string) $post['delivery'] : $newDelivery,
            'active' => !empty($post['active']) ? 1 : 0,
            'tpl_ar' => self::template($post['tpl_ar'] ?? ''),
            'tpl_en' => self::template($post['tpl_en'] ?? ''),
        ];
        $out = ['types' => $types, 'error' => '', 'unknown' => [], 'key' => $key];
        if ($type['name_ar'] === '' && $type['name_en'] === '') { $out['error'] = 'name'; return $out; }
        $unknown = array_values(array_unique(array_merge(LetterTemplate::validate($type['tpl_ar']), LetterTemplate::validate($type['tpl_en']))));
        if ($unknown) { $out['error'] = 'template_tokens'; $out['unknown'] = $unknown; return $out; }
        if ($type['delivery'] === 'pdf' && trim($type['tpl_ar']) === '' && trim($type['tpl_en']) === '') { $out['error'] = 'template_missing'; return $out; }
        if ($key === '') {
            $base = self::key($type['name_en'] !== '' ? $type['name_en'] : 'type');
            $base = $base !== '' && $base !== 'other' ? $base : 'type';
            $key = $base;
            for ($i = 2; self::type($types, $key) !== null; $i++) $key = substr($base, 0, 36) . '_' . $i;
            $type['key'] = $key;
            $types[] = $type;
        } else {
            $found = false;
            foreach ($types as $i => $t) if ($t['key'] === $key) { $types[$i] = $type; $found = true; }
            if (!$found) { $out['error'] = 'name'; return $out; }
        }
        return ['types' => array_values($types), 'error' => '', 'unknown' => [], 'key' => $key];
    }

    /** Single-line text: tags removed, spaces collapsed, at most $max characters. @param mixed $v */
    public static function text($v, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_scalar($v) ? (string) $v : '')));
        return mb_substr($s, 0, $max);
    }

    /** A template: tags removed, line ends kept, at most TEMPLATE_MAX characters. @param mixed $v */
    public static function template($v): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", strip_tags(is_scalar($v) ? (string) $v : ''));
        return mb_substr(trim($s), 0, self::TEMPLATE_MAX);
    }

    /** A type key: lower-case letters, digits and underscores, 40 at most. */
    public static function key(string $v): string
    {
        return substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($v)), '_'), 0, 40);
    }
}
