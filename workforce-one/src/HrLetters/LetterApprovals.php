<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/** The "HR Letter" approval workflow as the HR letters reach it (HL3): the approvals engine, with the current person as the actor. */
interface LetterApprovals
{
    /** The workflow is set; the first request sets it to one level, HR, with HR's own letter decided by the administrators and a reason to reject. */
    public function ensureReady(): bool;

    /** Starts the approval of a new letter inside the caller's transaction. */
    public function start(int $letterId, int $employeeId): bool;

    /** The letter's approval request (status PENDING, APPROVED, REJECTED, CANCELLED), or null. */
    public function find(int $letterId): ?object;

    /** The open level (its approver_wp_user_id), or null. */
    public function currentStep(int $approvalRequestId): ?object;

    /** The level the page showed is no longer the open one. @param int|null $posted */
    public function levelMoved(int $approvalRequestId, $posted): bool;

    /** The current person may decide the open level (or, with $override, an administrator). */
    public function canAct(int $approvalRequestId, bool $override): bool;

    /**
     * A decision inside the caller's transaction. @param int|null $posted the level the page showed
     * @return string '' when done, else note | moved | approval
     */
    public function act(int $approvalRequestId, string $decision, string $note, $posted, bool $override): string;

    /** Cancels the letter's open approval (a withdrawal), inside the caller's transaction. @return true|string|false true, 'moved', or false on a database error */
    public function closeOpen(int $letterId);

    /** The words of the approval path (the reason a level gave), for the employee. */
    public function pathNote(int $letterId): string;
}
