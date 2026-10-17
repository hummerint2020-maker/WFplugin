<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

/**
 * Location Capacity: planned and actual people per location and day against its seats. An
 * employee takes a seat at their location on a day scheduled with a type that requires the
 * location (e.g. Office); WFH, leave and missions do not. Pure.
 */
final class Capacity
{
    /** Occupancy from which a day is "near capacity", unless set on Work Locations (50–100). */
    public const WARN_PCT = 90;

    /** @param mixed $value */
    public static function warnPercent($value): int
    {
        return max(50, min(100, (int) $value));
    }

    public const LABELS = ['over' => 'Over capacity', 'warn' => 'Near capacity', 'ok' => 'OK', 'none' => 'No limit'];

    /**
     * @param array<int,array{id:int,name:string,seats:?int}> $locations active locations, in display order
     * @param array<int,array{location_id:int,date:string,signed_in:bool,mine:bool,actual_location_id?:int}> $seated one entry per employee-day taking a seat;
     *        actual_location_id (3.31.72): the branch they signed in at, when it is another than location_id
     * @param string[] $dates the days of the report, in order
     * @param string $today 'Y-m-d': later days have no actual figure
     * @param int $warnPct occupancy from which a day is near capacity
     * @return array<int,array<string,mixed>> per location id: id, name, seats, days (date => planned, actual, mine, pct, level), peak, days_over, days_warn
     */
    public static function grid(array $locations, array $seated, array $dates, string $today, int $warnPct = self::WARN_PCT): array
    {
        $out = [];
        foreach ($locations as $l) {
            $days = [];
            foreach ($dates as $d) $days[$d] = ['planned' => 0, 'actual' => $d <= $today ? 0 : null, 'mine' => 0];
            $out[(int) $l['id']] = ['id' => (int) $l['id'], 'name' => (string) $l['name'], 'seats' => $l['seats'] !== null && (int) $l['seats'] > 0 ? (int) $l['seats'] : null, 'days' => $days];
        }
        foreach ($seated as $s) {
            $id = (int) $s['location_id'];
            $d = (string) $s['date'];
            if (!isset($out[$id]['days'][$d])) continue;
            $day = &$out[$id]['days'][$d];
            $day['planned']++;
            if ($s['mine']) $day['mine']++;
            unset($day);
            if ($s['signed_in']) {
                $at = (int) ($s['actual_location_id'] ?? 0) ?: $id;
                if (!isset($out[$at]['days'][$d])) $at = $id;
                if ($out[$at]['days'][$d]['actual'] !== null) $out[$at]['days'][$d]['actual']++;
            }
        }
        foreach ($out as $id => $l) {
            $peak = 0; $over = 0; $warn = 0;
            foreach ($l['days'] as $d => $day) {
                $pct = self::pct($day['planned'], $l['seats']);
                $level = self::level($day['planned'], $l['seats'], $warnPct);
                $out[$id]['days'][$d] += ['pct' => $pct, 'level' => $level];
                $peak = max($peak, $day['planned']);
                if ($level === 'over') $over++;
                elseif ($level === 'warn') $warn++;
            }
            $out[$id] += ['peak' => $peak, 'days_over' => $over, 'days_warn' => $warn];
        }
        return $out;
    }

    /** Occupancy in whole percent; null without a seat limit. */
    public static function pct(int $planned, ?int $seats): ?int
    {
        return $seats ? (int) round($planned * 100 / $seats) : null;
    }

    /** over (more people than seats), warn ($warnPct or more), ok, or none (no seat limit). */
    public static function level(int $planned, ?int $seats, int $warnPct = self::WARN_PCT): string
    {
        if (!$seats) return 'none';
        if ($planned > $seats) return 'over';
        return $planned * 100 >= $warnPct * $seats ? 'warn' : 'ok';
    }
}
