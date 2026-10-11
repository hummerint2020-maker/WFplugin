<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Tasks settings (wp-admin → Workforce One → Tasks, option ews_tasks_settings). Each optional part of
 * Tasks can be switched off and tuned here. Pure: no WordPress calls.
 */
final class TaskSettings
{
    /** Switches: key => built-in value. */
    public const SWITCHES = [
        'personal' => 1,         // employees can create their own tasks
        'checklist' => 1,        // steps inside a task
        'comments' => 1,         // comments on a task
        'attachments' => 1,      // a file with a comment
        'activity' => 1,         // "who did what" on a task
        'teams' => 1,            // assign a task to a whole team
        'repeat' => 1,           // repeating tasks
        'due_time' => 1,         // a time with the due date
        'reminders' => 1,        // a reminder before the due time
        'workload' => 1,         // the managers' Workload view
        'home_card' => 1,        // "My tasks today" on Home
        'drag' => 1,             // drag a card to another column (computer)
        'notify_comments' => 1,  // tell the people on a task about a new comment
        'notify_status' => 1,    // tell the creator when the status changes
    ];
    public const FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
    public const REMIND_CHOICES = [0, 15, 30, 60, 120, 1440];
    public const TEAM_MODES = ['each', 'first'];
    public const WORKLOAD_WHO = ['managers', 'admins'];
    public const DEFAULTS = self::SWITCHES + [
        'attach_max_mb' => 5,
        'attach_types' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'],
        'remind_default' => 60,
        'team_mode' => 'each',
        'workload_who' => 'managers',
        'home_count' => 5,
        'done_limit' => 8,
    ];

    /** @param mixed $saved the stored option (missing keys take the built-in values) */
    public static function config($saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $out = self::DEFAULTS;
        foreach (self::SWITCHES as $k => $v) if (array_key_exists($k, $saved)) $out[$k] = !empty($saved[$k]) ? 1 : 0;
        return self::values($out, $saved);
    }

    /** @param array<string, mixed> $post the form (a missing checkbox means off) */
    public static function fromPost(array $post): array
    {
        $out = self::DEFAULTS;
        foreach (self::SWITCHES as $k => $v) $out[$k] = !empty($post[$k]) ? 1 : 0;
        if (!isset($post['attach_types'])) $post['attach_types'] = [];
        return self::values($out, $post);
    }

    /** @param array<string, mixed> $out @param array<string, mixed> $in */
    private static function values(array $out, array $in): array
    {
        $int = static function (string $key, int $min, int $max) use ($in, $out): int {
            return isset($in[$key]) && is_scalar($in[$key]) ? max($min, min($max, (int) $in[$key])) : (int) $out[$key];
        };
        $out['attach_max_mb'] = $int('attach_max_mb', 1, 20);
        $out['home_count'] = $int('home_count', 1, 10);
        $out['done_limit'] = $int('done_limit', 3, 50);
        if (isset($in['remind_default']) && in_array((int) $in['remind_default'], self::REMIND_CHOICES, true)) $out['remind_default'] = (int) $in['remind_default'];
        if (isset($in['team_mode']) && in_array($in['team_mode'], self::TEAM_MODES, true)) $out['team_mode'] = $in['team_mode'];
        if (isset($in['workload_who']) && in_array($in['workload_who'], self::WORKLOAD_WHO, true)) $out['workload_who'] = $in['workload_who'];
        if (isset($in['attach_types']) && is_array($in['attach_types'])) {
            $types = array_values(array_intersect(self::FILE_TYPES, array_map(static function ($t) { return strtolower((string) $t); }, $in['attach_types'])));
            $out['attach_types'] = $types;
        }
        // Files need comments to sit in, and a list of allowed types.
        if (!$out['comments'] || !$out['attach_types']) $out['attachments'] = 0;
        if (!$out['due_time']) $out['reminders'] = 0;
        return $out;
    }

    /**
     * The next due date of a repeating task after $from (Y-m-d), or null for none.
     * Rules: 'daily', 'weekly:<days>' (0 = Sunday … 6 = Saturday, comma separated), 'monthly:<day 1-31>'
     * (a short month uses its last day).
     */
    public static function nextDate(string $rule, string $from): ?string
    {
        $t = strtotime($from . ' 12:00:00 UTC');
        if ($t === false) return null;
        if ($rule === 'daily') return gmdate('Y-m-d', $t + 86400);
        if (preg_match('/^weekly:([0-6](?:,[0-6])*)$/', $rule, $m)) {
            $days = array_map('intval', explode(',', $m[1]));
            for ($i = 1; $i <= 7; $i++) {
                $d = $t + $i * 86400;
                if (in_array((int) gmdate('w', $d), $days, true)) return gmdate('Y-m-d', $d);
            }
            return null;
        }
        if (preg_match('/^monthly:([1-9]|[12]\d|3[01])$/', $rule, $m)) {
            $y = (int) gmdate('Y', $t); $mo = (int) gmdate('n', $t); $want = (int) $m[1];
            $day = min($want, (int) gmdate('t', gmmktime(12, 0, 0, $mo, 1, $y)));
            if ((int) gmdate('j', $t) < $day) return sprintf('%04d-%02d-%02d', $y, $mo, $day);
            $mo++; if ($mo > 12) { $mo = 1; $y++; }
            return sprintf('%04d-%02d-%02d', $y, $mo, min($want, (int) gmdate('t', gmmktime(12, 0, 0, $mo, 1, $y))));
        }
        return null;
    }

    /** A repeat rule from the form, or '' (none / invalid). @param array<string, mixed> $post */
    public static function ruleFromPost(array $post): string
    {
        $kind = (string) ($post['repeat'] ?? '');
        if ($kind === 'daily') return 'daily';
        if ($kind === 'weekly') {
            $days = array_values(array_unique(array_filter(array_map('intval', (array) ($post['repeat_days'] ?? [])), static function ($d) { return $d >= 0 && $d <= 6; })));
            sort($days);
            return $days ? 'weekly:' . implode(',', $days) : '';
        }
        if ($kind === 'monthly') {
            $day = (int) ($post['repeat_day'] ?? 0);
            return $day >= 1 && $day <= 31 ? 'monthly:' . $day : '';
        }
        return '';
    }
}
