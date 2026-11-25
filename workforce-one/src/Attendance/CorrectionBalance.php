<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Corrections balance in My requests (3.31.163, docs/tasks/corrections-balance.md). Pure: no WordPress calls.
 * What the "Your balance" strip shows for attendance corrections: how many of this month's corrections are left,
 * and what happens to the next one once none are left (it goes to HR, or it is refused).
 */
final class CorrectionBalance
{
    /**
     * @param array<string,mixed> $settings CorrectionSettings::config() (monthly_limit, above_limit)
     * @param int $used requests for days of this month that count towards the limit (CorrectionStore::monthCount)
     * @return array{left:int,limit:int,used:int,after:string}|null null when there is no monthly limit (nothing to count down);
     *   after: '' while some are left, else 'hr' (the next ones go to HR) or 'refuse' (no more this month)
     */
    public static function card(array $settings, int $used): ?array
    {
        $limit = (int) ($settings['monthly_limit'] ?? 0);
        if ($limit <= 0) return null;
        $used = max(0, $used);
        $left = max(0, $limit - $used);
        $after = '';
        if ($left === 0) $after = (($settings['above_limit'] ?? 'hr') === 'refuse') ? 'refuse' : 'hr';
        return ['left' => $left, 'limit' => $limit, 'used' => $used, 'after' => $after];
    }
}
