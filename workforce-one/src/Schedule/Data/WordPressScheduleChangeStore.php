<?php
namespace WorkforceOne\Schedule\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the schedule change requests (3.31.165). The tables follow the prefix of the container. */
final class WordPressScheduleChangeStore implements ScheduleChangeStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $r;
    /** @var string */
    private $d;
    /** @var bool */
    private $duplicate = false;

    private const SET = ['status' => '%s', 'decided_by' => '%d', 'decided_at' => '%s', 'decision_note' => '%s'];

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->r = $db->prefix . 'ews_schedule_change_requests';
        $this->d = $db->prefix . 'ews_schedule_change_days';
    }

    private function lock(): string
    {
        return (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') ? '' : ' FOR UPDATE';
    }

    public function insertRequest(array $row): int
    {
        return $this->db->insert($this->r, $row) === false ? 0 : (int) $this->db->insert_id;
    }

    public function insertDay(array $row): int
    {
        $this->duplicate = false;
        $quiet = $this->db->suppress_errors(true);
        $ok = $this->db->insert($this->d, $row);
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
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->r} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function lockRequest(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->r} WHERE id=%d LIMIT 1" . $this->lock(), $id));
        return is_object($r) ? $r : null;
    }

    public function days(int $requestId): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->d} WHERE request_id=%d ORDER BY employee_id,work_date,id", $requestId));
    }

    public function lockDays(int $requestId): ?array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->d} WHERE request_id=%d ORDER BY employee_id,work_date,id" . $this->lock(), $requestId));
        if (!is_array($rows) || $this->db->last_error !== '') return null;
        return $rows;
    }

    public function daysOf(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        if (!$ids) return $out;
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->d} WHERE request_id IN ($ph) ORDER BY request_id,employee_id,work_date,id", $chunk)) as $row) $out[(int) $row->request_id][] = $row;
        }
        return $out;
    }

    public function openOn(array $employeeIds, string $from, string $to): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (!$ids) return [];
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $this->db->get_results($this->db->prepare("SELECT d.employee_id,d.work_date,d.new_status,d.request_id,r.requester_employee_id FROM {$this->d} d JOIN {$this->r} r ON r.id=d.request_id
                WHERE d.open_key IS NOT NULL AND d.employee_id IN ($ph) AND d.work_date BETWEEN %s AND %s ORDER BY d.employee_id,d.work_date", array_merge($chunk, [$from, $to])));
            foreach ((array) $rows as $row) $out[] = $row;
        }
        return $out;
    }

    /** @param array<string,mixed> $set */
    private function setSql(array $set): array
    {
        $cols = [];
        $args = [];
        foreach ($set as $k => $v) {
            if (!isset(self::SET[$k])) continue;
            if ($v === null) { $cols[] = "{$k}=NULL"; continue; }
            $cols[] = $k . '=' . self::SET[$k];
            $args[] = $v;
        }
        return [implode(',', $cols), $args];
    }

    public function claim(int $id, string $from, array $set)
    {
        [$cols, $args] = $this->setSql($set);
        if ($cols === '') return false;
        return $this->db->query($this->db->prepare("UPDATE {$this->r} SET {$cols} WHERE id=%d AND status=%s", array_merge($args, [$id, $from])));
    }

    public function claimOwn(int $id, int $requesterEmployeeId, array $set)
    {
        [$cols, $args] = $this->setSql($set);
        if ($cols === '') return false;
        return $this->db->query($this->db->prepare("UPDATE {$this->r} SET {$cols} WHERE id=%d AND requester_employee_id=%d AND status='pending'", array_merge($args, [$id, $requesterEmployeeId])));
    }

    public function setDayResult(int $dayId, string $result, ?string $nowStatus): bool
    {
        $sql = $nowStatus === null
            ? $this->db->prepare("UPDATE {$this->d} SET result=%s,now_status=NULL,open_key=NULL WHERE id=%d", $result, $dayId)
            : $this->db->prepare("UPDATE {$this->d} SET result=%s,now_status=%s,open_key=NULL WHERE id=%d", $result, $nowStatus, $dayId);
        return $this->db->query($sql) !== false;
    }

    public function releaseDays(int $requestId): bool
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->d} SET open_key=NULL WHERE request_id=%d", $requestId)) !== false;
    }

    public function finish(int $id, string $status, int $applied)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->r} SET status=%s,applied=%d WHERE id=%d", $status, $applied, $id));
    }

    public function waiting(int $limit): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->r} WHERE status='pending' ORDER BY requested_at,id LIMIT %d", $limit));
    }

    public function forRequester(int $employeeId, int $limit): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->r} WHERE requester_employee_id=%d ORDER BY id DESC LIMIT %d", $employeeId, $limit));
    }

    public function openCount(): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->r} WHERE status='pending'");
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }
}
