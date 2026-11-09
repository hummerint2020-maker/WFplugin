<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Occasions (docs/tasks/occasions.md, owner decisions 2026-11-09): decorations for one company occasion at a time on the employee app, the
 * login page and kiosks, switched on and off by the administrator (an optional last day ends it); each employee's own birthday (only
 * they see it) and the birthdays of people the administrator picks (everyone sees them); a "moment" once a day; the words the
 * administrator writes for each occasion. Pure: no WordPress, no clock (today is given), no current user.
 */
final class Occasions
{
    /** The company occasions, in the order of the Features card. */
    public const COMPANY = ['valentine', 'ramadan', 'eid_fitr', 'eid_adha', 'new_year', 'halloween', 'national', 'company_birthday'];
    /** Every key words can be written for (the company occasions and the employee's birthday). */
    public const TEXT_KEYS = ['valentine', 'ramadan', 'eid_fitr', 'eid_adha', 'new_year', 'halloween', 'national', 'company_birthday', 'birthday'];
    /** The words of an occasion and their longest length. */
    public const TEXT_FIELDS = ['card_title' => 60, 'card_line' => 120, 'moment_title' => 40, 'moment_line' => 120];
    public const LANGS = ['ar', 'en'];
    public const MODES = ['bg', 'full'];
    public const PEOPLE_MAX = 20;
    /** The occasions with a moment (the company's birthday has none: owner, on the cake). */
    public const MOMENTS = ['valentine', 'ramadan', 'eid_fitr', 'eid_adha', 'new_year', 'halloween', 'national'];

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'active' => '', 'mode' => 'bg', 'until' => '', 'started' => '', 'founded' => '', 'kiosk' => 1,
        'birthdays' => 0, 'people' => [], 'cake_self' => 1, 'cake_everyone' => 0, 'texts' => [],
    ];

    /**
     * The saved settings, cleaned.
     * @param mixed $raw
     * @return array{active:string,mode:string,until:string,started:string,founded:string,kiosk:int,birthdays:int,people:list<int>,cake_self:int,cake_everyone:int,texts:array<string,array<string,array<string,string>>>}
     */
    public static function config($raw): array
    {
        $r = is_array($raw) ? $raw : [];
        $c = self::DEFAULTS;
        $c['active'] = in_array($r['active'] ?? '', self::COMPANY, true) ? (string) $r['active'] : '';
        $c['mode'] = in_array($r['mode'] ?? '', self::MODES, true) ? (string) $r['mode'] : 'bg';
        $c['until'] = self::date((string) ($r['until'] ?? ''));
        $c['started'] = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($r['started'] ?? '')) ? (string) $r['started'] : '';
        $c['founded'] = self::date((string) ($r['founded'] ?? ''));
        foreach (['kiosk', 'birthdays', 'cake_self', 'cake_everyone'] as $k) $c[$k] = array_key_exists($k, $r) ? (empty($r[$k]) ? 0 : 1) : self::DEFAULTS[$k];
        $people = [];
        foreach ((array) ($r['people'] ?? []) as $id) { $id = (int) $id; if ($id > 0 && !in_array($id, $people, true)) $people[] = $id; }
        $c['people'] = array_slice($people, 0, self::PEOPLE_MAX);
        $c['texts'] = self::texts($r['texts'] ?? []);
        return $c;
    }

    /**
     * The Features card's post. The words are kept as typed (trimmed, one line); a word too long refuses the whole save.
     * @param array<string,mixed> $post
     * @param array<string,mixed> $before the saved settings (config())
     * @return array{0:array<string,mixed>,1:string} [settings, error: '' | bad_key | bad_mode | bad_date | until_past | founded_future | text_long]
     */
    public static function fromPost(array $post, array $before, string $today, string $now): array
    {
        $active = (string) ($post['occ_active'] ?? '');
        if ($active !== '' && !in_array($active, self::COMPANY, true)) return [$before, 'bad_key'];
        $mode = (string) ($post['occ_mode'] ?? 'bg');
        if (!in_array($mode, self::MODES, true)) return [$before, 'bad_mode'];
        $until = trim((string) ($post['occ_until'] ?? ''));
        $founded = trim((string) ($post['occ_founded'] ?? ''));
        foreach ([$until, $founded] as $d) if ($d !== '' && self::date($d) === '') return [$before, 'bad_date'];
        // A last day already gone is refused only when it was changed (a saved one that has passed shows "Ended on ...").
        if ($until !== '' && $until < $today && $until !== (string) ($before['until'] ?? '')) return [$before, 'until_past'];
        if ($founded !== '' && $founded > $today) return [$before, 'founded_future'];
        $texts = [];
        foreach (self::TEXT_KEYS as $k) foreach (self::TEXT_FIELDS as $f => $max) foreach (self::LANGS as $l) {
            $v = self::line((string) ($post['occ_text'][$k][$f][$l] ?? ''));
            if (self::length($v) > $max) return [$before, 'text_long'];
            if ($v !== '') $texts[$k][$f][$l] = $v;
        }
        $c = self::config([
            'active' => $active, 'mode' => $mode, 'until' => $until, 'founded' => $founded,
            'started' => $active !== '' && ($active !== ($before['active'] ?? '') || ($before['started'] ?? '') === '') ? $now : ($active === '' ? '' : (string) ($before['started'] ?? '')),
            'kiosk' => !empty($post['occ_kiosk']), 'birthdays' => !empty($post['occ_birthdays']), 'people' => (array) ($post['occ_people'] ?? []),
            'cake_self' => !empty($post['occ_cake_self']), 'cake_everyone' => !empty($post['occ_cake_everyone']), 'texts' => $texts,
        ]);
        return [$c, ''];
    }

    /** What the Audit Log keeps of the settings (the words are not repeated there). */
    public static function summary(array $c, bool $on): string
    {
        return ($on ? 'on' : 'off') . '; active=' . ($c['active'] !== '' ? $c['active'] : 'none') . ($c['until'] !== '' ? ' until ' . $c['until'] : '')
            . '; mode=' . $c['mode'] . '; kiosk=' . ($c['kiosk'] ? 'on' : 'off') . '; birthdays=' . ($c['birthdays'] ? 'on' : 'off')
            . '; picked=' . count($c['people']) . '; cake=' . ($c['cake_self'] ? 'self' : '-') . '/' . ($c['cake_everyone'] ? 'everyone' : '-')
            . '; words=' . self::textCount($c['texts']);
    }

    /** The company occasion is over (its last day has passed, in the site's time zone). */
    public static function ended(array $c, string $today): bool
    {
        return $c['until'] !== '' && $c['until'] < $today;
    }

    /**
     * What shows for one viewer, or null.
     * @param array<string,mixed> $c config()
     * @param array{where:string,birthday?:string,picked?:list<array{id:int,name:string,birthday:string}>,self_id?:int,hidden?:string,preview?:string} $ctx
     *   where: app | login | kiosk; birthday: the viewer's Y-m-d ('' = none); picked: the picked people with their birthday; hidden: the
     *   instance the viewer hid; preview: an occasion key an administrator previews (works while off, nothing saved)
     * @return array{key:string,instance:string,mode:string,name:string,moment:string}|null moment: '' | cake | occasion
     */
    public static function resolve(array $c, bool $on, string $today, array $ctx): ?array
    {
        $where = (string) ($ctx['where'] ?? 'app');
        $preview = (string) ($ctx['preview'] ?? '');
        if ($preview !== '' && in_array($preview, self::TEXT_KEYS, true)) {
            $moment = $preview === 'birthday' ? 'cake' : (in_array($preview, self::MOMENTS, true) ? 'occasion' : '');
            return ['key' => $preview, 'instance' => 'preview|' . $preview, 'mode' => $c['mode'], 'name' => '', 'moment' => $where === 'app' ? $moment : ''];
        }
        if (!$on) return null;
        $out = null;
        if ($where === 'app' && $c['birthdays']) {
            if (self::isBirthday((string) ($ctx['birthday'] ?? ''), $today)) {
                $out = ['key' => 'birthday', 'instance' => 'birthday|' . substr($today, 0, 4), 'mode' => 'bg', 'name' => '', 'moment' => $c['cake_self'] ? 'cake' : ''];
            } else {
                foreach ((array) ($ctx['picked'] ?? []) as $p) {
                    if ((int) ($p['id'] ?? 0) === (int) ($ctx['self_id'] ?? 0) || !self::isBirthday((string) ($p['birthday'] ?? ''), $today)) continue;
                    $out = ['key' => 'birthday', 'instance' => 'picked|' . (int) $p['id'] . '|' . substr($today, 0, 4), 'mode' => 'bg', 'name' => (string) $p['name'], 'moment' => $c['cake_everyone'] ? 'cake' : ''];
                    break;
                }
            }
        }
        if ($out === null && $c['active'] !== '' && !self::ended($c, $today) && ($where !== 'kiosk' || $c['kiosk'])) {
            $out = ['key' => $c['active'], 'instance' => $c['active'] . '|' . $c['started'], 'mode' => $where === 'app' ? $c['mode'] : 'bg', 'name' => '',
                'moment' => $where === 'app' && in_array($c['active'], self::MOMENTS, true) ? 'occasion' : ''];
        }
        if ($out !== null && (string) ($ctx['hidden'] ?? '') === $out['instance']) return null;
        return $out;
    }

    /** A word the administrator wrote for an occasion, or '' (the built-in words are used). */
    public static function text(array $c, string $key, string $field, string $lang): string
    {
        return (string) ($c['texts'][$key][$field][$lang === 'ar' ? 'ar' : 'en'] ?? '');
    }

    /** {name} and {years} in a word. */
    public static function fill(string $text, string $name, int $years): string
    {
        return strtr($text, ['{name}' => $name, '{years}' => (string) $years]);
    }

    /** Whole years since the founding day (0 when not set or not yet a year). */
    public static function years(string $founded, string $today): int
    {
        if ($founded === '') return 0;
        $y = (int) substr($today, 0, 4) - (int) substr($founded, 0, 4);
        if (substr($today, 5, 5) < substr($founded, 5, 5)) $y--;
        return max(0, $y);
    }

    /** The year New Year greets (December greets the coming one). */
    public static function newYear(string $today): int
    {
        $y = (int) substr($today, 0, 4);
        return substr($today, 5, 2) === '12' ? $y + 1 : $y;
    }

    /** Words written in Arabic letters are written out right to left in a moment; others letter by letter. */
    public static function isArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    /** A birthday (Y-m-d) falls on today (29 February on 28 February in other years, as Employee Moments). */
    public static function isBirthday(string $birthday, string $today): bool
    {
        foreach (Moments::forDate($birthday, '', $today) as $m) if ($m['type'] === 'birthday') return true;
        return false;
    }

    /** @param mixed $raw @return array<string,array<string,array<string,string>>> */
    private static function texts($raw): array
    {
        $out = [];
        if (!is_array($raw)) return $out;
        foreach (self::TEXT_KEYS as $k) foreach (self::TEXT_FIELDS as $f => $max) foreach (self::LANGS as $l) {
            $v = self::line((string) ($raw[$k][$f][$l] ?? ''));
            if ($v !== '' && self::length($v) <= $max) $out[$k][$f][$l] = $v;
        }
        return $out;
    }

    private static function textCount(array $texts): int
    {
        $n = 0;
        foreach ($texts as $fields) foreach ((array) $fields as $langs) $n += count((array) $langs);
        return $n;
    }

    private static function line(string $v): string
    {
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        return trim(strip_tags($v));
    }

    private static function length(string $v): int
    {
        return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
    }

    private static function date(string $d): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return '';
        return $d;
    }
}
