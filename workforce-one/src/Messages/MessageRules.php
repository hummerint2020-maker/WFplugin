<?php
namespace WorkforceOne\Messages;

if (!defined('ABSPATH')) exit;

/**
 * The rules of an edited message (3.31.165, docs/tasks/system-messages.md §2). Pure: no WordPress calls.
 *
 * A message is one gettext text of the plugin, named by its key: the English original, or context + "\x04" + original,
 * as WordPress keys its translations. An edit replaces the text shown in one language bucket ('ar' or 'en').
 * check() refuses an edit that could break the page: a placeholder missing, added or of another type (sprintf() throws
 * in PHP 8), a lone %, a tag that the original did not have, a quote or backslash that could end an HTML attribute or a
 * script string where the text is printed unescaped, or a text too long.
 */
final class MessageRules
{
    public const KINDS = ['notification_title', 'notification', 'banner', 'error', 'ui'];

    /** printf placeholders the plugin uses (%s, %d, %1$s, %.1f, …) and %%. */
    // No space flag: the plugin never uses it, and texts like '100% sure' or '% sign' are prose, not tokens.
    private const TOKEN = '/%(?:(\d+)\$)?[-+0\'#]*\d*(?:\.\d+)?([bcdeEfFgGosuxX])|%%/';

    public static function key(string $context, string $original): string
    {
        return $context === '' ? $original : $context . "\x04" . $original;
    }

    /** @return array{0:string,1:string} [context, original] */
    public static function split(string $key): array
    {
        $p = strpos($key, "\x04");
        return $p === false ? ['', $key] : [substr($key, 0, $p), substr($key, $p + 1)];
    }

    /** The language bucket of a locale: Arabic for ar, ar_EG, …; English for everything else. */
    public static function bucket(string $locale): string
    {
        return strpos($locale, 'ar') === 0 ? 'ar' : 'en';
    }

