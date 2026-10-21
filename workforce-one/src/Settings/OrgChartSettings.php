<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Organization chart settings (3.31.97, owner decisions 2026-10-10, docs/tasks/org-chart.md), off until
 * turned on in Features:
 * - who sees the chart: administrators only (wp-admin), administrators and managers (managers see
 *   their part in the app), or everyone in the app;
 * - what an employee sees in the app: their line and teammates, or the whole company;
 * - flag a manager with more direct reports than `wide_span` (0 = never);
 * - a job title is required when adding an employee.
 * Pure: no WordPress calls. Stored as one option, ews_org_chart.
 */
final class OrgChartSettings
{
    public const AUDIENCES = ['admins', 'managers', 'everyone'];
    public const EMPLOYEE_VIEWS = ['line', 'company'];
    public const DEFAULTS = ['enabled' => 0, 'audience' => 'admins', 'employee_view' => 'line', 'wide_span' => 8, 'title_required' => 0];

    /** @param mixed $saved @return array{enabled:int,audience:string,employee_view:string,wide_span:int,title_required:int} */
    public static function config($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $span = $s['wide_span'] ?? self::DEFAULTS['wide_span'];
        return [
            'enabled' => empty($s['enabled']) ? 0 : 1,
            'audience' => in_array($s['audience'] ?? '', self::AUDIENCES, true) ? (string) $s['audience'] : self::DEFAULTS['audience'],
            'employee_view' => in_array($s['employee_view'] ?? '', self::EMPLOYEE_VIEWS, true) ? (string) $s['employee_view'] : self::DEFAULTS['employee_view'],
            'wide_span' => is_numeric($span) ? max(0, min(100, (int) $span)) : self::DEFAULTS['wide_span'],
            'title_required' => empty($s['title_required']) ? 0 : 1,
        ];
    }

    /** From the Features form. @param array<string,mixed> $post */
    public static function fromPost(array $post): array
    {
        return self::config([
            'enabled' => !empty($post['org_enabled']), 'audience' => $post['org_audience'] ?? '', 'employee_view' => $post['org_employee_view'] ?? '',
            'wide_span' => $post['org_wide_span'] ?? null, 'title_required' => !empty($post['org_title_required']),
        ]);
    }

    /**
     * May this person open My team in the app? Administrators (and people who manage employees)
     * always may; managers when the chart is for managers; everyone when it is for everyone.
     */
    public static function appAllowed(array $config, bool $isAdmin, bool $isManager): bool
    {
        if (empty($config['enabled'])) return false;
        if ($config['audience'] === 'everyone') return true;
        if ($config['audience'] === 'managers') return $isAdmin || $isManager;
        return false;
    }

    /** Job titles are free text, kept short and plain. */
    public static function title($v): string
    {
        $v = is_scalar($v) ? trim(preg_replace('/\s+/', ' ', strip_tags((string) $v))) : '';
        return function_exists('mb_substr') ? mb_substr($v, 0, 120) : substr($v, 0, 120);
    }
}
