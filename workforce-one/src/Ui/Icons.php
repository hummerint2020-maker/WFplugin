<?php
namespace WorkforceOne\Ui;

if (!defined('ABSPATH')) exit;

/**
 * The app's line icons (24 × 24, stroke = currentColor) as inline SVG, so they follow the text
 * colour and the theme. Decorative: always aria-hidden. Pure: no WordPress calls.
 */
final class Icons
{
    private const PATHS = [
        'home' => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'clock' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/>',
        'leave' => '<path d="M12 3a8 8 0 0 0-8 8h16a8 8 0 0 0-8-8z"/><path d="M12 11v8a2 2 0 0 1-4 0"/>',
        'overtime' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'tasks' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'polls' => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'attendance' => '<path d="M9 4h6v3H9z"/><path d="M9 5.5H6a1 1 0 0 0-1 1V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6.5a1 1 0 0 0-1-1h-3"/><path d="M9 14l2 2 4-4"/>',
        'people' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
        'reports' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'pay' => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
        'insights' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'more' => '<rect x="4" y="4" width="6" height="6" rx="1.5"/><rect x="14" y="4" width="6" height="6" rx="1.5"/><rect x="4" y="14" width="6" height="6" rx="1.5"/><rect x="14" y="14" width="6" height="6" rx="1.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'chevron' => '<path d="M9 18l6-6-6-6"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'install' => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M12 8v6M9.5 11.5 12 14l2.5-2.5M10 18h4"/>',
    ];

    /** Menu key (Settings\Navigation) → icon. */
    private const VIEWS = [
        'dashboard' => 'home', 'schedule' => 'calendar', 'time' => 'clock', 'vacation' => 'leave', 'overtime' => 'overtime',
        'tasks' => 'tasks', 'polls' => 'polls', 'attendance' => 'attendance', 'people' => 'people', 'reports' => 'reports',
        'pay' => 'pay', 'attendance-insights' => 'insights', 'profile' => 'user', 'notifications' => 'bell',
    ];

    public static function svg(string $name, int $size = 22, float $stroke = 1.8): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['more'];
        return '<svg class="wfo-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $stroke
            . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    public static function forView(string $view, int $size = 22, float $stroke = 1.8): string
    {
        return self::svg(self::VIEWS[$view] ?? 'more', $size, $stroke);
    }

    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }
}
