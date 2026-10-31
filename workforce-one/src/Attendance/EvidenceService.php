<?php
namespace WorkforceOne\Attendance;

use WorkforceOne\Attendance\Data\AttemptStore;
use WorkforceOne\Attendance\Data\TimeLogStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;

if (!defined('ABSPATH')) exit;

/**
 * Sign In evidence (3.31.139): keeping a refused or unsent attempt, the offline report's checks, which photo a request asks for, the daily cleanup and one
 * employee's day. Moved from includes/trait-signin-evidence.php with the same queries in the same order, the same settings reads, the same audits and
 * messages (docs/tasks/attendance-evidence.md). No SQL, no clock of its own beyond the Clock port, no transport.
 */
final class EvidenceService
{
    /** @var AttemptStore */
    private $attempts;
    /** @var TimeLogStore */
    private $logs;
    /** @var EvidenceSite */
    private $site;
    /** @var AuditLog */
    private $auditLog;
    /** @var Clock */
    private $clock;

    public function __construct(AttemptStore $attempts, TimeLogStore $logs, EvidenceSite $site, AuditLog $audit, Clock $clock)
    {
        $this->attempts = $attempts;
        $this->logs = $logs;
        $this->site = $site;
        $this->auditLog = $audit;
        $this->clock = $clock;
    }

    /**
     * Keeps one attempt. $a: event_type, result (refused | face_ok | face_failed | not_reached), reason, detail, and optionally latitude, longitude, accuracy,
     * distance_meters, location_status, source, photo_key, attempted_at, phone_time, clock_skew, time_sure, reported_at.
     * @param array<string,mixed> $a
     * @return int the attempt's id (0: not kept)
     */
    public function keep(?object $emp, array $a, int $userId, string $ip, string $device): int
    {
        if (!$emp || !$this->site->settings()['attempts']) return 0;
        $this->site->ensureSchemaOnce();
        $at = (string) ($a['attempted_at'] ?? $this->clock->now());
        $row = [
            'employee_id' => (int) $emp->id, 'user_id' => $userId, 'work_date' => substr($at, 0, 10),
            'event_type' => substr((string) $a['event_type'], 0, 20), 'result' => substr((string) $a['result'], 0, 20),
            'reason' => substr((string) ($a['reason'] ?? ''), 0, 40), 'detail' => isset($a['detail']) ? mb_substr((string) $a['detail'], 0, 255) : null,
            'attempted_at' => $at, 'ip_address' => $ip, 'device' => $device, 'source' => substr((string) ($a['source'] ?? 'app'), 0, 20),
        ];
        foreach (['latitude', 'longitude', 'accuracy', 'distance_meters', 'location_status', 'photo_key', 'phone_time', 'clock_skew', 'time_sure', 'reported_at'] as $k) {
            if (array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== '') $row[$k] = $a[$k];
        }
        $id = $this->attempts->insert($row);
        if (!$id) return 0;
        if ($row['result'] !== 'face_ok') {
            $this->record($emp, $id, $row);
            $this->maybeAlert($emp, $row['work_date']);
        }
        return $id;
    }

    /**
     * The Audit Log line of a refused or unsent attempt: signin_refused, face_check_failed, signin_not_reached.
     * @param array<string,mixed> $row
     */
    private function record(object $emp, int $id, array $row): void
    {
        $dist = ($row['distance_meters'] ?? null) !== null ? ' (' . $this->site->distance((float) $row['distance_meters']) . ' away)' : '';
        $this->auditLog->record(
            EvidenceRules::auditAction((string) $row['result'], (string) $row['event_type']),
            'signin_attempt',
            $id,
            EvidenceRules::auditText((string) $emp->name, EvidenceRules::eventLabel((string) $row['event_type']), EvidenceRules::reasonLabel((string) $row['reason'], $dist), $row)
        );
    }

    /** The department's managers are told once, when an employee reaches N refusals in a day. */
    private function maybeAlert(object $emp, string $day): void
    {
        $n = (int) $this->site->settings()['alert'];
        if ($n < 1) return;
        if (!EvidenceRules::alertDue($this->attempts->refusalsOn((int) $emp->id, $day), $n)) return;
        $this->site->alert($emp, $day, $n);
    }

    /**
     * An attempt the phone could not send, sent later: dropped when it did reach us after all (a record or an attempt of the same kind is there), when the
     * employee already sent five today, else kept. $attempt is what keep() takes.
     * @param string[] $kinds the record types that count as the same press
     * @param array<string,mixed> $attempt
     * @return string reached | limit | kept | not_kept
     */
    public function offline(object $emp, string $type, string $from, string $to, array $kinds, string $dayStart, array $attempt, int $userId, string $ip, string $device): string
    {
        $this->site->ensureSchemaOnce();
        if ($this->logs->kindsBetween((int) $emp->id, $kinds, $from, $to) || $this->attempts->typeBetween((int) $emp->id, $type, $from, $to)) return 'reached';
        // At most 5 a day: a phone cannot flood the report.
        if ($this->attempts->notReachedSince((int) $emp->id, $dayStart) >= 5) return 'limit';
        return $this->keep($emp, $attempt, $userId, $ip, $device) > 0 ? 'kept' : 'not_kept';
    }

    /** The row a photo request points at: a Sign In (kind log) or a refused face check (kind attempt); null for anything else or when there is no such row. */
    public function photo(string $kind, int $id): ?object
    {
        if ($kind === 'log') return $this->logs->photoOf($id);
        if ($kind === 'attempt') return $this->attempts->photoOf($id);
        return null;
    }

    /**
     * Daily: attempts and photos older than their days are deleted (the files first, then the keys, then the rows). The photo files nothing points to go last.
     * @param int $localTs the site's local time as a timestamp (the cut-offs are local times) @param int $unixTs now, as a Unix time (a file's age)
     */
    public function cleanup(int $localTs, int $unixTs): bool
    {
        if (!$this->attempts->exists()) return false;
        $s = $this->site->settings();
        $attemptCut = gmdate('Y-m-d H:i:s', $localTs - $s['attempt_days'] * 86400);
        $photoCut = gmdate('Y-m-d H:i:s', $localTs - $s['photo_days'] * 86400);
        foreach ($this->attempts->photoKeysBefore($attemptCut, $photoCut) as $k) $this->site->deletePhoto((string) $k);
        $this->attempts->clearPhotosBefore($photoCut);
        $this->attempts->deleteBefore($attemptCut);
        foreach ($this->logs->photoKeysBefore($photoCut) as $k) $this->site->deletePhoto((string) $k);
        $this->logs->clearPhotosBefore($photoCut);
        // A face check not followed by a Sign In leaves a photo nothing points to: gone after the same days.
        $this->site->sweepPhotos($unixTs - $s['photo_days'] * 86400);
        return true;
    }

    /** @return object[] every row of the employee on the day (records, corrected ones too) */
    public function dayLogs(int $employeeId, string $day): array
    {
        return $this->logs->allOn($employeeId, $day);
    }

    public function tableExists(): bool
    {
        return $this->attempts->exists();
    }

    /** @return object[] every attempt of the employee on the day */
    public function dayAttempts(int $employeeId, string $day): array
    {
        return $this->attempts->day($employeeId, $day);
    }
}
