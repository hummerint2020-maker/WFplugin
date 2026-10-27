<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * The approval settings the engine reads (3.31.128). Each is read when the engine needs it, not before: an option that is not
 * autoloaded costs a query on its first read, and the SQL order is part of what the golden trace compares.
 */
interface ApprovalPolicy
{
    /** What happens when a level's approver is the requester: 'allow' | 'next' | 'admins'. */
    public function selfRule(string $workflowKey): string;

    public function rejectNoteRequired(string $workflowKey): bool;

    /** @return array{hr_user:int,finance_user:int,day_start:string,day_end:string} */
    public function settings(): array;

    /** @return array<int,int> the days of the week the company works (0 = Sunday) */
    public function workingDays(): array;
}
