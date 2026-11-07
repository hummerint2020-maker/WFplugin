<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * The decisions a request may need at once (3.31.143, until L5e3 moves them): the manager stage of a request approved with no approval needed,
 * and HR's own correction, applied when it is made.
 */
interface CorrectionDecider
{
    /** @return string approved | pending_hr, or an error code */
    public function afterManager(int $requestId): string;

    /** @return string approved, or an error code */
    public function finalizeDirect(int $requestId, string $reason): string;
}
