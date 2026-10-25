<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * The lists behind the Attendance page's cards (3.31.125, docs/tasks/attendance-cards.md): which day results each card lists,
 * their order, paging, each person's week, the rate day by day and the lowest people. The rows are the same person-days the
 * card counts (the page's own loop, attendance_walk()), so a list always counts what its card counts.
 * Pure: no WordPress calls.
 */
final class CardLists
{
    public const KINDS = ['people', 'present', 'late', 'absent', 'rate'];
    public const PER = 20;
    public const LOWEST = 10;

    private const ACTUAL = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent'];

    /** Whether a day result belongs in a kind's list (people and rate list people, not days). */
    public static function accepts(string $kind, string $actual): bool
    {
        return isset(self::ACTUAL[$kind]) && self::ACTUAL[$kind] === $actual;
    }

    /**
     * Rows in their order (owner decision 8). By day: the dates in order, then within a day $sort (late: most minutes first,
     * so its sort is minus the minutes; present: the sign-in time; absent: 0), then the grid order. By employee: the grid
     * order, then the date. Stable.
     * @param list<array{pos:int,date:string,sort:int}> $keys
     * @return list<array{pos:int,date:string,sort:int}>
     */
    public static function order(array $keys, string $order): array
    {
        usort($keys, $order === 'employee'
            ? static function ($a, $b) { return [$a['pos'], $a['date']] <=> [$b['pos'], $b['date']]; }
            : static function ($a, $b) { return [$a['date'], $a['sort'], $a['pos']] <=> [$b['date'], $b['sort'], $b['pos']]; });
        return $keys;
    }

    /**
     * One page of $per rows from $offset (clamped), and where the next page starts (null at the end).
     * @template T
     * @param list<T> $rows
     * @return array{rows:list<T>,total:int,offset:int,next:int|null}
     */
    public static function page(array $rows, int $offset, int $per = self::PER): array
    {
        $total = count($rows);
        $offset = max(0, min($offset, $total));
        $next = $offset + $per < $total ? $offset + $per : null;
        return ['rows' => array_values(array_slice($rows, $offset, $per)), 'total' => $total, 'offset' => $offset, 'next' => $next];
    }

    /**
     * Adds one day result to a person's week: present, late, absent, leave (their own leave) and holiday (a company holiday,
     * counted apart, owner decision 5).
     * @param array{present:int,late:int,absent:int,leave:int,holiday:int} $week
     * @return array{present:int,late:int,absent:int,leave:int,holiday:int}
     */
    public static function count(array $week, string $actual, bool $holiday): array
    {
        if ($holiday) $week['holiday']++;
        elseif ($actual === 'Present') $week['present']++;
        elseif ($actual === 'Late') $week['late']++;
        elseif ($actual === 'Absent') $week['absent']++;
        elseif ($actual === 'Leave') $week['leave']++;
        return $week;
    }

    /** @return array{present:int,late:int,absent:int,leave:int,holiday:int} */
    public static function emptyWeek(): array
    {
        return ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0];
    }

    /**
     * The rate of each day (Insights::rate of its present, late and absent results); a day with none of them has no rate.
     * @param array<string,array{present:int,late:int,absent:int}> $days date => counts, in date order
     * @return list<array{date:string,rate:int|null,attended:int,required:int}>
     */
    public static function rateByDay(array $days): array
    {
        $out = [];
        foreach ($days as $date => $c) {
            $required = $c['present'] + $c['late'] + $c['absent'];
            $out[] = ['date' => (string) $date, 'rate' => $required ? Insights::rate($c['present'], $c['late'], $c['absent']) : null,
                'attended' => $c['present'] + $c['late'], 'required' => $required];
        }
        return $out;
    }

    /**
     * The people with the lowest rate this week, below 100% only: lowest first, then more absent days, then the grid order.
     * @param array<int,array{pos:int,present:int,late:int,absent:int}> $people employee id => counts
     * @return list<array{employee_id:int,pos:int,rate:int,attended:int,required:int}>
     */
    public static function lowest(array $people, int $limit = self::LOWEST): array
    {
        $out = [];
        foreach ($people as $id => $c) {
            $required = $c['present'] + $c['late'] + $c['absent'];
            if (!$required) continue;
            $rate = Insights::rate($c['present'], $c['late'], $c['absent']);
            if ($rate >= 100) continue;
            $out[] = ['employee_id' => (int) $id, 'pos' => $c['pos'], 'rate' => $rate, 'attended' => $c['present'] + $c['late'], 'required' => $required, 'absent' => $c['absent']];
        }
        usort($out, static function ($a, $b) { return [$a['rate'], -$a['absent'], $a['pos']] <=> [$b['rate'], -$b['absent'], $b['pos']]; });
        return array_map(static function ($r) { unset($r['absent']); return $r; }, array_slice($out, 0, $limit));
    }

    /**
     * Whether a day with a Sign In shows "No sign-out" (owner decision 7): a past day without a Sign Out always; today only
     * once the person's shift has ended ($shiftEnd, a Unix time; null = unknown, so not shown); never a later day.
     */
    public static function missingOut(bool $hasIn, bool $hasOut, string $date, string $today, int $now, ?int $shiftEnd): bool
    {
        if (!$hasIn || $hasOut || $date > $today) return false;
        if ($date < $today) return true;
        return $shiftEnd !== null && $now >= $shiftEnd;
    }

    /**
     * The note above the Late list: one start and "late after" time when every row shares the same shift start and grace,
     * otherwise null (the generic note).
     * @param list<array{start:string,grace:int}> $shifts
     * @return array{start:string,late_after:string}|null
     */
    public static function lateNote(array $shifts): ?array
    {
        $seen = [];
        foreach ($shifts as $s) $seen[$s['start'] . '|' . (int) $s['grace']] = $s;
        if (count($seen) !== 1) return null;
        $s = reset($seen);
        $after = strtotime('1970-01-01 ' . $s['start'] . ':00') + (int) $s['grace'] * 60;
        return ['start' => $s['start'], 'late_after' => gmdate('H:i', $after)];
    }
}
