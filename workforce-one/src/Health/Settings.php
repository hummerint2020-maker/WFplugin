<?php
namespace WorkforceOne\Health;

if (!defined('ABSPATH')) exit;

/**
 * System health (3.31.110, docs/tasks/system-health.md): the thresholds, the sampling rate, the
 * retention. Every value is clamped to a sane range. Pure: no WordPress calls.
 */
final class Settings
{
    /** The health schema version (its own, so switching the feature on never needs the global target). */
    public const SCHEMA = '1';
    /** Detailed query data is taken from this share of requests (percent). */
    public const RATES = [1, 5, 10, 25, 50, 100];
    public const RETENTION = [30, 90, 180];
    public const DEFAULT_RATE = 10;
    public const DEFAULT_RETENTION = 90;

    /** name => [default, min, max] */
    private const RANGES = [
        'slow_ms' => [2000, 200, 60000],            // a page whose slowest 1 in 20 is above this
        'heavy_ms' => [20000, 2000, 600000],        // the same for heavy operations and scheduled jobs
        'queries' => [150, 10, 5000],               // plugin queries per load
        'repeat' => [25, 2, 1000],                  // the same query this many times in one request
        'slow_query_ms' => [1000, 50, 60000],
        'memory_pct' => [80, 20, 99],               // of memory_limit
        'regression_pct' => [50, 10, 500],          // slower since a release
        'regression_ms' => [300, 50, 10000],
        'fail_pct' => [2, 1, 50],                   // failed requests
        'cron_late_min' => [30, 5, 1440],
        'min_runs' => [20, 5, 1000],
    ];

    /** @param mixed $saved @return array<string,int|string> */
    public static function settings($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::RANGES as $k => [$d, $min, $max]) {
            $out[$k] = isset($s[$k]) && is_numeric($s[$k]) ? max($min, min($max, (int) round((float) $s[$k]))) : $d;
        }
        $out['notice'] = array_key_exists('notice', $s) ? (empty($s['notice']) ? 0 : 1) : 1;
        $out['email'] = empty($s['email']) ? 0 : 1;
        $out['email_to'] = self::email($s['email_to'] ?? '');
        return $out;
    }

    /** Only what the form sends; the rest is dropped. @param array<string,mixed> $post @return array<string,int|string> */
    public static function fromPost(array $post): array
    {
        $in = [];
        foreach (array_keys(self::RANGES) as $k) {
            if (isset($post['health_' . $k])) $in[$k] = $post['health_' . $k];
        }
        $in['notice'] = !empty($post['health_notice']) ? 1 : 0;
        $in['email'] = !empty($post['health_email']) ? 1 : 0;
        $in['email_to'] = $post['health_email_to'] ?? '';
        return self::settings($in);
    }

    /** @param mixed $v */
    public static function rate($v): int
    {
        $v = is_numeric($v) ? (int) $v : self::DEFAULT_RATE;
        return in_array($v, self::RATES, true) ? $v : self::DEFAULT_RATE;
    }

    /** Days of daily history kept: 7 to 365. @param mixed $v */
    public static function retention($v): int
    {
        return is_numeric($v) ? max(7, min(365, (int) $v)) : self::DEFAULT_RETENTION;
    }

    /** One address, or ''. @param mixed $v */
    private static function email($v): string
    {
        $v = is_string($v) ? trim($v) : '';
        return strlen($v) <= 190 && preg_match('/^[^\s@<>,;]+@[^\s@<>,;]+\.[^\s@<>,;]+$/', $v) ? $v : '';
    }
}
