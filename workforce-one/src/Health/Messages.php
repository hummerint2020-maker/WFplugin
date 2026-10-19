<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/** System health: PHP error messages with the values taken out. Pure. */
final class Messages
{
    public const MAX = 255;

    public static function normalize(string $msg): string
    {
        $s = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '?', $msg) ?? $msg;   // an address outside quotes
        $s = preg_replace('/\b[A-Fa-f0-9]{24,}\b/', '?', $s) ?? $s;                                   // a token or hash
        $s = preg_replace("/'[^']*'/", "'?'", $s) ?? $s;
        $s = preg_replace('/"[^"]*"/', '"?"', $s) ?? $s;
        $s = preg_replace('/(?<![\w])\d+(?:\.\d+)?(?![\w])/', 'N', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        return substr($s, 0, self::MAX);
    }

    /** The path relative to the plugin directory, or '' when the file is not the plugin's. */
    public static function relative(string $file, string $base): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $file = str_replace('\\', '/', $file);
        return strpos($file, $base) === 0 ? substr($file, strlen($base), 190) : '';
    }

    public static function level(int $errno): string
    {
        if ($errno & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) return 'error';
        if ($errno & (E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING)) return 'warning';
        if ($errno & (E_DEPRECATED | E_USER_DEPRECATED)) return 'deprecated';
        return 'notice';
    }

    public static function fingerprint(string $level, string $file, int $line): string
    {
        return substr(md5($level . '|' . $file . '|' . $line), 0, 16);
    }
}
