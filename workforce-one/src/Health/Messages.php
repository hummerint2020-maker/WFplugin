<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/** System health: PHP error messages with the values taken out. Pure. */
final class Messages
{
    public const MAX = 255;

    public static function normalize(string $msg): string
    {
        $s = (string) strstr($msg . "\nStack trace:", "\nStack trace:", true);                 // the trace carries paths and values
        $steps = [
            ['/(Undefined (?:index|offset)):\s*.*$/s', '$1: ?'],                                  // PHP 7: the key is not quoted
            ['/(Undefined property: [A-Za-z0-9_\\\\]+::\$)[A-Za-z0-9_]+/', '$1?'],
            ['/(?:[A-Za-z]:)?[\\\\\/][^\s\'":]+\.php(?::\d+)?/', '?'],                          // an absolute path
            ['/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '?'],                         // an address outside quotes
            ['/\b[A-Fa-f0-9]{24,}\b/', '?'],                                                      // a token or hash
            ["/'[^']*'/", "'?'"],
            ['/"[^"]*"/', '"?"'],
            ['/(?<![\w])\d+(?:\.\d+)?(?![\w])/', 'N'],
        ];
        foreach ($steps as [$re, $to]) {
            $s = preg_replace($re, $to, $s);
            if (!is_string($s)) return '[unparsed]';
        }
        return substr(trim(preg_replace('/\s+/', ' ', $s) ?? ''), 0, self::MAX);
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
