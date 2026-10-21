<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Shift history (3.31.108, owner decisions 2026-10-17, mockup docs/mockups/shift-history/): an
 * employee's shift periods (from a day to a day, the last one open) and a shift's dated times. A day
 * is counted with the shift and the times it had on that day, so changing a shift no longer changes
 * old reports. Pure: no WordPress calls.
 *
 * A period: ['id'=>int,'from'=>'Y-m-d','to'=>'Y-m-d'|null,'shift_id'=>int] (shift 0 = company hours).
 * A version: ['from'=>'Y-m-d', ...times] for one shift.
 */
final class ShiftPeriods
{
    /** The first day of the history made on update: everything before a recorded change. */
    public const BEGINNING = '2000-01-01';

    /** The period covering $date (periods sorted or not), or null. @param list<array<string,mixed>> $periods */
    public static function on(array $periods, string $date): ?array
    {
        $best = null;
        foreach ($periods as $p) {
            if ((string) $p['from'] > $date) continue;
            if ($p['to'] !== null && (string) $p['to'] < $date) continue;
            if ($best === null || (string) $p['from'] > (string) $best['from']) $best = $p;
        }
        return $best;
    }

    /** The version in force on $date (the latest one from on or before it), or null. @param list<array<string,mixed>> $versions */
    public static function version(array $versions, string $date): ?array
    {
        $best = null;
        foreach ($versions as $v) {
            if ((string) $v['from'] <= $date && ($best === null || (string) $v['from'] > (string) $best['from'])) $best = $v;
        }
        return $best;
    }

    /**
     * Where a new period from $from goes: the one before it ends the day before, and it ends the day
     * before the next one starts (or stays open). One on the same day is replaced. Nothing is removed.
     * @param list<array<string,mixed>> $periods
     * @return array{replace:int,close:int,close_to:string,to:string|null}  replace: id of a period on the same day (0 = none);
     *         close: id of the period before (0 = none) and its new end; to: the new period's end
     */
    public static function insert(array $periods, string $from): array
    {
        $before = null; $after = null; $same = null;
        foreach ($periods as $p) {
            $f = (string) $p['from'];
            if ($f === $from) $same = $p;
            elseif ($f < $from && ($before === null || $f > (string) $before['from'])) $before = $p;
            elseif ($f > $from && ($after === null || $f < (string) $after['from'])) $after = $p;
        }
        return [
            'replace' => $same ? (int) $same['id'] : 0,
            'close' => $before ? (int) $before['id'] : 0,
            'close_to' => self::dayBefore($from),
            'to' => $after ? self::dayBefore((string) $after['from']) : null,
        ];
    }

    /** After removing a period: the one before it takes over its days (its new end). @param list<array<string,mixed>> $periods */
    public static function afterRemove(array $periods, int $id): array
    {
        $gone = null;
        foreach ($periods as $p) if ((int) $p['id'] === $id) $gone = $p;
        if (!$gone) return ['extend' => 0, 'to' => null];
        $before = null;
        foreach ($periods as $p) {
            if ((int) $p['id'] !== $id && (string) $p['from'] < (string) $gone['from'] && ($before === null || (string) $p['from'] > (string) $before['from'])) $before = $p;
        }
        return ['extend' => $before ? (int) $before['id'] : 0, 'to' => $gone['to']];
    }

    /** Why a change cannot be saved, or null. past: the day is before today and the person may not change the past. */
    public static function check(string $from, string $today, bool $may_change_past, bool $shift_ok): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if (!$d || $d->format('Y-m-d') !== $from || $from < self::BEGINNING) return 'date';
        if (!$shift_ok) return 'shift';
        if ($from < $today && !$may_change_past) return 'past';
        return null;
    }

    /** Whether $item (a period or a version) is the last one by its start. @param list<array<string,mixed>> $items @param array<string,mixed> $item */
    public static function isLatest(array $items, array $item): bool
    {
        foreach ($items as $x) if ((string) $x['from'] > (string) $item['from']) return false;
        return true;
    }

    public static function dayBefore(string $date): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d ? $d->modify('-1 day')->format('Y-m-d') : $date;
    }

    /** Whether a shift's times changed (what a new version is made for). @param array<string,mixed> $a @param array<string,mixed> $b */
    public static function timesChanged(array $a, array $b): bool
    {
        foreach (['start', 'end', 'grace', 'sign_in_cutoff_minutes', 'overnight'] as $k) {
            if ((string) ($a[$k] ?? '') !== (string) ($b[$k] ?? '')) return true;
        }
        return false;
    }

    /**
     * What to do with a shift's dated times before new ones are saved from $from (3.31.116, docs/tasks/payroll-integrity.md). $live is
     * what the newest version counts with now (the option). beginning: no version yet, record $live from the start; rewrite: the newest
     * version starts before $from and its stored values differ from $live, so store $live in it (it counts with $live today, and will keep
     * doing so once it is no longer the newest); none: nothing to do; later: a version already starts after $from (refused, owner decision).
     * @param list<array<string,mixed>> $versions @param array<string,mixed> $live @return array{action:string,from?:string}
     */
    public static function settle(array $versions, string $from, array $live): array
    {
        if (!$versions) return ['action' => 'beginning'];
        $latest = null;
        foreach ($versions as $v) if ($latest === null || (string) $v['from'] > (string) $latest['from']) $latest = $v;
        if ((string) $latest['from'] > $from) return ['action' => 'later', 'from' => (string) $latest['from']];
        if ((string) $latest['from'] === $from || !self::timesChanged($latest, $live)) return ['action' => 'none'];
        return ['action' => 'rewrite', 'from' => (string) $latest['from']];
    }
}
