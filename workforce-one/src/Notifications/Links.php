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
 * Pure: no WordPress calls.
 */
final class Links
{
    /** Kind of notification (its entity, else its category) => app page. */
    public const VIEWS = [
        'schedule' => 'schedule', 'swap' => 'schedule',
        'leave' => 'vacation', 'vacation' => 'vacation', 'early_leave' => 'vacation',
        'overtime' => 'overtime', 'correction' => 'corrections', 'break' => 'time', 'face_reset' => 'time',
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
}
