<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port to the approvals module for a change of manager: the open requests move to the new manager, and the new manager is told. */
interface OrgApprovals
{
    /**
     * Moves the employee's waiting requests from the old manager to the new one (approval_reassign_supervisor).
     *
     * @param int $pending set to the number of requests that now wait for the new manager
     * @return int|false false when a write failed
     */
    public function reassignSupervisor(int $employeeId, int $fromEmployeeId, int $toEmployeeId, int &$pending);

    /** Tells the new manager about the moved requests waiting for them (after the move is saved). */
    public function tellNewManager(int $employeeId, int $toEmployeeId, int $pending): void;
}
