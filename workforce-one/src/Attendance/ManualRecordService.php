<?php
namespace WorkforceOne\Attendance;

use WorkforceOne\Attendance\Data\BreakStore;
use WorkforceOne\Attendance\Data\TimeLogStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\People;
use WorkforceOne\Schedule\ScheduleDayService;

if (!defined('ABSPATH')) exit;

/**
 * The Sign In / Out report's manual records (3.31.138; docs/tasks/attendance-auto-manual.md): add, edit and Reset Day. The steps and their order are those
 * of the old trait; the validation is ManualRecordRules'. Quirks AT-h, AT-i, AT-u, AT-v, AT-w, AT-z are moved as they are: no lock and no check after the
 * insert, an edit may rewrite any row, a Reset deletes the day's rows (correction rows too) and breaks in two statements.
 */
final class ManualRecordService
{
    /** @var TimeLogStore */
    private $logs;
    /** @var BreakStore */
    private $breaks;
    /** @var ScheduleDayService */
    private $days;
    /** @var People */
    private $people;
    /** @var AttendanceSite */
    private $site;
    /** @var ManualRecordSite */
    private $manual;
    /** @var AuditLog */
    private $audit;

    public function __construct(TimeLogStore $logs, BreakStore $breaks, ScheduleDayService $days, People $people, AttendanceSite $site, ManualRecordSite $manual, AuditLog $audit)
    {
        $this->logs = $logs;
        $this->breaks = $breaks;
        $this->days = $days;
        $this->people = $people;
        $this->site = $site;
        $this->manual = $manual;
        $this->audit = $audit;
    }

    /**
     * @param int $id the record to edit, 0 to add
     * @param bool $dateValid the work date is a real date
     * @return array{error:?string,id:int} error is the ?time_error= code, or null when the record was saved
     */
    public function save(int $id, int $employeeId, string $workDate, string $type, ?string $eventAt, bool $dateValid, ?float $lat, ?float $lng, ?float $acc, string $label, int $actorId): array
    {
        $existing = $id ? $this->logs->idEmployee($id) : null;
        if ($id && !$existing) return ['error' => 'not_found', 'id' => $id];
        // Both the record's employee and the chosen one must be in the manager's department (3.31.90).
        if (($existing && !$this->manual->mayManage((int) $existing->employee_id)) || ($employeeId && !$this->manual->mayManage($employeeId))) return ['error' => 'scope', 'id' => $id];
        // A new record needs an active employee; an existing record may belong to an archived one.
        $emp = $employeeId ? ($id ? $this->site->employee($employeeId) : $this->people->activeEmployee($employeeId)) : null;
        $facts = ['type' => $type, 'date_valid' => $dateValid, 'work_date' => $workDate, 'event_at' => $eventAt, 'employee_found' => (bool) $emp];
        // A shift that ends after midnight: its Sign Out may be on the next calendar day (3.31.92).
        if ($emp) {
            $h = $this->manual->workingHours((int) $emp->id, $dateValid ? $workDate : '');
            $facts['overnight'] = isset($h['start'], $h['end']) && (string) $h['end'] <= (string) $h['start'];
        }
        if (!ManualRecordRules::check($facts)) {
            $others = $this->logs->othersOn($employeeId, $workDate, $id);
            $facts['others'] = array_map(function ($o) { return ['type' => $o->event_type, 'at' => $o->event_at]; }, $others);
        }
        if ($error = ManualRecordRules::check($facts)) return ['error' => $error, 'id' => $id];

        [$siteLat, $siteLng, $radius] = $this->manual->workSite($employeeId);
        [$locationStatus, $distance] = LocationAssessment::geofence($lat, $lng, $siteLat, $siteLng, $radius);
        $sch = $this->days->day($employeeId, $workDate);
        $data = ['employee_id' => $employeeId, 'work_date' => $workDate, 'event_type' => $type, 'event_at' => $eventAt, 'scheduled_status' => $sch ? $sch->status : 'Not Scheduled', 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $acc, 'location_status' => $locationStatus, 'distance_meters' => $distance];
        if ($id) {
            $ok = $this->logs->update($id, $data);
            $action = 'time_manual_edit';
            $detail = 'Admin edited ' . $label . ' for ' . $emp->name . ' on ' . $workDate;
        } else {
            $data['user_id'] = $emp->wp_user_id ? (int) $emp->wp_user_id : $actorId;
            $data['source'] = 'admin';
            $ok = $this->logs->insert($data) > 0;
            $id = $this->logs->lastInsertId();   // after a failed insert this is the previous insert's id (AT-h)
            $action = 'time_manual_add';
            $detail = 'Admin added ' . $label . ' for ' . $emp->name . ' on ' . $workDate;
        }
        if (!$ok) {
            $this->audit->record($action . '_failed', 'time_log', $id, $this->logs->lastError() ?: 'Database error');
            return ['error' => 'save', 'id' => $id];
        }
        $this->audit->record($action, 'time_log', $id, $detail);
        return ['error' => null, 'id' => $id];
    }

    /**
     * Reset Day: deletes the employee's rows and breaks on a day, and the cron events of those breaks.
     * @return array{0:int,1:int} the time-log rows and the break rows deleted
     */
    public function reset(int $employeeId, string $date): array
    {
        $deleted = $this->logs->deleteDay($employeeId, $date);
        // Breaks belong to the attendance day too: clear them (and their pending reminders).
        $this->site->ensureBreakTable();
        foreach ($this->breaks->idsOn($employeeId, $date) as $breakId) $this->site->clearBreakEvents((int) $breakId);
        $breakDeleted = $this->breaks->deleteDay($employeeId, $date);
        $this->audit->record('time_reset', 'time_log', $employeeId, 'Reset attendance and break records for ' . $date . ' (time_logs=' . $deleted . ', breaks=' . $breakDeleted . ')');
        return [$deleted, $breakDeleted];
    }
}
