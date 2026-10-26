<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Schedule\Data\ScheduleDayStore;

/** ScheduleDayStore in memory, for the Schedule services' unit tests. $failInsert / $failUpdate / $raceRow act out the database. */
final class MemoryScheduleDayStore implements ScheduleDayStore
{
    /** @var array<int,object> */
    public $rows = [];
    /** @var array<int,object> */
    public $calendar = [];
    public $failInsert = false;
    public $failUpdate = false;
    /** @var object|null a row another manager inserts just before ours (the UNIQUE key race) */
    public $raceRow = null;
    /** @var string|null a status another manager saves between our read and our UPDATE */
    public $raceStatus = null;
    public $error = '';
    private $next = 1;

    public function add(int $employeeId, string $date, string $status, string $note = ''): int
    {
        $id = $this->next++;
        $this->rows[$id] = (object) ['id' => (string) $id, 'employee_id' => (string) $employeeId, 'work_date' => $date, 'status' => $status, 'note' => $note];
        return $id;
    }

    /** A read clears the last error, as wpdb::flush() does before every query. */
    private function of(int $e, string $d): ?object
    {
        $this->error = '';
        foreach ($this->rows as $r) if ((int) $r->employee_id === $e && $r->work_date === $d) return $r;
        return null;
    }

    public function find(int $employeeId, string $date): ?object { return $this->of($employeeId, $date); }

    public function findStatusNote(int $employeeId, string $date): ?object
    {
        $r = $this->of($employeeId, $date);
        return $r ? (object) ['id' => $r->id, 'status' => $r->status, 'note' => $r->note] : null;
    }

    public function findStatusNoteById(int $id): ?object
    {
        $this->error = '';
        return isset($this->rows[$id]) ? (object) ['id' => $this->rows[$id]->id, 'status' => $this->rows[$id]->status, 'note' => $this->rows[$id]->note] : null;
    }

    public function idOf(int $employeeId, string $date): ?string { $r = $this->of($employeeId, $date); return $r ? (string) $r->id : null; }

    public function statusOf(int $id): ?string { $this->error = ''; return isset($this->rows[$id]) ? (string) $this->rows[$id]->status : null; }

    public function noteOf(int $id): ?string { $this->error = ''; return isset($this->rows[$id]) ? (string) $this->rows[$id]->note : null; }

    public function insert(array $row): int
    {
        if ($this->raceRow) { $this->rows[(int) $this->raceRow->id] = $this->raceRow; $this->raceRow = null; $this->error = 'Duplicate entry'; return 0; }
        if ($this->failInsert) { $this->error = 'disk full'; return 0; }
        $this->error = '';
        $id = $this->next++;
        $this->rows[$id] = (object) (['id' => (string) $id] + $row);
        return $id;
    }

    public function update(array $row, int $id)
    {
        if ($this->failUpdate) { $this->error = 'lock wait timeout'; return false; }
        foreach ($row as $k => $v) $this->rows[$id]->$k = $v;
        return 1;
    }

    public function updateStatusIf(int $id, string $expected, string $status, string $note, int $by, string $at)
    {
        if ($this->failUpdate) { $this->error = 'lock wait timeout'; return false; }
        if ($this->raceStatus !== null) { $this->rows[$id]->status = $this->raceStatus; $this->raceStatus = null; }
        if (!isset($this->rows[$id]) || $this->rows[$id]->status !== $expected) return 0;
        if ($this->rows[$id]->status === $status && $this->rows[$id]->note === $note) return 0;   // MySQL: no row changed
        $this->rows[$id]->status = $status; $this->rows[$id]->note = $note; $this->rows[$id]->updated_by = $by; $this->rows[$id]->updated_at = $at;
        return 1;
    }

    public function lastError(): string { return $this->error; }

    public function rowsOnDates(array $dates): array
    {
        $out = array_values(array_filter($this->rows, function ($r) use ($dates) { return in_array($r->work_date, $dates, true); }));
        usort($out, function ($a, $b) { return strcmp($a->work_date, $b->work_date); });
        return $out;
    }

    public function calendarEvents(string $start, string $end): array
    {
        return array_values(array_filter($this->calendar, function ($c) use ($start, $end) { return $c->event_date >= $start && $c->event_date <= $end; }));
    }
}
