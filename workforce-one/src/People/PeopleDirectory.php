<?php
namespace WorkforceOne\People;

use WorkforceOne\People\Data\PeopleStore;
use WorkforceOne\Platform\People;

if (!defined('ABSPATH')) exit;

/**
 * Who the employees are (3.31.133, docs/tasks/people-store.md): the people module's reads for the rest of the plugin, over the
 * employees Store and the request memos. It holds no SQL, never sees the plugin object, and is the Platform\People port the
 * Schedule services use. Every method does what the plugin's people_* function did, with the same guards and casts.
 */
final class PeopleDirectory implements People
{
    /** @var PeopleStore */
    private $store;
    /** @var PeopleMemo */
    private $memo;

    public function __construct(PeopleStore $store, PeopleMemo $memo)
    {
        $this->store = $store;
        $this->memo = $memo;
    }

    /** One employee, active or not (null for an id that is not positive: nothing is read). */
    public function employee(int $id, bool $activeOnly = false): ?object
    {
        if ($id <= 0) return null;
        return $this->store->byId($id, $activeOnly);
    }

    /** The active employee linked to a WordPress user (the lowest id when two are). */
    public function employeeByUser(int $userId): ?object
    {
        if ($userId <= 0) return null;
        return $this->store->activeByUser($userId);
    }

    public function activeBrief(int $employeeId): ?object
    {
        return $this->store->activeBrief($employeeId);
    }

    public function activeEmployee(int $employeeId): ?object
    {
        return $this->employee($employeeId, true);
    }

