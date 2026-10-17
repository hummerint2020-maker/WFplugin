<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * The installed app's offline screen (3.31.93), off until turned on in Features: shown instead of the
 * browser's blank page when the phone has no internet or the server does not answer within `timeout`
 * seconds. Every text on it is set here (English, the owner's choice); an empty text uses the default.
 * In the texts, {time} is the time of the Sign In / Out that did not reach the server, {action} is
 * "Sign In" or "Sign Out", and {updated} is when the schedule kept on the phone was last refreshed.
 * Pure: no WordPress calls. Stored as one option, ews_offline_screen.
 */
final class OfflineScreen
{
    /** key => [label, default, max length, multi-line] in the order of the settings form, by section. */
    public const TEXTS = [
        'off_badge' => ['Badge', 'Offline', 30, false],
        'off_title' => ['Title', 'No internet connection', 80, false],
        'off_msg' => ['Message', 'Turn on Wi-Fi or mobile data. This page opens by itself when you are back online.', 300, true],
        'retry' => ['Button', 'Try again', 30, false],
        'down_badge' => ['Badge', 'Not responding', 30, false],
        'down_title' => ['Title', 'Workforce One is not responding', 80, false],
        'down_msg' => ['Message', 'Your internet works, but the company server did not answer. It is not your phone. Try again in a few minutes.', 300, true],
        'ns_title' => ['Title', 'You are not signed in', 80, false],
        'ns_out_title' => ['Title (Sign Out)', 'You are not signed out', 80, false],
        'ns_msg' => ['Message', 'Your {action} attempt at {time} is saved on this phone and goes to your manager as soon as you are back online. When the connection returns, press {action} again to record it.', 300, true],
        'ns_down' => ['Message (server down)', 'Your {action} attempt at {time} is saved on this phone and goes to your manager when the server is back.', 300, true],
        'keep' => ['Note', 'Keep the app open if you can: it reloads by itself when the connection returns.', 200, false],
        'now' => ['Clock label', 'Time now', 30, false],
        'today' => ['Today', 'Your schedule today', 60, false],
        'week' => ['Rest of the week', 'Rest of the week', 60, false],
        'upd' => ['Last updated', 'Last updated {updated}', 80, false],
        'hours' => ['Hours', 'Hours', 30, false],
        'day_off' => ['A day off', 'Day off', 30, false],
        'not_set' => ['A day not planned yet', 'Not set', 30, false],
        'on_badge' => ['Badge', 'Online', 30, false],
        'toast' => ['Message (attempt sent)', 'You are back online. Your {action} attempt at {time} was sent to your manager. {action} now.', 300, true],
        'toast0' => ['Message (nothing pending)', 'You are back online.', 200, false],
    ];

    /** Sections of the settings form: title => text keys. */
    public const SECTIONS = [
        'No internet' => ['off_badge', 'off_title', 'off_msg', 'retry'],
        'Server not responding' => ['down_badge', 'down_title', 'down_msg'],
        'Sign In / Out pressed with no connection' => ['ns_title', 'ns_out_title', 'ns_msg', 'ns_down', 'keep', 'now'],
        'Schedule card' => ['today', 'week', 'upd', 'hours', 'day_off', 'not_set'],
        'Back online' => ['on_badge', 'toast', 'toast0'],
    ];

    public const TIMEOUT = 20;

    /** @return array<string,string> */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::TEXTS as $k => $t) { $out[$k] = $t[1]; }
        return $out;
    }

    /**
     * The settings as used: switches, the timeout (5 to 60 seconds) and every text, the default where
     * none was saved. `texts` holds only what was saved (for the form: empty = default).
     * @param mixed $saved
     * @return array{enabled:int,timeout:int,schedule:int,keep_note:int,texts:array<string,string>,saved:array<string,string>}
     */
    public static function config($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $own = is_array($s['texts'] ?? null) ? $s['texts'] : [];
        $texts = [];
        $kept = [];
        foreach (self::TEXTS as $k => $t) {
            $v = self::clean($own[$k] ?? '', $t[2], $t[3]);
            if ($v !== '') { $kept[$k] = $v; }
            $texts[$k] = $v !== '' ? $v : $t[1];
        }
        $timeout = is_numeric($s['timeout'] ?? null) ? max(5, min(60, (int) $s['timeout'])) : self::TIMEOUT;
        return [
            'enabled' => empty($s['enabled']) ? 0 : 1,
            'timeout' => $timeout,
            'schedule' => array_key_exists('schedule', $s) ? (empty($s['schedule']) ? 0 : 1) : 1,
            'keep_note' => array_key_exists('keep_note', $s) ? (empty($s['keep_note']) ? 0 : 1) : 1,
            'texts' => $texts,
            'saved' => $kept,
        ];
    }

    /**
     * From the Features form; what is stored (a text equal to its default is not kept, so a later
     * change of the default reaches it).
     * @param array<string,mixed> $post
     * @return array{enabled:int,timeout:int,schedule:int,keep_note:int,texts:array<string,string>}
     */
    public static function fromPost(array $post): array
    {
        $in = is_array($post['offline_texts'] ?? null) ? $post['offline_texts'] : [];
        $texts = [];
        foreach (self::TEXTS as $k => $t) {
            $v = self::clean($in[$k] ?? '', $t[2], $t[3]);
            if ($v !== '' && $v !== $t[1]) { $texts[$k] = $v; }
        }
        $c = self::config(['enabled' => !empty($post['offline_enabled']), 'timeout' => $post['offline_timeout'] ?? null, 'schedule' => !empty($post['offline_schedule']), 'keep_note' => !empty($post['offline_keep_note'])]);
        return ['enabled' => $c['enabled'], 'timeout' => $c['timeout'], 'schedule' => $c['schedule'], 'keep_note' => $c['keep_note'], 'texts' => $texts];
    }

    /** A short version of the settings, for the Audit Log. @param array<string,mixed> $stored */
    public static function summary(array $stored): string
    {
        $c = self::config($stored);
        return ($c['enabled'] ? 'on' : 'off') . '; timeout=' . $c['timeout'] . 's; schedule=' . ($c['schedule'] ? 'on' : 'off') . '; keep_note=' . ($c['keep_note'] ? 'on' : 'off') . '; texts changed=' . implode(',', array_keys($c['saved']));
    }

    /**
     * A revision of what the phone keeps: changes when anything shown on the offline page changes, so
     * the service worker (which carries it) is updated and fetches the page again.
     * @param array<string,mixed> $config config() output plus anything else drawn on the page
     */
    public static function revision(array $config): string
    {
        return substr(md5((string) json_encode($config)), 0, 12);
    }

    /** Plain text, no tags, single spaces (new lines kept for a message), cut to $max characters. */
    private static function clean($v, int $max, bool $multi): string
    {
        $v = is_scalar($v) ? (string) $v : '';
        $v = strip_tags($v);
        $v = $multi ? (string) preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $v)) : (string) preg_replace('/\s+/', ' ', $v);
        $v = (string) preg_replace("/\n{3,}/", "\n\n", $v);
        $v = trim($v);
        return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
    }
}
