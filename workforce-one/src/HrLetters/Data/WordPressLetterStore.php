<?php
namespace WorkforceOne\HrLetters\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the HR letters (HL3). The table follows the prefix of the container. */
final class WordPressLetterStore implements LetterStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;
    /** @var bool */
    private $duplicate = false;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_hr_letters';
    }

    public function insert(array $row): int
    {
        $this->duplicate = false;
        $quiet = $this->db->suppress_errors(true);
        $ok = $this->db->insert($this->t, $row);
        $this->db->suppress_errors($quiet);
        if ($ok === false) {
            $this->duplicate = stripos((string) $this->db->last_error, 'Duplicate') !== false || stripos((string) $this->db->last_error, 'UNIQUE') !== false;
            return 0;
        }
        return (int) $this->db->insert_id;
    }

    public function lastWasDuplicate(): bool
    {
        return $this->duplicate;
    }

    public function find(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function forEmployee(int $employeeId, int $limit): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d ORDER BY id DESC LIMIT %d", $employeeId, $limit));
    }

    public function openExists(int $employeeId, string $typeKey, string $language): bool
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t} WHERE employee_id=%d AND type_key=%s AND language=%s AND status IN ('pending','approved')", $employeeId, $typeKey, $language)) > 0;
    }

    public function markDecided(int $id, string $status, string $note, string $now)
    {
        if ($status === 'approved') return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='approved',decided_at=%s,decision_note=%s WHERE id=%d AND status='pending'", $now, $note, $id));
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='rejected',decided_at=%s,decision_note=%s,open_key=NULL WHERE id=%d AND status='pending'", $now, $note, $id));
    }

    public function withdraw(int $id, int $employeeId, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='withdrawn',decided_at=%s,open_key=NULL WHERE id=%d AND employee_id=%d AND status='pending'", $now, $id, $employeeId));
    }

    public function claimIssued(int $id, array $file, int $by, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='issued',open_key=NULL,file_key=%s,file_name=%s,file_source=%s,file_size=%d,issued_at=%s,issued_by=%d,issue_error='' WHERE id=%d AND status='approved' AND file_key IS NULL",
            $file['key'], $file['name'], $file['source'], (int) $file['size'], $now, $by, $id));
    }

    public function replaceFile(int $id, string $oldKey, array $file, int $by, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET file_key=%s,file_name=%s,file_source=%s,file_size=%d,issued_at=%s,issued_by=%d,downloaded_at=NULL WHERE id=%d AND status='issued' AND file_key=%s",
            $file['key'], $file['name'], $file['source'], (int) $file['size'], $now, $by, $id, $oldKey));
    }

    public function setIssueError(int $id, string $code)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET issue_error=%s WHERE id=%d AND status='approved'", $code, $id));
    }

    public function markReady(int $id, int $by, string $now, string $note)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='ready',open_key=NULL,issued_at=%s,issued_by=%d,decision_note=%s WHERE id=%d AND status='approved' AND delivery='none' AND file_key IS NULL", $now, $by, $note, $id));
    }

    public function cancel(int $id, int $by, string $now, string $note)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='cancelled',open_key=NULL,issued_at=%s,issued_by=%d,decision_note=%s WHERE id=%d AND status='approved' AND file_key IS NULL", $now, $by, $note, $id));
    }

    public function markDownloaded(int $id, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET downloaded_at=%s WHERE id=%d AND downloaded_at IS NULL", $now, $id));
    }
}
