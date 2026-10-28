<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/**
 * The employees table (3.31.133, docs/tasks/people-store.md). Every method is one query the plugin ran before, with the same SQL.
 * Return values are wpdb's, raw (strings and nulls), except where noted: the callers' casts live in the services.
 */
interface PeopleStore
{
    public function byId(int $id, bool $activeOnly): ?object;

    public function activeByUser(int $userId): ?object;

    /** Name and user of the active employees linked to these users, in the order the chunks of 500 return them. @param list<int> $userIds @return list<object> */
    public function activeNamesByUsers(array $userIds): array;

    /** Whole rows for these ids, in the order the chunks of 500 return them. @param list<int> $ids @return list<object> */
    public function rowsByIds(array $ids, bool $activeOnly): array;

    /** id, name and WordPress user for these ids (chunks of 500). @param list<int> $ids @return list<object> */
    public function briefsByIds(array $ids): array;

    public function activeId(int $id): ?string;

    public function name(int $id): ?string;

    public function userId(int $id, bool $activeOnly): ?string;

    /** @return list<string> */
    public function activeIdsInDepartment(int $departmentId): array;

    public function activeInDepartment(int $employeeId, int $departmentId): ?string;

    /** An active employee's name and WordPress user. */
    public function activeBrief(int $id): ?object;

    public function shiftIdOf(int $id): ?string;

    /** id and default_shift_id of these ids (chunks of 500). @param list<int> $ids @return list<object> */
    public function shiftIdsOf(array $ids): array;

    /** The shift id as the employee row has it now (one row, LIMIT 1). */
    public function shiftIdNow(int $id): ?string;

    /** false on a database error. */
    public function setShiftId(int $employeeId, int $shiftId): bool;

    /** @return list<object> id and default_shift_id of every employee */
    public function allShiftIds(): array;

    /** @return list<object> */
    public function activeList(int $limit): array;

    /** @return list<object> */
    public function activeUsers(): array;

    /** @return list<string> */
    public function activeIds(bool $attendanceOnly): array;

    /** @return list<string> */
    public function activeUserIds(int $departmentId): array;

    public function activeCount(int $departmentId, bool $withUser): int;

    /** Every active employee, whole rows, by name; null when the query failed. @return list<object>|null */
    public function activeAll(): ?array;

    public function attendanceEnabledValue(int $id): ?string;

    /** The active employees as the org chart shows them, by name. @return list<object> */
    public function orgRows(bool $withDepartments): array;

    /** Every employee (any state) for the managers import: id, name, domain_name, job_title, active. @return list<object> */
    public function importRows(): array;

    /** Sets the job title of an active employee. @return int|false rows changed */
    public function setJobTitle(int $employeeId, string $title, string $now);

    /** The employee's department id (null: no such employee; "" or null column: none). */
    public function departmentIdOf(int $employeeId): ?string;

    /** The employee as the update form needs it before a change: active, department, user, last working day, shift. */
    public function former(int $id): ?object;

    /** An employee's state for an archive: active and user. */
    public function status(int $id): ?object;

    /** The id of another employee with this domain name, or null. */
    public function domainOwner(string $domain, int $exceptId): ?string;

    /** The id of another employee linked to this WordPress user, or null. */
    public function userOwner(int $userId, int $exceptId): ?string;

    /** Adds an employee; the fields are sent as given (no formats). @param array<string,mixed> $row @return int|false */
    public function insert(array $row);

    public function insertId(): int;

    /** Changes the given fields of one employee. @param array<string,mixed> $fields @return int|false rows changed */
    public function updateFields(int $id, array $fields);

    /** Archives: inactive, with the last working day. @return int|false */
    public function archive(int $id, string $lastDay, string $now);

    /** The profile picture fields ($key null clears the avatar key). @return int|false */
    public function setPicture(int $id, string $type, string $url, ?string $key);

    /** How many employees match the search (name, domain or email), any state. */
    public function adminCount(string $search): int;

    /** One page of the Employees list, active first, by name. @return list<object> */
    public function adminPage(string $search, int $perPage, int $offset): array;

    /** Every employee, any state, for the supervisor pick list: id, name, domain_name, active. @return list<object> */
    public function pickList(): array;

    /** The job titles of the active employees, A–Z (at most 300). @return list<string> */
    public function activeTitles(): array;

    /** The active employees for the app's Employees view: id, name, domain_name, email. @return list<object> */
    public function contactList(): array;

    /** The people directory page: active employees by name, filtered by a search and/or a list of ids (at most 500). @param list<int>|null $ids @return list<object> */
    public function directory(string $search, ?array $ids): array;

    /** The public profile fields of an active employee, or null. */
    public function publicProfile(int $id): ?object;

    public function lastError(): string;
}
