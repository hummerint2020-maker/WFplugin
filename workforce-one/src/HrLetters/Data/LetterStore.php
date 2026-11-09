<?php
namespace WorkforceOne\HrLetters\Data;

if (!defined('ABSPATH')) exit;

/**
 * The HR letters (ews_hr_letters; docs/tasks/hr-letter-hl3.md §1). Every change of status is a conditional UPDATE on the status it leaves, so
 * two people (or two tabs) never both win; the caller checks that exactly one row changed. No method computes a time: the caller gives it.
 * Writes return the rows changed (0 when the condition no longer held) or false on a database error.
 */
interface LetterStore
{
    /** @param array<string,mixed> $row @return int the new id, 0 when the INSERT failed (see lastWasDuplicate) */
    public function insert(array $row): int;

    /** Whether the last insert failed because the employee already has the same request open (the UNIQUE open_key). */
    public function lastWasDuplicate(): bool;

    public function find(int $id): ?object;

    /** @return object[] the employee's letters, newest first */
    public function forEmployee(int $employeeId, int $limit): array;

    /** Whether the employee has a request of this type and language still open (waiting or approved). */
    public function openExists(int $employeeId, string $typeKey, string $language): bool;

    /** The final decision on a waiting request: approved (it stays open) or rejected. @return int|false */
    public function markDecided(int $id, string $status, string $note, string $now);

    /** The employee withdraws their own waiting request. @return int|false */
    public function withdraw(int $id, int $employeeId, string $now);

    /** A file is attached to a decided letter with none: issued. @param array{key:string,name:string,source:string,size:int} $file @return int|false */
    public function claimIssued(int $id, array $file, int $by, string $now);

    /** An issued letter's file is replaced (only while it is still $oldKey); the employee's next download counts as new. @param array{key:string,name:string,source:string,size:int} $file @return int|false */
    public function replaceFile(int $id, string $oldKey, array $file, int $by, string $now);

    /** Why a decided letter could not be made (template_missing, no_salary, render, write; '' clears it). @return int|false */
    public function setIssueError(int $id, string $code);

    /** Request only: ready to collect, with where (optional). @return int|false */
    public function markReady(int $id, int $by, string $now, string $note);

    /** HR cancels a decided letter with no file, with the reason. @return int|false */
    public function cancel(int $id, int $by, string $now, string $note);

    /** The owner's first download. @return int|false 1 the first time, 0 after */
    public function markDownloaded(int $id, string $now);
}
