<?php
namespace WorkforceOne\Api\Auth;

if (!defined('ABSPATH')) exit;

/**
 * Native app tokens: opaque random strings, never JWTs. Pure: no WordPress calls.
 *
 *   access:  wfo_at_<site>_<43 chars>   lives 15 minutes
 *   refresh: wfo_rt_<site>_<43 chars>   lives 60 days from its issue, and never past the device
 *                                       session's absolute limit (180 days from login)
 *
 * <site> is 8 hex characters identifying the Workforce One site (a token from another site is
 * refused before any lookup, and leaked tokens are easy to recognise). The random part is 32 bytes
 * from random_bytes(). The server stores only an HMAC-SHA256 of the whole token keyed with a site
 * secret, so the database never holds a usable token.
 */
final class Tokens
{
    public const ACCESS = 'access';
    public const REFRESH = 'refresh';
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 60 * 86400;
    public const SESSION_MAX = 180 * 86400;

    public const OK = 'ok';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';
    public const USED = 'used';
    public const DEVICE_REVOKED = 'device_revoked';

    private const PREFIX = [self::ACCESS => 'wfo_at_', self::REFRESH => 'wfo_rt_'];

    /** 8 hex characters for a site, from its home URL. */
    public static function siteId(string $homeUrl): string
    {
        return substr(hash('sha256', strtolower(rtrim($homeUrl, '/'))), 0, 8);
    }

    public static function make(string $kind, string $siteId, string $random32): string
    {
        return self::PREFIX[$kind] . $siteId . '_' . rtrim(strtr(base64_encode($random32), '+/', '-_'), '=');
    }

    /**
     * The kind of a well-formed token of this site, or null (wrong shape, wrong site, wrong kind).
     */
    public static function kindOf(string $token, string $siteId, ?string $expected = null): ?string
    {
        if (!preg_match('/^wfo_(at|rt)_([0-9a-f]{8})_[A-Za-z0-9_-]{43}$/D', $token, $m)) return null;
        if (!hash_equals($siteId, $m[2])) return null;
        $kind = $m[1] === 'at' ? self::ACCESS : self::REFRESH;
        return ($expected === null || $expected === $kind) ? $kind : null;
    }

    public static function hash(string $token, string $secret): string
    {
        return hash_hmac('sha256', $token, $secret);
    }

    /**
     * @param array{expires_at:int,revoked_at?:int|null,device_revoked_at?:int|null} $row Unix times
     */
    public static function accessStatus(array $row, int $now): string
    {
        if (!empty($row['device_revoked_at'])) return self::DEVICE_REVOKED;
        if (!empty($row['revoked_at'])) return self::REVOKED;
        if ($now >= (int) $row['expires_at']) return self::EXPIRED;
        return self::OK;
    }

    /**
     * Order matters: a used refresh token is a reuse (possible theft) even after it expired.
     * @param array{expires_at:int,used_at?:int|null,revoked_at?:int|null,device_revoked_at?:int|null,session_expires_at:int} $row
     */
    public static function refreshStatus(array $row, int $now): string
    {
        if (!empty($row['device_revoked_at'])) return self::DEVICE_REVOKED;
        if (!empty($row['used_at'])) return self::USED;
        if (!empty($row['revoked_at'])) return self::REVOKED;
        if ($now >= (int) $row['expires_at'] || $now >= (int) $row['session_expires_at']) return self::EXPIRED;
        return self::OK;
    }

    /** When a refresh token issued now expires: 60 days, capped by the session's absolute limit. */
    public static function refreshExpiry(int $now, int $sessionExpiresAt): int
    {
        return min($now + self::REFRESH_TTL, $sessionExpiresAt);
    }

    public static function accessExpiry(int $now, int $sessionExpiresAt): int
    {
        return min($now + self::ACCESS_TTL, $sessionExpiresAt);
    }
}
