<?php
namespace WorkforceOne\Employees;

if (!defined('ABSPATH')) exit;

/** Summary values of the wp-admin employee profile. Pure: no WordPress calls. */
final class ProfileSummary
{
    /** Up to two initials, whole characters in any script (e.g. "أحمد منير" → "أم"). */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$parts) return '';
        $first = mb_substr($parts[0], 0, 1, 'UTF-8');
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
        return mb_strtoupper($first . $last, 'UTF-8');
    }

    /**
     * Today's attendance result.
     * @param string      $planned        schedule status, or 'Not Set'
     * @param string|null $classification 'On Time' / 'Late Arrival' of the first Sign In, null if none
     * @param bool        $legacyLate     the first Sign In is a historical late_sign_in event
     */
    public static function todayResult(string $planned, ?string $classification, bool $legacyLate): string
    {
        if ($planned === 'Vacation' || $planned === 'Leave') return 'Leave';
        if (in_array($planned, ['Business Trip', 'Training Course'], true)) return $planned;
        if ($classification !== null) return ($legacyLate || $classification === 'Late Arrival') ? 'Late' : 'Present';
        return 'Pending';
    }

    /**
     * @param array<int, array{result: string}> $rows attendance report rows
     * @return array{counted: int, attended: int, late: int, absent: int, rate: int}
     */
    public static function attendance(array $rows): array
    {
        $s = ['counted' => 0, 'attended' => 0, 'late' => 0, 'absent' => 0, 'rate' => 0];
        foreach ($rows as $r) {
            if (!in_array($r['result'], ['Present', 'Late', 'Absent'], true)) continue;
            $s['counted']++;
            if ($r['result'] === 'Absent') $s['absent']++;
            else $s['attended']++;
            if ($r['result'] === 'Late') $s['late']++;
        }
        $s['rate'] = $s['counted'] ? (int) round($s['attended'] / $s['counted'] * 100) : 0;
        return $s;
    }
}
