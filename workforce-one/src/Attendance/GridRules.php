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
     * @return array{class:string,label:string,detail:string}
     */
    public static function badge(string $actual, string $time = ''): array
    {
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
        if ($n('grid_conflict')) $text .= ' ' . $n('grid_conflict') . ' change(s) were not saved because the schedule was changed by someone else after you opened this page. Review the current values and try again.';
        if ($n('grid_invalid')) $text .= ' ' . $n('grid_invalid') . ' change(s) could not be saved (unknown status, or an employee you cannot manage).';
        return ['text' => $text, 'error' => $n('grid_conflict') > 0 || $n('grid_invalid') > 0];
    }
}
