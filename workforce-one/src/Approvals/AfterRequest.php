<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** Work the engine leaves for the end of the page (3.31.128): the plugin runs it at `shutdown`, as the old code did. */
interface AfterRequest
{
    /** Runs the substitutes sync once at the end of the page. */
    public function syncSubstitutes(): void;

    /** At the end of the page, tells the administrators if the level still waits for them (the caller may have rolled back). */
    public function administratorsIfStillWaiting(int $approvalRequestId, int $stepOrder): void;
}
