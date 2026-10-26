<?php
namespace WorkforceOne\Schedule;

use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\People;
use WorkforceOne\Schedule\Data\ScheduleDayStore;

if (!defined('ABSPATH')) exit;

/**
 * Schedule days (3.31.127, the first layered slice; docs/tasks/layered-modules.md): read a day, save a day, and the
 * Attendance grid's guarded save. The rules are the ones the plugin had (save_att(), save_att_if_unchanged()), moved
 * here as they were: the same reads and writes in the same order, the same audit rows and the same notifications.
 * The person who saves comes in as $actor (0 for a job).
 */
final class ScheduleDayService
{
    /** @var ScheduleDayStore */
    private $store;
    /** @var People */
    private $people;
    /** @var ScheduleNotifier */
    private $notifier;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(ScheduleDayStore $store, People $people, ScheduleNotifier $notifier, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->people = $people;
        $this->notifier = $notifier;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /** An employee's day (the whole row), or null. */
    public function day(int $employeeId, string $date): ?object
    {
        return $this->store->find($employeeId, $date);
    }

    /** @return object[] the active company holidays between the two days */
    public function companyHolidays(string $start, string $end): array
    {
        return $this->store->calendarEvents($start, $end);
    }

    /**
     * Save a day whatever it was (leave, swaps, corrections, imports…). The note is replaced. The person is told when the
     * status or the note changed. @return int|string the row's id (as the database gives it), 0 on a database error
     */
    public function save(int $employeeId, string $date, string $status, string $note, int $actor)
    {
        $id = $this->store->idOf($employeeId, $date);
        $oldStatus = $id ? $this->store->statusOf((int) $id) : null;
        $oldNote = $id ? $this->store->noteOf((int) $id) : null;
        $data = ['status' => $status, 'note' => $note, 'updated_by' => $actor, 'updated_at' => $this->clock->now()];
        if ($id) {
            $updated = $this->store->update($data, (int) $id);
            if ($updated === false) {
                $this->audit->record('schedule_update_failed', 'schedule', (int) $id, $this->store->lastError() ?: 'Database update failed.');
                return 0;
            }
        } else {
            $data['employee_id'] = $employeeId;
            $data['work_date'] = $date;
            $id = $this->store->insert($data);
            if ($id === 0) {
                $this->audit->record('schedule_insert_failed', 'schedule', 0, $this->store->lastError() ?: 'Database insert failed.');
                return 0;
            }
        }
        $emp = $this->people->activeEmployee($employeeId);
        $changed = ($oldStatus !== $status) || ((string) $oldNote !== (string) $note);
        if ($emp && $emp->wp_user_id && $changed) $this->notifier->dayChanged((int) $emp->wp_user_id, $date, $status, (int) $id);
        return $id;
    }

    /**
     * The Attendance grid's save: only if the day is still what the manager loaded ($expected; null: it was empty).
     *
     * - An empty day is inserted; the UNIQUE employee_date key settles two managers creating it at once (the loser is
     *   told it is a conflict, not a database failure).
     * - A day with a status is changed with one conditional UPDATE (WHERE status = $expected), so a newer value is never
     *   overwritten. Its note is kept (the grid has no note).
     * - An UPDATE that changed nothing is a conflict only when the status is now something else; the same value is fine.
     *
     * @return array{ok:bool,conflict:bool,id:int,changed?:bool,old_status?:string|null}
     */
    public function saveIfUnchanged(int $employeeId, string $date, string $status, ?string $expected, int $actor): array
    {
        $row = $this->store->findStatusNote($employeeId, $date);
        if ($expected === null) {
            if ($row) return ['ok' => false, 'conflict' => true, 'id' => (int) $row->id];
            $id = $this->store->insert(['employee_id' => $employeeId, 'work_date' => $date, 'status' => $status, 'note' => '', 'updated_by' => $actor, 'updated_at' => $this->clock->now()]);
            if ($id === 0) {
                $winner = $this->store->findStatusNote($employeeId, $date);
                if ($winner) return ['ok' => false, 'conflict' => true, 'id' => (int) $winner->id];
                $this->audit->record('schedule_insert_failed', 'schedule', 0, $this->store->lastError() ?: 'Database insert failed.');
                return ['ok' => false, 'conflict' => false, 'id' => 0];
            }
            $oldStatus = null;
            $this->store->findStatusNoteById($id);   // read back, as before
        } else {
            if (!$row || (string) $row->status !== $expected) return ['ok' => false, 'conflict' => true, 'id' => $row ? (int) $row->id : 0];
            $oldStatus = (string) $row->status;
            $updated = $this->store->updateStatusIf((int) $row->id, $expected, $status, (string) $row->note, $actor, $this->clock->now());
            if ($updated === false) {
                $this->audit->record('schedule_update_failed', 'schedule', (int) $row->id, $this->store->lastError() ?: 'Database update failed.');
                return ['ok' => false, 'conflict' => false, 'id' => (int) $row->id];
            }
            if ($updated === 0) {
                $latest = $this->store->statusOf((int) $row->id);
                if ($latest === null || $latest !== $expected) return ['ok' => false, 'conflict' => true, 'id' => (int) $row->id];
                return ['ok' => true, 'conflict' => false, 'id' => (int) $row->id, 'changed' => false, 'old_status' => (string) $row->status];
            }
            $id = (int) $row->id;
        }
        $emp = $this->people->activeBrief($employeeId);
        $changed = ($oldStatus !== $status);
        if ($emp && $emp->wp_user_id && $changed) $this->notifier->dayChanged((int) $emp->wp_user_id, $date, $status, $id);
        return ['ok' => true, 'conflict' => false, 'id' => $id, 'changed' => $changed, 'old_status' => $oldStatus];
    }
}
