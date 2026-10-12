<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Settings of the two Sign In evidence features (3.31.92), both off until turned on in Features:
 * - refused attempts: each refused Sign In / Out (and face check) is kept with its reason, for
 *   `attempt_days` days; the manager is told after `alert` refusals of one employee in a day (0 = never);
 * - a photo with each Face Sign In / Out, kept `photo_days` days, seen by HR and the employee's
 *   department manager ('hr_manager') or by HR only ('hr').
 * Pure: no WordPress calls. Stored as one option, ews_signin_evidence.
 */
final class SignInEvidence
{
    public const DEFAULTS = ['attempts' => 0, 'attempt_days' => 60, 'alert' => 3, 'photo' => 0, 'photo_days' => 30, 'photo_viewers' => 'hr_manager'];

    /** @param mixed $saved @return array{attempts:int,attempt_days:int,alert:int,photo:int,photo_days:int,photo_viewers:string} */
    public static function config($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        return [
            'attempts' => empty($s['attempts']) ? 0 : 1,
            'attempt_days' => self::clamp($s['attempt_days'] ?? self::DEFAULTS['attempt_days'], 7, 365, self::DEFAULTS['attempt_days']),
            'alert' => self::clamp($s['alert'] ?? self::DEFAULTS['alert'], 0, 20, self::DEFAULTS['alert']),
            'photo' => empty($s['photo']) ? 0 : 1,
            'photo_days' => self::clamp($s['photo_days'] ?? self::DEFAULTS['photo_days'], 7, 90, self::DEFAULTS['photo_days']),
            'photo_viewers' => ($s['photo_viewers'] ?? '') === 'hr' ? 'hr' : 'hr_manager',
        ];
    }

    /** From the Features form. @param array<string,mixed> $post @return array{attempts:int,attempt_days:int,alert:int,photo:int,photo_days:int,photo_viewers:string} */
    public static function fromPost(array $post): array
    {
        return self::config([
            'attempts' => !empty($post['evidence_attempts']), 'attempt_days' => $post['evidence_attempt_days'] ?? null, 'alert' => $post['evidence_alert'] ?? null,
            'photo' => !empty($post['evidence_photo']), 'photo_days' => $post['evidence_photo_days'] ?? null, 'photo_viewers' => $post['evidence_photo_viewers'] ?? '',
        ]);
    }

    /**
     * A time the phone reported for an attempt that never reached the server, checked against the
     * server's own clock: the page carried the server time when it opened, and the browser counted the
     * milliseconds from then to the tap with a clock that changing the phone's time does not move.
     * @return array{at:int,phone:int,skew:int,sure:bool} unix seconds: the time to show, the phone's own time,
     *         their difference, and whether the server-based time can be trusted (false: the page had been
     *         open so long, e.g. through the phone's sleep, that the count may have paused)
     */
    public static function offlineTime(int $phoneMs, int $serverMsAtOpen, int $elapsedMs, int $receivedAt): array
    {
        $phone = intdiv(max(0, $phoneMs), 1000);
        $sure = $serverMsAtOpen > 0 && $elapsedMs >= 0 && $elapsedMs <= 6 * 3600 * 1000;
        $anchored = $sure ? intdiv($serverMsAtOpen + $elapsedMs, 1000) : $phone;
        // Never in the future of when it reached us.
        if ($anchored > $receivedAt) { $anchored = $receivedAt; }
        return ['at' => $anchored, 'phone' => $phone, 'skew' => $sure ? $phone - $anchored : 0, 'sure' => $sure];
    }

    /** @param mixed $v */
    private static function clamp($v, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($v)) return $fallback;
        return max($min, min($max, (int) $v));
    }
}
