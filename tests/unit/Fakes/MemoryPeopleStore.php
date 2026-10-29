<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\People\Data\PeopleStore;

/** An in-memory PeopleStore for the unit tests (3.31.133): rows are objects with string values, as wpdb gives them; every call is recorded. */
final class MemoryPeopleStore implements PeopleStore
{
    /** @var OrgWorld|null */
    public $world;
    /** @var array<int,object> */
    public $rows = [];
    /** @var list<string> */
    public $calls = [];
    /** @var bool the next setShiftId fails */
    public $failWrite = false;
    /** @var bool activeAll() fails (null) */
    public $failActiveAll = false;
    /** @var bool the departments table exists (orgRows asks) */
    public $lastWithDepartments = null;

    /** Adds an employee. @param array<string,mixed> $more */
    public function add(int $id, string $name, int $userId = 0, bool $active = true, array $more = []): void
    {
        $this->rows[$id] = (object) ($more + ['id' => (string) $id, 'name' => $name, 'domain_name' => 'd' . $id, 'wp_user_id' => $userId ? (string) $userId : null,
            'active' => $active ? '1' : '0', 'department_id' => null, 'default_shift_id' => null, 'attendance_enabled' => '1']);
    }

    private function note(string $c): void
    {
        $this->calls[] = $c;
    }

    public function byId(int $id, bool $activeOnly): ?object
    {
        $this->note('byId ' . $id . ($activeOnly ? ' active' : ''));
        $r = $this->rows[$id] ?? null;
        return $r && (!$activeOnly || $r->active === '1') ? clone $r : null;
    }

    public function activeByUser(int $userId): ?object
    {
        $this->note('activeByUser ' . $userId);
        foreach ($this->rows as $r) if ($r->active === '1' && (int) $r->wp_user_id === $userId) return clone $r;
        return null;
    }

    public function activeNamesByUsers(array $userIds): array
    {
        $this->note('activeNamesByUsers ' . implode(',', $userIds));
        $out = [];
        foreach ($this->rows as $r) if ($r->active === '1' && in_array((int) $r->wp_user_id, $userIds, true)) $out[] = (object) ['wp_user_id' => $r->wp_user_id, 'name' => $r->name];
        return $out;
    }

    public function rowsByIds(array $ids, bool $activeOnly): array
    {
        $this->note('rowsByIds ' . implode(',', $ids));
        $out = [];
        foreach ($ids as $id) if (isset($this->rows[$id]) && (!$activeOnly || $this->rows[$id]->active === '1')) $out[(int) $id] = clone $this->rows[$id];
        ksort($out);   // IN() without ORDER BY comes back in primary-key order
        return array_values($out);
    }

    public function briefsByIds(array $ids): array
    {
        $this->note('briefsByIds ' . implode(',', $ids));
        $out = [];
        foreach ($ids as $id) if (isset($this->rows[$id])) $out[(int) $id] = (object) ['id' => $this->rows[$id]->id, 'name' => $this->rows[$id]->name, 'wp_user_id' => $this->rows[$id]->wp_user_id];
        ksort($out);
        return array_values($out);
    }

    public function activeId(int $id): ?string
    {
        $this->note('activeId ' . $id);
        return isset($this->rows[$id]) && $this->rows[$id]->active === '1' ? (string) $id : null;
    }

    public function name(int $id): ?string
    {
        $this->note('name ' . $id);
        return $this->rows[$id]->name ?? null;
    }

    public function userId(int $id, bool $activeOnly): ?string
    {
        $this->note('userId ' . $id);
        $r = $this->rows[$id] ?? null;
        return $r && (!$activeOnly || $r->active === '1') ? $r->wp_user_id : null;
    }

    public function activeIdsInDepartment(int $departmentId): array
    {
        $this->note('activeIdsInDepartment ' . $departmentId);
        $out = [];
        foreach ($this->rows as $r) if ($r->active === '1' && (int) $r->department_id === $departmentId) $out[] = $r->id;
        return $out;
    }

    public function activeInDepartment(int $employeeId, int $departmentId): ?string
    {
        $this->note('activeInDepartment');
        $r = $this->rows[$employeeId] ?? null;
        return $r && $r->active === '1' && (int) $r->department_id === $departmentId ? $r->id : null;
    }

    public function activeBrief(int $id): ?object
    {
        $this->note('activeBrief ' . $id);
        $r = $this->rows[$id] ?? null;
        return $r && $r->active === '1' ? (object) ['name' => $r->name, 'wp_user_id' => $r->wp_user_id] : null;
    }

    public function shiftIdOf(int $id): ?string
    {
        $this->note('shiftIdOf ' . $id);
        return isset($this->rows[$id]) ? $this->rows[$id]->default_shift_id : null;
    }

