<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Schedule\Data\ScheduleChangeStore;

/** ScheduleChangeStore in memory (3.31.167): the UNIQUE open_key is enforced; $failDay acts out a database error. */
final class MemoryScheduleChangeStore implements ScheduleChangeStore
{
    /** @var array<int,object> */
    public $requests = [];
    /** @var array<int,object> */
    public $days = [];
    public $failDay = false;
    private $dup = false;
    private $nextR = 1;
    private $nextD = 1;

    public function insertRequest(array $row): int
    {
        $id = $this->nextR++;
        $this->requests[$id] = (object) (['id' => (string) $id, 'decided_by' => null, 'decided_at' => null] + $row);
        return $id;
    }

    public function insertDay(array $row): int
    {
        $this->dup = false;
        if ($this->failDay) return 0;
        foreach ($this->days as $d) if ($d->open_key !== null && $d->open_key === $row['open_key']) { $this->dup = true; return 0; }
        $id = $this->nextD++;
        $this->days[$id] = (object) (['id' => (string) $id, 'result' => '', 'now_status' => null] + $row);
        return $id;
    }

    public function lastWasDuplicate(): bool { return $this->dup; }
    public function find(int $id): ?object { return isset($this->requests[$id]) ? clone $this->requests[$id] : null; }
    public function lockRequest(int $id): ?object { return $this->find($id); }

    public function days(int $requestId): array
    {
        $out = array_values(array_filter($this->days, function ($d) use ($requestId) { return (int) $d->request_id === $requestId; }));
        usort($out, function ($a, $b) { return [(int) $a->employee_id, $a->work_date, (int) $a->id] <=> [(int) $b->employee_id, $b->work_date, (int) $b->id]; });
        return array_map(function ($d) { return clone $d; }, $out);
    }

    public function lockDays(int $requestId): ?array { return $this->days($requestId); }

    public function daysOf(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) if ($d = $this->days((int) $id)) $out[(int) $id] = $d;
        return $out;
    }

    public function openOn(array $employeeIds, string $from, string $to): array
    {
        $out = [];
        foreach ($this->days as $d) {
            if ($d->open_key === null || !in_array((int) $d->employee_id, $employeeIds, true) || $d->work_date < $from || $d->work_date > $to) continue;
            $out[] = (object) ['employee_id' => $d->employee_id, 'work_date' => $d->work_date, 'new_status' => $d->new_status, 'request_id' => $d->request_id,
                'requester_employee_id' => $this->requests[(int) $d->request_id]->requester_employee_id];
        }
        return $out;
    }

    public function claim(int $id, string $from, array $set)
    {
        if (!isset($this->requests[$id]) || $this->requests[$id]->status !== $from) return 0;
        foreach ($set as $k => $v) $this->requests[$id]->$k = $v;
        return 1;
    }

    public function claimOwn(int $id, int $requesterEmployeeId, array $set)
    {
        if (!isset($this->requests[$id]) || (int) $this->requests[$id]->requester_employee_id !== $requesterEmployeeId) return 0;
        return $this->claim($id, 'pending', $set);
    }

    public function setDayResult(int $dayId, string $result, ?string $nowStatus): bool
    {
        $this->days[$dayId]->result = $result;
        $this->days[$dayId]->now_status = $nowStatus;
        $this->days[$dayId]->open_key = null;
        return true;
    }

    public function releaseDays(int $requestId): bool
    {
        foreach ($this->days as $d) if ((int) $d->request_id === $requestId) $d->open_key = null;
        return true;
    }

    public function finish(int $id, string $status, int $applied)
    {
        $this->requests[$id]->status = $status;
        $this->requests[$id]->applied = $applied;
        return 1;
    }

    public function waiting(int $limit): array
    {
        $out = array_values(array_filter($this->requests, function ($r) { return $r->status === 'pending'; }));
        return array_map(function ($r) { return clone $r; }, array_slice($out, 0, $limit));
    }

    public function forRequester(int $employeeId, int $limit): array
    {
        $out = array_values(array_filter($this->requests, function ($r) use ($employeeId) { return (int) $r->requester_employee_id === $employeeId; }));
        return array_slice(array_reverse($out), 0, $limit);
    }

    public function openCount(): int { return count($this->waiting(PHP_INT_MAX)); }
    public function lastError(): string { return ''; }
}
