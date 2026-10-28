<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** The departments table as the other modules read it (3.31.133). Same SQL as before. */
interface DepartmentStore
{
    public function tableExists(): bool;

    /** The active manager (an active employee) of an employee's active department, or null. */
    public function activeManagerOf(int $employeeId): ?object;

    /** One department row by id (any state), or null. */
    public function byId(int $id): ?object;

    /** The active departments for the admin page, with the manager's name and the active employee count, by name. @return list<object> */
    public function adminList(): array;

    /** The `active` flags of the departments that share this name or code with another department. @return list<string> */
    public function duplicateFlags(string $name, string $code, int $exceptId): array;

    /** @return int|false rows changed */
    public function update(int $id, string $name, string $code, string $description, int $manager, string $now);

    /** @return int|false */
    public function insert(string $name, string $code, string $description, int $manager, string $now);

    public function insertId(): int;

    /** @return int|false rows changed */
    public function archive(int $id, string $now);

    public function activeEmployeeCount(int $departmentId): int;

    /** The id of the department when it is active, or null. */
    public function activeId(int $id): ?string;
}
