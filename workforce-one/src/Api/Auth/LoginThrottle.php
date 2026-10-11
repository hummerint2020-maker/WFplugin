<?php
namespace WorkforceOne\Api\Auth;

if (!defined('ABSPATH')) exit;

/**
 * Login attempt limits for the native API. Pure.
 *
 * Failed logins are counted per user name (5 in 15 minutes) and per client address (20 in
 * 15 minutes) in fixed windows that start at the first failure. While a bucket is full every login
 * for it is refused with RATE_LIMITED and Retry-After, right or wrong password, existing user or
 * not, so the answer never tells whether a user name exists.
 */
final class LoginThrottle
{
    public const USER = 'login_user';
    public const IP = 'login_ip';
    public const WINDOW = 900;
    public const LIMITS = [self::USER => 5, self::IP => 20];

    /**
     * Failures still counting in the current window.
     * @param array{window_start:int,count:int}|null $row
     */
    public static function count(?array $row, int $now): int
    {
        if (!$row || $now - (int) $row['window_start'] >= self::WINDOW) return 0;
        return (int) $row['count'];
    }

    /** @param array{window_start:int,count:int}|null $row */
    public static function blocked(string $bucket, ?array $row, int $now): bool
    {
        return self::count($row, $now) >= self::LIMITS[$bucket];
    }

    /** Seconds until the bucket opens again (at least 1). @param array{window_start:int,count:int}|null $row */
    public static function retryAfter(?array $row, int $now): int
    {
        return $row ? max(1, (int) $row['window_start'] + self::WINDOW - $now) : 1;
    }

    /** The key a bucket is stored under: never the raw user name or address. */
    public static function key(string $bucket, string $value, string $secret): string
    {
        $value = $bucket === self::USER ? strtolower(trim($value)) : trim($value);
        return hash_hmac('sha256', $bucket . '|' . $value, $secret);
    }
}
