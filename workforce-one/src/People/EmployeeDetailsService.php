<?php
namespace WorkforceOne\People;

use WorkforceOne\Employees\EmployeeDetailsRules;
use WorkforceOne\People\Data\PeopleStore;
use WorkforceOne\Platform\AuditLog;

if (!defined('ABSPATH')) exit;

/**
 * An employee's details for letters (HR letters HL2, docs/tasks/employee-details.md): the name in Arabic, gender and the hiring date, saved
 * by HR on the profile or for many employees from a previewed file. Every change is in the Audit Log. No SQL: the People store writes.
 */
final class EmployeeDetailsService
{
    /** @var PeopleStore */
    private $store;
    /** @var AuditLog */
    private $audit;
    /** @var PeoplePorts */
    private $ports;

    public function __construct(PeopleStore $store, AuditLog $audit, PeoplePorts $ports)
    {
        $this->store = $store;
        $this->audit = $audit;
        $this->ports = $ports;
    }

    /**
     * Saves what HR typed on the profile (the three fields; empty = not set).
     * @param array<string,mixed> $raw name_ar, gender, hire_date
     * @return string|null an error code (not_found, too_long, bad_gender, bad_date, future_date, save), or null when saved (or nothing changed)
     */
    public function save(int $id, array $raw): ?string
    {
        $row = $this->store->byId($id, false);
        if (!$row) return 'not_found';
        $clean = EmployeeDetailsRules::clean($raw, $this->ports->clock->today());
        if ($clean['error'] !== null) return $clean['error'];
        $diff = EmployeeDetailsRules::diff($row, $clean['values']);
        if (!$diff) return null;
        if ($this->store->setDetails($id, self::fields($diff), $this->ports->clock->now()) === false) return 'save';
        $this->ports->changed->changed($id, (int) ($row->wp_user_id ?? 0));
        $this->audit->record('employee_details', 'employee', $id, $row->name . ': ' . EmployeeDetailsRules::auditText($diff));
        return null;
    }

    /** Every employee for the file and the import, by id. @return array<int,object> */
    public function people(): array
    {
        $out = [];
        foreach ($this->store->detailsRows() as $r) $out[(int) $r->id] = $r;
        return $out;
    }

    /**
     * Saves a previewed file. Each employee is read again: a field is changed only while it still holds the value the preview showed (an
     * edit made since is kept, and counted as skipped). One write and one Audit Log row per employee, then one row for the file.
     * @param array<string,mixed> $plan DetailsImportPlan::build()'s plan
     * @return array{changed:int,employees:int,skipped:int}
     */
    public function apply(array $plan, string $file): array
    {
        $byEmployee = [];
        foreach ((array) $plan['changes'] as $c) $byEmployee[(int) $c['employee']][] = $c;
        $changed = 0;
        $employees = 0;
        $skipped = 0;
        foreach ($byEmployee as $id => $changes) {
            $row = $this->store->byId($id, false);
            if (!$row) { $skipped += count($changes); continue; }
            $diff = [];
            foreach ($changes as $c) {
                $now = isset($row->{$c['field']}) && (string) $row->{$c['field']} !== '' ? (string) $row->{$c['field']} : null;
                if ($now !== $c['from']) { $skipped++; continue; }
                $diff[] = ['field' => $c['field'], 'from' => $c['from'], 'to' => $c['to']];
            }
            if (!$diff) continue;
            if ($this->store->setDetails($id, self::fields($diff), $this->ports->clock->now()) === false) { $skipped += count($diff); continue; }
            $this->ports->changed->changed($id, (int) ($row->wp_user_id ?? 0));
            $this->audit->record('employee_details', 'employee', $id, $row->name . ': ' . EmployeeDetailsRules::auditText($diff) . ' · import');
            $changed += count($diff);
            $employees++;
        }
        $this->audit->record('employee_details_imported', 'settings', 0, $file . ': ' . $changed . ' change(s) for ' . $employees . ' employee(s), ' . $skipped . ' skipped');
        return ['changed' => $changed, 'employees' => $employees, 'skipped' => $skipped];
    }

    /** @param list<array{field:string,from:?string,to:?string}> $diff @return array<string,?string> */
    private static function fields(array $diff): array
    {
        $f = [];
        foreach ($diff as $d) $f[$d['field']] = $d['to'];
        return $f;
    }
}
