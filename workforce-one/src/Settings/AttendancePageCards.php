<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Which cards the Attendance page shows above its grid (3.31.152, option ews_attendance_page_cards,
 * docs/tasks/leave-type-attendance.md decision 6): chosen by the administrator for the whole company. The hidden ones are
 * stored, so a card added later is shown. All shown by default; Leave days only with the leave type on. A hidden card is
 * only taken off the page. Pure: no WordPress calls.
 */
final class AttendancePageCards
{
    /** The display order. */
    public const KEYS = ['people', 'present', 'late', 'absent', 'leave', 'rate'];

    /** @param mixed $saved @return array{hidden:list<string>} */
    public static function config($saved): array
    {
        $h = is_array($saved) && isset($saved['hidden']) && is_array($saved['hidden']) ? $saved['hidden'] : [];
        $hidden = [];
        foreach (self::KEYS as $k) if (in_array($k, $h, true)) $hidden[] = $k;
        return ['hidden' => $hidden];
    }

    /**
     * From Feature Configuration: the boxes ticked.
     * @param array<int|string,mixed> $checked
     * @return array{hidden:list<string>}
     */
    public static function fromPost(array $checked): array
    {
        $on = array_map('strval', array_values(array_filter($checked, 'is_scalar')));
        return ['hidden' => array_values(array_diff(self::KEYS, $on))];
    }

    /**
     * The cards to print, in order.
     * @param array{hidden:list<string>} $config
     * @return list<string>
     */
    public static function shown(array $config, bool $leaveTypeOn): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            if ($k === 'leave' && !$leaveTypeOn) continue;
            if (!in_array($k, $config['hidden'], true)) $out[] = $k;
        }
        return $out;
    }
}
