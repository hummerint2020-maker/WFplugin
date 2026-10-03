<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/** Leave balance arithmetic. Pure: no WordPress calls. */
final class Balance
{
    /** Days still free to request (can be negative if the balance was over-committed). */
    public static function remaining(float $entitlement, float $used, float $pending): float
    {
        return $entitlement - $used - $pending;
    }

    /** Remaining days, never below zero (for display). */
    public static function available(float $entitlement, float $used, float $pending): float
    {
        return max(0, self::remaining($entitlement, $used, $pending));
    }
}
