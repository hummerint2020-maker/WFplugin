<?php
namespace WorkforceOne\Employees;

if (!defined('ABSPATH')) exit;

/**
 * Updating employees' details for letters from a file (HL2, owner decisions 2026-11-08): reads the rows, finds each employee by "Employee ID"
 * or "Domain name", and says what would change and which rows cannot be used, before anything is saved. Columns found by their header (any
 * order, any letter case): name_ar, gender, hire_date (at least one). An empty cell leaves the saved value as it is. A row with any wrong
 * value is refused whole. Archived employees may be updated (letters for former employees). Pure: no WordPress calls.
 */
final class DetailsImportPlan
{
    public const MAX_ROWS = 5000;

    /**
     * @param list<list<string>> $rows the sheet, the first row being the headers
     * @param array<int,object> $people every employee by id (id, name, domain_name, active, name_ar, gender, hire_date)
     * @param string $today Y-m-d
     * @return array{error:?string,rows:int,unchanged:int,changes:list<array{row:int,employee:int,field:string,from:?string,to:?string}>,
     *   problems:list<array{row:int,code:string,value:string,employee:int}>}
     */
    public static function build(array $rows, array $people, string $today): array
    {
        $out = ['error' => null, 'rows' => 0, 'unchanged' => 0, 'changes' => [], 'problems' => []];
        if (!$rows) { $out['error'] = 'empty'; return $out; }
        $head = [];
        foreach (array_values((array) $rows[0]) as $i => $h) {
            $k = strtolower(trim((string) preg_replace('/[\s_]+/', ' ', str_replace("\xEF\xBB\xBF", '', (string) $h))));
            if ($k !== '' && !isset($head[$k])) $head[$k] = $i;
        }
        $col = static function (string $name) use ($head): ?int { return $head[$name] ?? null; };
        $cEid = $col('employee id');
        $cDom = $col('domain name');
        $cols = ['name_ar' => $col('name ar'), 'gender' => $col('gender'), 'hire_date' => $col('hire date')];
        if ($cEid === null && $cDom === null) { $out['error'] = 'columns'; return $out; }
        if ($cols['name_ar'] === null && $cols['gender'] === null && $cols['hire_date'] === null) { $out['error'] = 'columns'; return $out; }
        $data = array_slice($rows, 1);
        if (count($data) > self::MAX_ROWS) { $out['error'] = 'too_many'; return $out; }

        $byDomain = [];
        foreach ($people as $p) $byDomain[strtolower(trim((string) $p->domain_name))] = (int) $p->id;
        $cell = static function (array $r, ?int $c): string { return $c === null ? '' : trim((string) ($r[$c] ?? '')); };
        $seen = [];
        foreach ($data as $i => $r) {
            $r = array_values((array) $r);
            $line = $i + 2;
            if (implode('', array_map('trim', array_map('strval', $r))) === '') continue;
            $out['rows']++;
            $id = $cell($r, $cEid);
            $dom = $cell($r, $cDom);
            // The ID wins over the domain name when both are there.
            $eid = $id !== '' ? (ctype_digit($id) && isset($people[(int) $id]) ? (int) $id : -1) : ($dom !== '' ? ($byDomain[strtolower($dom)] ?? -1) : -1);
            if ($eid <= 0) { $out['problems'][] = ['row' => $line, 'code' => 'employee_unknown', 'value' => $id !== '' ? $id : $dom, 'employee' => 0]; continue; }
            if (isset($seen[$eid])) { $out['problems'][] = ['row' => $line, 'code' => 'duplicate', 'value' => (string) $people[$eid]->name, 'employee' => $eid]; continue; }
            $seen[$eid] = true;
            $raw = [];
            foreach ($cols as $f => $c) {
                $v = $cell($r, $c);
                // An empty cell leaves the saved value as it is; so does one holding only spaces (a no-break space too): the file never clears.
                if ($c !== null && preg_replace('/[\p{Z}\s]+/u', '', $v) !== '') $raw[$f] = $v;
            }
            $clean = EmployeeDetailsRules::clean($raw, $today, true);
            if ($clean['error'] !== null) {
                $bad = ['too_long' => 'name_ar', 'bad_gender' => 'gender', 'bad_date' => 'hire_date', 'future_date' => 'hire_date'][$clean['error']];
                $out['problems'][] = ['row' => $line, 'code' => $clean['error'], 'value' => (string) ($raw[$bad] ?? ''), 'employee' => $eid];
                continue;
            }
            $diff = EmployeeDetailsRules::diff($people[$eid], $clean['values']);
            if (!$diff) { $out['unchanged']++; continue; }
            foreach ($diff as $d) $out['changes'][] = ['row' => $line, 'employee' => $eid] + $d;
        }
        return $out;
    }
}
