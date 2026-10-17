<?php
namespace WorkforceOne\Organization;

use WorkforceOne\Settings\OrgChartSettings;

if (!defined('ABSPATH')) exit;

/**
 * Importing managers (and job titles) from the Excel file the Org chart exports (3.31.98). Reads the
 * rows, finds each employee and their new manager, and says what would change and which rows cannot
 * be used, before anything is saved. Rows that would put someone under a person who reports to them
 * are refused (checked with every other change in the file applied). Pure: no WordPress calls.
 *
 * Columns, found by their header (any order, any letter case): "Employee ID" or "Domain name" to find
 * the employee; "Manager ID" or "Manager domain name" for the manager (both empty = no manager);
 * "Job title" (optional; an empty cell keeps the saved title). Other columns are ignored.
 */
final class ImportPlan
{
    public const MAX_ROWS = 5000;

    /**
     * @param list<list<string>> $rows the sheet, the first row being the headers
     * @param array<int,array{id:int,name:string,domain:string,title:string,manager:int,active:bool}> $people every employee, by id
     * @return array{error:?string,rows:int,unchanged:int,moves:list<array{row:int,employee:int,from:int,to:int}>,
     *   titles:list<array{row:int,employee:int,from:string,to:string}>,problems:list<array{row:int,code:string,value:string}>}
     */
    public static function build(array $rows, array $people): array
    {
        $out = ['error' => null, 'rows' => 0, 'unchanged' => 0, 'moves' => [], 'titles' => [], 'problems' => []];
        if (!$rows) { $out['error'] = 'empty'; return $out; }
        $head = [];
        foreach (array_values((array) $rows[0]) as $i => $h) {
            $k = strtolower(trim(preg_replace('/\s+/', ' ', (string) $h)));
            if ($k !== '' && !isset($head[$k])) $head[$k] = $i;
        }
        $col = static function (string $name) use ($head): ?int { return $head[$name] ?? null; };
        $cEid = $col('employee id'); $cDom = $col('domain name');
        $cMid = $col('manager id'); $cMdom = $col('manager domain name'); $cTitle = $col('job title');
        if ($cEid === null && $cDom === null) { $out['error'] = 'columns'; return $out; }
        $hasManager = $cMid !== null || $cMdom !== null;
        if (!$hasManager && $cTitle === null) { $out['error'] = 'columns'; return $out; }
        $data = array_slice($rows, 1);
        if (count($data) > self::MAX_ROWS) { $out['error'] = 'too_many'; return $out; }

        $byDomain = [];
        foreach ($people as $p) $byDomain[strtolower(trim($p['domain']))] = (int) $p['id'];
        $cell = static function (array $r, ?int $c): string { return $c === null ? '' : trim((string) ($r[$c] ?? '')); };
        $find = static function (string $id, string $domain) use ($people, $byDomain): int {
            if ($id !== '' && ctype_digit($id)) return isset($people[(int) $id]) ? (int) $id : -1;
            if ($id !== '') return -1;
            if ($domain === '') return 0;
            return $byDomain[strtolower($domain)] ?? -1;
        };

        $managers = [];
        foreach ($people as $p) if ($p['active']) $managers[(int) $p['id']] = (int) $p['manager'];
        $wanted = [];   // employee => [row, to]
        $seen = [];
        foreach ($data as $i => $r) {
            $r = array_values((array) $r);
            $line = $i + 2;
            if (implode('', array_map('trim', array_map('strval', $r))) === '') continue;
            $out['rows']++;
            $eid = $find($cell($r, $cEid), $cell($r, $cDom));
            $who = $cell($r, $cEid) !== '' ? $cell($r, $cEid) : $cell($r, $cDom);
            if ($eid <= 0) { $out['problems'][] = ['row' => $line, 'code' => 'employee_unknown', 'value' => $who]; continue; }
            if (!$people[$eid]['active']) { $out['problems'][] = ['row' => $line, 'code' => 'employee_archived', 'value' => $people[$eid]['name']]; continue; }
            if (isset($seen[$eid])) { $out['problems'][] = ['row' => $line, 'code' => 'duplicate', 'value' => $people[$eid]['name']]; continue; }
            $seen[$eid] = true;
            $changed = false;
            if ($hasManager) {
                $mid = $find($cell($r, $cMid), $cell($r, $cMdom));
                $mwho = $cell($r, $cMid) !== '' ? $cell($r, $cMid) : $cell($r, $cMdom);
                if ($mid < 0) { $out['problems'][] = ['row' => $line, 'code' => 'manager_unknown', 'value' => $mwho]; continue; }
                if ($mid && !$people[$mid]['active']) { $out['problems'][] = ['row' => $line, 'code' => 'manager_archived', 'value' => $people[$mid]['name']]; continue; }
                if ($mid === $eid) { $out['problems'][] = ['row' => $line, 'code' => 'self', 'value' => $people[$eid]['name']]; continue; }
                if ($mid !== (int) $people[$eid]['manager']) { $wanted[$eid] = [$line, $mid]; $changed = true; }
            }
            if ($cTitle !== null) {
                $t = OrgChartSettings::title($cell($r, $cTitle));
                if ($t !== '' && $t !== (string) $people[$eid]['title']) {
                    $out['titles'][] = ['row' => $line, 'employee' => $eid, 'from' => (string) $people[$eid]['title'], 'to' => $t];
                    $changed = true;
                }
            }
            if (!$changed) $out['unchanged']++;
        }

        // Every change applied at once; a row that makes a loop is refused, and checked again without it.
        for ($guard = 0; $guard < 100; $guard++) {
            $m = $managers;
            foreach ($wanted as $e => [, $to]) $m[$e] = $to;
            $loop = array_values(array_intersect(OrgTree::loopsIn($m), array_keys($wanted)));
            if (!$loop) break;
            foreach ($loop as $e) {
                $out['problems'][] = ['row' => $wanted[$e][0], 'code' => 'loop', 'value' => $people[$e]['name']];
                unset($wanted[$e]);
            }
        }
        foreach ($wanted as $e => [$line, $to]) $out['moves'][] = ['row' => $line, 'employee' => $e, 'from' => (int) $people[$e]['manager'], 'to' => $to];
        usort($out['moves'], static function ($a, $b) { return $a['row'] <=> $b['row']; });
        usort($out['problems'], static function ($a, $b) { return $a['row'] <=> $b['row']; });
        return $out;
    }
}
