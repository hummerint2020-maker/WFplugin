<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Everything AttendanceService needs from the site, and nothing else: the plugin builds it from
 * its existing helpers (EWS_Attendance_Trait::attendance_context()), tests build it from fakes.
 * Each entry is a callable; the typed methods below are the contract.
 */
final class AttendanceContext
{
    public const KEYS = [
        // who and what today is
        'employee', 'attendanceEnabled', 'schedule', 'events', 'attendanceDay', 'generalLeave', 'requiresSignIn',
        'requiresLocation', 'faceRequired', 'windowOpen', 'windowBounds', 'classify',
        // where
        'assignedLocation', 'defaultSite', 'lastDeviceLocation',
        // clocks
        'localTime', 'localMysql', 'unixTime',
        // the time log
        'insertTimeLog', 'insertError', 'lastInsertId', 'firstEventId', 'deleteTimeLog',
        // side effects
        'evaluateAchievements', 'audit', 'currentUserId', 'clientIp',
        // concurrency
        'lock', 'unlock', 'idemFind', 'idemClaim', 'idemSave', 'idemRelease',
        // breaks
        'breakEnabled', 'openBreak', 'breakRemaining', 'insertBreak', 'firstOpenBreakId', 'deleteBreak',
        'scheduleBreakEvents', 'closeBreak', 'breakEnded',
    ];

    /** Entries a site may leave out (3.31.72: 'branches'; without it the assigned location decides, as before). */
    public const OPTIONAL = ['branches'];

    /** @var array<string,callable> */
    private $f;

    /** @param array<string,callable> $callables one for each of KEYS */
    public function __construct(array $callables)
    {
        $missing = array_diff(self::KEYS, array_keys($callables));
        if ($missing) throw new \InvalidArgumentException('AttendanceContext is missing: ' . implode(', ', $missing));
        $this->f = $callables;
    }

    /** @return mixed */
    private function call(string $key, ...$args)
    {
        return ($this->f[$key])(...$args);
    }

    /** The signed-in user's active employee row (id, name, wp_user_id), or null. */
    public function employee(): ?object { $e = $this->call('employee'); return is_object($e) ? $e : null; }
    public function attendanceEnabled(int $employeeId): bool { return (bool) $this->call('attendanceEnabled', $employeeId); }
    /** Today's schedule row (status), or null. */
    public function schedule(int $employeeId): ?object { $s = $this->call('schedule', $employeeId); return is_object($s) ? $s : null; }
    /** @return array<string,object> today's events by type (sign_in, late_sign_in, sign_out) */
    public function events(int $employeeId): array { return (array) $this->call('events', $employeeId); }
    /** The work day the current (possibly overnight) shift belongs to, Y-m-d. */
    public function attendanceDay(int $employeeId): string { return (string) $this->call('attendanceDay', $employeeId); }
    public function generalLeave(string $day): bool { return (bool) $this->call('generalLeave', $day); }
    public function requiresSignIn(string $status): bool { return (bool) $this->call('requiresSignIn', $status); }
    public function requiresLocation(string $status): bool { return (bool) $this->call('requiresLocation', $status); }
    public function faceRequired(): bool { return (bool) $this->call('faceRequired'); }
    public function windowOpen(int $employeeId): bool { return (bool) $this->call('windowOpen', $employeeId); }
    /** @return array{start:int|false|null,cutoff:int|false|null} */
    public function windowBounds(int $employeeId): array { return (array) $this->call('windowBounds', $employeeId); }
    /** On Time / Late Arrival for a Sign In at a local time (Y-m-d H:i:s). */
    public function classify(string $localMysql, int $employeeId): string { return (string) $this->call('classify', $localMysql, $employeeId); }

    /** The employee's assigned work location (latitude, longitude, radius, enforcement), or null. */
    public function assignedLocation(int $employeeId): ?object { $l = $this->call('assignedLocation', $employeeId); return is_object($l) ? $l : null; }
    /**
     * Today's branches (3.31.72), or null when the site does not provide them.
     * @return array{expected:?object, allowed:array<int,object>, flag_other:bool, kiosk_any:bool}|null
     *   allowed: the accepted branches by id (id, latitude, longitude, radius, enforcement), expected first
     */
    public function branches(int $employeeId): ?array
    {
        if (!isset($this->f['branches'])) return null;
        $b = $this->call('branches', $employeeId);
        return is_array($b) ? $b : null;
    }
    /** @return array{0:mixed,1:mixed,2:float} the company location settings: latitude, longitude, radius */
    public function defaultSite(): array { return (array) $this->call('defaultSite'); }
    /** @return array{latitude:mixed,longitude:mixed,location_timestamp:mixed}|null */
    public function lastDeviceLocation(int $employeeId): ?array { $r = $this->call('lastDeviceLocation', $employeeId); return is_array($r) ? $r : null; }

