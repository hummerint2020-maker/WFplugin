<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** The Auto Attendance page's rule list (3.31.139): a read model that joins the rules with the employees (docs/architecture/modules.json "reads"). It writes nothing. */
interface AutoRuleQuery
{
    /** @return object[] every rule of an employee that still exists, with the employee's name and domain, switched-on ones first */
    public function listWithNames(): array;
}
