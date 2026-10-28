<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/**
 * SQL adapter for the employees table (3.31.133); it never receives the plugin object. The SQL is the SQL the plugin ran before,
 * unchanged. The employees table is the plugin's own table name, as it was fixed when the plugin object was built (the old code
 * used that name also inside a switch_to_blog()); the departments table the chart joins is built with the current prefix.
 */
final class WordPressPeopleStore implements PeopleStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;
    /** @var string */
    private $departments;

    public function __construct(\wpdb $db, string $employeesTable = '')
    {
        $this->db = $db;
        $this->t = $employeesTable !== '' ? $employeesTable : $db->prefix . 'ews_employees';
        $this->departments = $db->prefix . 'ews_departments';
    }

    public function byId(int $id, bool $activeOnly): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE id=%d" . ($activeOnly ? ' AND active=1' : '') . " LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function activeByUser(int $userId): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE wp_user_id=%d AND active=1 ORDER BY id ASC LIMIT 1", $userId));
        return is_object($r) ? $r : null;
    }

    public function activeNamesByUsers(array $userIds): array
    {
        $out = [];
        foreach (array_chunk($userIds, 500) as $chunk) {
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT wp_user_id,name FROM {$this->t} WHERE active=1 AND wp_user_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ') ORDER BY id ASC', $chunk)) as $e) $out[] = $e;
        }
        return $out;
    }

    public function rowsByIds(array $ids, bool $activeOnly): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ')' . ($activeOnly ? ' AND active=1' : ''), $chunk)) as $e) $out[] = $e;
        }
        return $out;
    }

    public function briefsByIds(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT id,name,wp_user_id FROM {$this->t} WHERE id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ')', $chunk)) as $e) $out[] = $e;
        }
        return $out;
    }

    public function activeId(int $id): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE id=%d AND active=1", $id));
    }

    public function name(int $id): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT name FROM {$this->t} WHERE id=%d", $id));
    }

    public function userId(int $id, bool $activeOnly): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT wp_user_id FROM {$this->t} WHERE id=%d" . ($activeOnly ? ' AND active=1' : '') . " LIMIT 1", $id));
    }

    public function activeIdsInDepartment(int $departmentId): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT id FROM {$this->t} WHERE department_id=%d AND active=1", $departmentId));
    }

    public function activeInDepartment(int $employeeId, int $departmentId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1", $employeeId, $departmentId));
    }

    public function activeBrief(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT name,wp_user_id FROM {$this->t} WHERE id=%d AND active=1", $id));
        return is_object($r) ? $r : null;
    }

    public function shiftIdOf(int $id): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT default_shift_id FROM {$this->t} WHERE id=%d", $id));
    }

    public function shiftIdsOf(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT id,default_shift_id FROM {$this->t} WHERE id IN ($ph)", $chunk)) as $r) $out[] = $r;
        }
        return $out;
    }

    public function shiftIdNow(int $id): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT default_shift_id FROM {$this->t} WHERE id=%d LIMIT 1", $id));
    }

    public function setShiftId(int $employeeId, int $shiftId): bool
    {
        if ($shiftId) return $this->db->query($this->db->prepare("UPDATE {$this->t} SET default_shift_id=%d WHERE id=%d AND COALESCE(default_shift_id,0)<>%d", $shiftId, $employeeId, $shiftId)) !== false;
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET default_shift_id=NULL WHERE id=%d AND default_shift_id IS NOT NULL", $employeeId)) !== false;
    }

    public function allShiftIds(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,default_shift_id FROM {$this->t}"));
    }

    public function activeList(int $limit): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name,domain_name FROM {$this->t} WHERE active=1 ORDER BY name ASC" . ($limit > 0 ? ' LIMIT ' . $limit : '')));
    }

    public function activeUsers(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name,wp_user_id,department_id,active FROM {$this->t} WHERE active=1 AND wp_user_id>0 ORDER BY name"));
    }

    public function activeIds(bool $attendanceOnly): array
    {
        return (array) $this->db->get_col("SELECT id FROM {$this->t} WHERE active=1" . ($attendanceOnly ? ' AND (attendance_enabled IS NULL OR attendance_enabled=1)' : ''));
    }

    public function activeUserIds(int $departmentId): array
    {
        $sql = "SELECT wp_user_id FROM {$this->t} WHERE active=1 AND wp_user_id IS NOT NULL AND wp_user_id>0";
        return (array) ($departmentId ? $this->db->get_col($this->db->prepare($sql . ' AND department_id=%d', $departmentId)) : $this->db->get_col($sql));
    }

    public function activeCount(int $departmentId, bool $withUser): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->t} WHERE active=1" . ($withUser ? ' AND wp_user_id>0' : '');
        return (int) ($departmentId ? $this->db->get_var($this->db->prepare($sql . ' AND department_id=%d', $departmentId)) : $this->db->get_var($sql));
    }

    public function activeAll(): ?array
    {
        $rows = $this->db->get_results("SELECT * FROM {$this->t} WHERE active=1 ORDER BY name ASC");
        return is_array($rows) ? $rows : null;
    }

    public function attendanceEnabledValue(int $id): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT attendance_enabled FROM {$this->t} WHERE id=%d LIMIT 1", $id));
    }

    public function orgRows(bool $withDepartments): array
    {
        $d = $this->departments;
        return array_values((array) $this->db->get_results("SELECT e.id,e.name,e.domain_name,e.job_title,e.department_id,e.wp_user_id,e.profile_image_type,e.profile_image_url,e.avatar_key" . ($withDepartments ? ",d.name department_name FROM {$this->t} e LEFT JOIN {$d} d ON d.id=e.department_id" : " FROM {$this->t} e") . " WHERE e.active=1 ORDER BY e.name ASC"));
    }

    public function importRows(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name,domain_name,job_title,active FROM {$this->t}"));
    }

    public function setJobTitle(int $employeeId, string $title, string $now)
    {
        return $this->db->update($this->t, ['job_title' => $title, 'updated_at' => $now], ['id' => $employeeId, 'active' => 1]);
    }

    public function departmentIdOf(int $employeeId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT department_id FROM {$this->t} WHERE id=%d LIMIT 1", $employeeId));
    }

    public function former(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT active,department_id,wp_user_id,last_working_day,default_shift_id FROM {$this->t} WHERE id=%d LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function status(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT active,wp_user_id FROM {$this->t} WHERE id=%d LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function domainOwner(string $domain, int $exceptId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE domain_name=%s AND id<>%d LIMIT 1", $domain, $exceptId));
    }

    public function userOwner(int $userId, int $exceptId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE wp_user_id=%d AND id<>%d LIMIT 1", $userId, $exceptId));
    }

    public function insert(array $row)
    {
        return $this->db->insert($this->t, $row);
    }

    public function insertId(): int
    {
        return (int) $this->db->insert_id;
    }

    public function updateFields(int $id, array $fields)
    {
        return $this->db->update($this->t, $fields, ['id' => $id]);
    }

    public function archive(int $id, string $lastDay, string $now)
    {
        return $this->db->update($this->t, ['active' => 0, 'last_working_day' => $lastDay, 'updated_at' => $now], ['id' => $id]);
    }

    public function setPicture(int $id, string $type, string $url, ?string $key)
    {
        return $this->db->update($this->t, ['profile_image_type' => $type, 'profile_image_url' => $url, 'avatar_key' => $key], ['id' => $id], ['%s', '%s', '%s'], ['%d']);
    }

    /** The WHERE of the Employees page's search ('' for none). */
    private function searchWhere(string $search): string
    {
        if ($search === '') return '';
        $like = '%' . $this->db->esc_like($search) . '%';
        return $this->db->prepare(' WHERE name LIKE %s OR domain_name LIKE %s OR email LIKE %s', $like, $like, $like);
    }

    public function adminCount(string $search): int
    {
        $where = $this->searchWhere($search);
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t}{$where}");
    }

    public function adminPage(string $search, int $perPage, int $offset): array
    {
        $where = $this->searchWhere($search);
        return array_values((array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t}{$where} ORDER BY active DESC,name ASC LIMIT %d OFFSET %d", $perPage, $offset)));
    }

    public function pickList(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name,domain_name,active FROM {$this->t} ORDER BY name ASC"));
    }

    public function activeTitles(): array
    {
        return array_map('strval', (array) $this->db->get_col("SELECT DISTINCT job_title FROM {$this->t} WHERE active=1 AND job_title IS NOT NULL AND job_title<>'' ORDER BY job_title ASC LIMIT 300"));
    }

    public function contactList(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name,domain_name,email FROM {$this->t} WHERE active=1 ORDER BY name"));
    }

    public function directory(string $search, ?array $ids): array
    {
        $where = ['active=1'];
        $args = [];
        if ($search !== '') {
            $like = '%' . $this->db->esc_like($search) . '%';
            $where[] = '(name LIKE %s OR domain_name LIKE %s OR email LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        if ($ids !== null) {
            $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $args = array_merge($args, $ids);
        }
        $sql = "SELECT id,name,domain_name,profile_image_type,profile_image_url,avatar_key FROM {$this->t} WHERE " . implode(' AND ', $where) . ' ORDER BY name ASC LIMIT 500';
        return array_values((array) ($args ? $this->db->get_results($this->db->prepare($sql, ...$args)) : $this->db->get_results($sql)));
    }

    public function publicProfile(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT id,name,email,profile_image_type,profile_image_url,avatar_key FROM {$this->t} WHERE id=%d AND active=1 LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }
}
