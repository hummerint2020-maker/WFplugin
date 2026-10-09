<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * Rewrites the plugin's CREATE TABLE statements into the shape WordPress dbDelta() can read
 * (3.31.73). dbDelta reads one column or key per line and only knows "KEY name (cols)" with a space
 * before the bracket; a table written on one line, or "PRIMARY KEY(id)", made it send broken ALTERs
 * (CHANGE COLUMN …, ADD `` (``)) on every upgrade and skip new indexes on sites that were already
 * installed. "IF NOT EXISTS" made it read the table name as "IF". Hooked on dbdelta_queries, so
 * every plugin table goes through it; other tables are left alone. Pure: no WordPress calls.
 */
final class SchemaSql
{
    /** Plugin tables: the name after the site's table prefix starts with this. */
    private const TABLE = 'ews_';

    /**
     * The dbdelta_queries filter.
     *
     * @param mixed $queries
     * @return mixed
     */
    public static function filterQueries($queries)
    {
        if (!is_array($queries)) return $queries;
        foreach ($queries as $k => $sql) {
            if (is_string($sql)) $queries[$k] = self::forDbDelta($sql);
        }
        return $queries;
    }

    /** One CREATE TABLE for a plugin table in dbDelta's shape; anything else unchanged. */
    public static function forDbDelta(string $sql): string
    {
        if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`?)([^\s`(]+)\1\s*\(/i', $sql, $m)) return $sql;
        if (strpos($m[2], self::TABLE) === false) return $sql;
        $open = strlen($m[0]) - 1;
        $close = self::closing($sql, $open);
        if ($close === null) return $sql;
        $lines = [];
        foreach (self::split(substr($sql, $open + 1, $close - $open - 1)) as $part) {
            $part = trim((string) preg_replace('/\s+/', ' ', $part));
            if ($part !== '') $lines[] = self::line($part);
        }
        if (!$lines) return $sql;
        $tail = trim((string) preg_replace('/\s+/', ' ', substr($sql, $close + 1)));
        return 'CREATE TABLE ' . $m[2] . " (\n" . implode(",\n", $lines) . "\n)" . ($tail !== '' ? ' ' . $tail : '');
    }

    /** A column stays as written; a key gets dbDelta's spelling. */
    private static function line(string $part): string
    {
        if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/i', $part, $m)) return 'PRIMARY KEY  (' . self::cols($m[1]) . ')';
        if (preg_match('/\(\s*[\d\s,]+\)$/', $part)) return $part;   // "key VARCHAR(190)" is a column, not a key
        if (preg_match('/^(UNIQUE|FULLTEXT|SPATIAL)(?:\s+(?:KEY|INDEX))?(?:\s+`?(\w+)`?)?\s*\((.+)\)$/i', $part, $m)) {
            return strtoupper($m[1]) . ' KEY ' . ($m[2] !== '' ? $m[2] : self::firstCol($m[3])) . ' (' . self::cols($m[3]) . ')';
        }
        if (preg_match('/^(?:KEY|INDEX)(?:\s+`?(\w+)`?)?\s*\((.+)\)$/i', $part, $m)) {
            return 'KEY ' . ($m[1] !== '' ? $m[1] : self::firstCol($m[2])) . ' (' . self::cols($m[2]) . ')';
        }
        return self::intWidth($part);
    }

    /**
     * "BIGINT UNSIGNED" → "BIGINT(20) UNSIGNED": MariaDB and MySQL before 8.0.17 report integer columns
     * with their display width, and dbDelta changed every such column on every upgrade because the
     * types did not match. MySQL 8.0.17+ drops the width and dbDelta ignores that difference there.
     */
    private static function intWidth(string $column): string
    {
        $widths = ['TINYINT' => [4, 3], 'SMALLINT' => [6, 5], 'MEDIUMINT' => [9, 8], 'INT' => [11, 10], 'INTEGER' => [11, 10], 'BIGINT' => [20, 20]];
        return (string) preg_replace_callback('/^(`?\w+`?\s+)(TINYINT|SMALLINT|MEDIUMINT|INTEGER|INT|BIGINT)(?![\w(])(\s+UNSIGNED\b)?/i', static function (array $m) use ($widths): string {
            $type = strtoupper($m[2]);
            $unsigned = isset($m[3]) && $m[3] !== '';
            return $m[1] . ($type === 'INTEGER' ? 'INT' : $type) . '(' . $widths[$type][$unsigned ? 1 : 0] . ')' . ($unsigned ? ' UNSIGNED' : '');
        }, $column, 1);
    }

    private static function cols(string $cols): string
    {
        return implode(',', array_map('trim', self::split($cols)));
    }

    private static function firstCol(string $cols): string
    {
        return trim((string) preg_replace('/\(.*$/', '', trim(self::split($cols)[0], " `")), ' `');
    }

    /** The bracket that closes the one at $open, or null. Quoted text is skipped. */
    private static function closing(string $sql, int $open): ?int
    {
        $depth = 0;
        $quote = '';
        for ($i = $open, $n = strlen($sql); $i < $n; $i++) {
            $c = $sql[$i];
            if ($quote !== '') {
                if ($c === '\\') $i++;
                elseif ($c === $quote) $quote = '';
                continue;
            }
            if ($c === "'" || $c === '"') $quote = $c;
            elseif ($c === '(') $depth++;
            elseif ($c === ')' && --$depth === 0) return $i;
        }
        return null;
    }

    /** @return string[] the parts between top-level commas (not inside brackets or quotes) */
    private static function split(string $body): array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $quote = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];
            if ($quote !== '') {
                $cur .= $c;
                if ($c === '\\' && $i + 1 < $n) $cur .= $body[++$i];
                elseif ($c === $quote) $quote = '';
                continue;
            }
            if ($c === "'" || $c === '"') $quote = $c;
            elseif ($c === '(') $depth++;
            elseif ($c === ')') $depth--;
            elseif ($c === ',' && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        $out[] = $cur;
        return $out;
    }
}
