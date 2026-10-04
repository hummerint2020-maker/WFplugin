<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Records Sign In / Sign Out and starts / ends breaks. The one place that decides; the Web forms
 * (time_event(), presence_qr_signin(), break_start(), break_resume()) are adapters over it, and the
 * API will be another. No WordPress calls: everything goes through AttendanceContext.
 *
 * The steps and their order are those of record_time_event() and break_start() / break_resume()
 * up to 3.31.45 (tests/e2e_attendance_parity.py compares the two). New in 3.31.46:
 * - the check-and-insert runs under a per-employee lock, and the insert is verified afterwards
 *   (a concurrent duplicate removes itself), so a retried or double-sent request cannot record
 *   two Sign Ins, two Sign Outs or two open breaks;
 * - an optional idempotency key returns the stored result of the first request with that key.
 */
final class AttendanceService
{
    /** @var AttendanceContext */
    private $ctx;

    public function __construct(AttendanceContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function signIn(AttendanceCommand $cmd): AttendanceResult
    {
        $cmd->type = AttendanceCommand::SIGN_IN;
        return $this->record($cmd);
    }

    public function signOut(AttendanceCommand $cmd): AttendanceResult
    {
        $cmd->type = AttendanceCommand::SIGN_OUT;
        return $this->record($cmd);
    }

    public function record(AttendanceCommand $cmd): AttendanceResult
    {
        $c = $this->ctx;
        $emp = $c->employee();
        if ($emp && !$c->attendanceEnabled((int) $emp->id)) return AttendanceResult::failure(AttendanceResult::ATTENDANCE_DISABLED);
        return $this->guarded($emp, 'attendance.' . $cmd->type, $cmd->idempotencyKey, $cmd->fingerprint(), function () use ($cmd, $emp) {
            return $this->recordUnlocked($cmd, $emp);
        });
    }

    public function startBreak(?string $idempotencyKey = null): AttendanceResult
    {
        $c = $this->ctx;
        $enabled = $c->breakEnabled();
        $emp = $c->employee();
        $early = BreakRules::checkStart(['enabled' => $enabled, 'employee' => (bool) $emp, 'attendance_enabled' => $emp ? $c->attendanceEnabled((int) $emp->id) : false,
            'working_day' => true, 'signed_in' => true, 'remaining' => 1]);
        if ($early) return AttendanceResult::failure($early);
        return $this->guarded($emp, 'break.start', $idempotencyKey, 'break.start', function () use ($emp) {
            return $this->startBreakUnlocked($emp);
        });
    }

    public function resumeBreak(?string $idempotencyKey = null): AttendanceResult
    {
        $c = $this->ctx;
        $enabled = $c->breakEnabled();
        $emp = $c->employee();
        $early = BreakRules::checkResume(['enabled' => $enabled, 'employee' => (bool) $emp, 'attendance_enabled' => $emp ? $c->attendanceEnabled((int) $emp->id) : false, 'open_break' => true]);
        if ($early) return AttendanceResult::failure($early);
        return $this->guarded($emp, 'break.resume', $idempotencyKey, 'break.resume', function () use ($emp) {
            return $this->resumeBreakUnlocked($emp);
        });
    }

    /**
     * Runs $work under the employee's lock, and through the idempotency store when a key is given.
     * @param callable():AttendanceResult $work
     */
    private function guarded(?object $emp, string $scope, ?string $key, string $fingerprint, callable $work): AttendanceResult
    {
        $c = $this->ctx;
        $userId = $c->currentUserId();
        $keyHash = ($key !== null && $key !== '' && $emp && $userId) ? hash('sha256', $key) : null;
        if ($keyHash !== null) {
            $stored = $this->idempotent($userId, $scope, $keyHash, $fingerprint);
            if ($stored) return $stored;
        }
        $locked = $emp ? $c->lock((int) $emp->id) : false;
        try {
            $result = $work();
        } finally {
            if ($locked && $emp) $c->unlock((int) $emp->id);
        }
        if ($keyHash !== null) {
            // A failed save may succeed when retried: do not keep it.
            if (in_array($result->code, [AttendanceResult::SAVE_FAILED, BreakRules::SAVE_FAILED], true)) $c->idemRelease($userId, $scope, $keyHash);
            else $c->idemSave($userId, $scope, $keyHash, $result->toArray());
        }
        return $result;
    }

    /** The stored result for a key, a conflict / in-progress result, or null after claiming the key. */
    private function idempotent(int $userId, string $scope, string $keyHash, string $fingerprint): ?AttendanceResult
    {
        $c = $this->ctx;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $row = $c->idemFind($userId, $scope, $keyHash);
            if ($row) {
                if (!hash_equals((string) $row['request'], $fingerprint)) return AttendanceResult::failure(AttendanceResult::IDEMPOTENCY_CONFLICT);
                if ($row['response'] === null) return AttendanceResult::failure(AttendanceResult::IN_PROGRESS);
                return AttendanceResult::fromArray($row['response']);
            }
            if ($c->idemClaim($userId, $scope, $keyHash, $fingerprint)) return null;
        }
        return AttendanceResult::failure(AttendanceResult::IN_PROGRESS);
    }

