<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\HrLetters\Data\LetterQuery;
use WorkforceOne\HrLetters\Data\LetterStore;

/**
 * In-memory HR letters for the unit tests (HL3): the store and the read model on the same rows, with the UNIQUE open_key, the conditional
 * updates, a journal shared with the other fakes, and switches that make the next write fail.
 */
final class MemoryLetterStore implements LetterStore, LetterQuery
{
    /** @var array<int,object> */
    public $rows = [];
    /** @var array<int,object> employees by id (name, name_ar, gender, hire_date, created_at, job_title, active, wp_user_id, department_name) */
    public $employees = [];
    /** @var \ArrayObject<int,string>|null */
    public $journal;
    /** @var bool the next write fails (false) */
    public $failWrite = false;
    /** @var bool the next insert fails */
    public $failInsert = false;
    /** @var bool */
    private $duplicate = false;
    /** @var int */
    private $next = 1;

    private function note(string $s): void
    {
        if ($this->journal) $this->journal[] = $s;
    }

    /** @return int|false */
    private function write(int $id, callable $cond, array $set, string $what)
    {
        $this->note($what . ' ' . $id);
        if ($this->failWrite) { $this->failWrite = false; return false; }
        $r = $this->rows[$id] ?? null;
        if (!$r || !$cond($r)) return 0;
        foreach ($set as $k => $v) $r->$k = $v;
        return 1;
    }

    public function insert(array $row): int
    {
        $this->note('insert');
        $this->duplicate = false;
        if ($this->failInsert) { $this->failInsert = false; return 0; }
        if (($row['open_key'] ?? null) !== null) foreach ($this->rows as $r) if ($r->open_key === $row['open_key']) { $this->duplicate = true; return 0; }
        $id = $this->next++;
        $this->rows[$id] = (object) (['id' => $id] + $row + ['decided_at' => null, 'decision_note' => '', 'issued_at' => null, 'issued_by' => 0, 'file_key' => null,
            'file_name' => '', 'file_source' => '', 'file_size' => 0, 'issue_error' => '', 'downloaded_at' => null]);
        return $id;
    }

    public function lastWasDuplicate(): bool { return $this->duplicate; }

    public function find(int $id): ?object { return isset($this->rows[$id]) ? clone $this->rows[$id] : null; }

    public function forEmployee(int $employeeId, int $limit): array
    {
        $out = array_values(array_filter($this->rows, static function ($r) use ($employeeId) { return (int) $r->employee_id === $employeeId; }));
        usort($out, static function ($a, $b) { return $b->id <=> $a->id; });
        return array_map(static function ($r) { return clone $r; }, array_slice($out, 0, $limit));
    }

    public function openExists(int $employeeId, string $typeKey, string $language): bool
    {
        foreach ($this->rows as $r) if ((int) $r->employee_id === $employeeId && $r->type_key === $typeKey && $r->language === $language && in_array($r->status, ['pending', 'approved'], true)) return true;
        return false;
    }

    public function markDecided(int $id, string $status, string $note, string $now)
    {
        $set = ['status' => $status, 'decided_at' => $now, 'decision_note' => $note] + ($status === 'approved' ? [] : ['open_key' => null]);
        return $this->write($id, static function ($r) { return $r->status === 'pending'; }, $set, 'markDecided:' . $status);
    }

    public function withdraw(int $id, int $employeeId, string $now)
    {
        return $this->write($id, static function ($r) use ($employeeId) { return $r->status === 'pending' && (int) $r->employee_id === $employeeId; }, ['status' => 'withdrawn', 'decided_at' => $now, 'open_key' => null], 'withdraw');
    }

    public function claimIssued(int $id, array $file, int $by, string $now)
    {
        return $this->write($id, static function ($r) { return $r->status === 'approved' && $r->file_key === null; },
            ['status' => 'issued', 'open_key' => null, 'file_key' => $file['key'], 'file_name' => $file['name'], 'file_source' => $file['source'], 'file_size' => (int) $file['size'], 'issued_at' => $now, 'issued_by' => $by, 'issue_error' => ''], 'claimIssued');
    }

    public function replaceFile(int $id, string $oldKey, array $file, int $by, string $now)
    {
        return $this->write($id, static function ($r) use ($oldKey) { return $r->status === 'issued' && $r->file_key === $oldKey; },
            ['file_key' => $file['key'], 'file_name' => $file['name'], 'file_source' => $file['source'], 'file_size' => (int) $file['size'], 'issued_at' => $now, 'issued_by' => $by, 'downloaded_at' => null], 'replaceFile');
    }

    public function setIssueError(int $id, string $code)
    {
        return $this->write($id, static function ($r) { return $r->status === 'approved'; }, ['issue_error' => $code], 'setIssueError:' . $code);
    }

    public function markReady(int $id, int $by, string $now, string $note)
    {
        return $this->write($id, static function ($r) { return $r->status === 'approved' && $r->delivery === 'none' && $r->file_key === null; },
            ['status' => 'ready', 'open_key' => null, 'issued_at' => $now, 'issued_by' => $by, 'decision_note' => $note], 'markReady');
    }

    public function cancel(int $id, int $by, string $now, string $note)
    {
        return $this->write($id, static function ($r) { return $r->status === 'approved' && $r->file_key === null; },
            ['status' => 'cancelled', 'open_key' => null, 'issued_at' => $now, 'issued_by' => $by, 'decision_note' => $note], 'cancel');
    }

    public function markDownloaded(int $id, string $now)
    {
        return $this->write($id, static function ($r) { return $r->downloaded_at === null; }, ['downloaded_at' => $now], 'markDownloaded');
    }

    public function facts(int $id): ?object
    {
        $r = $this->rows[$id] ?? null;
        if (!$r) return null;
        $e = $this->employees[(int) $r->employee_id] ?? (object) [];
        return (object) ((array) $r + (array) $e);
    }

    public function counts(): array
    {
        $out = [];
        foreach ($this->rows as $r) $out[$r->status] = ($out[$r->status] ?? 0) + 1;
        return $out;
    }

    public function adminList(string $status, string $search, int $page, int $perPage): array
    {
        $rows = array_values(array_filter($this->rows, static function ($r) use ($status) { return $status === '' || $r->status === $status; }));
        return ['rows' => $rows, 'total' => count($rows)];
    }

    public function waiting(): array
    {
        return array_values(array_filter($this->rows, static function ($r) { return $r->status === 'pending'; }));
    }
}
