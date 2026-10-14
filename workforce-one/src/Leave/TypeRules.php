<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * What a leave type allows (3.31.99, owner decisions 2026-10-14, docs/tasks/approvals.md):
 * - Planned leave must be asked before the day starts (the earliest day is tomorrow), as every leave
 *   was before.
 * - Unplanned leave (sick, emergency) can be asked on its own, on the day or up to `after_days` days
 *   after it (a setting per type); it uses its own balance.
 * - What is left of a year's balance at year end: not carried over (the default, as before), all of it,
 *   or up to `carry_max` days. The balance renews every 1 January.
 * Pure: no WordPress calls.
 */
final class TypeRules
{
    public const KINDS = ['planned', 'unplanned'];
    public const CARRY = ['none', 'all', 'max'];
    /** How far back an unplanned leave may be asked, at most. */
    public const MAX_AFTER_DAYS = 60;

    /** @param object|array<string,mixed>|null $type a leave type row @return array{kind:string,after_days:int,carry:string,carry_max:float} */
    public static function config($type): array
    {
        $t = (array) $type;
        $kind = in_array($t['kind'] ?? '', self::KINDS, true) ? (string) $t['kind'] : 'planned';
        $carry = in_array($t['carry_mode'] ?? '', self::CARRY, true) ? (string) $t['carry_mode'] : 'none';
        return [
            'kind' => $kind,
            'after_days' => $kind === 'unplanned' ? max(0, min(self::MAX_AFTER_DAYS, (int) ($t['after_days'] ?? 0))) : 0,
            'carry' => $carry,
            'carry_max' => $carry === 'max' ? max(0.0, (float) ($t['carry_max'] ?? 0)) : 0.0,
        ];
    }

    /** From the Leaves page form. @param array<string,mixed> $post @return array{kind:string,after_days:int,carry_mode:string,carry_max:float} */
    public static function fromPost(array $post): array
    {
        $c = self::config(['kind' => $post['kind'] ?? '', 'after_days' => $post['after_days'] ?? 0, 'carry_mode' => $post['carry_mode'] ?? '', 'carry_max' => $post['carry_max'] ?? 0]);
        return ['kind' => $c['kind'], 'after_days' => $c['after_days'], 'carry_mode' => $c['carry'], 'carry_max' => round($c['carry_max'], 2)];
    }

    /** The first day a new request of this type may start on. */
    public static function earliestStart(array $config, string $today): string
    {
        $days = $config['kind'] === 'unplanned' ? -$config['after_days'] : 1;
        return gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') + $days * 86400);
    }

    /** Days carried into the next year from what was left of this one. */
    public static function carried(array $config, float $entitlement, float $used, float $pending): float
    {
        $left = max(0.0, $entitlement - $used - $pending);
        if ($config['carry'] === 'all') return round($left, 2);
        if ($config['carry'] === 'max') return round(min($left, $config['carry_max']), 2);
        return 0.0;
    }
}
