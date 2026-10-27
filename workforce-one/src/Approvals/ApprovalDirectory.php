<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * The people the approvals engine needs to know about (3.31.128): who a requester is and who their managers are. The People
 * module owns this data; the engine never reads its tables.
 */
interface ApprovalDirectory
{
    /** The WordPress user of an employee (0 = none). */
    public function userId(int $employeeId): int;

    /** An employee's row (only an active one when $activeOnly), or null. */
    public function employee(int $employeeId, bool $activeOnly): ?object;

    /** The employee linked to a WordPress user, or null. */
    public function employeeByUser(int $userId): ?object;

    public function name(int $employeeId): string;

    public function userExists(int $userId): bool;

    /** Whether anyone reports to this employee (they are a manager). */
    public function hasReports(int $employeeId): bool;

    /** The employee's active direct manager, or null. */
    public function supervisorOf(int $employeeId): ?object;

    public function teamManager(int $employeeId): ?object;

    public function departmentManager(int $employeeId): ?object;
}
