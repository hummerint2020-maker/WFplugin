# 3.31.119: cleanup integrity (F12, F13, F14, F15, F10) — design

PR D of `docs/tasks/review-codex-3.31.115.md`. Fixes to existing behaviour: no new switch, no schema change; one option written once
by the upgrade check (§2.6). Base: 3.31.118. Paths under `workforce-one/`.

## 1. Root causes
**F12 shift periods (`includes/trait-shift-history.php`)**: `shift_period_add` (`:202-227`) writes unchecked (lazy seed `:213`,
replace `:218`, close previous `:220`, insert new `:221`, `shift_sync_current` → `people_set_shift_id` `trait-employees.php:100-105`)
and returns true (`:226`): a failed insert leaves the previous period closed and the days after with no period (`shift_id_on`
`:188-195` falls back to the current shift). `shift_period_remove` (`:247-258`): delete and extend unchecked. Callers ignore the
result (`trait-employee-admin.php:118` update, before the employees UPDATE `:119`; `:100` create). No lock, transaction or unique key
on (employee_id, from_date); periods come from a per-request cache: two HR saving at once overlap or duplicate.
**F12 org moves (`includes/trait-org-moves.php`)**: `org_move_apply` (`:100-118`) has no transaction; `employee_admin_set_supervisor`
(`:104`, checked) is two writes without one (`approval_set_relationship`, `trait-approvals.php:391-405`: a failed insert leaves no
supervisor); `approval_reassign_supervisor` (`trait-approvals.php:317-335`) UPDATE `:326` unchecked and notifies (`:330`) before the
history row exists; history update/insert (`:109`/`:112`) unchecked, returns ok. Employees page path `org_record_employees_page_change`
(`:232-239`, called `trait-employee-admin.php:124-127`) the same.
**F13 org moves stuck in 'applying'**: claim `:154` sets 'applying' with no time; `:152` selects only 'scheduled'; revert `:165`
unchecked; `:162`, `:176` unconditional; duplicate check `:91` ignores 'applying'; the chart lists 'applying' under scheduled with no
cancel (`:251`, `:256`); cancel accepts scheduled/failed only (`:216`). Tasks recurrence (`trait-task-extras.php:244-245`) claims first
on purpose (at most once): kept, note only.
**F14 automatic attendance (`includes/trait-auto-attendance.php`)**: `auto_attendance_event` (`:75-115`) SELECT `:81` → INSERT `:96`, no
lock, no after-insert check; `auto_attendance_bulk_sign_out` (`:203-263`) the same (`:223` / `:225`), break close `:253` unconditional
and unchecked. The normal path locks per employee (`trait-attendance.php:124-125`, `:153-167`; `AttendanceService.php:90-95`) and keeps
the first row after the insert (`AttendanceService.php:185-191`). `time_logs` has no unique key.
**F15 cron and roles**: `employee-schedule-manager.php:337` deactivate clears without args (misses the break events `[$id]`,
`trait-attendance.php:138-139`, not even in its list), one site only on multisite. `uninstall.php:16` omits `ews_push_deliver`; `:78`
omits `ews_foreman` (`trait-permissions.php:44`). All hooks: ews_notifications_cleanup, ews_auto_attendance_tick, ews_smart_nudges_tick,
ews_tasks_tick, ews_corrections_tick, ews_office_minimum_tick, ews_approvals_tick, ews_privacy_cleanup, ews_health_rollup,
ews_break_duration_reminder, ews_break_manager_escalation, ews_push_deliver. Roles: ews_administrator, ews_manager, ews_supervisor,
ews_employee, ews_foreman.
**F10 health rollup** (`trait-system-health.php:426-459`): delete `:433`, chunked insert `:445-458`, fallback `:457` unchecked, no
transaction.

## 2. Changes
- **2.1 `shift_period_add`**: inside `ews_with_lock('shift_periods_'.$eid, …, 'busy', 10)`: clear the cache and read again; checks;
  START TRANSACTION; every write checked, first failure → ROLLBACK + code. Replace `WHERE id AND from_date` (0 → the row must still
  exist, else `changed`); close conditional `WHERE id AND to_date IS NULL|= read` (must be 1, else `changed`; the real guarantee, also
  on SQLite); insert checked; `people_set_shift_id` returns bool, checked; COMMIT; caches and audit after. Messages: `save` "The shift
  could not be saved. Nothing was changed; please try again."; `changed` "This employee's shift was changed by someone else at the
  same moment. Check the history and try again."; `busy` "This employee's shift is being saved by someone else. Try again in a moment."