    public function shiftIdsOf(array $ids): array
    {
        $this->note('shiftIdsOf ' . implode(',', $ids));
        $out = [];
        foreach ($ids as $id) if (isset($this->rows[$id])) $out[] = (object) ['id' => $this->rows[$id]->id, 'default_shift_id' => $this->rows[$id]->default_shift_id];
        return $out;
    }

    public function shiftIdNow(int $id): ?string
    {
        $this->note('shiftIdNow ' . $id);
        return isset($this->rows[$id]) ? $this->rows[$id]->default_shift_id : null;
    }

    public function setShiftId(int $employeeId, int $shiftId): bool
    {
        $this->note('setShiftId ' . $employeeId . ' ' . $shiftId);
        if ($this->failWrite) return false;
        if (isset($this->rows[$employeeId])) $this->rows[$employeeId]->default_shift_id = $shiftId ? (string) $shiftId : null;
        return true;
    }

    public function allShiftIds(): array
    {
        $this->note('allShiftIds');
        return array_values(array_map(function ($r) { return (object) ['id' => $r->id, 'default_shift_id' => $r->default_shift_id]; }, $this->rows));
    }

    public function activeList(int $limit): array
    {
        $this->note('activeList ' . $limit);
        $rows = array_values(array_filter($this->rows, function ($r) { return $r->active === '1'; }));
        usort($rows, function ($a, $b) { return strcmp($a->name, $b->name); });
        return array_slice($rows, 0, $limit > 0 ? $limit : null);
    }

    public function activeUsers(): array
    {
        $this->note('activeUsers');
        return array_values(array_filter($this->rows, function ($r) { return $r->active === '1' && (int) $r->wp_user_id > 0; }));
    }

    public function activeIds(bool $attendanceOnly): array
    {
        $this->note('activeIds ' . ($attendanceOnly ? 'attendance' : 'all'));
        $out = [];
        foreach ($this->rows as $r) if ($r->active === '1' && (!$attendanceOnly || $r->attendance_enabled === null || $r->attendance_enabled === '1')) $out[] = $r->id;
        return $out;
    }

    public function activeUserIds(int $departmentId): array
    {
        $this->note('activeUserIds ' . $departmentId);
        $out = [];
        foreach ($this->rows as $r) if ($r->active === '1' && (int) $r->wp_user_id > 0 && (!$departmentId || (int) $r->department_id === $departmentId)) $out[] = $r->wp_user_id;
        return $out;
    }

    public function activeCount(int $departmentId, bool $withUser): int
    {
        $this->note('activeCount');
        return count(array_filter($this->rows, function ($r) use ($departmentId, $withUser) {
            return $r->active === '1' && (!$withUser || (int) $r->wp_user_id > 0) && (!$departmentId || (int) $r->department_id === $departmentId);
        }));
    }

    public function activeAll(): ?array
    {
        $this->note('activeAll');
        if ($this->failActiveAll) return null;
        $rows = array_values(array_filter($this->rows, function ($r) { return $r->active === '1'; }));
        usort($rows, function ($a, $b) { return strcmp($a->name, $b->name); });
        return $rows;
    }

    public function attendanceEnabledValue(int $id): ?string
    {
        $this->note('attendanceEnabledValue ' . $id);
        return isset($this->rows[$id]) ? $this->rows[$id]->attendance_enabled : null;
    }

    public function orgRows(bool $withDepartments): array
    {
        $this->note('orgRows ' . ($withDepartments ? 'with' : 'without'));
        $this->lastWithDepartments = $withDepartments;
        return array_values(array_filter($this->rows, function ($r) { return $r->active === '1'; }));
    }

    public function importRows(): array
    {
        $this->note('importRows');
        return array_values(array_map(function ($r) { return clone $r; }, $this->rows));
    }

    public function setJobTitle(int $employeeId, string $title, string $now)
    {
        $this->note('setJobTitle ' . $employeeId);
        if ($this->world) $this->world->note('people setJobTitle ' . $employeeId);
        if ($this->world && $this->world->fails('people.setJobTitle')) return false;
        $r = $this->rows[$employeeId] ?? null;
        if (!$r || $r->active !== '1') return 0;
        $r->job_title = $title;
        return 1;
    }

    public function departmentIdOf(int $employeeId): ?string
    {
        $this->note('departmentIdOf ' . $employeeId);
        return isset($this->rows[$employeeId]) && $this->rows[$employeeId]->department_id !== null ? (string) $this->rows[$employeeId]->department_id : (isset($this->rows[$employeeId]) ? '' : null);
    }

    /** @var int */
    public $nextId = 500;
    /** @var string */
    public $error = '';

    private function wnote(string $c): void
    {
        $this->note($c);
        if ($this->world) $this->world->note('people ' . $c);
    }

    private function wfails(string $op): bool
    {
        return $this->world !== null && $this->world->fails('people.' . $op);
    }

