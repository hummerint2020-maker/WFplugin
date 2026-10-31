<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** What the manual records (the Sign In / Out report's add, edit and Reset Day) need from the rest of the plugin. */
interface ManualRecordSite
{
    /** May the current user change this employee's records? (The department scope; administrators may all.) */
    public function mayManage(int $employeeId): bool;

    /** @return array<string,mixed> the employee's working hours on a day ('' = today): start, end, overnight... */
    public function workingHours(int $employeeId, string $date): array;

    /** @return array{0:mixed,1:mixed,2:float} the work site Sign In uses for the employee: latitude, longitude, radius */
    public function workSite(int $employeeId): array;
}
