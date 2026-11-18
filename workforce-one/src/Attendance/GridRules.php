<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Presentation rules of the manager Attendance grid (employee app, ?ews_view=attendance).
 * The day result itself comes from Insights::actual(), shared with Attendance Insights. Pure.
 */
final class GridRules
{
    /** Counted in the summary cards; every other result is "Other". */
    public const SUMMARY = ['Present', 'Late', 'Absent', 'Leave'];

    /**
     * Badge of one day's result.
     * @param string $actual Insights::actual() result
     * @param string $time   Sign In time (Present / Late) or holiday name (Leave)
     * @param string|null $leaveType the approved leave's type (leaveType()); null: as before
     * @return array{class:string,label:string,detail:string,type?:string}
     */
    public static function badge(string $actual, string $time = '', ?string $leaveType = null): array
    {
        if ($actual === 'Leave' && $leaveType !== null && $leaveType !== '') return ['class' => 'leave', 'label' => '▣ ' . $leaveType, 'detail' => $time, 'type' => $leaveType];
        switch ($actual) {
            case 'Present': return ['class' => 'present', 'label' => '✓ Present', 'detail' => $time];
            case 'Late': return ['class' => 'late', 'label' => '◷ Late', 'detail' => $time];
            case 'Absent': return ['class' => 'absent', 'label' => '× Absent', 'detail' => ''];
            case 'Leave': return ['class' => 'leave', 'label' => '▣ Leave', 'detail' => $time];
            case 'Pending': return ['class' => 'pending', 'label' => 'Awaiting sign in', 'detail' => ''];
            case 'Not Scheduled': return ['class' => 'muted', 'label' => '— Not scheduled', 'detail' => ''];
        }
        // Insights::actual() returns the type name itself for business-trip types (Business Trip, Training Course…).
        return ['class' => 'trip', 'label' => '✈ ' . $actual, 'detail' => ''];
    }

    /**
     * The type a Leave day shows (3.31.152, docs/tasks/leave-type-attendance.md §1): only a day of approved leave, that is a
     * Leave result, not a company holiday (the holiday wins, owner decision 4), still planned Vacation (the status the
     * approval writes; a day changed by hand shows "Leave", owner answer 6), with a type name. Otherwise null: as before.
     */
    public static function leaveType(string $actual, bool $holiday, string $planned, ?string $name): ?string
    {
        if ($actual !== 'Leave' || $holiday || $planned !== 'Vacation' || $name === null) return null;
        $name = trim($name);
        return $name === '' ? null : $name;
    }

    /**
     * The Leave days card: the total and the line under it by short name (shortType()), most days first, then by name; the
     * days without a type ("Other leave", owner answer 1) last.
     * @param array<string,int> $byType type name => days
     * @return array{total:int,split:list<array{0:string,1:int}>,other:int}
     */
    public static function leaveSummary(array $byType, int $other): array
    {
        $split = [];
        $short = [];   // two types with the same short name ("Annual", "Annual Leave") are one entry
        foreach ($byType as $name => $n) if ((int) $n > 0) { $k = self::shortType((string) $name); $short[$k] = ($short[$k] ?? 0) + (int) $n; }
        foreach ($short as $name => $n) $split[] = [(string) $name, $n];
        usort($split, static function ($a, $b) { return [$b[1], $a[0]] <=> [$a[1], $b[0]]; });
        return ['total' => array_sum(array_column($split, 1)) + max(0, $other), 'split' => $split, 'other' => max(0, $other)];
    }

    /** A type's short name for the card's line (owner answer 2): "Annual Leave" → "Annual", "إجازة مرضية" → "مرضية"; never empty. */
    public static function shortType(string $name): string
    {
        $name = trim($name);
        $short = trim((string) preg_replace(['/\s+leave$/iu', '/^إجازة\s+/u'], '', $name));
        return $short !== '' ? $short : $name;
    }

    /**
     * Result message after a save or import, from the redirect's query arguments.
     * @param array<string,mixed> $q
     * @return array{text:string,error:bool}|null
     */
    public static function message(array $q): ?array
    {
        $n = static function (string $k) use ($q): int { return isset($q[$k]) ? max(0, (int) $q[$k]) : 0; };
        if (isset($q['imported'])) return ['text' => 'Imported ' . $n('imported') . ' row(s); rejected ' . $n('rejected') . '.', 'error' => false];
        if (isset($q['saved'])) return ['text' => 'Attendance saved.', 'error' => false];
        if (!isset($q['grid_saved'])) return null;
        $text = 'Saved ' . $n('grid_saved') . ' schedule record(s). Skipped empty cells: ' . $n('grid_skipped') . '.';
        // Conflicts (grid_conflict) get their own pop-up from the app layout (ux_notice_from_query()).
        if ($n('grid_invalid')) $text .= ' ' . $n('grid_invalid') . ' change(s) could not be saved (unknown status, or an employee you cannot manage).';
        return ['text' => $text, 'error' => $n('grid_invalid') > 0];
    }
}
