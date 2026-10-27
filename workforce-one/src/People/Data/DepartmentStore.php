<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** The departments table as the other modules read it (3.31.133). Same SQL as before. */
interface DepartmentStore
{
    public function tableExists(): bool;

    /** The active manager (an active employee) of an employee's active department, or null. */
    public function activeManagerOf(int $employeeId): ?object;
}
