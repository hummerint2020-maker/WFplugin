<?php
namespace WorkforceOne\Api\Auth;

if (!defined('ABSPATH')) exit;

/**
 * Where the native API reads the access token from. Pure.
 *
 * Only two request headers: "Authorization: Bearer <token>" first, then
 * "X-WFO-Authorization: Bearer <token>" for hosts that strip the Authorization header. Never the
 * query string, the URL, a cookie or the body (they end up in logs, browser history and caches).
 */
final class Bearer
{
    public const HEADER = 'authorization';
    public const FALLBACK_HEADER = 'x_wfo_authorization';

    /** The bearer token, or null when neither header carries one. */
    public static function fromHeaders(?string $authorization, ?string $fallback): ?string
    {
        foreach ([$authorization, $fallback] as $value) {
            $token = self::parse($value);
            if ($token !== null) return $token;
        }
        return null;
    }

    private static function parse(?string $value): ?string
    {
        if ($value === null) return null;
        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/iD', $value, $m)) return null;
        return strlen($m[1]) <= 200 ? $m[1] : null;
    }
}
