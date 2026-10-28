<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the org-moves history (3.31.134): the SQL the plugin ran before, unchanged. */
final class WordPressOrgMoveStore implements OrgMoveStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_org_moves';
    }

    public function tableExists(): bool
    {
        return $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $this->t)) === $this->t;
    }

    public function openIdFor(int $employeeId, int $ignoreId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE employee_id=%d AND status IN ('scheduled','applying') AND id<>%d LIMIT 1", $employeeId, $ignoreId));
    }

    public function insert(array $row)
    {
        return $this->db->insert($this->t, $row);
    }

    public function insertId(): int
    {
        return (int) $this->db->insert_id;
    }

    public function finishClaimed(int $id, string $claimAt, array $row)
    {
        return $this->db->update($this->t, $row + ['note' => ''], ['id' => $id, 'status' => 'applying', 'applied_at' => $claimAt]);
    }

    public function dueRows(string $today): array
    {
        return array_values((array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE status='scheduled' AND effective_date<=%s ORDER BY effective_date ASC,id ASC LIMIT 200", $today)));
    }

    public function claim(int $id, string $claimAt)
    {
        return $this->db->update($this->t, ['status' => 'applying', 'applied_at' => $claimAt], ['id' => $id, 'status' => 'scheduled']);
    }

    public function markAlreadyDone(int $id, string $claimAt, string $now)
    {
        return $this->db->update($this->t, ['status' => 'done', 'note' => 'already in place', 'applied_at' => $now], ['id' => $id, 'status' => 'applying', 'applied_at' => $claimAt]);
    }

    public function release(int $id, string $claimAt)
    {
        return $this->db->update($this->t, ['status' => 'scheduled', 'applied_at' => null], ['id' => $id, 'status' => 'applying', 'applied_at' => $claimAt]);
    }

    public function fail(int $id, ?string $claimAt, string $code, string $now)
    {
        $where = ['id' => $id, 'status' => 'applying'];
        if ($claimAt !== null) $where['applied_at'] = $claimAt;
        return $this->db->update($this->t, ['status' => 'failed', 'note' => $code, 'applied_at' => $now], $where);
    }

    public function applyingRows(): array
    {
        return array_values((array) $this->db->get_results("SELECT * FROM {$this->t} WHERE status='applying' ORDER BY id ASC LIMIT 200"));
    }

    /** The WHERE of a move still applying with this claim time (none: a move claimed before there was a claim time). */
    private function claimWhere(int $id, ?string $appliedAt): string
    {
        return $appliedAt === null
            ? $this->db->prepare('id=%d AND status=%s AND applied_at IS NULL', $id, 'applying')
            : $this->db->prepare('id=%d AND status=%s AND applied_at=%s', $id, 'applying', $appliedAt);
    }

    public function resume(int $id, ?string $appliedAt)
    {
        $where = $this->claimWhere($id, $appliedAt);
        return $this->db->query("UPDATE {$this->t} SET status='scheduled',applied_at=NULL,note='resumed' WHERE {$where}");
    }

    public function failInterrupted(int $id, ?string $appliedAt, string $now)
    {
        $where = $this->claimWhere($id, $appliedAt);
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='failed',note='interrupted',applied_at=%s WHERE {$where}", $now));
    }

    public function byId(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function cancel(int $id, string $status, string $now)
    {
        return $this->db->update($this->t, ['status' => 'cancelled', 'applied_at' => $now], ['id' => $id, 'status' => $status]);
    }

    public function doneRows(): array
    {
        return array_values((array) $this->db->get_results("SELECT * FROM {$this->t} WHERE status='done' ORDER BY id DESC LIMIT 2000"));
    }

    public function openRows(): array
    {
        return array_values((array) $this->db->get_results("SELECT * FROM {$this->t} WHERE status IN ('scheduled','applying') ORDER BY effective_date ASC,id ASC LIMIT 500"));
    }

    public function failedRows(): array
    {
        return array_values((array) $this->db->get_results("SELECT * FROM {$this->t} WHERE status='failed' ORDER BY id DESC LIMIT 100"));
    }
}
