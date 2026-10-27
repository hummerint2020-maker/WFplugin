<?php
namespace WorkforceOne\Approvals\Data;

if (!defined('ABSPATH')) exit;

/** Where the approval substitutes (a manager away, someone deciding for them) are kept (3.31.128). */
interface SubstituteStore
{
    /** The substitute deciding for this manager at $now (scheduled ones whose time came count), or null. */
    public function activeFor(int $managerEmployeeId, string $now): ?object;

    /** Substitutes tied to a leave request that are not over. @return array<int,object> */
    public function linkedToLeave(): array;

    /** Scheduled and active substitutes, earliest start first. @return array<int,object> */
    public function current(): array;

    /** Active substitutes. @return array<int,object> */
    public function active(): array;

    public function find(int $id): ?object;

    /**
     * Moves a substitute from a status to another, only if it still has the status that was read.
     * @param array<string,mixed> $set
     * @return int|false rows changed (1 = done), false on a database error
     */
    public function transition(int $id, string $from, array $set);

    /** Counts a decision made by the substitute. */
    public function incrementDecided(int $id): void;

    public function decided(int $id): int;

    /** @param array<string,mixed> $row @return int|false the new id */
    public function insert(array $row);

    /** Another substitute of this manager overlaps the days (ignoring one). */
    public function overlapForManager(int $managerEmployeeId, string $ends, string $starts, int $ignoreId): bool;

    /** The candidate is a manager with a substitute of their own on those days. */
    public function overlapForCandidate(int $candidateEmployeeId, string $ends, string $starts): bool;

    /** Substitutes this employee decides for now, soonest end first. @return array<int,object> */
    public function forSubstitute(int $substituteEmployeeId): array;

    /** This manager's own substitutes that are not over. @return array<int,object> */
    public function ownOpen(int $managerEmployeeId, string $now): array;

    /** wp-admin → Substitutes: the first 200, active first. @return array<int,object> */
    public function adminList(): array;
}
