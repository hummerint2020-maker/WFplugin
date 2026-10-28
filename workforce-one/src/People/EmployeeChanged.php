<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port: an employee's row or reporting line changed, so what the plugin keeps about them for this request is dropped (invalidate_employee_runtime_cache). */
interface EmployeeChanged
{
    public function changed(int $employeeId, int $wpUserId): void;
}
