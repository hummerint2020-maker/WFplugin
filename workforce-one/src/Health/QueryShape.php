<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/**
 * System health: what is kept of a database query. Literals are removed, so the stored shape cannot
 * tell who or what the query was about. Pure.
 */
final class QueryShape
{
    public const MAX = 500;

    /** What is read of a query before it is shaped: a longer one is cut (the shape keeps 500 characters anyway). */
    private const READ = 20000;

    /**
     * Strings and comments are found in one pass, so a "#" or "--" inside a value does not start a comment and a quote inside
     * a comment does not start a string. A quote left open to the end is a value too. If the text cannot be parsed, a fixed
     * mark is returned: the input never is.
     */
    public static function normalize(string $sql, string $prefix = ''): string
    {
        $s = substr($sql, 0, self::READ);
        $s = preg_replace_callback('~(\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|/\*.*?\*/|--[ \t][^\n]*|\#[^\n]*)~s', static function (array $m): string {
            return $m[0][0] === '\'' || $m[0][0] === '"' ? '?' : ' ';
        }, $s);
        if (!is_string($s) || preg_last_error() !== PREG_NO_ERROR) return '[unparsed]';
        $s = preg_replace('/[\'"].*$/s', '?', $s);                         // a quote that never closes
        if (!is_string($s)) return '[unparsed]';
        $steps = [
            ['/\b0x[0-9a-f]+\b/i', '?'],
            ['/(?<![\w`$)\]?])-?\d+(?:\.\d+)?(?![\w`])/', '?'],
            ['/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)'],                 // IN (?, ?, ?) and one VALUES row
            ['/\(\?\)(?:\s*,\s*\(\?\))+/', '(?)'],                   // several VALUES rows
        ];
        foreach ($steps as [$re, $to]) {
            $s = preg_replace($re, $to, $s);
            if (!is_string($s)) return '[unparsed]';
        }
        if ($prefix !== '') {
            $s = preg_replace('/(?<![\w])' . preg_quote($prefix, '/') . '/', '{p}', $s);
            if (!is_string($s)) return '[unparsed]';
        }
        $s = preg_replace('/\s+/', ' ', $s);
        return is_string($s) ? substr(trim($s), 0, self::MAX) : '[unparsed]';
    }

    public static function fingerprint(string $shape, string $caller): string
    {
        return substr(md5($shape . '|' . $caller), 0, 16);
    }

    /**
     * The deepest frame that belongs to the plugin, from wp_debug_backtrace_summary()
     * ("a(), b, EWS_Manager_V31_1->people_name, wpdb->get_results"), or ''.
     */
    public static function caller(string $summary): string
    {
        $found = '';
        foreach (explode(',', $summary) as $frame) {
            $frame = trim($frame);
            if (preg_match('/^EWS_Manager_V31_1->([A-Za-z0-9_]{1,80})/', $frame, $m)) $found = $m[1];
            elseif (preg_match('/^(WorkforceOne\\\\[A-Za-z0-9_\\\\]+?)(?:::|->)([A-Za-z0-9_]{1,80})/', $frame, $m)) $found = substr(str_replace('WorkforceOne\\', '', $m[1]), -60) . '::' . $m[2];
        }
        return substr($found, 0, 120);
    }
}
