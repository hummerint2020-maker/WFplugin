<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * A short device name from a browser's User-Agent, for the refused attempts (3.31.92):
 * "iPhone · Safari", "Android (SM-A546E) · Chrome", "Windows · Edge". Pure: no WordPress calls.
 */
final class DeviceLabel
{
    public static function from(string $ua): string
    {
        if (trim($ua) === '') return '';
        $os = 'Other';
        if (preg_match('/iPhone/i', $ua)) $os = 'iPhone';
        elseif (preg_match('/iPad/i', $ua)) $os = 'iPad';
        elseif (preg_match('/Android[^;)]*;\s*(?:[a-z]{2}[-_][a-z]{2};\s*)?([^;)]+?)(?:\s+Build\/[^;)]*)?\)/i', $ua, $m)) {
            $model = trim($m[1]);
            $os = ($model !== '' && strtoupper($model) !== 'K' && stripos($model, 'Linux') === false) ? 'Android (' . $model . ')' : 'Android';
        } elseif (stripos($ua, 'Android') !== false) $os = 'Android';
        elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
        elseif (stripos($ua, 'Macintosh') !== false) $os = 'Mac';
        elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

        if (preg_match('/SamsungBrowser/i', $ua)) $browser = 'Samsung Internet';
        elseif (preg_match('/Edg(e|A|iOS)?\//i', $ua)) $browser = 'Edge';
        elseif (preg_match('/OPR\/|Opera/i', $ua)) $browser = 'Opera';
        elseif (preg_match('/Firefox|FxiOS/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/CriOS|Chrome\//i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/Safari\//i', $ua) || in_array($os, ['iPhone', 'iPad'], true)) $browser = 'Safari';
        else $browser = '';
        return mb_substr($os . ($browser !== '' ? ' · ' . $browser : ''), 0, 190);
    }
}