    /**
     * The names of the active employees linked to these users, user id => name; a user linked to two gets the lowest id's.
     * @param array<int,int> $userIds @return array<int,string>
     */
    public function namesByUser(array $userIds): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        foreach ($this->store->activeNamesByUsers($ids) as $e) {
            if (!isset($out[(int) $e->wp_user_id])) $out[(int) $e->wp_user_id] = (string) $e->name;
        }
        return $out;
    }

    /** Whole rows by id. @param array<int,int> $ids @return array<int,object> */
    public function byIds(array $ids, bool $activeOnly = false): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        foreach ($this->store->rowsByIds($ids, $activeOnly) as $e) $out[(int) $e->id] = $e;
        return $out;
    }

    /** id, name and WordPress user by id. @param array<int,int> $ids @return array<int,object> */
    public function briefsByIds(array $ids): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        foreach ($this->store->briefsByIds($ids) as $e) $out[(int) $e->id] = $e;
        return $out;
    }

    /**
     * The names of many employees at once, id => name (archived ones too): the shared read for "a name next to a row", built on
     * briefsByIds so it costs one query per 500 ids.
     * @param array<int,int> $ids @return array<int,string>
     */
    public function namesByIds(array $ids): array
    {
        $out = [];
        foreach ($this->briefsByIds($ids) as $id => $e) $out[$id] = (string) $e->name;
        return $out;
    }

    /** The id as a string when the employee exists and is active, else null. */
    public function activeId(int $employeeId): ?string
    {
        return $this->store->activeId($employeeId);
    }

    public function name(int $employeeId): ?string
    {
        return $this->store->name($employeeId);
    }

    public function userId(int $employeeId, bool $activeOnly = false): ?string
    {
        return $this->store->userId($employeeId, $activeOnly);
    }

    /** @return list<int> */
    public function departmentMemberIds(int $departmentId): array
    {
        return array_map('intval', $this->store->activeIdsInDepartment($departmentId));
    }

    public function inDepartment(int $employeeId, int $departmentId): bool
    {
        return (bool) $this->store->activeInDepartment($employeeId, $departmentId);
    }

    /** The shift an employee is on now (0 = company hours), read once per request. */
    public function shiftId(int $employeeId): int
    {
        if (isset($this->memo->shift[$employeeId])) return $this->memo->shift[$employeeId];
        return $this->memo->shift[$employeeId] = (int) $this->store->shiftIdOf($employeeId);
    }

    /**
     * The shift many employees are on now, in one query per 500, kept for the request as shiftId() keeps it. An employee row that
     * carries default_shift_id is not read again.
     * @param array<int,object|int> $people @return array<int,int> by id, in the order given
     */
    public function shiftIds(array $people): array
    {
        $ids = [];
        $missing = [];
        foreach ($people as $p) {
            $id = is_object($p) ? (int) ($p->id ?? 0) : (int) $p;
            if ($id <= 0) continue;
            $ids[$id] = $id;
            if (isset($this->memo->shift[$id])) continue;
            if (is_object($p) && property_exists($p, 'default_shift_id')) $this->memo->shift[$id] = (int) $p->default_shift_id;
            else $missing[$id] = $id;
        }
        if ($missing) {
            foreach ($this->store->shiftIdsOf(array_values($missing)) as $r) $this->memo->shift[(int) $r->id] = (int) $r->default_shift_id;
            foreach ($missing as $id) if (!isset($this->memo->shift[$id])) $this->memo->shift[$id] = 0;   // as shiftId() for an unknown id
        }
        $out = [];
        foreach ($ids as $id) $out[$id] = $this->memo->shift[$id];
        return $out;
    }

    /** The shift ids as stored for these employees, no memo: id => shift id, for the employees found. @param list<int> $ids @return array<int,int> */
    public function storedShiftIds(array $ids): array
    {
        $out = [];
        foreach ($this->store->shiftIdsOf($ids) as $r) $out[(int) $r->id] = (int) $r->default_shift_id;
        return $out;
    }

    /** The shift id read from the row now, no memo. */
    public function shiftIdNow(int $employeeId): int
    {
        return (int) $this->store->shiftIdNow($employeeId);
    }

    /** Set the shift an employee is on now. The memo is forgotten before the write, so a failed write leaves nothing stale. @return bool false on a database error */
    public function setShiftId(int $employeeId, int $shiftId): bool
    {
        $this->memo->forgetShift($employeeId);
        return $this->store->setShiftId($employeeId, $shiftId);
    }

    /** Every employee (archived too) with the shift they are on now. @return array<int,int> id => shift id */
    public function allShiftIds(): array
    {
        $out = [];
        foreach ($this->store->allShiftIds() as $r) $out[(int) $r->id] = (int) $r->default_shift_id;
        return $out;
    }

    /** @return object[] */
    public function activeList(int $limit = 0): array
    {
        return $this->store->activeList($limit);
    }

    /** @return object[] */
    public function activeUsers(): array
    {
        return $this->store->activeUsers();
    }

    /** @return string[] */
    public function activeIds(bool $attendanceOnly = false): array
    {
        return $this->store->activeIds($attendanceOnly);
    }

    /** @return string[] */
    public function activeUserIds(int $departmentId = 0): array
    {
        return $this->store->activeUserIds($departmentId);
    }

    public function activeCount(int $departmentId = 0, bool $withUser = false): int
    {
        return $this->store->activeCount($departmentId, $withUser);
    }

    /** Has the active list been read in this request? */
    public function activeAllLoaded(): bool
    {
        return $this->memo->active !== null;
    }

    /** Every active employee, whole rows, by name; read once per request (a failed read is not remembered). @return array|null */
    public function activeAll(): ?array
    {
        if ($this->memo->active !== null) return $this->memo->active;
        return $this->memo->active = $this->store->activeAll();
    }

    /** Is the employee's attendance tracked (unknown or NULL counts as yes)? */
    public function attendanceEnabled(int $employeeId): bool
    {
        if ($employeeId <= 0) return false;
        $value = $this->store->attendanceEnabledValue($employeeId);
        return $value === null ? true : ((int) $value === 1);
    }

    /** An employee was written: forget what the memos hold for them. */
    public function forget(int $employeeId = 0): void
    {
        $this->memo->forget($employeeId);
    }
}
