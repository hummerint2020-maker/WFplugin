<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** What the approvals need to know about leaves (the Leave module owns them) (3.31.128). */
interface LeaveFacts
{
    /** A leave request's status ('' when there is none). */
    public function statusOf(int $leaveRequestId): string;

    /** Whether the employee is on leave on a day between the two dates. */
    public function awayBetween(int $employeeId, string $from, string $to): bool;
}
