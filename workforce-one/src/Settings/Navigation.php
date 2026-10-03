<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * The app's navigation items and their admin-editable labels, visibility and order (one list,
 * used by the View Navigation page and by the app). Pure: no WordPress calls.
 */
final class Navigation
{
    public const DEFAULTS = [
        'dashboard' => ['label' => 'Dashboard', 'mobile_label' => 'Dashboard', 'icon' => '🏠', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 10, 'mobile_order' => 10],
        'schedule' => ['label' => 'Schedule', 'mobile_label' => 'Schedule', 'icon' => '📅', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 20, 'mobile_order' => 20],
        'time' => ['label' => 'Sign In / Out', 'mobile_label' => 'Sign In / Out', 'icon' => '🕘', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 30, 'mobile_order' => 30],
        'vacation' => ['label' => 'Leave', 'mobile_label' => 'Leave', 'icon' => '📝', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 40, 'mobile_order' => 40],
        'overtime' => ['label' => 'Overtime', 'mobile_label' => 'Overtime', 'icon' => '⏱️', 'desktop_visible' => 1, 'mobile_visible' => 0, 'desktop_order' => 50, 'mobile_order' => 50],
        'tasks' => ['label' => 'Tasks', 'mobile_label' => 'Tasks', 'icon' => '✅', 'desktop_visible' => 1, 'mobile_visible' => 0, 'desktop_order' => 60, 'mobile_order' => 60],
        'polls' => ['label' => 'Polls', 'mobile_label' => 'Polls', 'icon' => '🗳️', 'desktop_visible' => 1, 'mobile_visible' => 0, 'desktop_order' => 65, 'mobile_order' => 65],
        'attendance' => ['label' => 'Attendance', 'mobile_label' => 'Attendance', 'icon' => '📝', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 70, 'mobile_order' => 50],
        'people' => ['label' => 'People', 'mobile_label' => 'People', 'icon' => '👥', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 75, 'mobile_order' => 55],
        'reports' => ['label' => 'Reports', 'mobile_label' => 'Reports', 'icon' => '📊', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 80, 'mobile_order' => 60],
        'pay' => ['label' => 'My Pay', 'mobile_label' => 'My Pay', 'icon' => '💰', 'desktop_visible' => 1, 'mobile_visible' => 0, 'desktop_order' => 85, 'mobile_order' => 75],
        'attendance-insights' => ['label' => 'Attendance Insights', 'mobile_label' => 'Attendance Insights', 'icon' => '📈', 'desktop_visible' => 1, 'mobile_visible' => 1, 'desktop_order' => 90, 'mobile_order' => 70],
    ];

    /**
     * Saved settings merged over the defaults. Labels are expected to be sanitised already.
     * @param mixed $saved
     * @return array<string, array<string, mixed>>
     */
    public static function config($saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::DEFAULTS as $key => $def) {
            $row = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : [];
            $out[$key] = self::item($def, $row);
        }
        return $out;
    }

    /**
     * From the form: a missing checkbox means hidden.
     * @param mixed $posted nav[key][label|mobile_label|desktop_visible|mobile_visible|desktop_order|mobile_order]
     * @return array<string, array<string, mixed>>
     */
    public static function fromPost($posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        $out = [];
        foreach (self::DEFAULTS as $key => $def) {
            $row = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : [];
            $row['desktop_visible'] = !empty($row['desktop_visible']);
            $row['mobile_visible'] = !empty($row['mobile_visible']);
            $out[$key] = self::item($def, $row);
        }
        return $out;
    }

    /** @param array<string, mixed> $def @param array<string, mixed> $row */
    private static function item(array $def, array $row): array
    {
        $label = trim((string) ($row['label'] ?? ''));
        $mobile = trim((string) ($row['mobile_label'] ?? ''));
        return [
            'label' => $label !== '' ? self::cut($label, 60) : $def['label'],
            'mobile_label' => $mobile !== '' ? self::cut($mobile, 40) : $def['mobile_label'],
            'icon' => $def['icon'],
            'desktop_visible' => array_key_exists('desktop_visible', $row) ? (!empty($row['desktop_visible']) ? 1 : 0) : $def['desktop_visible'],
            'mobile_visible' => array_key_exists('mobile_visible', $row) ? (!empty($row['mobile_visible']) ? 1 : 0) : $def['mobile_visible'],
            'desktop_order' => max(1, (int) ($row['desktop_order'] ?? $def['desktop_order'])),
            'mobile_order' => max(1, (int) ($row['mobile_order'] ?? $def['mobile_order'])),
        ];
    }

    private static function cut(string $s, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }
}
