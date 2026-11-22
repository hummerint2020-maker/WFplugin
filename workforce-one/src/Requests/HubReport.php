<?php
namespace WorkforceOne\Requests;

if (!defined('ABSPATH')) exit;

/**
 * The Requests Hub's Reports tab (3.31.159, docs/tasks/hub-reports.md): every request of every type in five statuses.
 * Pure: the status rules (the read model's SQL CASE and the PHP check come from the same map), the filter, the decision
 * of an approval request's steps, and merging the face resets (kept in an option) into the newest-first list.
 */
final class HubReport
{
    public const WAITING = 1;
    public const APPROVED = 2;
    public const REJECTED = 3;
    public const WITHDRAWN = 4;
    public const CANCELLED = 5;
    public const STATUSES = [self::WAITING => 'waiting', self::APPROVED => 'approved', self::REJECTED => 'rejected', self::WITHDRAWN => 'withdrawn', self::CANCELLED => 'cancelled'];

    /** Type codes: the order of Hub::TYPES, from 1. The read model's rows carry these numbers, never text. */
    public const TYPE_CODES = ['leave' => 1, 'leave_cancellation' => 2, 'overtime' => 3, 'early_leave' => 4, 'shift_swap' => 5,
        'face_reset' => 6, 'legacy_vacation' => 7, 'correction' => 8, 'hr_letter' => 9];

    /** The Type filter (owner decision 11: the old vacation table is inside Leave). */
    public const FILTER_TYPES = ['leave' => ['leave', 'legacy_vacation'], 'leave_cancellation' => ['leave_cancellation'], 'overtime' => ['overtime'],
        'early_leave' => ['early_leave'], 'shift_swap' => ['shift_swap'], 'correction' => ['correction'], 'hr_letter' => ['hr_letter'], 'face_reset' => ['face_reset']];

