<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/** System health: duration histograms. Twenty buckets (to 5 minutes, then everything above), so a percentile needs no stored samples. Pure. */
final class Stats
{
    /** Upper edges in ms; the last bucket is everything above the last edge. A percentile is the upper edge of its bucket. */
    public const EDGES = [50, 100, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 7500, 10000, 15000, 20000, 30000, 60000, 120000, 300000];
    public const BUCKETS = 20;

    public static function bucket(int $ms): int
    {
        foreach (self::EDGES as $i => $edge) if ($ms <= $edge) return $i;
        return self::BUCKETS - 1;
    }

    /** @return list<int> */
    public static function empty(): array
    {
        return array_fill(0, self::BUCKETS, 0);
    }

    /** @param array<int,int|string> $a @param array<int,int|string> $b @return list<int> */
    public static function merge(array $a, array $b): array
    {
        $out = [];
        for ($i = 0; $i < self::BUCKETS; $i++) $out[] = (int) ($a[$i] ?? 0) + (int) ($b[$i] ?? 0);
        return $out;
    }

    /**
     * The ms at or below which $p (0..1) of the runs fall: the upper edge of that bucket; above 5 minutes the worst seen.
     * Never more than the worst seen, when it is known (3.31.126: "slow 3 s" with "worst 2.7 s" read as a mistake).
     * @param array<int,int|string> $hist
     */
    public static function percentile(array $hist, float $p, int $max = 0): int
    {
        $n = 0;
        for ($i = 0; $i < self::BUCKETS; $i++) $n += (int) ($hist[$i] ?? 0);
        if ($n <= 0) return 0;
        $need = max(1, (int) ceil($n * max(0.0, min(1.0, $p))));
        $seen = 0;
        for ($i = 0; $i < self::BUCKETS; $i++) {
            $seen += (int) ($hist[$i] ?? 0);
            if ($seen >= $need) return $i < count(self::EDGES) ? ($max > 0 ? min(self::EDGES[$i], $max) : self::EDGES[$i]) : max($max, self::EDGES[count(self::EDGES) - 1]);
        }
        return $max;
    }

    /**
     * Where the time of the measured loads went (3.31.150): the database, outside services (HTTP, push), and the rest (PHP and the
     * server). Null when fewer than $min loads carried the measure. The part with at least $mostly percent of the time names the
     * cause; otherwise 'mixed'. Database and outside time are clamped so the rest is never below 0.
     * @return array{n:int,total_ms:int,db_ms:int,ext_ms:int,out_ms:int,cause:string}|null
     */
    public static function split(int $n, int $msSum, int $dbUsSum, int $extUsSum, int $min, int $mostly = 60): ?array
    {
        if ($n <= 0 || $n < $min) return null;
        $total = max(0.0, $msSum / $n);
        $db = min($total, max(0.0, $dbUsSum / 1000 / $n));
        $ext = min($total - $db, max(0.0, $extUsSum / 1000 / $n));
        $out = max(0.0, $total - $db - $ext);
        $cause = 'mixed';
        if ($total > 0) {
            foreach (['db' => $db, 'external' => $ext, 'outside' => $out] as $k => $v) {
                if ($v / $total * 100 >= $mostly) { $cause = $k; break; }
            }
        }
        return ['n' => $n, 'total_ms' => (int) round($total), 'db_ms' => (int) round($db), 'ext_ms' => (int) round($ext), 'out_ms' => (int) round($out), 'cause' => $cause];
    }

    /** True when $after is slower than $before by at least $pct percent and $ms milliseconds. */
    public static function slower(float $before, float $after, int $pct, int $ms): bool
    {
        return $before > 0 && $after - $before >= $ms && ($after - $before) / $before * 100 >= $pct;
    }
}