- **2.2 `shift_period_remove`**: same lock and transaction; delete must be 1, extend conditional; returns true|code; the handler
  redirects with it.
- **2.3 `employee_update`** (`trait-employee-admin.php:118`): not true → `employee_error=save` before anything else is written. Create
  (`:100`) unchanged (seeded lazily at the next change).
- **2.4 Org moves**: new `org_supervisor_change($eid,$from,$to,array $history,$claim=null)` → START TRANSACTION;
  `employee_admin_set_supervisor`; `approval_reassign_supervisor` (now `int|false`, no notification); history insert or, for a
  scheduled row, UPDATE `WHERE id AND status='applying' AND applied_at=claim` (must be 1); COMMIT; failure → ROLLBACK. New
  `approval_reassign_notify()` after COMMIT. `org_move_apply` and the Employees page (chart on: the helper; off: set supervisor in a
  transaction) use it; `org_record_employees_page_change` folded in.
- **2.5 F13 claim and recovery** (`applied_at` holds the claim time while applying): claim sets applied_at; before the select, recovery
  via pure `MoveRules::recover(status, claimedAt, note, now)`: applying, claimed > 15 min, note ≠ resumed → back to scheduled
  (note=resumed, conditional, audit `org_move_resumed`, picked up in the same run); stale and resumed → failed/interrupted + audit;
  applying with applied_at NULL (before the upgrade) → failed/interrupted (Q1). Revert / same / failed updates conditional on the claim.
  Duplicate check counts 'applying'. `templates/admin/org-chart.php` `$failed_why` gets 'interrupted'.
- **2.6 Upgrade check**: pure `ShiftPeriods::problems(periods)` (last closed, gap, overlap, same start); once from `shift_history_daily`
  (option `ews_shift_history_checked`): one Audit Log row `shift_history_gap` per affected employee; no repair (Q2).
- **2.7 F14**: `attendance_lock($eid)` around SELECT, insert and audit (try/finally); after the insert the first-row-wins check
  (MIN(id)) as the normal path, a losing row deleted → `already_*`, no audit; lock timeout / SQLite: unlocked, the check decides. Bulk
  per employee: lock, re-check, insert, check, break close conditional on Open and checked (hooks cleared only on 1 row), unlock;
  `attendance_lock($eid,$wait=5)`, bulk 2 s.
- **2.8 F15**: new `src/Support/Lifecycle.php` (`CRON_HOOKS`, `ROLES`, `clearCron()` with `wp_unschedule_hook`, `removeRoles()`);
  deactivate loops sites when network-wide; uninstall requires the file and uses both; roles stay on deactivate. Unit test: every
  scheduled hook literal is listed; role_defs keys equal ROLES.
- **2.9 F10**: `health_build_daily` in a transaction; a failed row insert after the fallback → ROLLBACK + error_log; old rows stay until
  the next hourly rebuild.

## 3. Transactions, locks, concurrency
Named lock, then transaction; no nesting (MySQL < 5.7 releases a held lock on a second GET_LOCK). SQLite rolls back the whole
transaction on a failed statement: every new path stops at the first failure. MyISAM no rollback. Auto attendance: 2 round trips + 1
MIN(id) per event (5,000 employees ≈ 1-3 s). Org moves: recovery fenced by the claim time (a move applies once).

## 4. Upgrade
No migration or schema change; 3.31.119. Old stuck 'applying' rows → failed/interrupted, under Needs attention, cancellable (Q1).
Existing period gaps → one Audit Log row each, once (Q2). Orphan events from earlier deactivations clear at the next deactivate;
uninstalled sites cannot be reached (documented).

## 5. Success path unchanged
Same results, redirects and audit rows when the database works; reassigned-request notification after the commit; normal Sign In/Out
untouched; deactivate/uninstall clear more events.

## 6. Edge cases
Shifts: same-day identical replace, no periods, future-day change while a past one is saved, remove during a save, create failing.
Org moves: run dying with the transaction open, dying twice, upgrade during an old-code run, cancel of applying (after recovery).
Auto attendance: tap during the automatic Sign Out, corrected sign_out, lock timeout, SQLite, break resumed during bulk.
Cron/roles: open breaks lose reminders on deactivate, pending push batches dropped, large networks.

