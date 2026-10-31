<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * The correction pages' reads (3.31.140): a read model, free to join the requests with the employees, departments, users and the approval levels
 * (docs/architecture/modules.json "reads"). It writes nothing. No method computes a time.
 */
interface CorrectionQuery
{
    /** One request with its employee's name, user and department; null when there is none. */
    public function get(int $id): ?object;

    /** @return object[] waiting requests whose open approval level names this user (100 at most), oldest first */
    public function toDecideByApprover(int $userId): array;

    /** @return object[] pending requests of every employee (200 at most), oldest first */
    public function toDecideOpen(): array;

    /** @return object[] requests waiting for HR (200 at most), oldest first */
    public function toDecideHr(): array;

    /** @return object[] active departments by name (id, name) */
    public function departments(): array;

    /** @return object[] status and number of requests, for the filter (any status) */
    public function listCounts(CorrectionListFilter $f): array;

    public function listTotal(CorrectionListFilter $f): int;

    /** @return object[] one page of the list, newest request first, with the employee, department, decider and manager names */
    public function listRows(CorrectionListFilter $f, int $perPage, int $offset): array;

    /** @return object[] employee, type, status and department of every request for days between the two dates (department 0 = all) */
    public function report(string $from, string $to, int $department): array;

    /** @return object[] every request for days between the two dates with the employee, department and decider names (department 0 = all) */
    public function export(string $from, string $to, int $department): array;
}
