<?php
namespace WorkforceOne\Api\Auth;

if (!defined('ABSPATH')) exit;

/**
 * The client address for the login limits when the site is behind a proxy or a CDN (3.31.111). Pure.
 *
 * Only when the connecting address is one of the trusted proxies (addresses or CIDR ranges, IPv4 or IPv6) is
 * X-Forwarded-For read, from the right, skipping trusted proxies: the first valid address that is not one is the client.
 * Otherwise the connecting address is used and the header is ignored, so a client cannot choose its own address.
 */
final class ClientIp
{
    /**
     * @param list<string> $trusted
     */
    public static function resolve(string $remote, ?string $forwardedFor, array $trusted): string
    {
        $remote = self::clean($remote);
        if ($forwardedFor === null || $forwardedFor === '' || !self::isTrusted($remote, $trusted)) return $remote;
        $hops = array_reverse(array_map([self::class, 'clean'], explode(',', $forwardedFor)));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) return $remote;   // junk: do not guess
            if (!self::isTrusted($hop, $trusted)) return $hop;
        }
        return $remote;
    }

    /** An address as a proxy may write it: "1.2.3.4:5678", "[2001:db8::1]:443", "::ffff:1.2.3.4" become plain addresses. */
    public static function clean(string $ip): string
    {
        $ip = trim($ip);
        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $ip, $m)) $ip = $m[1];
        elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m)) $ip = $m[1];
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) $ip = $m[1];
        return $ip;
    }

    /** @param list<string> $trusted */
    public static function isTrusted(string $ip, array $trusted): bool
    {
        foreach ($trusted as $entry) if (self::matches($ip, $entry)) return true;
        return false;
    }

    private static function matches(string $ip, string $entry): bool
    {
        $a = @inet_pton($ip);
        if ($a === false) return false;
        if (strpos($entry, '/') === false) {
            $b = @inet_pton($entry);
            return $b !== false && $a === $b;
        }
        [$net, $bits] = explode('/', $entry, 2);
        $b = @inet_pton($net);
        if ($b === false || !ctype_digit($bits) || strlen($a) !== strlen($b)) return false;
        $bits = (int) $bits;
        if ($bits > strlen($b) * 8) return false;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
