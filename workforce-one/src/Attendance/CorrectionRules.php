<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Attendance correction requests (3.31.74): is a request acceptable, and which events does it add.
 * A correction never changes a recorded event: it adds the missing one(s), or, for a wrong time, a
 * new event that supersedes the original (which is kept). Pure: no WordPress calls.
 *
 * Facts (check() and plan()):
 *   type        out | in | time | day          target   sign_in | sign_out (type "time")
 *   date, today, now (Y-m-d, Y-m-d, H:i)        time_in, time_out (H:i, '' when not given)
 *   in, out     the day's recorded Sign In / Sign Out (Y-m-d H:i:s) or null
 *   overnight   the shift ends after midnight   source   employee | hr
 *   settings    Settings\CorrectionSettings::config()
 *   pending     another request for the day is waiting          month_count   requests this month
 *   reason, has_photo
 */
final class CorrectionRules
{
    public const ERRORS = ['type', 'date', 'future', 'deadline', 'pending', 'reason', 'photo', 'time', 'future_time', 'has_in', 'no_in',
        'has_out', 'has_events', 'no_event', 'order', 'same', 'limit'];

    /** @param array<string,mixed> $f @return string '' or an error code from ERRORS */
    public static function check(array $f): string
    {
        $s = $f['settings'];
        $type = (string) ($f['type'] ?? '');
        $hr = ($f['source'] ?? 'employee') === 'hr';
        if (!in_array($type, ['out', 'in', 'time', 'day'], true) || (!$hr && empty($s['types'][$type]))) return 'type';
        $date = (string) ($f['date'] ?? '');
        if (!self::isDate($date)) return 'date';
        if ($date > (string) $f['today']) return 'future';
        if (!$hr && self::daysBetween($date, (string) $f['today']) > (int) $s['deadline_days']) return 'deadline';
        if (!$hr && !empty($f['pending'])) return 'pending';
        if (trim((string) ($f['reason'] ?? '')) === '') return 'reason';
        if (!$hr && ($s['photo'][$type] ?? 'off') === 'required' && empty($f['has_photo'])) return 'photo';
        $in = $f['in'] ?? null;
        $out = $f['out'] ?? null;
        $tin = (string) ($f['time_in'] ?? '');
        $tout = (string) ($f['time_out'] ?? '');
        $needIn = $type === 'in' || $type === 'day' || ($type === 'time' && ($f['target'] ?? '') === 'sign_in');
        $needOut = $type === 'out' || $type === 'day' || ($type === 'time' && ($f['target'] ?? '') === 'sign_out');
        if ($type === 'time' && !in_array($f['target'] ?? '', ['sign_in', 'sign_out'], true)) return 'time';
        if (($needIn && !self::isTime($tin)) || ($needOut && !self::isTime($tout))) return 'time';
        switch ($type) {
            case 'out':
                if (!$in) return 'no_in';
                if ($out) return 'has_out';
                break;
            case 'in':
                if ($in) return 'has_in';
                break;
            case 'day':
                if ($in || $out) return 'has_events';
                break;
            case 'time':
                if (($f['target'] === 'sign_in' && !$in) || ($f['target'] === 'sign_out' && !$out)) return 'no_event';
                break;
        }
        $p = self::times($f);
        if ($type === 'time') {
            $old = $f['target'] === 'sign_in' ? $in : $out;
            $new = $f['target'] === 'sign_in' ? $p['in'] : $p['out'];
            if (substr((string) $old, 0, 16) === substr((string) $new, 0, 16)) return 'same';
        }
        if ($p['in'] && $p['out'] && $p['out'] <= $p['in']) return 'order';
        $latest = $p['out'] ?: $p['in'];
        if ($date === (string) $f['today'] && $latest && substr($latest, 0, 16) > $date . ' ' . (string) $f['now']) return 'future_time';
        if (!$hr && (int) $s['monthly_limit'] > 0 && (int) ($f['month_count'] ?? 0) >= (int) $s['monthly_limit'] && $s['above_limit'] === 'refuse') return 'limit';
        return '';
    }

