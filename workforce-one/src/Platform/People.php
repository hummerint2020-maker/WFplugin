<?php
namespace WorkforceOne\Platform;

if (!defined('ABSPATH')) exit;

/** A port onto the People module, for the modules that need to know who someone is (they never read its table). */
interface People
{
    /** An active employee's name and WordPress user, or null (archived or unknown). */
    public function activeBrief(int $employeeId): ?object;

    /** An active employee's whole row, or null. */
    public function activeEmployee(int $employeeId): ?object;
}
