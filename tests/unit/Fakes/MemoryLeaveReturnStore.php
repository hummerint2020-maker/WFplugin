<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Leave\Data\LeaveReturnStore;

/** LeaveReturnStore in memory (3.31.164), for LeaveReturnService's unit tests. $fail names a write that returns false. */
final class MemoryLeaveReturnStore implements LeaveReturnStore
{
    /** @var array<int,object> leave rows with their type's columns */
    public $leaves = [];
    /** @var array<int,object> */
    public $changes = [];
    /** @var array<int,object> */
    public $snaps = [];
    /** @var array<int,object> */
    public $balances = [];
    /** @var string a method name whose write fails */
    public $fail = '';
    /** @var string[] the writes, in order */
    public $log = [];
    private $nextChange = 1;

    public function addLeave(array $row): object
    {
        $l = (object) ($row + ['cancellation_status' => null, 'cancel_from' => null, 'type_name' => 'Annual', 'deduct_balance' => 1, 'kind' => 'planned', 'carry_mode' => 'none', 'carry_max' => 0]);
        return $this->leaves[(int) $l->id] = $l;
    }

    public function addDay(int $id, int $leaveId, int $employeeId, string $date, ?int $scheduleId, ?string $status): void
    {
        $this->snaps[$id] = (object) ['id' => (string) $id, 'leave_request_id' => (string) $leaveId, 'employee_id' => (string) $employeeId, 'work_date' => $date,
            'had_schedule' => $scheduleId ? '1' : '0', 'schedule_id' => $scheduleId ? (string) $scheduleId : null, 'status' => $status, 'note' => ''];
    }

    private function w(string $m) { $this->log[] = $m; return $this->fail === $m; }

    public function lockLeave(int $id): ?object { return isset($this->leaves[$id]) ? clone $this->leaves[$id] : null; }
    public function findOwn(int $id, $employeeId): ?object { $l = $this->leaves[$id] ?? null; return $l && (int) $l->employee_id === (int) $employeeId ? clone $l : null; }
    public function changes(int $leaveId, int $limit): array
    {
        $c = array_values(array_filter($this->changes, static function ($x) use ($leaveId) { return (int) $x->leave_request_id === $leaveId; }));
        usort($c, static function ($a, $b) { return $b->id <=> $a->id; });
        return array_map(static function ($x) { return clone $x; }, array_slice($c, 0, $limit));
    }
    public function changeCount(int $leaveId): int { return count($this->changes($leaveId, 1000)); }
    public function insertChange(array $row): int { if ($this->w('insertChange')) return 0; $id = $this->nextChange++; $this->changes[$id] = (object) (['id' => $id] + $row); return $id; }
    public function closeChange(int $id, array $set): bool { if ($this->w('closeChange')) return false; if ($this->changes[$id]->status === 'Pending') foreach ($set as $k => $v) $this->changes[$id]->$k = $v; return true; }
    public function finishChange(int $id, array $set): bool { if ($this->w('finishChange')) return false; foreach ($set as $k => $v) $this->changes[$id]->$k = $v; return true; }
    private function days(int $leaveId, string $from): array
    {
        $d = array_values(array_filter($this->snaps, static function ($x) use ($leaveId, $from) { return (int) $x->leave_request_id === $leaveId && $x->work_date >= $from; }));
        usort($d, static function ($a, $b) { return strcmp($a->work_date, $b->work_date); });
        return $d;
    }
    public function daysFrom(int $leaveId, string $from): int { return count($this->days($leaveId, $from)); }
    public function leaveDays(int $leaveId): ?array { return $this->days($leaveId, '0000-00-00'); }
    public function leaveDaysFrom(int $leaveId, string $from): ?array { return $this->days($leaveId, $from); }
    public function lockLeaveDaysFrom(int $leaveId, string $from): ?array { return $this->days($leaveId, $from); }
    public function countLeaveDays(int $leaveId): int { return count($this->days($leaveId, '0000-00-00')); }
    public function deleteLeaveDay(int $snapshotId) { if ($this->w('deleteLeaveDay')) return false; $n = isset($this->snaps[$snapshotId]) ? 1 : 0; unset($this->snaps[$snapshotId]); return $n; }
    public function snapshot(int $id): ?object { return $this->snaps[$id] ?? null; }
    public function snapshotId(int $id): ?string { return isset($this->snaps[$id]) ? (string) $id : null; }
    public function lowerUsed($balanceId, $days, string $at) { if ($this->w('lowerUsed')) return false; $b = $this->balances[(int) $balanceId] ?? null; if (!$b) return 0; $b->used = max(0.0, $b->used - (float) $days); return 1; }
    public function balanceFor(int $employeeId, int $typeId, int $year): ?object
    {
        foreach ($this->balances as $b) if ((int) $b->employee_id === $employeeId && (int) $b->leave_type_id === $typeId && (int) $b->leave_year === $year) return clone $b;
        return null;
    }
    public function balanceById(int $id): ?object { return isset($this->balances[$id]) ? clone $this->balances[$id] : null; }
    public function addCarried(int $id, $add, string $at) { if ($this->w('addCarried')) return false; $this->balances[$id]->carried += $add; $this->balances[$id]->entitlement += $add; return 1; }
    private function cas(int $id, array $set, ?string $was = 'Pending')
    {
        $l = $this->leaves[$id] ?? null;
        if (!$l || $l->status !== 'Approved' || $l->cancellation_status !== $was) return 0;
        foreach ($set as $k => $v) $l->$k = $v;
        return 1;
    }
    public function cancelWhole(int $id, int $by, string $at) { return $this->w('cancelWhole') ? false : $this->cas($id, ['status' => 'Cancelled', 'cancellation_status' => 'Approved', 'cancelled_by' => $by]); }
    public function endLegacy(int $id, int $by, string $at) { return $this->w('endLegacy') ? false : $this->cas($id, ['cancellation_status' => 'Ended']); }
    public function shorten(int $id, string $end, $days, int $by, string $at) { return $this->w('shorten') ? false : $this->cas($id, ['end_date' => $end, 'requested_days' => $days, 'cancellation_status' => 'Approved']); }
    public function endPending(int $id, int $by, string $at) { return $this->w('endPending') ? false : $this->cas($id, ['cancellation_status' => 'Ended']); }
    public function applyReturn(int $id, array $set, ?string $was) { return $this->w('applyReturn') ? false : $this->cas($id, $set, $was); }
    public function rejectCancellation(int $id) { return $this->w('rejectCancellation') ? false : $this->cas($id, ['cancellation_status' => 'Rejected']); }
    public function markPending(int $id, ?string $from) { return $this->w('markPending') ? false : $this->cas($id, ['cancellation_status' => 'Pending', 'cancel_from' => $from], null); }
    public function askAgain(int $id, string $back)
    {
        if ($this->w('askAgain')) return false;
        $l = $this->leaves[$id] ?? null;
        if (!$l || $l->status !== 'Approved' || !in_array($l->cancellation_status, [null, 'Not Requested', 'Approved', 'Rejected', 'Withdrawn', 'Ended'], true)) return 0;
        $l->cancellation_status = 'Pending';
        $l->cancel_from = $back;
        return 1;
    }
    public function withdrawCancellation(int $id, int $employeeId) { return $this->w('withdrawCancellation') ? false : $this->cas($id, ['cancellation_status' => 'Withdrawn']); }
    public function approvedLeaves(int $employeeId): array { return array_values(array_filter($this->leaves, static function ($l) use ($employeeId) { return (int) $l->employee_id === $employeeId && $l->status === 'Approved'; })); }
}
