<?php
namespace WorkforceOne\People;

use WorkforceOne\Organization\MoveRules;
use WorkforceOne\Organization\OrgTree;
use WorkforceOne\People\Data\OrgMoveStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/**
 * Moving people in the organization chart (3.31.134, moved from trait-org-moves.php): checking a move, doing it (the new manager, the waiting approval
 * steps and the history row in one transaction), keeping a dated one for its day, and the daily run with its claims. The rules are MoveRules; the
 * reads are the People directories; no SQL here. A move is claimed with conditional UPDATEs (status and claim time), so two visits at once do it once.
 */
final class OrgMoveService
{
    /** @var OrgMoveStore */
    private $moves;
    /** @var ReportingService */
    private $reporting;
    /** @var PeopleDirectory */
    private $people;
    /** @var OrgDirectory */
    private $org;
    /** @var Transaction */
    private $tx;
    /** @var AuditLog */
    private $audit;
    /** @var PeoplePorts */
    private $ports;

    public function __construct(OrgMoveStore $moves, ReportingService $reporting, PeopleDirectory $people, OrgDirectory $org, Transaction $tx, AuditLog $audit, PeoplePorts $ports)
    {
        $this->moves = $moves;
        $this->reporting = $reporting;
        $this->people = $people;
        $this->org = $org;
        $this->tx = $tx;
        $this->audit = $audit;
        $this->ports = $ports;
    }

    /**
     * Checks a move. $reasonRequired: a move from the chart needs a reason. The facts are read in the order of the old code.
     *
     * @return string|null error code
     */
    public function check(int $employeeId, int $to, string $date, string $reason, bool $reasonRequired, int $ignoreScheduledId = 0, bool $checkLoop = true): ?string
    {
        $current = $this->org->managerOf($employeeId);
        $facts = ['employee_ok' => $employeeId > 0 && (bool) $this->people->activeId($employeeId), 'employee_id' => $employeeId, 'manager_id' => $to,
            'current_manager' => $current && $this->people->activeId($current) ? $current : 0, 'date' => $date, 'today' => $this->ports->clock->today(),
            'reason' => $reason, 'reason_required' => $reasonRequired];
        if ($to) $facts['manager_ok'] = (bool) $this->people->activeId($to);
        $facts['scheduled_exists'] = (bool) $this->moves->openIdFor($employeeId, $ignoreScheduledId);
        if ($to && $checkLoop) $facts['would_loop'] = (new OrgTree($this->org->managerIds()))->wouldLoop($employeeId, $to);
        return MoveRules::check($facts);
    }

    /**
     * Does a move now: the new manager, the waiting requests, the history row and the Audit Log. All or nothing.
     * $claim: the claim time of a scheduled move ($scheduledId) being done by the daily run.
     *
     * @return array{ok:bool,requests:int,id:int}
     */
    public function apply(int $employeeId, int $to, string $date, string $reason, string $source, int $scheduledId = 0, ?string $claim = null): array
    {
        $from = $this->org->managerOf($employeeId);
        $row = ['employee_id' => $employeeId, 'from_manager_id' => $from, 'to_manager_id' => $to, 'effective_date' => $date, 'reason' => $reason, 'status' => 'done', 'source' => $source];
        $r = $this->supervisorChange($employeeId, $from, $to, $row, $scheduledId ? ['id' => $scheduledId, 'at' => (string) $claim] : null);
        if (!$r['ok']) return $r;
        $this->ports->changed->changed($employeeId, (int) $this->people->userId($employeeId, false));
        $this->audit->record('org_move', 'employee', $employeeId, $this->summary($employeeId, $from, $to, $date, $reason) . ($r['requests'] ? ' · ' . $r['requests'] . ' waiting approval step(s) moved' : '') . ' · ' . $source);
        return $r;
    }

