<?php
namespace WorkforceOne\Attendance;

use WorkforceOne\Attendance\Data\CorrectionQuery;
use WorkforceOne\Attendance\Data\CorrectionStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/**
 * Attendance correction requests (3.31.143, docs/tasks/attendance-corrections.md §11): a new request (the employee's, or HR's own correction,
 * applied at once), the employee's withdrawal, and the end-of-day reminder. A move of cx_create, cx_withdraw, corrections_tick and
 * corrections_remind: the same reads and writes in the same order, the same transactions, audits and notifications, every quirk kept
 * (CX-e, CX-f, CX-g, CX-o, CX-q, CX-r, CX-s, CX-z, CX-aa, CX-ab). No SQL, no clock but the Clock port, no current user but the one given.
 */
final class CorrectionRequests
{
    /** @var CorrectionStore */
    private $store;
    /** @var CorrectionQuery */
    private $query;
    /** @var CorrectionPorts */
    private $ports;
    /** @var Transaction */
    private $tx;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(CorrectionStore $store, CorrectionQuery $query, CorrectionPorts $ports, Transaction $tx, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->query = $query;
        $this->ports = $ports;
        $this->tx = $tx;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /**
     * Creates a request (source employee) or HR's direct correction (source hr, applied at once).
     * @param mixed $emp the employee (an object), or null
     * @param array<string,mixed> $in the request's fields (cx_input)
     * @param mixed $photo an entry of $_FILES, or null
     * @return array{id?:int,status?:string,error?:string}
     */
    public function create($emp, array $in, string $source, $photo, int $userId): array
    {
        $site = $this->ports->site;
        $files = $this->ports->files;
        if (!$site->enabled()) return ['error' => 'disabled'];
        if (!$emp) return ['error' => 'employee'];
        $site->ensureSchema();
        $facts = $site->facts($emp, $in, $source, $files->hasUpload($photo));
        $error = CorrectionRules::check($facts);
        if ($error !== '') return ['error' => $error];
        if ($source === 'employee' && ($site->settings()['photo'][$facts['type']] ?? 'off') === 'off') $photo = null;
        [$key, $name, $perr] = $files->save($photo);
        if ($perr !== '') return ['error' => $perr];
        $drop = function () use ($key, $files) { if ($key) $files->delete((string) $key); };   // a request that does not go through leaves no photo behind
        $plan = CorrectionRules::plan($facts);
        $times = CorrectionRules::times($facts);
        $row = CorrectionRules::requestRow((int) $emp->id, $facts, $plan, $times, $key, $name, $source, $userId, $this->clock->now());
        if ($source === 'hr') {
            $id = $this->store->insert($row);
            if (!$id) { $drop(); return ['error' => 'save']; }
            $res = $this->ports->decider->finalizeDirect($id, (string) $facts['reason']);
            if ($res !== 'approved') { $this->store->delete($id); $drop(); return ['error' => $res]; }
            $this->audit->record('correction_direct', 'employee', (int) $emp->id, $emp->name . ' ' . $facts['date'] . ': ' . $site->changeText($this->query->get($id), 'en') . ' — ' . $facts['reason']);
            return ['id' => $id, 'status' => 'approved'];
        }
        $id = $this->store->insert($row);
        if (!$id) { $drop(); return ['error' => 'save']; }
        $this->audit->record('correction_request', 'employee', (int) $emp->id, $emp->name . ' ' . $facts['date'] . ': ' . $site->changeText($this->query->get($id), 'en'));
        $approvals = $this->ports->approvals;
        $mode = $approvals->mode();
        if ($mode === 'NONE' || $approvals->isLevels($mode)) {
            if (!$approvals->start($id, (int) $emp->id)) { $this->store->delete($id); $drop(); return ['error' => 'approval']; }
            $ar = $approvals->find($id);
            if ($ar && $ar->status === 'APPROVED') { $r = $this->ports->decider->afterManager($id); return ['id' => $id, 'status' => $r]; }   // CX-z: an error comes back as the status
            $step = $ar ? $approvals->currentStep((int) $ar->id) : null;
            if ($step && $step->approver_wp_user_id) $this->ports->notices->decider((int) $step->approver_wp_user_id, $this->query->get($id));
            return ['id' => $id, 'status' => 'pending'];
        }
        $c = $this->query->get($id);
        foreach ($site->timeManagerIds() as $m) {
            if ((int) $m === $userId) continue;
            $this->ports->notices->decider((int) $m, $c);
        }
        return ['id' => $id, 'status' => 'pending'];
    }

    /**
     * The employee withdraws a request still waiting (My requests): the approval first, then the request, in one transaction (3.31.132).
     * @return true|string true, or done (not theirs, or no longer waiting) | save
     */
    public function withdraw(int $id, int $employeeId, int $userId)
    {
        if (!$this->store->ownOpen($id, $employeeId)) return 'done';
        if (!$this->tx->begin()) return 'save';
        // Waiting for HR, its approval request is already APPROVED by the manager levels: it stays so (3.31.117).
        $engine = $this->ports->approvals->closeOpen($id);
        if ($engine !== true) { $this->tx->rollback(); return $engine === 'moved' ? 'done' : 'save'; }
        $ok = $this->store->withdraw($id, $employeeId, $userId, $this->clock->now());
        if ($ok === false) { $this->tx->rollback(); return 'save'; }
        if ($ok !== 1) { $this->tx->rollback(); return 'done'; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
        $this->audit->record('correction_withdrawn', 'employee', $employeeId, 'Correction #' . $id . ' withdrawn by the employee');
        return true;
    }

    /** WP-Cron every 5 minutes: once a day at the set time, a reminder to everyone still signed in (the claim is a read then a write: CX-o). */
    public function tick(): void
    {
        $site = $this->ports->site;
        if (!$site->enabled()) return;
        $s = $site->settings();
        if (empty($s['reminder']) || $site->hm() < $s['reminder_time']) return;
        $today = $site->today();
        if ($site->remindedOn() === $today) return;
        $site->markReminded($today);
        $site->ensureSchemaOnce();
        $this->remind($today);
    }

    /** "You did not sign out today" to each candidate of the day, unless today's hours are overnight (CX-aa). @return int people reminded */
    public function remind(string $date): int
    {
        $n = 0;
        foreach ($this->query->remindCandidates($date) as $r) {
            if (!(int) $r->wp_user_id) continue;
            $h = $this->ports->site->workingHoursToday((int) $r->id);
            if (!empty($h['overnight']) && $h['start'] > $h['end']) continue;   // still at work
            $this->ports->notices->signOutReminder((int) $r->wp_user_id, $date, (string) $r->signed_in);
            $n++;
        }
        return $n;
    }
}