## 7. Tests
Unit: `ShiftPeriodsTest::testProblems`, `MoveRulesTest::testRecover`, `LifecycleTest`. Failure injection: `e2e_break_writes.php` gets
optional verbs (`<table> INSERT`); new `tests/e2e_cleanup_mu.php` (slow-start, slow-timelog, die-orgmove). e2e: shift_history (each
write broken → save and nothing changed; Employees page; the upgrade check once; race on the 6-worker server), org_moves (broken
writes, seeded applying rows, applying blocks a new move, die-orgmove applied once after aging), auto_attendance (two processes at once:
one row; bulk vs the employee's tap; broken writes), new `e2e_lifecycle.py` (deactivate clears all 12, reactivate restores, uninstall
with data kept), system_health (broken daily insert keeps rows). Also: attendance, attendance_parity, employees, org_chart, approvals.

## 8. Risks
15-minute threshold vs a very slow run (fenced; worst case one rollback); new changed/busy messages; bulk slightly slower; SQLite
health fallback leaves old + some new rows until the hour; the upgrade marks every old stuck row failed (HR cancels); the hook scan
asserts literals only.

## 9. Not in this release
The administrator's decision on a multi-level Awaiting swap (`trait-swap.php:185`): approval engine, "one release per approval type",
an owner decision (Q4) → 3.31.120. Tasks claim-first kept.

## 10. Owner questions (recommended first)
1. Moves stuck in "applying" from before: failed ("interrupted"), shown for HR to cancel or redo / back to scheduled automatically.
2. Existing gaps in shift history: an Audit Log row per employee, no repair / nothing / automatic repair.
3. A move interrupted twice: failed / retry every hour.
4. (3.31.120) Administrator approving a swap waiting at level 1 of 2: decide the current level only (as leave and overtime) / the whole
   request at once.

## Owner's answers (2026-10-21)
All as recommended: Q1 old stuck moves → failed ("interrupted"), shown for HR; Q2 one Audit Log row per employee with a gap, no repair;
Q3 a move interrupted twice → failed; Q4 (3.31.120) the administrator decides the current level only, as leave and overtime.

## Implementation notes and review (3.31.119)
Where the code differs from the design:
- The Employees page no longer writes `employees.default_shift_id` itself: the shift history sets it under its lock (review 3), so an
  older form saved at the same moment cannot put the previous shift back. The page reports `save` for any refused shift change (busy,
  changed, a shift that no longer exists); a shift id that no longer exists now blocks the save instead of being stored.
- The create path uses `org_set_supervisor_only` (its two supervisor writes together); the design left it unchanged.
- `shift_sync_current` (the daily job) runs under the employee's shift lock and reads again (review 5).
- `approval_reassign_supervisor` moves only steps still PENDING / WAITING (review 7).
- Not added from §7: `tests/e2e_cleanup_mu.php` (a run that dies is simulated with seeded rows), the shift race on the 6-worker server,
  Bulk Sign Out vs a tap, a broken daily health insert, uninstall with data kept.

Independent review (read-only), findings and what was done:
1. CONFIRMED, MySQL counts changed rows: closing a period that already ends there (the repair the Audit Log asks for) was "changed":
   **fixed** (nothing to close then; the same for the extend on removal); e2e repairs a closed last period.
2. CONFIRMED, the unit test's hook scan missed every five-minute hook (the interval name came first): **fixed** (every ews_ literal
   of the call, minus the interval; the five hooks asserted); e2e_lifecycle checks that no ews_ event is left at all.
3. PLAUSIBLE, the Employees page wrote default_shift_id outside the lock: **fixed** (not written there).
4. CONFIRMED, removing an upcoming change failed when the period before was open (overlapping data): **fixed**.
5. PLAUSIBLE, the daily sync wrote default_shift_id without the lock: **fixed**.
6. PLAUSIBLE, a move claimed by old code during the upgrade is marked interrupted at once: **not changed** (owner Q1; HR sees it and
   dismisses it; a done move then has an extra Audit Log row).
7. PLAUSIBLE, existing: the reassign UPDATE had no status check: **fixed**.
8. PLAUSIBLE, two first requests could both run the one-time check: **fixed** (`add_option` claims it).
9. Nits: the automatic event returns its own id (**fixed**); the losing row's delete is not checked (left: the next run's check finds it).
10. Tests: on MySQL (CI) the lock serialises the two-process race, on SQLite the check after the insert does; both pass the same test.