    /**
     * The supervisor, the waiting approval steps and the history row, in one transaction; the new manager is told after it.
     * A scheduled move's row is written only while this run still holds its claim.
     *
     * @param array<string,mixed> $row
     * @param array{id:int,at:string}|null $claim
     * @return array{ok:bool,requests:int,id:int}
     */
    public function supervisorChange(int $employeeId, int $from, int $to, array $row, ?array $claim = null): array
    {
        $now = $this->ports->clock->now();
        $fail = ['ok' => false, 'requests' => 0, 'id' => 0];
        if (!$this->tx->begin()) return $fail;
        if (!$this->reporting->setSupervisor($employeeId, $to)) { $this->tx->rollback(); return $fail; }
        $pending = 0;
        $moved = $this->ports->approvals->reassignSupervisor($employeeId, $from, $to, $pending);
        if ($moved === false) { $this->tx->rollback(); return $fail; }
        $row['requests_moved'] = $moved;
        $row['applied_at'] = $now;
        if ($claim) {
            if ($this->moves->finishClaimed((int) $claim['id'], (string) $claim['at'], $row) !== 1) { $this->tx->rollback(); return $fail; }   // a database error, or the claim was taken back (an interrupted run)
            $id = (int) $claim['id'];
        } else {
            if ($this->moves->insert($row + ['created_by' => $this->ports->actor->userId(), 'created_at' => $now]) === false) { $this->tx->rollback(); return $fail; }
            $id = $this->moves->insertId();
        }
        if (!$this->tx->commit()) { $this->tx->rollback(); return $fail; }
        $this->ports->approvals->tellNewManager($employeeId, $to, $pending);
        return ['ok' => true, 'requests' => $moved, 'id' => $id];
    }

    /** The supervisor alone (the chart off, or unchanged), both of its writes together. */
    public function setSupervisorOnly(int $employeeId, int $supervisorId): bool
    {
        if (!$this->tx->begin()) return false;
        if (!$this->reporting->setSupervisor($employeeId, $supervisorId)) { $this->tx->rollback(); return false; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return false; }
        return true;
    }

    /** A move dated after today: kept and done on its day. The insert is not checked (as before): the id is whatever the database says. */
    public function schedule(int $employeeId, int $to, string $date, string $reason, string $source): int
    {
        $from = $this->org->managerOf($employeeId);
        $this->moves->insert(['employee_id' => $employeeId, 'from_manager_id' => $from, 'to_manager_id' => $to, 'effective_date' => $date,
            'reason' => $reason, 'status' => 'scheduled', 'source' => $source, 'created_by' => $this->ports->actor->userId(), 'created_at' => $this->ports->clock->now()]);
        $id = $this->moves->insertId();
        $this->audit->record('org_move_scheduled', 'employee', $employeeId, $this->summary($employeeId, $from, $to, $date, $reason) . ' · ' . $source);
        return $id;
    }

    public function summary(int $employeeId, int $from, int $to, string $date, string $reason): string
    {
        $n = function (int $id): string { return $id ? (string) $this->people->name($id) : 'no manager'; };
        return $n($employeeId) . ': ' . $n($from) . ' → ' . $n($to) . ' from ' . $date . ($reason !== '' ? ' · "' . $reason . '"' : '');
    }

    /**
     * The moves whose day has come (the daily job, and the first visit each hour). A move that can no longer be done (the employee or the manager
     * archived, or it would now make a loop) is marked failed with the reason, and shown under "Needs attention".
     */
    public function applyDue(): int
    {
        if (!$this->moves->tableExists()) return 0;
        $today = $this->ports->clock->today();
        $done = 0;
        $this->recover();
        $due = [];
        foreach ($this->moves->dueRows($today) as $m) {
            // Claimed first, so two visits at the same time do not both do it; the claim time tells an interrupted run.
            $m->claim = $this->ports->clock->now();
            if ($this->moves->claim((int) $m->id, (string) $m->claim) === 1) $due[] = $m;
        }
        // In passes: a move that would make a loop now may be fine once another move of the same day is done.
        while ($due) {
            $later = [];
            foreach ($due as $m) {
                $code = $this->check((int) $m->employee_id, (int) $m->to_manager_id, (string) $m->effective_date, (string) $m->reason, false, (int) $m->id);
                if ($code === 'loop') { $later[] = $m; continue; }
                if ($code === 'same') { $this->moves->markAlreadyDone((int) $m->id, (string) $m->claim, $this->ports->clock->now()); continue; }   // only while this run holds the claim
                if ($code !== null && $code !== 'date') { $this->failed($m, $code); continue; }
                $r = $this->apply((int) $m->employee_id, (int) $m->to_manager_id, (string) $m->effective_date, (string) $m->reason, (string) $m->source, (int) $m->id, (string) $m->claim);
                // Not done (nothing was changed): back to scheduled for the next run.
                if (!$r['ok']) $this->moves->release((int) $m->id, (string) $m->claim);
                else $done++;
            }
            if (count($later) === count($due)) { foreach ($later as $m) $this->failed($m, 'loop'); break; }
            $due = $later;
        }
        return $done;
    }

