<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

/** Daily attendance trend of a period (the Attendance Summary chart). Pure. */
final class Trend
{
    /**
     * @param array<int,array<string,mixed>> $days report_days() rows
     * @return array<string,array{present:int,late:int,absent:int,rate:?int}> per date, in date order
     */
    public static function daily(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            $date = (string) $d['date'];
            if (!isset($out[$date])) $out[$date] = ['present' => 0, 'late' => 0, 'absent' => 0, 'rate' => null];
            if ($d['result'] === 'Present') $out[$date]['present']++;
            elseif ($d['result'] === 'Late') $out[$date]['late']++;
            elseif ($d['result'] === 'Absent') $out[$date]['absent']++;
        }
        ksort($out);
        foreach ($out as $date => $c) {
            $all = $c['present'] + $c['late'] + $c['absent'];
            $out[$date]['rate'] = $all > 0 ? (int) round(($c['present'] + $c['late']) * 100 / $all) : null;
        }
        return $out;
    }
}
