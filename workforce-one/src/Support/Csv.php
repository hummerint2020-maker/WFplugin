<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/** CSV export helpers. Pure: no WordPress calls. */
final class Csv
{
    /**
     * A cell that starts with = + - @ (or a tab / carriage return) is run as a formula by
     * spreadsheet apps; prefix it with an apostrophe so it is shown as text ("CSV injection").
     * Plain negative numbers are left alone.
     */
    public static function cell($value): string
    {
        $value = (string) $value;
        if ($value === '' || is_numeric($value)) return $value;
        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /** @param array<int, mixed> $row */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