    /**
     * Each type's status column and rules, first match wins: [values, status] or [values, status, [column, op, value]].
     * In a condition the value ':today' is the site's date given by the caller. Anything else is the default.
     */
    public const STATUS_MAP = [
        'leave' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['Pending'], self::WAITING], [['Approved'], self::APPROVED], [['Rejected'], self::REJECTED],
            [['Cancelled'], self::CANCELLED, ['cancellation_status', '=', 'Approved']],   // cancelled at the employee's request after approval
            [['Cancelled'], self::WITHDRAWN]]],                                            // withdrawn while it waited
        'leave_cancellation' => ['col' => 'cancellation_status', 'default' => self::CANCELLED, 'rules' => [
            [['Pending'], self::WAITING], [['Approved', 'Ended'], self::APPROVED], [['Rejected'], self::REJECTED], [['Withdrawn'], self::WITHDRAWN]]],
        'overtime' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['Pending'], self::WAITING], [['Approved'], self::APPROVED], [['Rejected'], self::REJECTED], [['Cancelled'], self::WITHDRAWN]]],
        'early_leave' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['Pending'], self::WAITING], [['Approved'], self::APPROVED], [['Rejected'], self::REJECTED], [['Cancelled'], self::WITHDRAWN]]],
        'shift_swap' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['Pending'], self::WAITING, ['work_date', '>=', ':today']],   // the day passed before the colleague answered: Cancelled (owner decision 9)
            [['Awaiting'], self::WAITING], [['Accepted', 'Approved'], self::APPROVED], [['Rejected', 'Declined'], self::REJECTED], [['Cancelled'], self::WITHDRAWN]]],
        'legacy_vacation' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['Pending'], self::WAITING], [['Approved'], self::APPROVED], [['Rejected'], self::REJECTED]]],
        'correction' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['pending', 'pending_hr'], self::WAITING], [['approved'], self::APPROVED], [['rejected'], self::REJECTED], [['cancelled'], self::WITHDRAWN]]],
        'hr_letter' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['pending'], self::WAITING], [['approved', 'issued', 'ready'], self::APPROVED], [['rejected'], self::REJECTED], [['withdrawn'], self::WITHDRAWN]]],
        'face_reset' => ['col' => 'status', 'default' => self::CANCELLED, 'rules' => [
            [['pending'], self::WAITING], [['approved'], self::APPROVED], [['rejected'], self::REJECTED]]],
    ];

    /** The status of one stored row (array of its columns). */
    public static function status(string $type, array $row, string $today): int
    {
        $m = self::STATUS_MAP[$type] ?? null;
        if (!$m) return self::CANCELLED;
        $v = (string) ($row[$m['col']] ?? '');
        foreach ($m['rules'] as $rule) {
            if (!in_array($v, $rule[0], true)) continue;
            if (isset($rule[2])) {
                [$col, $op, $want] = $rule[2];
                $want = $want === ':today' ? $today : $want;
                $have = (string) ($row[$col] ?? '');
                if ($op === '=' && $have !== $want) continue;
                if ($op === '>=' && strcmp($have, $want) < 0) continue;
            }
            return $rule[1];
        }
        return $m['default'];
    }

    /**
     * The same rules as a SQL CASE over alias $a. $quote quotes a string literal ($wpdb->prepare('%s')); $today is the site's date.
     * Only numbers come out, so a UNION across tables of different collations never compares text.
     */
    public static function caseSql(string $type, string $a, callable $quote, string $today): string
    {
        $m = self::STATUS_MAP[$type];
        $sql = 'CASE';
        foreach ($m['rules'] as $rule) {
            $in = implode(',', array_map($quote, $rule[0]));
            $cond = "{$a}.{$m['col']} IN ({$in})";
            if (isset($rule[2])) {
                [$col, $op, $want] = $rule[2];
                $cond .= " AND {$a}.{$col}" . ($op === '>=' ? '>=' : '=') . $quote($want === ':today' ? $today : $want);
            }
            $sql .= " WHEN {$cond} THEN " . (int) $rule[1];
        }
        return $sql . ' ELSE ' . (int) $m['default'] . ' END';
    }

    /** Days in a month (no calendar extension needed). */
    private static function cal_days(int $y, int $m): int
    {
        if ($m === 2) return ($y % 4 === 0 && ($y % 100 !== 0 || $y % 400 === 0)) ? 29 : 28;
        return in_array($m, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /** Status codes for the filter: '' or unknown = every status. */
    public static function statusCode(string $key): int
    {
        $code = array_search($key, self::STATUSES, true);
        return $code === false ? 0 : (int) $code;
    }

    /**
     * The filter from the address (GET). From/To are whole days; the default is the current month; From after To is swapped.
     * @return array{from:string,to:string,type:string,types:list<string>,status:int,who:string,employee:int,department:int,page:int}
     */
    public static function filter(array $get, string $today): array
    {
        $date = static function ($v): string {
            $v = is_string($v) ? trim($v) : '';
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return '';
            return $v;
        };
        $from = $date($get['from'] ?? '');
        $to = $date($get['to'] ?? '');
        if ($from === '') $from = substr($today, 0, 8) . '01';
        if ($to === '') $to = substr($from, 0, 8) . sprintf('%02d', self::cal_days((int) substr($from, 0, 4), (int) substr($from, 5, 2)));
        if (strcmp($from, $to) > 0) [$from, $to] = [$to, $from];
        $type = is_string($get['type'] ?? null) && isset(self::FILTER_TYPES[$get['type']]) ? (string) $get['type'] : '';
        [$kind, $id] = self::parseWho(is_string($get['who'] ?? null) ? (string) $get['who'] : '');
        return ['from' => $from, 'to' => $to, 'type' => $type, 'types' => $type === '' ? array_keys(self::TYPE_CODES) : self::FILTER_TYPES[$type],
            'status' => self::statusCode(is_string($get['status'] ?? null) ? (string) $get['status'] : ''),
            'who' => $kind === '' ? '' : $kind . ':' . $id, 'employee' => $kind === 'emp' ? $id : 0, 'department' => $kind === 'dept' ? $id : 0,
            'page' => max(1, (int) ($get['paged'] ?? 1))];
    }

    /** "emp:12" / "dept:3" (the Employee or department field). @return array{0:string,1:int} */
    public static function parseWho(string $who): array
    {
        if (preg_match('/^(emp|dept):(\d+)$/', trim($who), $m) && (int) $m[2] > 0) return [$m[1], (int) $m[2]];
        return ['', 0];
    }

    /**
     * The final decision of an approval request from its steps (rows with step_order, status, acted_at, comment,
     * approver_wp_user_id, acted_by_wp_user_id). A REJECTED step wins; else the last APPROVED; SKIPPED steps don't count.
     * 'current' is the position of the first level still waiting (0 when none).
     * @return array{decision:string,by:int,for:int,at:string,comment:string,level:int,levels:int,current:int}
     */
    public static function outcome(array $steps): array
    {
        $levels = 0;
        $pos = [];
        usort($steps, static function ($a, $b) { return ((int) ($a->step_order ?? 0)) <=> ((int) ($b->step_order ?? 0)); });
        foreach ($steps as $s) {
            if (strtoupper((string) ($s->status ?? '')) === 'SKIPPED') continue;
            $pos[(int) $s->step_order] = ++$levels;
        }
        $out = ['decision' => '', 'by' => 0, 'for' => 0, 'at' => '', 'comment' => '', 'level' => 0, 'levels' => $levels, 'current' => 0];
        $pick = null;
        foreach ($steps as $s) {
            $st = strtoupper((string) ($s->status ?? ''));
            if (!$out['current'] && in_array($st, ['WAITING', 'PENDING'], true)) $out['current'] = $pos[(int) $s->step_order] ?? 0;
            if ($st === 'REJECTED') { $pick = $s; break; }
            if ($st === 'APPROVED') $pick = $s;
        }
        if ($pick === null) return $out;
        $by = (int) ($pick->acted_by_wp_user_id ?? 0);
        $for = (int) ($pick->approver_wp_user_id ?? 0);
        $out['decision'] = strtoupper((string) $pick->status);
        $out['by'] = $by ?: $for;
        $out['for'] = $by && $for && $by !== $for ? $for : 0;
        $out['at'] = (string) ($pick->acted_at ?? '');
        $out['comment'] = trim((string) ($pick->comment ?? ''));
        $out['level'] = $pos[(int) $pick->step_order] ?? 0;
        return $out;
    }

    /**
     * The face resets in the filter's period, newest first: [at, employee_id, status] from the option's entries.
     * @param array<int|string,mixed> $entries
     * @param array<int,bool>|null $employees only these employees (a department), or null for everyone
     * @return list<array{at:string,id:int,status:int}>
     */
    public static function faces(array $entries, array $f, string $today, ?array $employees): array
    {
        if (!in_array('face_reset', $f['types'], true)) return [];
        $out = [];
        foreach ($entries as $emp => $r) {
            $emp = (int) $emp;
            if (!$emp || !is_array($r)) continue;
            $at = (string) ($r['requested_at'] ?? '');
            if ($at === '' || strcmp(substr($at, 0, 10), $f['from']) < 0 || strcmp(substr($at, 0, 10), $f['to']) > 0) continue;
            if ($f['employee'] && $f['employee'] !== $emp) continue;
            if ($employees !== null && empty($employees[$emp])) continue;
            $st = self::status('face_reset', $r, $today);
            $out[] = ['at' => $at, 'id' => $emp, 'status' => $st];
        }
        usort($out, static function ($a, $b) { return self::newerFirst($a['at'], self::TYPE_CODES['face_reset'], $a['id'], $b['at'], self::TYPE_CODES['face_reset'], $b['id']); });
        return $out;
    }

    /** Newest first; equal times by type code, then id, both descending (the read model's ORDER BY). */
    public static function newerFirst(string $atA, int $tA, int $idA, string $atB, int $tB, int $idB): int
    {
        return [$atB, $tB, $idB] <=> [$atA, $tA, $idA];
    }

    /**
     * One page of keys: the read model's keys (already newest first, at most $offset+$per of them) merged with the face resets.
     * @param list<array{t:int,id:int,at:string}> $keys
     * @param list<array{at:string,id:int,status:int}> $faces
     * @return list<array{t:int,id:int,at:string}>
     */
    public static function merge(array $keys, array $faces, int $offset, int $per): array
    {
        $all = $keys;
        foreach ($faces as $f) $all[] = ['t' => self::TYPE_CODES['face_reset'], 'id' => $f['id'], 'at' => $f['at']];
        usort($all, static function ($a, $b) { return self::newerFirst($a['at'], $a['t'], $a['id'], $b['at'], $b['t'], $b['id']); });
        return array_slice($all, $offset, $per);
    }

    /** Face resets with the status filter applied. */
    public static function facesOfStatus(array $faces, int $status): array
    {
        return $status ? array_values(array_filter($faces, static function ($f) use ($status) { return $f['status'] === $status; })) : $faces;
    }

    /**
     * The totals tiles: [status => n] summed over the types, plus 'all'. Counts are type => status => n.
     * @param array<int,array<int,int>> $counts
     * @return array<string,int>
     */
    public static function tiles(array $counts, array $faces): array
    {
        $out = ['all' => 0] + array_fill_keys(array_values(self::STATUSES), 0);
        foreach ($counts as $byStatus) foreach ($byStatus as $st => $n) { if (isset(self::STATUSES[$st])) { $out[self::STATUSES[$st]] += (int) $n; $out['all'] += (int) $n; } }
        foreach ($faces as $f) { $out[self::STATUSES[$f['status']]]++; $out['all']++; }
        return $out;
    }

    public static function typeName(int $code): string
    {
        $k = array_search($code, self::TYPE_CODES, true);
        return $k === false ? '' : (string) $k;
    }
}
