<?php
namespace WorkforceOne\Attendance\Data;

use WorkforceOne\Attendance\EmployeeLock;

if (!defined('ABSPATH')) exit;

/**
 * MySQL / MariaDB GET_LOCK (3.31.137), named per site and employee, released at the end of the request or when the connection closes;
 * no table or privilege needed, available on shared hosting. Where it is not available (SQLite, some clusters) the request goes on
 * unlocked and AttendanceService's check after the insert keeps the first row only. The name is built on every call from the table
 * prefix, as the old code did.
 */
final class WordPressEmployeeLock implements EmployeeLock
{
    /** @var \wpdb */
    private $db;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
    }

    public function lock(int $employeeId, int $waitSeconds = 5): bool
    {
        if (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') return false;
        $prev = $this->db->suppress_errors(true);
        $got = $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,%d)', $this->name($employeeId), $waitSeconds));
        $this->db->suppress_errors($prev);
        return (string) $got === '1';
    }

    public function unlock(int $employeeId): void
    {
        $prev = $this->db->suppress_errors(true);
        $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $this->name($employeeId)));
        $this->db->suppress_errors($prev);
    }

    /** Lock names are server-wide: include the site (database + table prefix). At most 64 characters. */
    private function name(int $employeeId): string
    {
        return 'wfo_att_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $this->db->prefix), 0, 12) . '_' . $employeeId;
    }
}
