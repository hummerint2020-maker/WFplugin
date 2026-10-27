<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** What the engine asks of the substitutes (a manager away with someone covering, 3.31.107), without knowing how they are stored. */
interface SubstituteRouting
{
    /**
     * The substitute a manager's level goes to, or null.
     * @return array{row:object,employee:object}|null
     */
    public function route(int $managerEmployeeId, int $requesterEmployeeId): ?array;

    /** Whether the manager, away, may still decide a level that waits for their substitute. */
    public function managerMayDecide(object $step, int $userId): bool;

    /** A level was decided: counts it when a substitute decided, and schedules the sync. */
    public function afterDecision(object $step, int $userId): void;
}