    /**
     * The events a valid request adds, and how it is approved.
     * @param array<string,mixed> $f
     * @return array{events:array<int,array{type:string,at:string}>,supersede:?string,earlier:bool,above_limit:bool,needs_hr:bool}
     */
    public static function plan(array $f): array
    {
        $s = $f['settings'];
        $p = self::times($f);
        $type = (string) $f['type'];
        $events = [];
        $supersede = null;
        if (in_array($type, ['in', 'day'], true) || ($type === 'time' && $f['target'] === 'sign_in')) $events[] = ['type' => 'sign_in', 'at' => (string) $p['in']];
        if (in_array($type, ['out', 'day'], true) || ($type === 'time' && $f['target'] === 'sign_out')) $events[] = ['type' => 'sign_out', 'at' => (string) $p['out']];
        if ($type === 'time') $supersede = (string) $f['target'];
        $earlier = $type === 'time' && $f['target'] === 'sign_in' && $p['in'] < (string) $f['in'];
        $hr = ($f['source'] ?? 'employee') === 'hr';
        $above = !$hr && (int) $s['monthly_limit'] > 0 && (int) ($f['month_count'] ?? 0) >= (int) $s['monthly_limit'];
        return ['events' => $events, 'supersede' => $supersede, 'earlier' => $earlier, 'above_limit' => $above,
            'needs_hr' => !$hr && (($earlier && !empty($s['earlier_needs_hr'])) || ($above && $s['above_limit'] === 'hr'))];
    }

    /**
     * The Sign In and Sign Out of the day after the correction (Y-m-d H:i:s), with a Sign Out before
     * the Sign In moved to the next day on an overnight shift.
     * @param array<string,mixed> $f
     * @return array{in:?string,out:?string}
     */
    public static function times(array $f): array
    {
        $date = (string) $f['date'];
        $type = (string) $f['type'];
        $target = (string) ($f['target'] ?? '');
        $in = $f['in'] ?? null;
        $out = $f['out'] ?? null;
        $tin = (string) ($f['time_in'] ?? '');
        $tout = (string) ($f['time_out'] ?? '');
        if (($type === 'in' || $type === 'day' || ($type === 'time' && $target === 'sign_in')) && self::isTime($tin)) $in = $date . ' ' . $tin . ':00';
        if (($type === 'out' || $type === 'day' || ($type === 'time' && $target === 'sign_out')) && self::isTime($tout)) {
            $out = $date . ' ' . $tout . ':00';
            if ($in && $out <= $in && !empty($f['overnight'])) $out = gmdate('Y-m-d', (int) strtotime($date . ' +1 day')) . ' ' . $tout . ':00';
        }
        return ['in' => $in !== null ? (string) $in : null, 'out' => $out !== null ? (string) $out : null];
    }

    /**
     * The kinds of request that fit a day as it is recorded, among the enabled ones (the form offers
     * only these; check() refuses the others): Forgot Sign Out needs a Sign In and no Sign Out, Forgot
     * Sign In needs no Sign In, Whole day missing needs neither, Wrong time needs one of them.
     * @param array<string,int|bool> $enabled  CorrectionSettings::config()['types']
     * @return string[] in CorrectionSettings::TYPES order
     */
    public static function kinds(bool $has_in, bool $has_out, array $enabled): array
    {
        $fits = ['out' => $has_in && !$has_out, 'in' => !$has_in, 'time' => $has_in || $has_out, 'day' => !$has_in && !$has_out];
        $out = [];
        foreach (['out', 'in', 'time', 'day'] as $t) if (!empty($enabled[$t]) && $fits[$t]) $out[] = $t;
        return $out;
    }

    /**
     * What "My recent days" says under a day that needs no correction, from its report_days() result:
     * late | on_time | day_off | leave | holiday | not_scheduled | pending | absent | planned (show the
     * planned status, e.g. a business trip). Only a day with a Sign In is "on time".
     */
    public static function dayNote(string $result, bool $signed_in, int $late_minutes): string
    {
        if ($late_minutes > 0 || $result === 'Late') return 'late';
        $map = ['Present' => 'on_time', 'Off Day' => 'day_off', 'Leave' => 'leave', 'Holiday' => 'holiday',
            'Not Scheduled' => 'not_scheduled', 'Pending' => 'pending', 'Absent' => 'absent'];
        if (isset($map[$result])) return $map[$result];
        return $result !== '' ? 'planned' : ($signed_in ? 'on_time' : 'not_scheduled');
    }

    public static function isDate(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function isTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    public static function daysBetween(string $from, string $to): int
    {
        return (int) round(((int) strtotime($to . ' 00:00:00 UTC') - (int) strtotime($from . ' 00:00:00 UTC')) / 86400);
    }
}
