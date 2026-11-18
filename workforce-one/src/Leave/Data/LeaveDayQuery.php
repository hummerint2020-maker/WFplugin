<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/**
 * The type of each day of approved leave (3.31.152, docs/tasks/leave-type-attendance.md): the days an approval set to
 * Vacation (ews_leave_schedule_snapshots) of the requests still Approved, with their leave type's name. Reads only.
 */
interface LeaveDayQuery
{
    /**
     * @param int[] $employeeIds
     * @return array<int,array<string,string>> employee id => Y-m-d => the type's name ('' when the type is gone or was never set)
     */
    public function typesByDay(array $employeeIds, string $from, string $to): array;
}
