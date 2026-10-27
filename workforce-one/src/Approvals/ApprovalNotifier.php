<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** The messages the approvals engine sends (3.31.128). The words, links and the inbox stay in the plugin; this is the port. */
interface ApprovalNotifier
{
    /** Tells the site's administrators that a level waits for them. */
    public function administratorsNeeded(int $approvalRequestId, int $stepOrder): void;
}
