<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port to Payroll's salary advances: is the feature on, and what an employee still owes. Two methods so the feature check stays first. */
interface OpenAdvances
{
    public function enabled(): bool;

    public function leftFor(int $employeeId): float;
}
