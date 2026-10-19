<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/**
 * System health: a stable name for each request, with no personal data. Only code identifiers
 * from fixed lists are used; a request's URL and query string are never read here. Pure.
 */
final class Routes
{
    /** The plugin's scheduled jobs. */
    public const CRON_HOOKS = [
        'ews_auto_attendance_tick', 'ews_smart_nudges_tick', 'ews_tasks_tick', 'ews_corrections_tick', 'ews_approvals_tick',
        'ews_office_minimum_tick', 'ews_privacy_cleanup', 'ews_notifications_cleanup', 'ews_push_deliver',
        'ews_break_duration_reminder', 'ews_break_manager_escalation', 'ews_health_rollup',
    ];

    /** Heavy operations always sampled for query detail (they run rarely). */
    public const ALWAYS_SAMPLE = [
        'post:ews_payroll_close', 'post:ews_payroll_reopen', 'post:ews_payroll_export', 'post:ews_payroll_pdf', 'post:ews_payroll_payslip',
        'post:ews31_report_xlsx', 'post:ews31_report_download', 'post:ews31_report_email', 'post:ews31_att_import',
        'post:ews_org_import_apply', 'post:ews_dw_export', 'post:ews_dw_payout_pdf',
    ];

    private const AREAS = ['admin' => 'wp-admin', 'app' => 'App', 'post' => 'Actions', 'rest' => 'API', 'cron' => 'Jobs', 'other' => 'Other'];

    /**
     * @param array{doing_cron?:bool,cron_hook?:string,is_admin?:bool,script?:string,page?:string,page_known?:bool,action?:string,action_known?:bool,
     *   app_page?:bool,view?:string,views?:list<string>,rest_route?:string,rest_method?:string,namespace?:string} $f
     */
    public static function classify(array $f): string
    {
        if (!empty($f['doing_cron']) && isset($f['cron_hook'])) {
            return 'cron:' . (in_array($f['cron_hook'], self::CRON_HOOKS, true) ? $f['cron_hook'] : 'other');
        }
        if (isset($f['rest_route']) && $f['rest_route'] !== '') {
            $p = self::restPattern($f['rest_route'], (string) ($f['namespace'] ?? 'workforce-one/v1'));
            return $p === null ? 'rest:other' : 'rest:' . self::method((string) ($f['rest_method'] ?? '')) . ' ' . $p;
        }
        if (($f['script'] ?? '') === 'admin-post.php') {
            $a = (string) ($f['action'] ?? '');
            return 'post:' . (strpos($a, 'ews') === 0 && self::slug($a) && !empty($f['action_known']) ? $a : 'other');
        }
        if (!empty($f['is_admin'])) {
            $p = (string) ($f['page'] ?? '');
            return strpos($p, 'ews31') === 0 && self::slug($p) && !empty($f['page_known']) ? 'admin:' . $p : 'other:admin';
        }
        if (!empty($f['app_page'])) {
            $v = (string) ($f['view'] ?? '');
            if ($v === '') return 'app:default';
            return self::slug($v) && in_array($v, (array) ($f['views'] ?? []), true) ? 'app:' . $v : 'app:other';
        }
        return 'other:front';
    }

    /** '/workforce-one/v1/corrections/(?P<id>\d+)/decision' → '/corrections/{id}/decision', or null. */
    public static function restPattern(string $route, string $namespace = 'workforce-one/v1'): ?string
    {
        $prefix = '/' . trim($namespace, '/');
        if (strpos($route, $prefix . '/') !== 0 && $route !== $prefix) return null;
        $rest = substr($route, strlen($prefix));
        $rest = preg_replace('/\(\?P<([a-z_]+)>[^()]*(?:\([^()]*\)[^()]*)*\)/i', '{$1}', $rest);
        if (!is_string($rest) || $rest === '') $rest = '/';
        return preg_match('/^[A-Za-z0-9_\-\/{}]{1,120}$/', $rest) ? $rest : null;
    }

    public static function slug(string $s): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]{1,60}$/', $s);
    }

    /** "wp-admin", "App" …: the area a route belongs to. */
    public static function area(string $route): string
    {
        $k = strstr($route, ':', true);
        return self::AREAS[$k === false ? 'other' : $k] ?? 'Other';
    }

    public static function alwaysSampled(string $route): bool
    {
        return in_array($route, self::ALWAYS_SAMPLE, true) || strpos($route, 'cron:') === 0;
    }

    /** Heavy operations and jobs get the larger "slow" limit. */
    public static function heavy(string $route): bool
    {
        return in_array($route, self::ALWAYS_SAMPLE, true) || strpos($route, 'cron:') === 0;
    }

    private static function method(string $m): string
    {
        $m = strtoupper($m);
        return in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $m : 'GET';
    }
}
