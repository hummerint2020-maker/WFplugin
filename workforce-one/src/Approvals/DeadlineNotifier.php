<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** The messages the approval deadlines send (3.31.128). The words, the links and the inbox stay in the plugin. */
interface DeadlineNotifier
{
    /** The approver is reminded that a request has waited $remindHours working hours. */
    public function reminded(object $step, int $remindHours): void;

    /**
     * A level moved up: the old approver is told, then the new one ($up) or the administrators.
     * @param object|null $up the new approver, or null when it went to the administrators
     */
    public function movedUp(int $fromUserId, object $step, ?object $up, string $requesterName): void;
}
