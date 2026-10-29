<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port to Schedule's shift history (shift_period_add): a shift for an employee from a day on, kept in the history. The adapter holds Schedule's constants. */
interface ShiftHistory
{
    /** The employee's first shift (the beginning of the history). @return true|string true when saved, else the reason */
    public function fromBeginning(int $employeeId, int $shiftId);

    /** The shift from this day on ('Y-m-d'); the days before keep the old one. @return true|string true when saved, else the reason */
    public function from(int $employeeId, int $shiftId, string $date);
}
