<?php
namespace WorkforceOne\Approvals;

use WorkforceOne\Approvals\Data\ApprovalRequestStore;
use WorkforceOne\Approvals\Data\SubstituteStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;

if (!defined('ABSPATH')) exit;

/**
 * Substitutes (3.31.128; the rules are in Substitutes): a manager away, someone deciding for them. It routes a level to the
 * substitute when a request is started or moves on, keeps the substitutes up to date (start, end, follow a leave) and checks
 * and saves a new one. It changes levels only through the request Store. Every read and write is in the order the trait had.
 */
final class SubstituteService implements SubstituteRouting
{
    /** @var SubstituteStore */
    private $substitutes;
    /** @var ApprovalRequestStore */
    private $requests;
    /** @var ApprovalDirectory */
    private $directory;
    /** @var SubstitutePolicy */
    private $policy;
    /** @var LeaveFacts */
    private $leave;
    /** @var SubstituteNotifier */
    private $notifier;
    /** @var AfterRequest */
    private $after;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(
        SubstituteStore $substitutes,
        ApprovalRequestStore $requests,
        ApprovalDirectory $directory,
        SubstitutePorts $ports,
        AfterRequest $after,
        AuditLog $audit,
        Clock $clock
    ) {
        $this->substitutes = $substitutes;
        $this->requests = $requests;
        $this->directory = $directory;
        $this->policy = $ports->policy;
        $this->leave = $ports->leave;
        $this->notifier = $ports->notifier;
        $this->after = $after;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /** The substitute deciding for this manager now, or null (scheduled ones whose time came count before the sync marks them). */
    private function activeFor(int $managerEmployeeId, ?string $now = null): ?object
    {
        if (!$this->policy->enabled() || !$managerEmployeeId) return null;
        $now = $now ?: $this->clock->now();
        return $this->substitutes->activeFor($managerEmployeeId, $now);
    }

    public function route(int $managerEmployeeId, int $requesterEmployeeId): ?array
    {
        $row = $this->activeFor($managerEmployeeId);
        if (!$row || (int) $row->substitute_employee_id === $requesterEmployeeId) return null;
        $sub = $this->directory->employee((int) $row->substitute_employee_id, true);
        if (!$sub || !(int) $sub->wp_user_id) return null;
        return ['employee' => $sub, 'row' => $row];
    }

    public function managerMayDecide(object $step, int $userId): bool
    {
        if (!$userId || empty($step->for_employee_id) || !$this->policy->settings()['manager_decides']) return false;
        return $this->directory->userId((int) $step->for_employee_id) === $userId;
    }

    public function afterDecision(object $step, int $userId): void
    {
        if (!empty($step->substitute_id) && (int) $step->approver_wp_user_id === $userId) {
            $this->substitutes->incrementDecided((int) $step->substitute_id);
        }
        $this->after->syncSubstitutes();
    }

    /**
     * Brings every substitute up to date at $now: one linked to a leave follows it (approved: scheduled; rejected or cancelled:
     * cancelled); a scheduled one starts at its time, an active one ends at its time; levels now waiting for a manager who has an
     * active substitute go to the substitute.
     * @return array{started:int,ended:int,cancelled:int,moved:int}
     */
    public function sync(?string $now): array
    {
        $done = ['started' => 0, 'ended' => 0, 'cancelled' => 0, 'moved' => 0];
        if (!$this->policy->enabled()) return $done;
        $now = is_string($now) && $now !== '' ? $now : $this->clock->now();
        foreach ($this->substitutes->linkedToLeave() as $row) {
            $do = Substitutes::forLeave((string) $row->status, $this->leave->statusOf((int) $row->leave_request_id));
            if ($do === 'schedule' && $this->substitutes->transition((int) $row->id, 'pending', ['status' => 'scheduled']) === 1) {
                $row->status = 'scheduled';
                $this->notifier->picked($row);
            } elseif ($do === 'cancel' && $this->finish($row, 'cancelled', $now)) {
                $done['cancelled']++;
            }
        }
        foreach ($this->substitutes->current() as $row) {
            $do = Substitutes::transition((string) $row->status, (string) $row->starts_at, (string) $row->ends_at, $now);
            if ($do === 'start') {
                if ($this->begin($row, $now)) $done['started']++;
            } elseif ($do === 'end') {
                if ($this->finish($row, 'ended', $now)) $done['ended']++;
            } elseif ($do === 'expire') {
                $this->substitutes->transition((int) $row->id, 'scheduled', ['status' => 'ended', 'ended_at' => $now]);
            }
        }
        foreach ($this->substitutes->active() as $row) $done['moved'] += $this->takeWaiting($row);
        return $done;
    }

    /** The levels waiting for the manager (not yet "for" anyone) go to the substitute. @return int how many */
    private function takeWaiting(object $row): int
    {
        $sub = $this->directory->employee((int) $row->substitute_employee_id, true);
        if (!$sub || !(int) $sub->wp_user_id) return 0;
        return (int) $this->requests->assignWaitingToSubstitute((int) $sub->id, (int) $sub->wp_user_id, (int) $row->manager_employee_id, (int) $row->id);
    }

    private function begin(object $row, string $now): bool
    {
        if ($this->substitutes->transition((int) $row->id, 'scheduled', ['status' => 'active', 'started_at' => $now]) !== 1) return false;
        $moved = $this->takeWaiting($row);
        $manager = $this->directory->name((int) $row->manager_employee_id);
        $this->audit->record('substitute_started', 'approval_substitute', (int) $row->id, 'employee #' . (int) $row->substitute_employee_id . ' for #' . (int) $row->manager_employee_id . ' · ' . $moved . ' waiting moved');
        $this->notifier->started($row, $moved, $manager);
        return true;
    }

    /**
     * Ends (its time is over, or ended now) or cancels a substitute; what still waits goes back to the manager unless the setting
     * keeps it.
     */
    public function finish(object $row, string $status, string $now): bool
    {
        $was = (string) $row->status;
        if ($this->substitutes->transition((int) $row->id, $was, ['status' => $status, 'ended_at' => $now]) !== 1) return false;
        $this->audit->record('substitute_' . $status, 'approval_substitute', (int) $row->id, 'employee #' . (int) $row->substitute_employee_id . ' for #' . (int) $row->manager_employee_id);
        if ($was !== 'active') return true;
        $back = 0;
        $still = 0;
        $muid = $this->directory->userId((int) $row->manager_employee_id);
        if ($this->policy->settings()['on_return'] === 'back' && $muid) {
            $back = (int) $this->requests->returnPendingToManager((int) $row->manager_employee_id, $muid, (int) $row->id);
        } else {
            $still = $this->requests->countPendingForSubstitute((int) $row->id);
        }
        $decided = $this->substitutes->decided((int) $row->id);
        $sub = $this->directory->name((int) $row->substitute_employee_id);
        $this->notifier->ended($muid, $row, $decided, $back, $still, $sub);
        return true;
    }

    /** Whether $candidate may be a substitute for $manager under the setting. */
    private function eligible(?object $manager, ?object $candidate): bool
    {
        $who = $this->policy->settings()['who'];
        return Substitutes::eligible($who, [
            'active' => $candidate && (int) $candidate->active === 1 && (int) $candidate->wp_user_id > 0,
            'has_reports' => $candidate && $who === 'managers' && $this->directory->hasReports((int) $candidate->id),
            'same_department' => $candidate && $manager && (int) $manager->department_id > 0 && (int) $candidate->department_id === (int) $manager->department_id,
        ]);
    }

    /**
     * Why this substitute cannot be saved, or null.
     * @return string|null same, dates, past, who, away, overlap, manager, substitute
     */
    public function check(int $managerId, int $substituteId, string $starts, string $ends, int $ignoreId = 0): ?string
    {
        $manager = $this->directory->employee($managerId, true);
        $cand = $this->directory->employee($substituteId, true);
        $overlap = false;
        $away = false;
        if ($manager && $cand && $starts !== '' && $ends !== '' && $ends > $starts) {
            $overlap = $this->substitutes->overlapForManager((int) $manager->id, $ends, $starts, $ignoreId);
            // Away themselves: on leave on one of those days, or with a substitute of their own then.
            $away = $this->leave->awayBetween((int) $cand->id, substr($starts, 0, 10), substr(gmdate('Y-m-d H:i:s', strtotime($ends) - 1), 0, 10))
                || $this->substitutes->overlapForCandidate((int) $cand->id, $ends, $starts);
        }
        return Substitutes::check(['manager' => $manager ? (int) $manager->id : 0, 'substitute' => $cand ? (int) $cand->id : 0, 'starts' => $starts, 'ends' => $ends, 'now' => $this->clock->now(),
            'eligible' => $this->eligible($manager, $cand), 'away' => $away, 'overlap' => $overlap]);
    }

    /**
     * Saves a substitute row. $actorUserId: who created it (0 = nobody, as from a cron).
     * @return int|string the id, or a refusal code
     */
    public function create(int $managerId, int $substituteId, string $starts, string $ends, string $source, string $reason, int $leaveId, string $status, int $actorUserId)
    {
        $error = $this->check($managerId, $substituteId, $starts, $ends);
        if ($error !== null) return $error;
        $row = ['manager_employee_id' => $managerId, 'substitute_employee_id' => $substituteId, 'starts_at' => $starts, 'ends_at' => $ends, 'status' => $status, 'source' => $source,
            'reason' => mb_substr($reason, 0, 255), 'created_by' => $actorUserId, 'created_at' => $this->clock->now()];
        if ($leaveId) $row['leave_request_id'] = $leaveId;
        $id = $this->substitutes->insert($row);
        if ($id === false) return 'save';
        $this->audit->record('substitute_set', 'approval_substitute', $id, 'employee #' . $substituteId . ' for #' . $managerId . ' · ' . $starts . ' → ' . $ends . ' · ' . $source);
        if ($status === 'scheduled') {
            $saved = $this->substitutes->find($id);
            if ($saved) $this->notifier->picked($saved);
        }
        $this->sync(null);
        return $id;
    }

    /** A substitute by id (the controller checks the switch first). */
    public function find(int $id): ?object
    {
        return $this->substitutes->find($id);
    }

    /**
     * For My approvals: the substitutes this employee decides for now, each with how many levels wait for it; $present turns
     * (row, waiting) into what the page shows, called in the same order the old code read and presented them.
     * @param callable(object,int):mixed $present
     * @return array<int,mixed>
     */
    public function decidingFor(int $employeeId, callable $present): array
    {
        $out = [];
        foreach ($this->substitutes->forSubstitute($employeeId) as $r) {
            $n = $this->requests->countPendingForSubstitute((int) $r->id);
            $out[] = $present($r, $n);
        }
        return $out;
    }

    /** This manager's own substitutes that are not over, for My approvals. @return array<int,object> */
    public function ownOpen(int $employeeId, string $now): array
    {
        return $this->substitutes->ownOpen($employeeId, $now);
    }

    /** wp-admin → Substitutes: the first 200, active first. @return array<int,object> */
    public function adminRows(): array
    {
        return $this->substitutes->adminList();
    }
}
