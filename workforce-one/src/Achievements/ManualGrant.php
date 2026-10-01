<?php
namespace WorkforceOne\Achievements;

if (!defined('ABSPATH')) exit;

/** Rules of the Achievements admin page. Pure: no WordPress calls. */
final class ManualGrant
{
    public const ICONS = ['🏅', '🤝', '⭐', '🔥', '🏆', '💎', '👑', '🚀', '💡', '🎯', '🌟', '❤️'];
    public const STYLES = ['circle' => 'Circle', 'shield' => 'Shield', 'star' => 'Star', 'ribbon' => 'Ribbon'];
    public const DEFAULT_DESCRIPTION = 'A special recognition from Workforce One.';

    /**
     * @param array{name: string, description: string, icon: string, style: string, employee_found: bool, enabled: bool} $f
     * @return array{0: array{name: string, description: string, icon: string, style: string}, 1: ?string} [values, error code]
     */
    public static function check(array $f): array
    {
        $name = trim($f['name']);
        $values = [
            'name' => function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120),
            'description' => trim($f['description']) !== '' ? trim($f['description']) : self::DEFAULT_DESCRIPTION,
            'icon' => in_array($f['icon'], self::ICONS, true) ? $f['icon'] : self::ICONS[0],
            'style' => isset(self::STYLES[$f['style']]) ? $f['style'] : 'circle',
        ];
        if (empty($f['enabled'])) return [$values, 'disabled'];
        if ($name === '') return [$values, 'required'];
        if (empty($f['employee_found'])) return [$values, 'employee'];
        return [$values, null];
    }

    /** @return array{pct: int, near: bool} progress toward the next threshold; "near" from 80%. */
    public static function progress(int $current, int $target): array
    {
        $pct = $target > 0 ? (int) min(100, round($current / $target * 100)) : 100;
        return ['pct' => $pct, 'near' => $current > 0 && $target > $current && $pct >= 80];
    }

    public static function errorMessage(string $code): string
    {
        $messages = [
            'disabled' => 'Enable Achievements before granting an achievement.',
            'required' => 'An achievement name is required.',
            'employee' => 'Employee not found.',
            'save' => 'Could not grant the achievement.',
        ];
        return $messages[$code] ?? 'Could not grant the achievement.';
    }

    public static function noticeMessage(string $key): ?string
    {
        $messages = [
            'saved' => 'Achievement settings saved.',
            'granted' => 'Achievement granted successfully. The employee received the normal achievement notification.',
        ];
        return $messages[$key] ?? null;
    }
}
