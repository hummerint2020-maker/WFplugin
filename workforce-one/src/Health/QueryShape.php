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

    public static function normalize(string $sql, string $prefix = ''): string
    {
        $s = preg_replace('~/\*.*?\*/~s', ' ', $sql) ?? $sql;
        $s = preg_replace('/(--|#)[^\n]*/', ' ', $s) ?? $s;
        // Quoted strings, with escaped and doubled quotes.
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $s) ?? $s;
        $s = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '?', $s) ?? $s;
        $s = preg_replace('/\b0x[0-9a-f]+\b/i', '?', $s) ?? $s;
        $s = preg_replace('/(?<![\w`$)\]?])-?\d+(?:\.\d+)?(?![\w`])/', '?', $s) ?? $s;
        // IN (?, ?, ?) and VALUES (?, ?), (?, ?) become one placeholder group.
        $s = preg_replace('/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)', $s) ?? $s;
        $s = preg_replace('/\(\?\)(?:\s*,\s*\(\?\))+/', '(?)', $s) ?? $s;
        if ($prefix !== '') $s = preg_replace('/(?<![\w])' . preg_quote($prefix, '/') . '/', '{p}', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        return substr($s, 0, self::MAX);
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
