<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * The Sign In / Out report's reads (3.31.139): a read model, free to join the time logs, the attempts and the employees (docs/architecture/modules.json "reads").
 * It writes nothing. No method computes a time.
 */
interface TimeReportQuery
{
    /** How many rows the filter selects (records and attempts together). */
    public function count(TimeReportFilter $f): int;

    /** @return object[] kind ('log' | 'attempt') and id of one page, newest first */
    public function pageKeys(TimeReportFilter $f, int $perPage, int $offset): array;

    /** @param int[] $ids @return array<int,object> the time-log rows (with the employee and the correction that replaced them) by id */
    public function logRows(array $ids): array;

    /** @param int[] $ids @return array<int,object> the attempts (with the employee) by id */
    public function attemptRows(array $ids): array;

    /** @return object[] the employees with most refusals in the period (six at most) */
    public function refusedBy(TimeReportFilter $f): array;

    public function refusedTotal(TimeReportFilter $f): int;

    /** @return object[] the employees the report's form may pick: the active ones in scope, and $includeId (0 = none) */
    public function employees(int $includeId, ?int $scope): array;

    /** @return object[] the CSV's rows: every record of the period in scope, with the employee and the correction that replaced it */
    public function csv(string $start, string $end, ?int $scope): array;
}
