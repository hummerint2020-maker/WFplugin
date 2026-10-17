<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/** Small number formats shared by the app pages. Pure: no WordPress calls. */
final class Format
{
    /** 1.5, 2, 0.25 — at most two decimals, no trailing zeros. */
    public static function number(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** Minutes as hours: 100 → "1.67", 120 → "2". */
    public static function hours(int $minutes): string
    {
        return self::number($minutes / 60);
    }

    /** Minutes as [hours, minutes]: 90 → [1, 30]. */
    public static function split(int $minutes): array
    {
        $minutes = max(0, $minutes);
        return [intdiv($minutes, 60), $minutes % 60];
    }
}