    private function recordUnlocked(AttendanceCommand $cmd, ?object $emp): AttendanceResult
    {
        $c = $this->ctx;
        $type = $cmd->type;
        $eid = $emp ? (int) $emp->id : 0;
        $sch = $emp ? $c->schedule($eid) : null;
        $ev = $emp ? $c->events($eid) : [];
        $assigned = $emp ? $c->assignedLocation($eid) : null;
        $requiresLocation = $sch ? $c->requiresLocation((string) $sch->status) : false;

        // 1. May this event be recorded at all?
        $facts = [
            'employee' => (bool) $emp,
            'face_required' => $c->faceRequired(),
            'face_ok' => $cmd->faceOk,
            'general_leave' => $sch && $emp && $c->generalLeave($c->attendanceDay($eid)),
            'working_day' => $sch && $c->requiresSignIn((string) $sch->status),
            'signed_in' => isset($ev['sign_in']) || isset($ev['late_sign_in']),
            'signed_out' => isset($ev['sign_out']),
        ];
        $bounds = null;
        if ($emp && $type === AttendanceCommand::SIGN_IN) {
            $facts['window'] = SignInRules::WINDOW_OPEN;
            if (!$c->windowOpen($eid)) {
                $bounds = $c->windowBounds($eid);
                $facts['window'] = (!empty($bounds['start']) && $c->localTime() < $bounds['start']) ? SignInRules::WINDOW_NOT_YET : SignInRules::WINDOW_CLOSED;
            }
        }
        if ($emp && $type === AttendanceCommand::SIGN_OUT) $facts['on_break'] = $c->breakEnabled() && $c->openBreak($eid);
        $rule = SignInRules::check($type, $facts);
        if ($rule) return AttendanceResult::failure($rule, ['bounds' => $bounds, 'employee_id' => $eid]);

        // 2. Where is the employee?
        $lat = $cmd->latitude;
        $lng = $cmd->longitude;
        $prev = ($lat !== null && $lng !== null) ? $c->lastDeviceLocation($eid) : null;
        // The phone's location timestamp is Unix time (UTC): compare it with time(), not local time.
        [$integrityStatus, $integrityReason] = LocationAssessment::integrity($lat, $lng, $cmd->accuracy, $cmd->locationTimestampMs, $c->unixTime(), $prev ?: null);
        [$siteLat, $siteLng, $siteRadius] = $assigned ? [$assigned->latitude, $assigned->longitude, (float) $assigned->radius] : $c->defaultSite();
        [$locationStatus, $distance] = LocationAssessment::geofence($lat, $lng, $siteLat, $siteLng, (float) $siteRadius);
        if ($cmd->qrLocation) {
            // A QR can be photographed and forwarded, so QR Sign-In additionally requires the
            // employee's own device location to be inside the kiosk's work location.
            $q = $cmd->qrLocation;
            [$qrError, $qrDistance] = LocationAssessment::qrCheck($lat, $lng, $q['latitude'], $q['longitude'], (float) ($q['radius'] ?: 200), $integrityStatus, $integrityReason);
            if ($qrError !== null) return AttendanceResult::failure($qrError, ['distance' => $qrDistance, 'location_name' => (string) $q['name']]);
        }
        if ($type !== AttendanceCommand::SIGN_OUT && $requiresLocation && $assigned && $assigned->enforcement && $locationStatus !== 'inside') {
            return AttendanceResult::failure(AttendanceResult::OUTSIDE_LOCATION, ['distance' => $distance]);
        }

        // 3. Record it.
        $now = $c->localMysql();
        $day = $c->attendanceDay($eid);
        $id = $c->insertTimeLog(['employee_id' => $eid, 'user_id' => $c->currentUserId(), 'work_date' => $day, 'event_type' => $type, 'event_at' => $now,
            'scheduled_status' => $sch->status, 'ip_address' => $c->clientIp(), 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $cmd->accuracy,
            'location_status' => $locationStatus, 'distance_meters' => $distance, 'location_timestamp' => $cmd->locationTimestampMs,
            'integrity_status' => $integrityStatus, 'integrity_reason' => $integrityReason, 'created_at' => $now]);
        if (!$id) {
            $detail = $c->insertError() ?: 'Database insert failed.';
            $c->audit('time_' . $type . '_failed', 'time_log', 0, $emp->name . ' / ' . $detail);
            return AttendanceResult::failure(AttendanceResult::SAVE_FAILED);
        }
        // Another request for the same event may have passed the checks at the same time (no lock
        // on this database, or it timed out): the first row wins, a later one removes itself.
        $first = $c->firstEventId($eid, $day, $type === AttendanceCommand::SIGN_IN ? ['sign_in', 'late_sign_in'] : ['sign_out']);
        if ($first && $first !== $id) {
            $c->deleteTimeLog($id);
            return AttendanceResult::failure($type === AttendanceCommand::SIGN_IN ? SignInRules::ALREADY_SIGNED_IN : SignInRules::ALREADY_SIGNED_OUT, ['bounds' => null, 'employee_id' => $eid]);
        }
        $classification = $type === AttendanceCommand::SIGN_IN ? $c->classify($now, $eid) : '';
        if ($type === AttendanceCommand::SIGN_OUT) $c->evaluateAchievements($eid, $c->attendanceDay($eid));
        // The audit row's id is the last insert, as before 3.31.46 (after an achievement award that is the award's notification).
        $c->audit('time_' . $type, 'time_log', $c->lastInsertId(), $emp->name . ' / ' . $sch->status . ' / ' . $now . ' / ' . $classification
            . ' / face_verified=' . ($cmd->faceOk ? 'yes' : 'no') . ' / source=' . ($cmd->qrKiosk ? 'qr_kiosk_' . (int) $cmd->qrKiosk['id'] : 'normal'));
        return AttendanceResult::success(['event_id' => $id, 'event_type' => $type, 'event_at' => $now, 'work_date' => $day, 'classification' => $classification]);
    }

