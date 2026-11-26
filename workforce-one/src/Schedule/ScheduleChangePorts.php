<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * What ScheduleChangeService reaches in the rest of the plugin (3.31.167, docs/tasks/schedule-change-request.md §2.4): the week
 * lock, schedule types, People, leave, payroll months, branches, the approvals engine and the notifications. Built once, in
 * includes/trait-services.php (schedule_change_ports()). All words and links stay there: the service passes codes and values.
 */
interface ScheduleChangePorts
{
    /** The switch, the week lock and My approvals are on. */
    public function enabled(): bool;

    /** @return array<string,mixed> ScheduleChangeSettings::config() */
    public function settings(): array;

    /** Today, site time (Y-m-d). */
    public function today(): string;

    /** The day is in a final week and the signed-in person may not edit it directly (week_lock_refusal() === 'locked'). */
    public function weekFinalFor(string $date): bool;

    /** The type's attendance rule ('absent' for Absent); null when it is not an active type. */
    public function statusRule(string $status): ?string;

    /** The signed-in manager may manage this person's attendance (active, attendance on, in their department). */
    public function inScope(int $employeeId): bool;

    /** The person, active with attendance on (id, name, wp_user_id), or null: checked again when the change is applied. */
    public function activeEmployee(int $employeeId): ?object;

    /** A pending or approved leave covers the day. */
    public function leaveOn(int $employeeId, string $date): bool;

    /** Attendance is recorded that day. */
    public function hasAttendance(int $employeeId, string $date): bool;

    /** @return string[] the payroll months (Y-m) closed between the two days */
    public function closedMonths(string $from, string $to): array;

    /** Branches are planned "By the schedule". */
    public function branchMode(): bool;

    /** The branch (0: their main branch) is one of the person's, and one the requester may plan when $requester is true. */
    public function branchAllowed(int $employeeId, int $locationId, string $status, bool $requester): bool;

    /** The workflow is set (the first request sets it to one level, HR). Its own transaction: called before ours. */
    public function workflowReady(): bool;

    /** The approval starts, waiting for its first level (inside the caller's transaction). */
    public function startApproval(int $requestId, int $requesterEmployeeId): bool;

    /** The engine's request for this schedule change, or null. */
    public function findApproval(int $requestId): ?object;

    /** @param int|null $posted the level the page showed */
    public function levelMoved(int $approvalRequestId, $posted): bool;

    public function canAct(int $approvalRequestId, bool $override): bool;

    /** @param int|null $posted @return string '' or the engine's error code (note, moved, approval…) */
    public function act(int $approvalRequestId, string $decision, string $note, $posted, bool $override): string;

    /** The open levels closed (withdrawn, or closed automatically when $closed). @return mixed true, false (database error) or 'moved' */
    public function closeApproval(int $requestId, bool $closed);

    /** After COMMIT: the days of a final week this changed, recorded once. @param string[] $days */
    public function weekLockRecord(int $employeeId, array $days, string $ref): void;

    /** After COMMIT: the level's approver is told it waits for them. */
    public function notifyApprover(object $request): void;

    /**
     * After COMMIT: the requester is told. @param string $event approved | partial | rejected | closed
     * @param array<string,mixed> $detail note (rejected), why (closed), skipped (partial)
     */
    public function notifyRequester(string $event, object $request, array $detail): void;

    /** After COMMIT: one message per employee with their applied days. @param array<string,string> $days Y-m-d => new status */
    public function notifyApplied(int $employeeId, array $days, int $requestId): void;

    /** After COMMIT (setting): the employee is told a change of their days was asked. @param array<string,string> $days */
    public function notifyRequested(int $employeeId, array $days, int $requestId): void;

    /** The person's name for the Audit Log. */
    public function employeeName(int $employeeId): string;

    /** The user doing it (0 for a job). */
    public function actorId(): int;
}
