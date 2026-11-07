<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** The messages the approvals engine sends (3.31.128). The words, links and the inbox stay in the plugin; this is the port. */
interface ApprovalNotifier
{
    /** Tells the site's administrators that a level waits for them, and why (an AdminReason code; QA 8). */
    public function administratorsNeeded(int $approvalRequestId, int $stepOrder, string $reason = '', int $requesterEmployeeId = 0): void;
}