    /** WordPress local time as a Unix-like timestamp (current_time('timestamp')). */
    public function localTime(): int { return (int) $this->call('localTime'); }
    public function localMysql(): string { return (string) $this->call('localMysql'); }
    /** Real Unix time (time()), for the device's location timestamp. */
    public function unixTime(): int { return (int) $this->call('unixTime'); }

    /** @param array<string,mixed> $row @return int the new id, 0 on failure */
    public function insertTimeLog(array $row): int { return (int) $this->call('insertTimeLog', $row); }
    public function insertError(): string { return (string) $this->call('insertError'); }
    /** The id of the last row inserted on this connection (whatever the table). */
    public function lastInsertId(): int { return (int) $this->call('lastInsertId'); }
    /** @param string[] $types The first event of these types for the employee and day (0 = none). */
    public function firstEventId(int $employeeId, string $day, array $types): int { return (int) $this->call('firstEventId', $employeeId, $day, $types); }
    public function deleteTimeLog(int $id): void { $this->call('deleteTimeLog', $id); }

    public function evaluateAchievements(int $employeeId, string $day): void { $this->call('evaluateAchievements', $employeeId, $day); }
    public function audit(string $action, string $entity, int $entityId, string $details): void { $this->call('audit', $action, $entity, $entityId, $details); }
    public function currentUserId(): int { return (int) $this->call('currentUserId'); }
    public function clientIp(): string { return (string) $this->call('clientIp'); }

    /** Serialise attendance writes of one employee (true when the lock is held). */
    public function lock(int $employeeId): bool { return (bool) $this->call('lock', $employeeId); }
    public function unlock(int $employeeId): void { $this->call('unlock', $employeeId); }
    /** @return array{request:string,response:array<string,mixed>|null}|null */
    public function idemFind(int $userId, string $scope, string $keyHash): ?array { $r = $this->call('idemFind', $userId, $scope, $keyHash); return is_array($r) ? $r : null; }
    /** Records the key as in progress; false when it already exists. */
    public function idemClaim(int $userId, string $scope, string $keyHash, string $requestHash): bool { return (bool) $this->call('idemClaim', $userId, $scope, $keyHash, $requestHash); }
    /** @param array<string,mixed> $response */
    public function idemSave(int $userId, string $scope, string $keyHash, array $response): void { $this->call('idemSave', $userId, $scope, $keyHash, $response); }
    public function idemRelease(int $userId, string $scope, string $keyHash): void { $this->call('idemRelease', $userId, $scope, $keyHash); }

    public function breakEnabled(): bool { return (bool) $this->call('breakEnabled'); }
    /** Today's open break session (id, start_at), or null. */
    public function openBreak(int $employeeId): ?object { $b = $this->call('openBreak', $employeeId); return is_object($b) ? $b : null; }
    public function breakRemaining(int $employeeId): int { return (int) $this->call('breakRemaining', $employeeId); }
    /** @param array<string,mixed> $row @return int the new id, 0 on failure */
    public function insertBreak(array $row): int { return (int) $this->call('insertBreak', $row); }
    public function firstOpenBreakId(int $employeeId, string $day): int { return (int) $this->call('firstOpenBreakId', $employeeId, $day); }
    public function deleteBreak(int $id): void { $this->call('deleteBreak', $id); }
    /** The reminder and the manager escalation cron events of a new break. */
    public function scheduleBreakEvents(int $breakId): void { $this->call('scheduleBreakEvents', $breakId); }
    /** Ends an open break; false when it was no longer open. */
    public function closeBreak(int $breakId, string $endMysql, int $minutes): bool { return (bool) $this->call('closeBreak', $breakId, $endMysql, $minutes); }
    /** The "Break Ended" notification. */
    public function breakEnded(object $employee, int $breakId, int $minutes): void { $this->call('breakEnded', $employee, $breakId, $minutes); }
}
