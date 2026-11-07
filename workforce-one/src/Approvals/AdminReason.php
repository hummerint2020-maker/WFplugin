<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * Why a level waits for the administrators (QA 8, docs/tasks/admin-fallback-reason.md). The engine keeps the reason on the
 * rerouted step in skip_reason ('self', 'missing' or 'no_login'; a skipped level keeps its own skip reason there); a level
 * moved up past the top is known from its escalation columns. Old rows without a reason give 'unknown' (a neutral text). Pure.
 */
final class AdminReason
{
    public const CODES = ['self', 'no_manager', 'no_managers_manager', 'no_team_manager', 'no_department_manager', 'no_hr', 'no_finance',
        'approver_gone', 'no_login', 'moved_up', 'unknown'];

    /** The reason code of a step, '' when it does not wait for the administrators. */
    public static function of(object $step): string
    {
        if (empty($step->rerouted) || (int) ($step->approver_wp_user_id ?? 0)) return '';
        if ((int) ($step->escalations ?? 0) > 0 && (int) ($step->escalated_from_wp_user_id ?? 0)) return 'moved_up';
        $why = (string) ($step->skip_reason ?? '');
        if ($why === 'self' || $why === 'no_login') return $why;
        if ($why === 'missing') return self::missing((string) ($step->resolver_type ?? ''));
        return 'unknown';
    }

    /** Nobody to decide it: which part of the setup is missing, by the level's resolver. */
    public static function missing(string $resolverType): string
    {
        $map = ['SUPERVISOR' => 'no_manager', 'MANAGERS_MANAGER' => 'no_managers_manager', 'TEAM_MANAGER' => 'no_team_manager',
            'DEPARTMENT_MANAGER' => 'no_department_manager', 'HR' => 'no_hr', 'FINANCE' => 'no_finance',
            'SPECIFIC_EMPLOYEE' => 'approver_gone', 'SPECIFIC_USER' => 'approver_gone'];
        return $map[strtoupper($resolverType)] ?? 'unknown';
    }
}
