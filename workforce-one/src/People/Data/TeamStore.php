<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** The teams and their members as the other modules read them (3.31.133). Same SQL as before; raw wpdb values. */
interface TeamStore
{
    /** The active manager of the employee's first active team, with team_id and team_name; or null. */
    public function activeManagerFor(int $employeeId): ?object;

    /** @return list<string> */
    public function activeMemberIds(int $teamId): array;

    /** @return list<string> */
    public function activeTeamIdsOf(int $employeeId): array;

    /** employee_id and team_id of the active memberships of these employees, by team (chunks of 500). @param list<int> $ids @return list<object> */
    public function activeTeamRowsByEmployees(array $ids): array;

    /** Is the teams table there (SHOW TABLES LIKE)? */
    public function tableExists(): bool;

    public function activeCountInDepartment(int $departmentId): int;

    /** One active team row by id, or null. */
    public function activeById(int $id): ?object;

    /** The active teams for the admin page, with the manager's and department's names and the member count, by name. @return list<object> */
    public function adminList(): array;

    /** The active employees for the team form, with their department's name, by name. @return list<object> */
    public function pageEmployees(): array;

    /** The active departments for the team form, by name. @return list<object> */
    public function pageDepartments(): array;

    /** The `active` flags of the other teams that share this name. @return list<string> */
    public function duplicateFlags(string $name, int $exceptId): array;

    /** @return int|false rows changed */
    public function update(int $id, string $name, string $description, int $manager, int $departmentId, string $now);

    /** @return int|false */
    public function insert(string $name, string $description, int $manager, int $departmentId, string $now);

    public function insertId(): int;

    /** Every active member of the team becomes inactive (a statement whose result is not checked). @return int|false */
    public function deactivateMembersOfTeam(int $teamId, string $now);

    /** The id of the membership row of this employee in this team (any state), or null. */
    public function memberId(int $teamId, int $employeeId): ?string;

    /** @return int|false */
    public function reactivateMember(int $memberId, string $now);

    /** @return int|false */
    public function insertMember(int $teamId, int $employeeId, string $now);

    /** @return int|false rows changed */
    public function archive(int $id, string $now);

    /** All members of the team become inactive (the result is not checked). @return int|false */
    public function deactivateAllMembers(int $teamId, string $now);

    /** The department id of an active team (null: no such active team). */
    public function activeDepartmentOf(int $teamId): ?string;

    /** The ids of the active teams this employee manages in this department. @return list<string> */
    public function managedIn(int $employeeId, int $departmentId): array;

    /** Every active membership of this employee becomes inactive (the result is not checked). @return int|false */
    public function deactivateMembershipsOf(int $employeeId, string $now);

    /** The id of the team when it is active, or null. */
    public function activeId(int $teamId): ?string;

    public function lastError(): string;

    /** How many of these teams are active teams of this department. @param list<int> $teamIds */
    public function countActiveInDepartment(int $departmentId, array $teamIds): int;

    /** Does this employee manage an active team in a department other than this one? (a count) */
    public function managesElsewhere(int $employeeId, int $departmentId): int;

    /** The active teams for the Employees page: id, name, manager_employee_id, department_id. @return list<object> */
    public function pageTeams(): array;

    /** The active teams an employee belongs to, with their manager (the admin profile). @return list<object> */
    public function teamsOfWithManager(int $employeeId): array;

    /** The names of these teams that are active, A–Z (rows with a name). @param list<int> $ids @return list<object> */
    public function activeNameRows(array $ids): array;

    /** The names of these teams that are active, A–Z. @param list<int> $ids @return list<string> */
    public function activeNames(array $ids): array;

    /** id and name of these teams, whatever their state. @param list<int> $ids @return list<object> */
    public function namesByIds(array $ids): array;

    /** id and name of the active teams, A–Z. @return list<object> */
    public function activeList(): array;
}
