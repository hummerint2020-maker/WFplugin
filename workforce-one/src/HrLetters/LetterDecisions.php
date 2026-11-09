<?php
namespace WorkforceOne\HrLetters;

use WorkforceOne\HrLetters\Data\LetterStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/**
 * Deciding HR letters (HL3, docs/tasks/hr-letter-hl3.md §5): a level's decision (My approvals, or the Requests Hub with the administrators'
 * override), and HR's actions on a decided letter: Ready to collect (with where) and Cancel (with the reason). The engine's step and the
 * letter row change in one transaction; the letter is delivered after it (a failure there never undoes the decision).
 * No SQL, no clock but the Clock port.
 */
final class LetterDecisions
{
    /** @var LetterStore */
    private $store;
    /** @var LetterIssuer */
    private $issuer;
    /** @var LetterPorts */
    private $ports;
    /** @var Transaction */
    private $tx;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(LetterStore $store, LetterIssuer $issuer, LetterPorts $ports, Transaction $tx, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->issuer = $issuer;
        $this->ports = $ports;
        $this->tx = $tx;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /**
     * @param int|null $posted the level the page showed
     * @return string '' when decided (or moved on to the next level), else access | done | moved | note | approval | save
     */
    public function decide(int $id, string $decision, string $note, $posted, bool $override): string
    {
        if (!$this->ports->site->enabled() || !in_array($decision, ['approve', 'reject'], true)) return 'access';
        $l = $this->store->find($id);
        if (!$l || (string) $l->status !== 'pending') return 'done';
        $approvals = $this->ports->approvals;
        $ar = $approvals->find($id);
        if (!$ar) return 'access';
        // A request still open (WAITING_FOR_LEVEL_n / WAITING_FOR_PEER) is checked against its open level; a decided one has none.
        $open = !in_array((string) $ar->status, ['APPROVED', 'REJECTED', 'CANCELLED'], true);
        if ($approvals->levelMoved($open ? (int) $ar->id : 0, $posted)) return 'moved';
        if (!$approvals->canAct((int) $ar->id, $override)) return 'access';
        if (!$this->tx->begin()) return 'save';
        $res = $approvals->act((int) $ar->id, $decision, $note, $posted, $override);
        if ($res !== '') { $this->tx->rollback(); return $res; }
        $fresh = $approvals->find($id);
        $final = $fresh && in_array((string) $fresh->status, ['APPROVED', 'REJECTED'], true);
        $approved = $final && (string) $fresh->status === 'APPROVED';
        if ($final && $this->store->markDecided($id, $approved ? 'approved' : 'rejected', $approved ? '' : mb_substr($note, 0, 500), $this->clock->now()) !== 1) { $this->tx->rollback(); return 'done'; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
        $this->audit->record('hr_letter_' . $decision, 'hr_letter', $id, $final ? ($approved ? 'approved' : 'rejected') : 'level decision');
        $after = $this->store->find($id);
        if ($approved) $this->issuer->afterApproval($id);
        elseif ($final && $after) $this->ports->notices->employee('rejected', $after, $note !== '' ? $note : $approvals->pathNote($id));
        elseif ($after) $this->ports->notices->decider($after);
        return '';
    }

    /** Request only: HR marks a decided letter ready to collect, with where (optional). @return string '' | done */
    public function markReady(int $id, string $where, int $by): string
    {
        $where = LetterSettings::text($where, 190);
        if ($this->store->markReady($id, $by, $this->clock->now(), $where) !== 1) return 'done';
        $this->audit->record('hr_letter_ready', 'hr_letter', $id, 'Ready to collect' . ($where !== '' ? ': ' . $where : ''));
        $l = $this->store->find($id);
        if ($l) $this->ports->notices->employee('collect', $l);
        return '';
    }

    /** HR cancels a decided letter with no file, with the reason (required; the employee sees it). @return string '' | note | done */
    public function cancel(int $id, string $reason, int $by): string
    {
        $reason = trim(mb_substr($reason, 0, 500));
        if ($reason === '') return 'note';
        if ($this->store->cancel($id, $by, $this->clock->now(), $reason) !== 1) return 'done';
        $this->audit->record('hr_letter_cancelled', 'hr_letter', $id, 'Cancelled by HR: ' . $reason);
        $l = $this->store->find($id);
        if ($l) $this->ports->notices->employee('cancelled', $l, $reason);
        return '';
    }
}
