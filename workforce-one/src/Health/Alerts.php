<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/** System health: which findings are open alerts, which are emailed, which a user hid. Pure. */
final class Alerts
{
    public const CLOSE_AFTER = 86400;    // closes after 24 h without a match
    public const REPEAT_AFTER = 86400;   // a still-open alert is emailed again after 24 h
    public const DAILY_CAP = 5;
    public const HIDE_DAYS = 7;

    /**
     * @param array<string,array<string,mixed>> $open
     * @param list<array{key:string,severity:string,rule:string,route:string,args:array<string,mixed>}> $findings
     * @return array{open:array<string,array<string,mixed>>,opened:list<string>}
     */
    public static function update(array $open, array $findings, int $now): array
    {
        $opened = [];
        $seen = [];
        foreach ($findings as $f) {
            $k = $f['key'];
            $seen[$k] = true;
            if (!isset($open[$k])) {
                $open[$k] = ['opened' => $now, 'emailed_at' => 0];
                $opened[] = $k;
            }
            $open[$k] = ['last_seen' => $now, 'severity' => $f['severity'], 'rule' => $f['rule'], 'route' => $f['route'], 'args' => $f['args']] + $open[$k];
        }
        foreach ($open as $k => $a) {
            if (!isset($seen[$k]) && $now - (int) ($a['last_seen'] ?? 0) > self::CLOSE_AFTER) unset($open[$k]);
        }
        return ['open' => $open, 'opened' => $opened];
    }

    /** Alerts to put in the next email: critical and warning, never emailed or emailed over 24 h ago; none past the daily cap. @param array<string,array<string,mixed>> $open @return list<string> */
    public static function due(array $open, int $now, int $sentToday): array
    {
        if ($sentToday >= self::DAILY_CAP) return [];
        $keys = [];
        foreach ($open as $k => $a) {
            if (!in_array($a['severity'] ?? '', ['critical', 'warning'], true)) continue;
            if ((int) ($a['last_seen'] ?? 0) < $now - self::CLOSE_AFTER) continue;
            if ((int) ($a['emailed_at'] ?? 0) === 0 || $now - (int) $a['emailed_at'] >= self::REPEAT_AFTER) $keys[] = (string) $k;
        }
        return $keys;
    }

    /** @param array<string,array<string,mixed>> $open @param list<string> $keys @return array<string,array<string,mixed>> */
    public static function markEmailed(array $open, array $keys, int $now): array
    {
        foreach ($keys as $k) if (isset($open[$k])) $open[$k]['emailed_at'] = $now;
        return $open;
    }

    /**
     * What a person sees: open alerts not hidden by them, critical first.
     * @param array<string,array<string,mixed>> $open @param array<string,int> $hidden key => until
     * @return list<array<string,mixed>> each with its key
     */
    public static function visible(array $open, array $hidden, int $now): array
    {
        $out = [];
        foreach ($open as $k => $a) {
            if ((int) ($hidden[$k] ?? 0) > $now) continue;
            $out[] = ['key' => (string) $k] + $a;
        }
        usort($out, static function (array $a, array $b): int {
            return [Findings::SEVERITIES[$a['severity'] ?? 'note'] ?? 2, $a['key']] <=> [Findings::SEVERITIES[$b['severity'] ?? 'note'] ?? 2, $b['key']];
        });
        return $out;
    }

    /** Hide these alerts for a week, dropping hides that ended. @param array<string,int> $hidden @param list<string> $keys @return array<string,int> */
    public static function hide(array $hidden, array $keys, int $now): array
    {
        foreach ($hidden as $k => $until) if ((int) $until <= $now) unset($hidden[$k]);
        foreach ($keys as $k) $hidden[$k] = $now + self::HIDE_DAYS * 86400;
        return $hidden;
    }

    /** @param list<array<string,mixed>> $visible @return array{critical:int,warning:int,note:int} */
    public static function counts(array $visible): array
    {
        $c = ['critical' => 0, 'warning' => 0, 'note' => 0];
        foreach ($visible as $a) {
            $s = $a['severity'] ?? 'note';
            if (isset($c[$s])) $c[$s]++;
        }
        return $c;
    }
}
