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
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'camera' => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3.2"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
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
        'office' => '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
        'wfh' => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 17l.8 2.2L22 20l-2.2.8L19 23l-.8-2.2L16 20l2.2-.8z"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'dots' => '<circle cx="12" cy="5" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="19" r="1.6" fill="currentColor"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'pin' => '<path d="M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'trophy' => '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8.5 21h7M10 17h4v4h-4z"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M15 8l2 2"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'swap' => '<path d="M7 4 3 8l4 4"/><path d="M3 8h14"/><path d="m17 20 4-4-4-4"/><path d="M21 16H7"/>',
        'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
        'share' => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'install' => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M12 8v6M9.5 11.5 12 14l2.5-2.5M10 18h4"/>',
    ];

    /** Menu key (Settings\Navigation) → icon. */
    private const VIEWS = [
        'dashboard' => 'home', 'schedule' => 'calendar', 'time' => 'clock', 'vacation' => 'leave', 'overtime' => 'overtime',
        'tasks' => 'tasks', 'polls' => 'polls', 'attendance' => 'attendance', 'people' => 'people', 'reports' => 'reports',
        'pay' => 'pay', 'attendance-insights' => 'insights', 'profile' => 'user', 'notifications' => 'bell', 'corrections' => 'history',
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

    /**
     * A schedule status (Office, WFH, a leave type, a custom type…) → icon and tone, so a status is
     * never shown by colour alone. Tones: office, wfh, leave, away, off, absent, none.
     * @return array{0:string,1:string}
     */
    public static function forStatus(string $status): array
    {
        $s = strtolower(trim($status));
        if ($s === '' || $s === 'not set') return ['calendar', 'none'];
        if ($s === 'office') return ['office', 'office'];
        if ($s === 'absent') return ['alert', 'absent'];
        if ($s === 'wfh' || strpos($s, 'home') !== false || strpos($s, 'remote') !== false) return ['wfh', 'wfh'];
        if (strpos($s, 'leave') !== false || strpos($s, 'vacation') !== false || strpos($s, 'sick') !== false || strpos($s, 'holiday') !== false) return ['leave', 'leave'];
        if (strpos($s, 'off') !== false || strpos($s, 'rest') !== false || strpos($s, 'weekend') !== false) return ['sun', 'off'];
        return ['briefcase', 'away'];
    }

    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }
}
