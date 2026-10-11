<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Daily workers settings (3.31.76, option ews_dw_settings; each site can override the recording
 * mode and the pay period). Pure: no WordPress calls.
 */
final class DailyWorkerSettings
{
    /** foreman: the foreman records the day sheet; self: workers sign in themselves (mobile + PIN); both */
    public const MODES = ['foreman', 'self', 'both'];
    public const PERIODS = ['daily', 'weekly'];
    /** Built-in trades and their order; kept in English, shown translated. */
    public const TRADES = ['Formwork carpenter', 'Steel fixer', 'Helper', 'Plasterer', 'Plumber', 'Electrician', 'Painter'];

    public const DEFAULTS = [
        'mode' => 'foreman',
        'period' => 'weekly',
        'week_start' => 6,          // Saturday
        'hourly_rate' => 0.0,       // default for extra hours when a worker has none
        'photo' => 1,               // the day sheet needs a group photo
        'trades' => self::TRADES,
        'subcontractors' => [],
    ];

    /** @param mixed $saved @return array{mode:string,period:string,week_start:int,hourly_rate:float,photo:int,trades:list<string>,subcontractors:list<string>} */
    public static function config($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $c = self::DEFAULTS;
        if (in_array($s['mode'] ?? '', self::MODES, true)) $c['mode'] = $s['mode'];
        if (in_array($s['period'] ?? '', self::PERIODS, true)) $c['period'] = $s['period'];
        if (isset($s['week_start']) && is_numeric($s['week_start']) && (int) $s['week_start'] >= 0 && (int) $s['week_start'] <= 6) $c['week_start'] = (int) $s['week_start'];
        if (isset($s['hourly_rate']) && is_numeric($s['hourly_rate']) && (float) $s['hourly_rate'] >= 0) $c['hourly_rate'] = round((float) $s['hourly_rate'], 2);
        if (isset($s['photo'])) $c['photo'] = (int) (bool) $s['photo'];
        if (isset($s['trades']) && is_array($s['trades'])) $c['trades'] = self::names($s['trades']);
        if (isset($s['subcontractors']) && is_array($s['subcontractors'])) $c['subcontractors'] = self::names($s['subcontractors']);
        return $c;
    }

    /**
     * Settings from the wp-admin form (already unslashed and sanitized as text).
     * @param array<string,mixed> $post
     * @return array{0:array<string,mixed>,1:string} [settings, error: '' | mode | period | rate | trades]
     */
    public static function fromPost(array $post): array
    {
        $mode = (string) ($post['dw_mode'] ?? '');
        if (!in_array($mode, self::MODES, true)) return [[], 'mode'];
        $period = (string) ($post['dw_period'] ?? '');
        if (!in_array($period, self::PERIODS, true)) return [[], 'period'];
        $rate = (string) ($post['dw_hourly_rate'] ?? '0');
        if ($rate === '') $rate = '0';
        if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100000) return [[], 'rate'];
        $trades = self::names(self::lines((string) ($post['dw_trades'] ?? '')));
        if (!$trades) return [[], 'trades'];
        $week = (int) ($post['dw_week_start'] ?? 6);
        return [[
            'mode' => $mode, 'period' => $period, 'week_start' => ($week >= 0 && $week <= 6) ? $week : 6,
            'hourly_rate' => round((float) $rate, 2), 'photo' => empty($post['dw_photo']) ? 0 : 1,
            'trades' => $trades, 'subcontractors' => self::names(self::lines((string) ($post['dw_subcontractors'] ?? ''))),
        ], ''];
    }

    /** A site's mode / period: its own when set, else the company's. */
    public static function forSite(array $config, string $siteMode, string $sitePeriod): array
    {
        return [
            'mode' => in_array($siteMode, self::MODES, true) ? $siteMode : $config['mode'],
            'period' => in_array($sitePeriod, self::PERIODS, true) ? $sitePeriod : $config['period'],
        ];
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return preg_split('/\r\n|\r|\n|,|،/u', $text) ?: [];
    }

    /** @param array<mixed> $list @return list<string> trimmed, unique, at most 60 of 60 characters */
    private static function names(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
            if ($v === '' || in_array($v, $out, true)) continue;
            $out[] = mb_substr($v, 0, 60);
            if (count($out) >= 60) break;
        }
        return $out;
    }
}
