<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Face verification decisions. Pure: no WordPress calls.
 *
 * Only the decision leaves the server: returning the distance (as up to 3.31.45) let a client
 * adjust a descriptor step by step towards a match (a similarity oracle). Failed attempts are
 * limited per user: MAX_FAILURES in WINDOW_SECONDS, then refused until the window ends.
 */
final class FaceMatch
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 600;

    /**
     * @param array<int,mixed> $probe  descriptor sent by the browser
     * @param array<int,mixed> $stored enrolled descriptor
     * @return bool|null null when the descriptors have different lengths
     */
    public static function matches(array $probe, array $stored, float $threshold): ?bool
    {
        if (count($probe) !== count($stored)) return null;
        $sum = 0.0;
        foreach ($probe as $i => $v) {
            $d = (float) $v - (float) ($stored[$i] ?? 0);
            $sum += $d * $d;
        }
        return sqrt($sum) < $threshold;
    }

    /**
     * @param array{since?:int,failures?:int}|null $state the user's recent failures
     */
    public static function lockedOut(?array $state, int $now): bool
    {
        return self::retryAfter($state, $now) > 0;
    }

    /** Seconds until verification may be tried again (0 = now). @param array{since?:int,failures?:int}|null $state */
    public static function retryAfter(?array $state, int $now): int
    {
        $state = self::current($state, $now);
        if ($state['failures'] < self::MAX_FAILURES) return 0;
        return max(1, $state['since'] + self::WINDOW_SECONDS - $now);
    }

    /**
     * The state after one more failed attempt.
     * @param array{since?:int,failures?:int}|null $state
     * @return array{since:int,failures:int}
     */
    public static function afterFailure(?array $state, int $now): array
    {
        $state = self::current($state, $now);
        if ($state['failures'] === 0) $state['since'] = $now;
        $state['failures']++;
        return $state;
    }

    /**
     * @param array{since?:int,failures?:int}|null $state
     * @return array{since:int,failures:int}
     */
    private static function current(?array $state, int $now): array
    {
        $since = (int) ($state['since'] ?? 0);
        $failures = (int) ($state['failures'] ?? 0);
        if ($failures <= 0 || $now - $since >= self::WINDOW_SECONDS) return ['since' => $now, 'failures' => 0];
        return ['since' => $since, 'failures' => $failures];
    }
}
