<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Manager office minimum (3.31.153, docs/tasks/manager-office-minimum.md): how many team managers are planned in the office
 * on a day against the company's managers' minimum, next to the Office minimum. A manager of two teams counts once. Pure: no
 * WordPress calls.
 */
final class ManagerMinimum
{
    private const ORDER = ['office' => 0, 'wfh' => 1];

    /**
     * The managers' minimum on a date: the weekday's own number when set, else the company's.
     * @param array{mgr_min:int,mgr_days:array<int,int>} $cfg
     */
    public static function minimumFor(array $cfg, string $date): int
    {
        return OfficeMinimum::minimumFor(['min' => (int) $cfg['mgr_min'], 'days' => (array) $cfg['mgr_days']], $date);
    }

    /**
     * One day. $plans: each manager's plan that day, kind office (counts) | wfh (could come in) | anything else (neither).
     * Rows: in the office first, then working from home, then the others; then by name.
     * @param array<int,array{kind:string,status:string}> $plans employee id => plan
     * @param array<int,string> $names employee id => name
     * @return array{min:int,office:int,short:int,rows:list<array{id:int,name:string,kind:string,status:string}>,can:list<int>}
     */
    public static function day(int $min, array $plans, array $names): array
    {
        $rows = [];
        $office = 0;
        $can = [];
        foreach ($plans as $id => $p) {
            $kind = (string) $p['kind'];
            if ($kind === 'office') $office++;
            elseif ($kind === 'wfh') $can[] = (int) $id;
            $rows[] = ['id' => (int) $id, 'name' => (string) ($names[$id] ?? ''), 'kind' => $kind, 'status' => (string) $p['status']];
        }
        usort($rows, static function ($a, $b) {
            return [self::ORDER[$a['kind']] ?? 2, $a['name'], $a['id']] <=> [self::ORDER[$b['kind']] ?? 2, $b['name'], $b['id']];
        });
        $byName = [];
        foreach ($rows as $r) if ($r['kind'] === 'wfh') $byName[] = $r['id'];
        return ['min' => $min, 'office' => $office, 'short' => max(0, $min - $office), 'rows' => $rows, 'can' => $byName];
    }

    /**
     * The day a change (a leave) leaves furthest below the managers' minimum, among the days it takes a manager out of the
     * office; null when none. $before / $after: date => the day's managers (day()) or null when not checked.
     * @param array<string,array{office:int,short:int}|null> $before
     * @param array<string,array{office:int,short:int}|null> $after
     * @param string[] $dates
     */
    public static function worstDrop(array $before, array $after, array $dates): ?string
    {
        $worst = null;
        foreach ($dates as $d) {
            $b = $before[$d] ?? null;
            $a = $after[$d] ?? null;
            if (!$b || !$a) continue;
            if ($a['office'] >= $b['office'] || $a['short'] <= 0) continue;
            if ($worst === null || $a['short'] > ($after[$worst]['short'] ?? 0)) $worst = $d;
        }
        return $worst;
    }

    /**
     * The managers: employee id => the teams they manage (unique, sorted); people who manage none are left out.
     * @param array<int,mixed> $teamsByEmployee
     * @return array<int,list<string>>
     */
    public static function managers(array $teamsByEmployee): array
    {
        $out = [];
        foreach ($teamsByEmployee as $id => $teams) {
            $t = array_values(array_unique(array_filter(array_map('strval', (array) $teams), 'strlen')));
            if (!$t) continue;
            sort($t, SORT_NATURAL | SORT_FLAG_CASE);
            $out[(int) $id] = $t;
        }
        return $out;
    }
}
