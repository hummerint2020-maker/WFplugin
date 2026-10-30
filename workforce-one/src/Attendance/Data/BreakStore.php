<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** The break sessions (ews_break_sessions; docs/tasks/attendance-store.md): the reads of 3.31.136. */
interface BreakStore
{
    /** @return object[] the employee's break sessions of one work day, oldest first */
    public function sessionsOn(int $employeeId, string $day): array;

    /** The employee's newest open break of one work day (null when none). */
    public function openOn(int $employeeId, string $day): ?object;
}
