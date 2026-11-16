<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** The approval engine as the correction requests use it (3.31.143): workflow attendance_correction, entity attendance_correction. */
interface CorrectionApprovals
{
    /** The workflow's mode ('' when it is not active: the managers decide as before). */
    public function mode(): string;

    /** Whether a mode has approval levels. */
    public function isLevels(string $mode): bool;

    /** Starts the approval of a request (its own transaction). @return bool false when it could not start */
    public function start(int $requestId, int $employeeId): bool;

    /** The approval request of a correction, or null. */
    public function find(int $requestId): ?object;

    /** The level that waits now, or null. */
    public function currentStep(int $approvalRequestId): ?object;

    /** Cancels the open approval of a correction (inside the caller's transaction). @return true|string true, or moved | an error */
    public function closeOpen(int $requestId);
}
