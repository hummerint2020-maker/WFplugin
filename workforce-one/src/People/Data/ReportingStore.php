<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** The reporting lines (employee_relationships, joined with employees where the old SQL did) read by the other modules (3.31.133). Same SQL as before; raw wpdb values. */
interface ReportingStore
{
    /** Is the table there (SHOW TABLES LIKE)? */
    public function tableExists(): bool;

    /** The active employee related to $employeeId by $type (the newest line), or null. */
    public function relatedActive(int $employeeId, string $type): ?object;

    /** employee_id and related_employee_id of the active supervisors of these employees, newest line first (chunks of 500). @param list<int> $ids @return list<object> */
    public function supervisorRowsOf(array $ids): array;

    /** The manager an employee has now (0 = none), archived or not. */
    public function currentManagerId(int $employeeId): int;

    /** Every active supervisor line, newest first. @return list<object> */
    public function activeSupervisorRows(): array;

    /** Does an active employee report directly to this one? */
    public function hasActiveReport(int $employeeId): bool;
}
