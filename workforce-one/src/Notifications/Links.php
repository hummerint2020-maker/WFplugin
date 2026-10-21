<?php
namespace WorkforceOne\Notifications;

if (!defined('ABSPATH')) exit;

/**
 * Where a notification takes its reader (3.31.94, owner-approved list): the same place whether it is
 * opened from the phone (push) or from the app's Notifications page.
 * - Without a link of its own, a notification opens the app page of its kind (view()).
 * - A link into wp-admin is kept only for someone who may open that page; anyone else gets the
 *   app's Notifications page with the notification shown (no WordPress "not allowed" page).
 * - An app page that needs a permission the reader does not have does the same.
 * - In Notification Settings an administrator can choose another page for each kind (3.31.95):
 *   destinations() keeps the choices, apply() uses them.
 * Pure: no WordPress calls.
 */
final class Links
{
    /** Kind of notification (its entity, else its category) => app page. */
    public const VIEWS = [
        'schedule' => 'schedule', 'swap' => 'schedule',
        'leave' => 'vacation', 'vacation' => 'vacation', 'early_leave' => 'vacation',
        'overtime' => 'overtime', 'correction' => 'corrections', 'signout_reminder' => 'time', 'break' => 'time', 'face_reset' => 'time',
        'task' => 'tasks', 'payroll' => 'pay', 'achievement' => 'profile', 'recognition' => 'profile', 'kudos' => 'profile',
        'poll' => 'polls', 'presence' => 'presence', 'daily_workers' => 'sites', 'office_minimum' => 'attendance',
    ];

    /** wp-admin pages that notifications link to => the permission each needs (as on its menu entry). */
    public const ADMIN_PAGES = [
        'ews31-time-report' => 'ews_manage_time', 'ews31-requests' => 'manage_options',
        'ews31-daily-workers' => 'ews_manage_daily_workers', 'ews31-face-reset-requests' => 'ews_manage_employees',
    ];

    /** App pages that need a permission => that permission. */
    public const VIEW_CAPS = [
        'attendance' => 'ews_manage_attendance', 'employees' => 'ews_manage_employees', 'reports' => 'ews_view_reports',
        'attendance-insights' => 'ews_view_reports', 'people' => 'ews_view_people', 'employee' => 'ews_view_people',
    ];

    /** Pages an administrator can choose for a kind of notification (Notification Settings → Opens). */
    public const CHOICES = [
        'dashboard' => 'Home', 'schedule' => 'Schedule', 'time' => 'Sign In / Out', 'vacation' => 'Leave', 'overtime' => 'Overtime',
        'corrections' => 'Corrections', 'tasks' => 'Tasks', 'pay' => 'My Pay', 'profile' => 'My Profile', 'polls' => 'Polls',
        'presence' => 'Presence', 'notifications' => 'Notifications page',
    ];

    /** Kinds whose place is set by each notification, not by this setting. */
    public const NOT_CHOSEN = ['smart_nudge'];

    /** The app page for a notification of this kind. */
    public static function view(string $category, string $entity = ''): string
    {
        return self::VIEWS[$entity] ?? self::VIEWS[$category] ?? 'notifications';
    }

    /**
     * The permission a link needs: a wp-admin page's (manage_options for one not listed), an app page's,
     * or '' when anyone signed in may open it.
     * @param string $adminPath the path of wp-admin, e.g. "/wp-admin/"
     */
    public static function capability(string $url, string $adminPath): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if ($adminPath !== '' && strpos($path, $adminPath) === 0) {
            $page = is_string($q['page'] ?? null) ? $q['page'] : '';
            return self::ADMIN_PAGES[$page] ?? 'manage_options';
        }
        $view = is_string($q['ews_view'] ?? null) ? $q['ews_view'] : '';
        return self::VIEW_CAPS[$view] ?? '';
    }

    /**
     * The choices from the Notification Settings form, as stored: only kinds that do not use the
     * recommended page. A link must be on this site (a path, or this site's address); one that is not
     * is left out and reported.
     * @param mixed $posted opens[kind] => recommended | a CHOICES page | link, and opens_link[kind]
     * @param string[] $kinds
     * @return array{0:array<string,array{to:string,link?:string}>,1:string[]} the choices, and the kinds whose link was refused
     */
    public static function destinations($posted, $links, array $kinds, string $siteUrl): array
    {
        $posted = is_array($posted) ? $posted : [];
        $links = is_array($links) ? $links : [];
        $out = [];
        $refused = [];
        foreach ($kinds as $kind) {
            if (in_array($kind, self::NOT_CHOSEN, true)) continue;
            $to = is_string($posted[$kind] ?? null) ? $posted[$kind] : 'recommended';
            if (isset(self::CHOICES[$to])) { $out[$kind] = ['to' => $to]; continue; }
            if ($to !== 'link') continue;
            $link = self::sameSite(is_string($links[$kind] ?? null) ? trim($links[$kind]) : '', $siteUrl);
            if ($link === '') { $refused[] = $kind; continue; }
            $out[$kind] = ['to' => 'link', 'link' => $link];
        }
        return [$out, $refused];
    }

    /**
     * Where a notification of this kind goes: its own link ($link) unless the administrator chose
     * another page or a link for the kind.
     * @param array<string,array{to:string,link?:string}> $choices destinations() output
     * @param string $appUrl the app's page
     */
    public static function apply(array $choices, string $kind, string $link, string $appUrl, string $siteUrl): string
    {
        $c = $choices[$kind] ?? null;
        if (!is_array($c) || in_array($kind, self::NOT_CHOSEN, true)) return $link;
        $to = (string) ($c['to'] ?? '');
        if (isset(self::CHOICES[$to])) {
            $sep = strpos($appUrl, '?') === false ? '?' : '&';
            return $appUrl . $sep . 'ews_view=' . $to;
        }
        if ($to === 'link') {
            $own = self::sameSite((string) ($c['link'] ?? ''), $siteUrl);
            return $own !== '' ? $own : $link;
        }
        return $link;
    }

    /** A link on this site, made absolute; '' for one elsewhere or not a link. */
    public static function sameSite(string $link, string $siteUrl): string
    {
        $link = trim($link);
        if ($link === '' || preg_match('/[\s<>"]/', $link)) return '';
        $site = parse_url($siteUrl);
        if (!is_array($site) || empty($site['host'])) return '';
        $origin = ($site['scheme'] ?? 'https') . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '');
        if ($link[0] === '/' && substr($link, 0, 2) !== '//') return $origin . $link;
        $p = parse_url($link);
        if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) return '';
        if (strtolower((string) ($p['host'] ?? '')) !== strtolower((string) $site['host'])) return '';
        if ((int) ($p['port'] ?? 0) !== (int) ($site['port'] ?? 0)) return '';
        return $link;
    }
}
