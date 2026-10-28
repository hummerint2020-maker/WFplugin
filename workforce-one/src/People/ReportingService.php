<?php
namespace WorkforceOne\People;

use WorkforceOne\People\Data\ReportingStore;

if (!defined('ABSPATH')) exit;

/**
 * Who reports to whom, written (3.31.134, moved from approval_set_relationship / employee_admin_set_supervisor / approval_relationship_save): the
 * employee's active line of a type is ended and a new one added. V1 keeps one current target per type. No transaction here: a caller that needs the
 * line and other writes together (a move) owns it.
 */
final class ReportingService
{
    /** @var ReportingStore */
    private $store;
    /** @var OrgClock */
    private $clock;

    public function __construct(ReportingStore $store, OrgClock $clock)
    {
        $this->store = $store;
        $this->clock = $clock;
    }

    /**
     * Creates or updates one active relationship. The caller has cleaned the values (ids as positive integers, the type as a key).
     *
     * @return int|array{code:string,message:string} the new row's id, or the error
     */
    public function setRelationship(int $employeeId, string $type, int $relatedEmployeeId, int $relatedWpUserId)
    {
        if (!$employeeId || !$type) return ['code' => 'invalid_relationship', 'message' => 'Invalid employee relationship.'];
        if (!$relatedEmployeeId && !$relatedWpUserId) return ['code' => 'invalid_target', 'message' => 'A related employee or WordPress user is required.'];
        if ($this->store->deactivate($employeeId, $type, $this->clock->now()) === false) return ['code' => 'db_error', 'message' => $this->store->lastError()];
        if ($this->store->insertLine($employeeId, $type, $relatedEmployeeId, $relatedWpUserId, $this->clock->now()) === false) return ['code' => 'db_error', 'message' => $this->store->lastError()];
        return $this->store->insertId();
    }

    /** The supervisor of an employee: set to $supervisorId, or ended when 0. false when a write failed. */
    public function setSupervisor(int $employeeId, int $supervisorId): bool
    {
        if ($supervisorId) return !is_array($this->setRelationship($employeeId, 'supervisor', $supervisorId, 0));
        return $this->store->deactivateSupervisor($employeeId, $this->clock->now()) !== false;
    }

    /** The Approvals page's save: the old line ended, then the new one added; neither result is looked at (the page says "saved" either way). */
    public function savePicked(int $employeeId, int $supervisorId): void
    {
        if (!$employeeId) return;
        $this->store->deactivateSupervisor($employeeId, $this->clock->now());
        if ($supervisorId) $this->setRelationship($employeeId, 'supervisor', $supervisorId, 0);
    }
}
