<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/**
 * The people module's request-level memos (3.31.133), held in one object instead of two statics on the plugin: the shift each
 * employee is on (employees.default_shift_id) and the list of active employees. They live for one PHP request and are cleared
 * whenever an employee is written (docs/tasks/people-store.md §4). The plugin keeps one instance in a static, so it is shared by
 * every plugin object and every site, as the statics it replaces were.
 */
final class PeopleMemo
{
    /** @var array<int,int> employees.default_shift_id by employee */
    public $shift = [];
    /** @var array|null the active employees by name (emps()); null = not read yet, or the read failed */
    public $active = null;

    public function forgetShift(int $employeeId): void
    {
        unset($this->shift[$employeeId]);
    }

    /** What invalidate_employee_runtime_cache() cleared of People's: that employee's shift (when given) and the active list. */
    public function forget(int $employeeId = 0): void
    {
        if ($employeeId > 0) unset($this->shift[$employeeId]);
        $this->active = null;
    }
}