    /**
     * The placeholders of a text, numbered: '%s on %s' → ['1s', '2s']; '%2$s %1$d' → ['2s', '1d'] (sorted, unique).
     * Null when the text has a lone % or mixes numbered and plain placeholders (not a valid printf text).
     * @return list<string>|null
     */
    public static function placeholders(string $s): ?array
    {
        $out = [];
        $n = 0;
        $numbered = null;
        $rest = preg_replace_callback(self::TOKEN, function ($m) use (&$out, &$n, &$numbered) {
            if ($m[0] === '%%') return '';
            $isNum = ($m[1] ?? '') !== '';
            if ($numbered === null) $numbered = $isNum;
            elseif ($numbered !== $isNum) $numbered = 'mixed';
            $num = $isNum ? (int) $m[1] : ++$n;
            $out[] = $num . self::type($m[2]);
            return '';
        }, $s);
        if ($numbered === 'mixed' || strpos((string) $rest, '%') !== false) return null;
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** Types that take the same argument count as one (a string or a number written as a string is still a string). */
    private static function type(string $t): string
    {
        return $t === 's' ? 's' : (in_array($t, ['d', 'u', 'c', 'b', 'o', 'x', 'X'], true) ? 'd' : 'f');
    }

    /** '%s and %d' → '%1$s and %2$d': the text an editor starts from, so placeholders can be moved safely. */
    public static function numbered(string $s): string
    {
        $n = 0;
        return (string) preg_replace_callback(self::TOKEN, function ($m) use (&$n) {
            if ($m[0] === '%%' || ($m[1] ?? '') !== '') return $m[0];
            $n++;
            return '%' . $n . '$' . substr($m[0], 1);
        }, $s);
    }

    /** The tags of a text, in order (e.g. ['<strong>', '</strong>']). @return list<string> */
    public static function tags(string $s): array
    {
        preg_match_all('/<\/?[a-zA-Z][^<>]*>/', $s, $m);
        $t = $m[0];
        sort($t);
        return $t;
    }

    /** The longest an edit of this kind may be (characters). */
    public static function limit(string $kind, string $original): int
    {
        switch ($kind) {
            case 'notification_title': return 80;
            case 'banner': return 300;
            case 'notification':
            case 'error': return 500;
            default: return min(500, max(60, 3 * self::length($original)));
        }
    }

    /**
     * Checks an edit against its original. Returns the text to store and '' when it is fine, else the refusal code:
     * empty, placeholders, percent, html, chars, too_long.
     * @return array{0:string,1:string}
     */
    public static function check(string $original, string $text, string $kind): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') return ['', 'empty'];
        $want = self::placeholders($original);
        // The original's own placeholders are plain (%s) when its call site gives the values in order: an edit may move them
        // only as numbered ones, which PHP and wp.i18n both read. Plain ones in the edit are numbered the same way first.
        // An original that is not a printf text (a lone %, as in "100%") never goes through sprintf: no placeholder rules.
        if ($want !== null) {
            $got = self::placeholders($text);
            if ($got === null) return [$text, strpos((string) preg_replace(self::TOKEN, '', $text), '%') !== false ? 'percent' : 'placeholders'];
            if ($got !== $want) return [$text, 'placeholders'];
            if ($want !== []) $text = self::numbered($text);
            // %% means a % sign only where the text goes through sprintf (it has placeholders); elsewhere it would show twice.
            elseif (strpos($text, '%%') !== false) return [$text, 'percent'];
        }
        if (self::tags($text) !== self::tags($original)) return [$text, 'html'];
        $bare = (string) preg_replace('/<\/?[a-zA-Z][^<>]*>/', '', $text);
        if (strpbrk($bare, '<>') !== false) return [$text, 'html'];
        // Quotes and backslashes: only as many as the original had (it may be printed inside an attribute or a script string).
        // Typed quotes become typographic ones, so "you're" still works.
        $origBare = (string) preg_replace('/<\/?[a-zA-Z][^<>]*>/', '', $original);
        if (substr_count($bare, '\\') > substr_count($origBare, '\\')) return [$text, 'chars'];
        foreach (["'" => '’', '"' => '”'] as $q => $typo) {
            if (substr_count($bare, $q) > substr_count($origBare, $q)) $text = self::replaceOutsideTags($text, $q, $typo);
        }
        if (self::length($text) > self::limit($kind, $original)) return [$text, 'too_long'];
        return [$text, ''];
    }

    private static function replaceOutsideTags(string $text, string $from, string $to): string
    {
        $parts = preg_split('/(<\/?[a-zA-Z][^<>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) $parts[$i] = str_replace($from, $to, $p);
        }
        return implode('', $parts);
    }

    /**
     * What each placeholder stands for, from the code's translators comment:
     * "translators: 1: employee name, 2: day" or "translators: %s: employee name". Unknown ones get their number.
     * @return array<string,string> placeholder as written in the numbered text ('%1$s') => label
     */
    public static function labels(string $comment, string $original): array
    {
        $comment = trim((string) preg_replace('/^\s*translators:\s*/i', '', $comment));
        $named = [];
        if (preg_match_all('/(?:^|,)\s*(?:%(\d+)\$[a-z]|%[a-z]|(\d+))\s*:\s*([^,]+)/i', $comment, $m, PREG_SET_ORDER)) {
            $n = 0;
            foreach ($m as $x) {
                $num = ($x[1] ?? '') !== '' ? (int) $x[1] : (($x[2] ?? '') !== '' ? (int) $x[2] : ++$n);
                $named[$num] = trim($x[3]);
            }
        }
        $out = [];
        preg_match_all(self::TOKEN, self::numbered($original), $t, PREG_SET_ORDER);
        foreach ($t as $x) {
            if ($x[0] === '%%' || isset($out[$x[0]])) continue;
            $num = (int) ($x[1] ?? 0);
            $out[$x[0]] = $named[$num] ?? ('#' . $num);
        }
        return $out;
    }

    public static function length(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
