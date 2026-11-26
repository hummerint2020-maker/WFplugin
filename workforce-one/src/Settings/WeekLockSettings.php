<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Week lock settings (3.31.121, option ews_week_lock; the switch is ews_feature_week_lock).
 * Pure: no WordPress calls.
 */
final class WeekLockSettings
{
    public const DEFAULTS = [
        'cutoff_day' => 4,          // Thursday (0 = Sunday)
        'cutoff_time' => '17:00',   // site time
        'notify' => 1,              // tell everyone with a schedule that week at the cutoff
        // The final-week banner on the Attendance page (3.31.165, owner answer 12): empty keeps the translated default.
        'banner_title_ar' => '',
        'banner_text_ar' => '',
        'banner_title_en' => '',
        'banner_text_en' => '',
    ];

    /** The banner's fields and their longest length. */
    public const BANNER = ['banner_title_ar' => 120, 'banner_text_ar' => 500, 'banner_title_en' => 120, 'banner_text_en' => 500];

    /** @param mixed $saved @return array{cutoff_day:int,cutoff_time:string,notify:int,banner_title_ar:string,banner_text_ar:string,banner_title_en:string,banner_text_en:string} */
    public static function config($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $c = self::DEFAULTS;
        if (isset($s['cutoff_day']) && is_numeric($s['cutoff_day']) && (int) $s['cutoff_day'] >= 0 && (int) $s['cutoff_day'] <= 6) $c['cutoff_day'] = (int) $s['cutoff_day'];
        if (isset($s['cutoff_time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $s['cutoff_time'])) $c['cutoff_time'] = (string) $s['cutoff_time'];
        if (isset($s['notify'])) $c['notify'] = (int) (bool) $s['notify'];
        foreach (self::BANNER as $k => $max) if (isset($s[$k]) && is_string($s[$k])) $c[$k] = self::clean($s[$k], $max, strpos($k, 'text') !== false);
        return $c;
    }

    /** Plain text: no tags, trimmed, capped; a title is one line. */
    private static function clean(string $v, int $max, bool $multiline): string
    {
        $v = trim(strip_tags($v));
        if (!$multiline) $v = (string) preg_replace('/\s+/u', ' ', $v);
        return mb_substr($v, 0, $max);
    }

    /**
     * The banner a person sees, in their language ('ar' or 'en'): each part the custom one when set, else null (the caller's
     * translated default). {since} is replaced in the custom text.
     * @param array<string,mixed> $cfg config() @return array{title:?string,text:?string}
     */
    public static function banner(array $cfg, string $lang, string $since): array
    {
        $l = $lang === 'ar' ? 'ar' : 'en';
        $title = trim((string) ($cfg['banner_title_' . $l] ?? ''));
        $text = trim((string) ($cfg['banner_text_' . $l] ?? ''));
        return ['title' => $title !== '' ? str_replace('{since}', $since, $title) : null, 'text' => $text !== '' ? str_replace('{since}', $since, $text) : null];
    }

    /** Any custom banner text is set. @param array<string,mixed> $cfg */
    public static function customBanner(array $cfg): bool
    {
        foreach (array_keys(self::BANNER) as $k) if (trim((string) ($cfg[$k] ?? '')) !== '') return true;
        return false;
    }

    /**
     * From Feature Configuration (unslashed).
     * @param array<string,mixed> $post wl_cutoff_day, wl_cutoff_time, wl_notify, wl_banner_title_ar … (absent: kept from $saved)
     * @param mixed $saved the stored option (a page without the banner fields keeps them)
     * @return array{0:array<string,mixed>,1:string} [settings, error: '' | day | time]
     */
    public static function fromPost(array $post, $saved = []): array
    {
        $day = trim((string) ($post['wl_cutoff_day'] ?? ''));
        if (!ctype_digit($day) || (int) $day > 6) return [[], 'day'];
        $time = trim((string) ($post['wl_cutoff_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) return [[], 'time'];
        $out = ['cutoff_day' => (int) $day, 'cutoff_time' => $time, 'notify' => empty($post['wl_notify']) ? 0 : 1];
        $kept = self::config($saved);
        foreach (self::BANNER as $k => $max) $out[$k] = isset($post['wl_' . $k]) && is_string($post['wl_' . $k]) ? self::clean($post['wl_' . $k], $max, strpos($k, 'text') !== false) : $kept[$k];
        return [$out, ''];
    }
}