    private function failed(object $m, string $code): void
    {
        if ($this->moves->fail((int) $m->id, isset($m->claim) ? (string) $m->claim : null, $code, $this->ports->clock->now()) !== 1) return;
        $this->audit->record('org_move_failed', 'employee', (int) $m->employee_id, 'Scheduled move #' . (int) $m->id . ' not done: ' . $code);
    }

    /**
     * Moves left "applying" by a run that died (MoveRules::recover): done again once, or failed ("interrupted") for HR to cancel or redo when it
     * was already done again, or claimed before there was a claim time.
     */
    private function recover(): void
    {
        $now = $this->ports->clock->timestamp();
        foreach ($this->moves->applyingRows() as $m) {
            $at = $m->applied_at !== null && (string) $m->applied_at !== '' ? (int) strtotime((string) $m->applied_at) : null;
            $do = MoveRules::recover($at, (string) $m->note, $now);
            if ($do === 'wait') continue;
            $appliedAt = $at === null ? null : (string) $m->applied_at;
            if ($do === 'resume') {
                if ($this->moves->resume((int) $m->id, $appliedAt) === 1)
                    $this->audit->record('org_move_resumed', 'employee', (int) $m->employee_id, 'Scheduled move #' . (int) $m->id . ' was interrupted: it is done again');
            } elseif ($this->moves->failInterrupted((int) $m->id, $appliedAt, $this->ports->clock->now()) === 1) {
                $this->audit->record('org_move_failed', 'employee', (int) $m->employee_id, 'Scheduled move #' . (int) $m->id . ' not done: interrupted');
            }
        }
    }

    /**
     * Cancels a scheduled or failed move. Nothing is checked about the result (as before): an unknown or finished move is reported as cancelled too.
     *
     * @return int the employee of the move (0: no such move)
     */
    public function cancel(int $id): int
    {
        $m = $this->moves->byId($id);
        if ($m && in_array($m->status, ['scheduled', 'failed'], true)) {
            $this->moves->cancel($id, (string) $m->status, $this->ports->clock->now());
            $this->audit->record('org_move_cancelled', 'employee', (int) $m->employee_id, ($m->status === 'failed' ? 'Failed' : 'Scheduled') . ' move #' . $id . ' cancelled: '
                . $this->summary((int) $m->employee_id, (int) $m->from_manager_id, (int) $m->to_manager_id, (string) $m->effective_date, (string) $m->reason));
        }
        return $m ? (int) $m->employee_id : 0;
    }

    /**
     * The moves for the chart: the last ones per person (newest first), the scheduled ones and the failed ones.
     *
     * @param callable(int):string $cancelNonce the nonce for cancelling a move
     * @return array{history:array<int,list<array<string,mixed>>>,scheduled:list<array<string,mixed>>,failed:list<array<string,mixed>>}
     */
    public function forChart(callable $cancelNonce): array
    {
        $out = ['history' => [], 'scheduled' => [], 'failed' => []];
        if (!$this->moves->tableExists()) return $out;
        $row = function ($m) use ($cancelNonce) {
            return ['id' => (int) $m->id, 'e' => (int) $m->employee_id, 'f' => (int) $m->from_manager_id, 't' => (int) $m->to_manager_id, 'd' => (string) $m->effective_date,
                'r' => (string) $m->reason, 's' => (string) $m->source, 'q' => (int) $m->requests_moved, 'n' => (string) $m->note,
                'nonce' => in_array($m->status, ['scheduled', 'failed'], true) ? $cancelNonce((int) $m->id) : ''];
        };
        foreach ($this->moves->doneRows() as $m) {
            $e = (int) $m->employee_id;
            if (count($out['history'][$e] ?? []) < 5) $out['history'][$e][] = $row($m);
        }
        foreach ($this->moves->openRows() as $m) $out['scheduled'][] = $row($m);
        foreach ($this->moves->failedRows() as $m) $out['failed'][] = $row($m);
        return $out;
    }
}
