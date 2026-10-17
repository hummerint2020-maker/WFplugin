<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Face verification decisions. Pure: no WordPress calls.
 *
 * Only the decision leaves the server: returning the distance (as up to 3.31.45) let a client
 * adjust a descriptor step by step towards a match (a similarity oracle). Failed attempts are
 * limited per user: MAX_FAILURES in WINDOW_SECONDS, then refused until the window ends. A descriptor
 * already seen (the enrolled one, or one accepted recently) is a replay, not a live capture (3.31.91).
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
     * Distance below which two descriptors are the same capture sent again (3.31.91). Two live camera
     * captures of the same face are never this close (they differ by about 0.1 or more); a descriptor
     * recorded once and sent again, or the enrolled template itself, is.
     */
    public const REPLAY_DISTANCE = 0.02;

    /** Euclidean distance; null when the lengths differ. @param array<int,mixed> $a @param array<int,mixed> $b */
    public static function distance(array $a, array $b): ?float
    {
        if (count($a) !== count($b) || !$a) return null;
        $sum = 0.0;
        foreach (array_values($a) as $i => $v) {
            $d = (float) $v - (float) (array_values($b)[$i] ?? 0);
            $sum += $d * $d;
        }
        return sqrt($sum);
    }

    /**
     * Is this descriptor one already seen: the enrolled template, or one accepted recently?
     * @param array<int,mixed> $probe
     * @param array<int,array<int,mixed>> $seen
     */
    public static function isReplay(array $probe, array $seen): bool
    {
        foreach ($seen as $s) {
            $d = self::distance($probe, (array) $s);
            if ($d !== null && $d < self::REPLAY_DISTANCE) return true;
        }
        return false;
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