    public function former(int $id): ?object
    {
        $this->wnote('former ' . $id);
        $r = $this->rows[$id] ?? null;
        return $r ? (object) ['active' => $r->active, 'department_id' => $r->department_id, 'wp_user_id' => $r->wp_user_id, 'last_working_day' => $r->last_working_day ?? null, 'default_shift_id' => $r->default_shift_id] : null;
    }

    public function status(int $id): ?object
    {
        $this->wnote('status ' . $id);
        $r = $this->rows[$id] ?? null;
        return $r ? (object) ['active' => $r->active, 'wp_user_id' => $r->wp_user_id] : null;
    }

    public function domainOwner(string $domain, int $exceptId): ?string
    {
        $this->wnote('domainOwner ' . $domain . ' ' . $exceptId);
        foreach ($this->rows as $id => $r) if ($id !== $exceptId && $r->domain_name === $domain) return (string) $id;
        return null;
    }

    public function userOwner(int $userId, int $exceptId): ?string
    {
        $this->wnote('userOwner ' . $userId . ' ' . $exceptId);
        foreach ($this->rows as $id => $r) if ($id !== $exceptId && (int) $r->wp_user_id === $userId) return (string) $id;
        return null;
    }

    public function insert(array $row)
    {
        $this->wnote('insert ' . ($row['domain_name'] ?? ''));
        if ($this->wfails('insert')) { $this->error = 'insert failed'; return false; }
        $this->nextId++;
        $this->rows[$this->nextId] = (object) ($row + ['id' => (string) $this->nextId, 'active' => '1', 'last_working_day' => null]);
        return 1;
    }

    public function insertId(): int
    {
        return $this->nextId;
    }

    public function updateFields(int $id, array $fields)
    {
        $this->wnote('updateFields ' . $id . ' ' . implode(',', array_keys($fields)));
        if ($this->wfails('updateFields')) { $this->error = 'update failed'; return false; }
        if (!isset($this->rows[$id])) return 0;
        foreach ($fields as $k => $v) $this->rows[$id]->$k = $v === null ? null : (string) $v;
        return 1;
    }

    public function archive(int $id, string $lastDay, string $now)
    {
        $this->wnote('archive ' . $id . ' ' . $lastDay);
        if ($this->wfails('archive')) { $this->error = 'archive failed'; return false; }
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id]->active = '0';
        $this->rows[$id]->last_working_day = $lastDay;
        return 1;
    }

    public function setPicture(int $id, string $type, string $url, ?string $key)
    {
        $this->wnote('setPicture ' . $id . ' ' . $type);
        if ($this->wfails('setPicture')) { $this->error = 'picture failed'; return false; }
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id]->profile_image_type = $type;
        $this->rows[$id]->profile_image_url = $url;
        $this->rows[$id]->avatar_key = $key;
        return 1;
    }

    private function matching(string $search): array
    {
        return array_values(array_filter($this->rows, function ($r) use ($search) {
            return $search === '' || stripos($r->name, $search) !== false || stripos($r->domain_name, $search) !== false || stripos((string) ($r->email ?? ''), $search) !== false;
        }));
    }

    public function adminCount(string $search): int
    {
        $this->wnote('adminCount ' . $search);
        return count($this->matching($search));
    }

    public function adminPage(string $search, int $perPage, int $offset): array
    {
        $this->wnote('adminPage ' . $search . ' ' . $perPage . ' ' . $offset);
        $rows = $this->matching($search);
        usort($rows, function ($a, $b) { return [$b->active, $a->name] <=> [$a->active, $b->name]; });
        return array_slice($rows, $offset, $perPage);
    }

    public function pickList(): array
    {
        $this->wnote('pickList');
        $rows = array_values($this->rows);
        usort($rows, function ($a, $b) { return strcmp($a->name, $b->name); });
        return $rows;
    }

    public function activeTitles(): array
    {
        $this->wnote('activeTitles');
        $t = [];
        foreach ($this->rows as $r) if ($r->active === '1' && !empty($r->job_title)) $t[] = (string) $r->job_title;
        $t = array_values(array_unique($t));
        sort($t);
        return $t;
    }

    public function contactList(): array
    {
        $this->wnote('contactList');
        $rows = array_values(array_filter($this->rows, function ($r) { return $r->active === '1'; }));
        usort($rows, function ($a, $b) { return strcmp($a->name, $b->name); });
        return $rows;
    }

    public function directory(string $search, ?array $ids): array
    {
        $this->wnote('directory ' . $search . ' ' . ($ids === null ? '-' : implode(',', $ids)));
        $rows = array_values(array_filter($this->matching($search), function ($r) use ($ids) { return $r->active === '1' && ($ids === null || in_array((int) $r->id, $ids, true)); }));
        usort($rows, function ($a, $b) { return strcmp($a->name, $b->name); });
        return array_slice($rows, 0, 500);
    }

    public function publicProfile(int $id): ?object
    {
        $this->wnote('publicProfile ' . $id);
        $r = $this->rows[$id] ?? null;
        return $r && $r->active === '1' ? clone $r : null;
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