    private function startBreakUnlocked(?object $emp): AttendanceResult
    {
        $c = $this->ctx;
        $eid = (int) $emp->id;
        $sch = $c->schedule($eid);
        $ev = $c->events($eid);
        $working = $sch && $c->requiresSignIn((string) $sch->status);
        $facts = ['enabled' => true, 'employee' => true, 'attendance_enabled' => true, 'working_day' => $working];
        if ($working) {
            $facts['signed_in'] = isset($ev['sign_in']) || isset($ev['late_sign_in']);
            $facts['signed_out'] = isset($ev['sign_out']);
            if ($facts['signed_in'] && !$facts['signed_out']) {
                $facts['on_break'] = (bool) $c->openBreak($eid);
                if (!$facts['on_break']) $facts['remaining'] = $c->breakRemaining($eid);
            }
        }
        $rule = BreakRules::checkStart($facts);
        if ($rule) return AttendanceResult::failure($rule);
        $now = $c->localMysql();
        $day = $c->attendanceDay($eid);
        $id = $c->insertBreak(['employee_id' => $eid, 'user_id' => (int) $emp->wp_user_id, 'work_date' => $day, 'start_at' => $now, 'status' => 'Open', 'created_at' => $now]);
        if (!$id) return AttendanceResult::failure(BreakRules::SAVE_FAILED);
        $first = $c->firstOpenBreakId($eid, $day);
        if ($first && $first !== $id) {
            $c->deleteBreak($id);
            return AttendanceResult::failure(BreakRules::ALREADY_ON_BREAK);
        }
        $c->scheduleBreakEvents($id);
        $c->audit('break_start', 'break', $id, $emp->name . ' / ' . $now);
        return AttendanceResult::success(['break_id' => $id, 'started_at' => $now]);
    }

    private function resumeBreakUnlocked(?object $emp): AttendanceResult
    {
        $c = $this->ctx;
        $session = $c->openBreak((int) $emp->id);
        $rule = BreakRules::checkResume(['enabled' => true, 'employee' => true, 'attendance_enabled' => true, 'open_break' => (bool) $session]);
        if ($rule) return AttendanceResult::failure($rule);
        $now = $c->localMysql();
        $minutes = BreakRules::minutes((int) strtotime((string) $session->start_at), (int) strtotime($now));
        // Only the request that actually ends the break goes on (a concurrent second one finds it ended).
        if (!$c->closeBreak((int) $session->id, $now, $minutes)) return AttendanceResult::failure(BreakRules::NO_OPEN_BREAK);
        $c->breakEnded($emp, (int) $session->id, $minutes);
        $c->audit('break_resume', 'break', (int) $session->id, $emp->name . ' / ' . $now . ' / ' . $minutes . 'm');
        return AttendanceResult::success(['break_id' => (int) $session->id, 'minutes' => $minutes]);
    }
}
