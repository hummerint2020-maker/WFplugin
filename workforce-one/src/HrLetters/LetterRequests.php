<?php
namespace WorkforceOne\HrLetters;

use WorkforceOne\HrLetters\Data\LetterStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/**
 * An employee's HR letter requests (HL3, docs/tasks/hr-letter-hl3.md §5): a new request, HR's letter for an employee (no request, no approval;
 * former employees too), and the employee's withdrawal. The letter row and its approval are written in one transaction; the workflow is
 * set before it (its own transaction). A withdrawal closes the approval first, then the row: the same order as a decision, so the two never
 * deadlock. No SQL, no clock but the Clock port, no current user but the one given.
 */
final class LetterRequests
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
     * The employee asks for a letter (My requests → New request → HR letter).
     * @param array<string,mixed> $in type, other_text, addressee, language, note
     * @return array{id:int,status:string,error:string} error: access | type | other_off | other_text | language | note_long | open | no_salary | approval | save
     */
    public function request(object $employee, array $in, int $userId): array
    {
        $site = $this->ports->site;
        $none = ['id' => 0, 'status' => '', 'error' => 'access'];
        if (!$site->requestable()) return $none;
        $settings = $site->settings();
        $c = LetterRules::check($in, $site->types(), $settings);
        if ($c['error'] !== '') return ['error' => $c['error']] + $none;
        $empId = (int) $employee->id;
        $openKey = LetterRules::openKey($empId, $c['type_key'], $c['language'], (bool) $settings['one_open']);
        if ($openKey !== null && $this->store->openExists($empId, $c['type_key'], $c['language'])) return ['error' => 'open'] + $none;
        if ($c['with_salary'] && $this->ports->salary->on($empId, $site->today()) === null) return ['error' => 'no_salary'] + $none;
        $approvals = $this->ports->approvals;
        if (!$approvals->ensureReady()) return ['error' => 'approval'] + $none;
        $now = $this->clock->now();
        if (!$this->tx->begin()) return ['error' => 'save'] + $none;
        $id = $this->store->insert(self::row($empId, $userId, 'employee', $c, 'pending', $openKey, $now));
        if (!$id) {
            $this->tx->rollback();
            return ['error' => $this->store->lastWasDuplicate() ? 'open' : 'save'] + $none;
        }
        if (!$approvals->start($id, $empId)) { $this->tx->rollback(); return ['error' => 'save'] + $none; }
        $ar = $approvals->find($id);
        $auto = $ar && (string) $ar->status === 'APPROVED';   // the workflow is "No approval needed"
        if ($auto && $this->store->markDecided($id, 'approved', '', $now) !== 1) { $this->tx->rollback(); return ['error' => 'save'] + $none; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return ['error' => 'save'] + $none; }
        $this->audit->record('hr_letter_request', 'hr_letter', $id, (string) $employee->name . ' asked for ' . $c['type_label'] . ' (' . $c['language'] . ')');
        $site->markUsed();
        $letter = $this->store->find($id);
        if ($auto) $this->issuer->afterApproval($id);
        elseif ($letter) $this->ports->notices->decider($letter);
        $after = $this->store->find($id);
        return ['id' => $id, 'status' => $after ? (string) $after->status : 'pending', 'error' => ''];
    }

    /**
     * HR's letter for an employee (owner decision Q10): issued directly, no request and no approval; former employees too. Not for HR's own
     * record (owner decision 7). A letter showing the salary needs Manage Payroll (owner decision 6).
     * @param array<string,mixed> $in type, other_text, addressee, language, make (template | upload)
     * @param mixed $upload an entry of $_FILES when HR uploads the letter
     * @return array{id:int,status:string,error:string} error: access | self | payroll | the check's codes | no_salary | a file error | save
     */
    public function createForEmployee(object $employee, array $in, $upload, int $userId, int $ownEmployeeId, bool $canPayroll): array
    {
        $site = $this->ports->site;
        $none = ['id' => 0, 'status' => '', 'error' => 'access'];
        if (!$site->enabled()) return $none;
        $empId = (int) $employee->id;
        if ($ownEmployeeId > 0 && $ownEmployeeId === $empId) return ['error' => 'self'] + $none;
        $settings = ['other' => 1] + $site->settings();
        $c = LetterRules::check($in, $site->types(), $settings);
        if ($c['error'] !== '') return ['error' => $c['error']] + $none;
        $make = ($in['make'] ?? 'template') === 'upload' ? 'upload' : 'template';
        if ($make === 'upload') { $c['delivery'] = 'upload'; $c['with_salary'] = 0; }   // HR's own file: nothing is made from the template
        if ($c['with_salary'] && !$canPayroll) return ['error' => 'payroll'] + $none;
        if ($c['with_salary'] && $this->ports->salary->on($empId, $site->today()) === null) return ['error' => 'no_salary'] + $none;
        $id = $this->store->insert(self::row($empId, $userId, 'hr', $c, 'approved', null, $this->clock->now()) + ['decided_at' => $this->clock->now()]);
        if (!$id) return ['error' => 'save'] + $none;
        $this->audit->record('hr_letter_direct', 'hr_letter', $id, (string) $employee->name . ': ' . $c['type_label'] . ' (' . $c['language'] . ') made by HR');
        $site->markUsed();
        $error = '';
        if ($make === 'upload' && $upload !== null) $error = $this->issuer->upload($id, $upload, $userId);
        elseif ($c['delivery'] === 'pdf') $error = $this->issuer->issue($id, $userId);
        // else: an upload type without a file yet, or request only: it waits on the HR letters page
        $after = $this->store->find($id);
        return ['id' => $id, 'status' => $after ? (string) $after->status : 'approved', 'error' => $error];
    }

    /** The employee withdraws their own waiting request. @return string '' | done | save */
    public function withdraw(int $id, int $employeeId): string
    {
        $l = $this->store->find($id);
        if (!$l || !LetterRules::mayWithdraw($l, $employeeId)) return 'done';
        if (!$this->tx->begin()) return 'save';
        $closed = $this->ports->approvals->closeOpen($id);
        if ($closed === false) { $this->tx->rollback(); return 'save'; }
        if ($closed === 'moved') { $this->tx->rollback(); return 'done'; }
        if ($this->store->withdraw($id, $employeeId, $this->clock->now()) !== 1) { $this->tx->rollback(); return 'done'; }
        if (!$this->tx->commit()) { $this->tx->rollback(); return 'save'; }
        $this->audit->record('hr_letter_withdrawn', 'hr_letter', $id, 'Withdrawn by the employee');
        return '';
    }

    /** @return object[] the employee's letters for My requests, newest first */
    public function mine(int $employeeId): array
    {
        if (!$this->ports->site->enabled()) return [];
        return $this->store->forEmployee($employeeId, 20);
    }

    /** @param array<string,mixed> $c LetterRules::check() @return array<string,mixed> */
    private static function row(int $employeeId, int $userId, string $source, array $c, string $status, ?string $openKey, string $now): array
    {
        return ['employee_id' => $employeeId, 'requested_by' => $userId, 'source' => $source, 'type_key' => $c['type_key'], 'type_label' => $c['type_label'],
            'other_text' => $c['other_text'], 'addressee' => $c['addressee'], 'language' => $c['language'], 'note' => $c['note'], 'delivery' => $c['delivery'],
            'with_salary' => (int) $c['with_salary'], 'status' => $status, 'open_key' => $openKey, 'requested_at' => $now];
    }
}
