<?php
namespace WorkforceOne\Approvals;

use WorkforceOne\Approvals\Data\ApprovalRequestStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;

if (!defined('ABSPATH')) exit;

/**
 * Approval deadlines (3.31.100, owner decisions 2026-10-14, docs/tasks/approvals.md; moved 3.31.128): when nobody decides a
 * level, its approver is reminded after so many working hours, and the request moves up after more: to the approver's own
 * manager, or to the administrators when they have none. The first approver is told; the step keeps its whole history
 * (escalations, escalated_from_wp_user_id, the Audit Log). Runs hourly from a cron: no user is signed in, so nothing here asks.
 */
final class DeadlineService
{
    /** @var ApprovalRequestStore */
    private $requests;
    /** @var ApprovalPolicy */
    private $policy;
    /** @var ApprovalDirectory */
    private $directory;
    /** @var DeadlineNotifier */
    private $notifier;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(ApprovalRequestStore $requests, ApprovalPolicy $policy, ApprovalDirectory $directory, DeadlineNotifier $notifier, AuditLog $audit, Clock $clock)
    {
        $this->requests = $requests;
        $this->policy = $policy;
        $this->directory = $directory;
        $this->notifier = $notifier;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /**
     * Reminds and moves up the levels past their time. $keys: the workflows that have a deadline, key => {remind, escalate}.
     * @param array<string,array{remind:int,escalate:int}> $keys
     * @return array{reminded:int,moved:int}
     */
    public function tick(array $keys, ?string $now): array
    {
        $done = ['reminded' => 0, 'moved' => 0];
        $now = is_string($now) && $now !== '' ? $now : $this->clock->now();
        // Levels waiting since before 3.31.100 have no start: their wait counts from now.
        $this->requests->backfillPendingSince($now);
        $s = $this->policy->settings();
        $days = $this->policy->workingDays();
        foreach ($keys as $key => $d) {
            foreach ($this->requests->overdueCandidates((string) $key) as $step) {
                $hours = WorkingHours::between((string) $step->pending_since, $now, $days, $s['day_start'], $s['day_end']);
                $withAdmins = !(int) $step->approver_wp_user_id;
                if ($d['escalate'] && $hours >= $d['escalate'] && !$withAdmins) {
                    if ($this->escalate($step, (string) $key, $now)) $done['moved']++;
                } elseif ($d['remind'] && $hours >= $d['remind'] && !$step->reminded_at && !$withAdmins) {
                    if ($this->requests->markReminded((int) $step->id, $now) !== 1) continue;
                    $this->notifier->reminded($step, (int) $d['remind']);
                    $done['reminded']++;
                }
            }
        }
        return $done;
    }

    /** Moves one waiting level up: to its approver's manager (not the requester), or to the administrators. @return bool whether it moved */
    private function escalate(object $step, string $workflowKey, string $now): bool
    {
        $fromUid = (int) $step->approver_wp_user_id;
        // A level with a substitute moves up from the manager it waits for (3.31.107), not from the substitute.
        $base = (int) ($step->for_employee_id ?? 0) ?: (int) $step->approver_employee_id;
        $up = $base ? $this->directory->supervisorOf($base) : null;
        $requesterUid = $this->directory->userId((int) $step->requester_employee_id);
        if ($up && ((int) $up->id === (int) $step->requester_employee_id || !(int) $up->wp_user_id || (int) $up->wp_user_id === $requesterUid || (int) $up->wp_user_id === $fromUid)) $up = null;
        $ok = $this->requests->escalate((int) $step->id, $up ? [(int) $up->id, (int) $up->wp_user_id] : null, $fromUid, $now);
        if ($ok !== 1) return false;
        $who = $this->directory->name((int) $step->requester_employee_id);
        $this->audit->record('approval_escalated', 'approval_request', (int) $step->approval_request_id,
            $workflowKey . ' · level ' . (int) $step->step_order . ' · user #' . $fromUid . ' → ' . ($up ? 'employee #' . (int) $up->id : 'administrators'));
        $this->notifier->movedUp($fromUid, $step, $up, $who);
        return true;
    }
}
