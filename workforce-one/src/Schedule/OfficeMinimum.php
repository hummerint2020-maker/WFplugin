<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Office minimum (3.31.77): how many people are planned in the office on a day against the
 * company's minimum, and each team's share of that minimum. Pure: no WordPress calls.
 *
 * A team's share is proportional to its size (minimum × team size ÷ everyone), rounded by largest
 * remainder so the shares add up to the minimum; a team with a fixed number keeps it and the rest is
 * shared by the others. Keys are team ids (0 = people in no team).
 */
final class OfficeMinimum
{
    /**
     * The minimum on a date: the weekday's own number when set, else the company's.
     * @param array{min:int,days:array<int,int>} $cfg
     */
    public static function minimumFor(array $cfg, string $date): int
    {
        $t = strtotime($date . ' 12:00:00 UTC');
        $w = $t === false ? -1 : (int) gmdate('w', $t);
        return isset($cfg['days'][$w]) ? max(0, (int) $cfg['days'][$w]) : max(0, (int) $cfg['min']);
    }

    /**
     * @param array<int,int> $sizes people per team
     * @param array<int,int> $fixed a team's fixed number (teams not in $sizes are ignored)
     * @return array<int,int> share per team, same keys as $sizes
     */
    public static function shares(int $min, array $sizes, array $fixed = []): array
    {
        $out = [];
        $left = max(0, $min);
        foreach ($sizes as $k => $n) {
            if (array_key_exists($k, $fixed)) {
                $out[$k] = max(0, (int) $fixed[$k]);
                $left -= $out[$k];
            }
        }
        $left = max(0, $left);
        $free = array_diff_key($sizes, $out);
        $total = array_sum(array_map('intval', $free));
        if ($total <= 0) {
            foreach ($free as $k => $n) $out[$k] = 0;
            return self::ordered($out, $sizes);
        }
        $rem = [];
        $given = 0;
        foreach ($free as $k => $n) {
            $exact = $left * max(0, (int) $n) / $total;
            $out[$k] = (int) floor($exact);
            $given += $out[$k];
            $rem[$k] = $exact - floor($exact);
        }
        // The rest, one each, to the largest remainders (then the bigger team, then the order given).
        $keys = array_keys($rem);
        $pos = array_flip($keys);
        usort($keys, static function ($a, $b) use ($rem, $free, $pos) {
            if (abs($rem[$a] - $rem[$b]) > 1e-9) return $rem[$a] < $rem[$b] ? 1 : -1;
            if ((int) $free[$a] !== (int) $free[$b]) return (int) $free[$b] <=> (int) $free[$a];
            return $pos[$a] <=> $pos[$b];
        });
        for ($i = 0; $i < $left - $given && $i < count($keys); $i++) $out[$keys[$i]]++;
        return self::ordered($out, $sizes);
    }

    /**
     * One day: each team's share against who it planned in the office, the biggest shortfall first.
     * @param array<int,int> $shares
     * @param array<int,int> $office people planned in the office per team
     * @return array{min:int,office:int,short:int,teams:list<array{team:int,share:int,office:int,gap:int}>}
     */
    public static function day(int $min, array $shares, array $office): array
    {
        $rows = [];
        foreach ($shares as $k => $share) {
            $n = (int) ($office[$k] ?? 0);
            $rows[] = ['team' => (int) $k, 'share' => (int) $share, 'office' => $n, 'gap' => $n - (int) $share];
        }
        usort($rows, static function ($a, $b) {
            return $a['gap'] <=> $b['gap'] ?: $a['team'] <=> $b['team'];
        });
        $total = array_sum(array_map('intval', $office));
        return ['min' => $min, 'office' => $total, 'short' => max(0, $min - $total), 'teams' => $rows];
    }

    /** @param array<int,int> $out @param array<int,int> $sizes @return array<int,int> */
    private static function ordered(array $out, array $sizes): array
    {
        $r = [];
        foreach ($sizes as $k => $n) $r[$k] = $out[$k] ?? 0;
        return $r;
    }
}
