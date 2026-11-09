<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/** An employee's salary on a day (HL3), from Payroll: basic + allowances, as for salary advances. Read only when a letter shows it; never stored. */
interface LetterSalary
{
    /** @return array{basic:float,allowances:float,total:float}|null null when no pay rate is on file */
    public function on(int $employeeId, string $date): ?array;
}
