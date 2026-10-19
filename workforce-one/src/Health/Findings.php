<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/**
 * System health → "Look at this first": the rules that turn what was measured into a ranked list.
 * Returns codes and numbers; the plugin words them (so they translate). Pure.
 *
 * Input:
 *  - routes: route => [n, failed, fatal, hist (12), ms_max, pq_sum, pq_n, mem_max (KB), max_repeat, repeat_caller,
 *            recent_p50, recent_n, before_p50, before_n, version (the release the slowdown started with, or '')]
 *  - queries: list of [route, caller, ms_max]            (single slow queries)
 *  - errors: list of [file, line, message, n]            (PHP errors first seen in the last 24 hours)
 *  - cron: hook => [overdue_min (0 when not late), failed (bool)]
 *  - memory_limit_kb
 */
final class Findings
{
    public const SEVERITIES = ['critical' => 0, 'warning' => 1, 'note' => 2];

    /**
     * @param array<string,mixed> $in
     * @param array<string,int|string> $s Settings::settings()
     * @return list<array{key:string,severity:string,rule:string,route:string,args:array<string,mixed>,impact:float}>
     */
    public static function evaluate(array $in, array $s): array
    {
        $out = [];
        $add = static function (string $key, string $sev, string $rule, string $route, array $args, float $impact = 0.0) use (&$out): void {
            $out[$key] = ['key' => $key, 'severity' => $sev, 'rule' => $rule, 'route' => $route, 'args' => $args, 'impact' => $impact];
        };
        $limitKb = (int) ($in['memory_limit_kb'] ?? 0);
        foreach ((array) ($in['routes'] ?? []) as $route => $r) {
            $route = (string) $route;
            $n = (int) ($r['n'] ?? 0);
            $fatal = (int) ($r['fatal'] ?? 0);
            if ($fatal > 0) $add('fatal:' . $route, 'critical', 'fatal', $route, ['n' => $fatal], 1000000 + $fatal);
            if ($n >= $s['min_runs']) {
                $failed = (int) ($r['failed'] ?? 0);
                if ($failed > 0 && $failed / $n * 100 >= $s['fail_pct']) $add('fail_rate:' . $route, 'critical', 'fail_rate', $route, ['failed' => $failed, 'n' => $n], 500000 + $failed);
                $limit = Routes::heavy($route) ? (int) $s['heavy_ms'] : (int) $s['slow_ms'];
                $p95 = Stats::percentile((array) ($r['hist'] ?? []), 0.95, (int) ($r['ms_max'] ?? 0));
                if ($p95 > $limit) $add('slow:' . $route, 'warning', 'slow', $route, ['p95' => $p95, 'limit' => $limit, 'max' => (int) ($r['ms_max'] ?? 0), 'n' => $n], (float) $n * ($p95 - $limit));
            }
            if ((int) ($r['recent_n'] ?? 0) >= $s['min_runs'] && (int) ($r['before_n'] ?? 0) >= $s['min_runs']
                && Stats::slower((float) $r['before_p50'], (float) $r['recent_p50'], (int) $s['regression_pct'], (int) $s['regression_ms'])) {
                $add('regression:' . $route, 'warning', 'regression', $route, ['before' => (int) $r['before_p50'], 'after' => (int) $r['recent_p50'], 'version' => (string) ($r['version'] ?? '')],
                    (float) $r['recent_n'] * ((float) $r['recent_p50'] - (float) $r['before_p50']));
            }
            if ((int) ($r['max_repeat'] ?? 0) >= $s['repeat']) $add('repeat:' . $route, 'warning', 'repeat', $route, ['times' => (int) $r['max_repeat'], 'caller' => (string) ($r['repeat_caller'] ?? '')], (float) $r['max_repeat']);
            $pq = (int) ($r['pq_n'] ?? 0);
            if ($pq > 0) {
                $avg = (int) round((float) $r['pq_sum'] / $pq);
                if (strpos($route, 'other:') === 0) {
                    if ($avg >= 20) $add('other_cost:' . $route, 'note', 'other_cost', $route, ['avg' => $avg]);
                } elseif ($avg >= $s['queries']) {
                    $add('many_queries:' . $route, 'warning', 'many_queries', $route, ['avg' => $avg], (float) $avg);
                }
            }
            if ($limitKb > 0 && (int) ($r['mem_max'] ?? 0) > 0 && (int) $r['mem_max'] / $limitKb * 100 >= $s['memory_pct']) {
                $add('memory:' . $route, 'warning', 'memory', $route, ['used_kb' => (int) $r['mem_max'], 'limit_kb' => $limitKb], (float) $r['mem_max']);
            }
        }
        foreach ((array) ($in['queries'] ?? []) as $q) {
            if ((int) ($q['ms_max'] ?? 0) >= $s['slow_query_ms']) {
                $add('slow_query:' . $q['route'] . ':' . $q['caller'], 'warning', 'slow_query', (string) $q['route'], ['ms' => (int) $q['ms_max'], 'caller' => (string) $q['caller']], (float) $q['ms_max']);
            }
        }
        foreach ((array) ($in['errors'] ?? []) as $e) {
            $add('error:' . $e['file'] . ':' . $e['line'], 'note', 'new_error', '', ['file' => (string) $e['file'], 'line' => (int) $e['line'], 'message' => (string) $e['message'], 'n' => (int) ($e['n'] ?? 1)]);
        }
        foreach ((array) ($in['cron'] ?? []) as $hook => $c) {
            $route = 'cron:' . $hook;
            if ((int) ($c['overdue_min'] ?? 0) > $s['cron_late_min']) $add('cron_overdue:' . $route, 'critical', 'cron_overdue', $route, ['minutes' => (int) $c['overdue_min']], 100000 + (int) $c['overdue_min']);
            if (!empty($c['failed'])) $add('cron_failed:' . $route, 'critical', 'cron_failed', $route, []);
        }
        $list = array_values($out);
        usort($list, static function (array $a, array $b): int {
            return [self::SEVERITIES[$a['severity']], -$a['impact'], $a['key']] <=> [self::SEVERITIES[$b['severity']], -$b['impact'], $b['key']];
        });
        return $list;
    }
}
