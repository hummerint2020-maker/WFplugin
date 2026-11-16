<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * More time for a long export, between its parts (3.31.149). Never shortens the limit (0 = none
 * stays none), and does nothing where the host has turned set_time_limit() off: on PHP 8 calling
 * a disabled function is a fatal error that "@" does not hide.
 */
final class ExportTime
{
    public static function extend(int $seconds = 120): void
    {
        if (!function_exists('set_time_limit')) return;
        $now = (int) ini_get('max_execution_time');
        if ($now <= 0 || $now >= $seconds) return;
        @set_time_limit($seconds);
    }
}
