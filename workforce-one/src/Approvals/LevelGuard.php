<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * A decision applies only to the approval level the person saw (3.31.122). Forms post the id of the
 * PENDING step they showed (`approval_step`), or 0 when they showed no open level. A form that does
 * not post it decides the current level, as before (owner decision). Pure.
 */
final class LevelGuard
{
    public const OK = 'ok';
    public const MOVED = 'moved';

    /** The posted step: null = the form did not say; a value that is not a step id = 0. @param mixed $raw */
    public static function posted($raw): ?int
    {
        if ($raw === null) return null;
        if (is_int($raw)) return max(0, $raw);
        if (!is_string($raw)) return 0;
        $raw = trim($raw);
        return $raw !== '' && ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    /** OK when the form did not say or showed the open level ($current: its step id, 0 = none open). */
    public static function check(?int $posted, int $current): string
    {
        return $posted === null || $posted === $current ? self::OK : self::MOVED;
    }
}
