# 3.31.117: corrections and swap integrity (F06, F07, F05) — design

PR B of `docs/tasks/review-codex-3.31.115.md`. Fixes to existing behaviour: no new feature, no switch, no schema change. Base: main
ce975c4. Owner decision 2026-10-21: **the leave wins over overtime.** Paths under `workforce-one/`.

## 1. Root causes
**F06** (`includes/trait-corrections.php`)
- `cx_no_work_day()` (`:150-157`) returns false (correctable) as soon as `overtime_approved_dates()` (`trait-overtime.php:328-335`) finds an
  approved row, before any leave/holiday/day-off check. Overtime creation does not refuse leave days.
- The docblock `src/Attendance/CorrectionRules.php:19` and the 3.31.115 CHANGELOG ("a day off or holiday with approved overtime") disagree;
  the code follows the wider docblock.
- The rule exists twice: `cx_no_work_day()` → `cx_facts()['no_work']` (`:124`: `cx_create` Web `:584` / API `:985`, `cx_should_close`
  `:384-390` at decision time in `cx_decide` `:357` and `cx_finalize` `:428`); and `cx_recent_days()` (`:650`, "My recent days", the sheet,
  API `corrections/days` `:969`) computes `$no_work` again from the report result plus `$overtime`.
- The report result alone cannot find every leave: Holiday has precedence over the plan in `DayMetrics::compute` (a leave across a public
  holiday reads "Holiday"), and `report_off_day()` ignores the schedule (a leave on a weekly day off reads "Off Day"). The reliable signal is
  the schedule row: `Insights::planCategory(status, attendance_rule) === 'Leave'`. No half-day leaves exist.

**F07**
- `cx_close()` (`:393-399`) calls `approval_close()` (`trait-approvals.php:828-836`, "the caller owns the transaction"), ignores its result,
  opens no transaction, closes the engine first and only then updates the correction conditionally (`cx_finalize(false)`, `:413-421`).
  (a) A concurrent decision moved the correction first → `done`, but the engine is already CANCELLED. (b) `pending_hr` (engine APPROVED):
  `approval_close` returns false, silently.
- Same root: `cx_withdraw()` (`:1034-1044`) rolls back with `save` when `approval_close` returns false, so a `pending_hr` correction under
  an active workflow cannot be withdrawn although My requests shows Withdraw (`:1027`).
- Established pattern (leave/overtime/advance/correction withdraw): START TRANSACTION → conditional entity update (`!==1` → `done`) →
  `approval_close` (false → ROLLBACK, `save`) → COMMIT; audit and notifications after. No `ews_with_lock`.

**F05** (`includes/trait-swap.php` `swap_apply`, `:64-79`)
- Reads (`:66-67`) and `checkStillCurrent` (`:68`) before START TRANSACTION (`:71`); the two schedule UPDATEs (`:72-73`) match `id` only, 0
  rows counts as success. A schedule write in between (a leave approval writing `Vacation` by id, a grid save, a leave-cancel restore,
  another swap) is overwritten: worst case Office/WFH back over a just-approved leave.
- The swap is claimed (`swap_close`, `:75`) after the schedule writes. Callers: `swap_request_respond` (`:146`), `swap_admin_decide` (`:180`),
  `swap_finish_approved` (`:233-234`, from `swap_manager_decide` `:257` and `swap_to_approvers` `:212`).

## 2. Changes
**2.1 F06, one pure rule** `CorrectionRules::noWork(bool $leave, bool $overtime, bool $working_day, string $result): bool`:
leave → true; overtime → false; not a working day → true; else `in_array($result, NO_WORK_RESULTS)`. Docblocks `:19`, `:27-30` fixed.
`NO_WORK_RESULTS`, `kinds()` and the "something recorded" exception (`check()` `:46`, `kinds()` `:140`) unchanged.

**2.2 `cx_leave_dates($emp,$from,$to)`** (array<string,true>): one query on `ews_schedule` for the range, keeping rows whose
`Insights::planCategory($status, schedule_type_config($status)['attendance_rule'] ?? null) === 'Leave'`.

**2.3 `cx_no_work_day`** → `CorrectionRules::noWork(leave, overtime, is_working_day, report result)`; the report query skipped when leave
is true or the day is not a working day.

**2.4 `cx_recent_days()`** reads `cx_leave_dates` once and uses the same `noWork` (plus the start-date rule), so Web, API, "My recent days",
`cx_create` and `cx_should_close` share one rule.

**2.5** `no_work` message unchanged (already says "a leave day … Ask HR").

**2.6 F07, `approval_close_open($workflow_key,$entity_type,$entity_id)`** in `trait-approvals.php` (corrections only; `approval_close`
unchanged): no request, or APPROVED/REJECTED/CANCELLED when read → `true`; else the same UPDATE as `approval_close` conditional on the
status read (`false` → false, 0 rows → `'moved'`), then the instances UPDATE (`false` → false); `substitutes_sync_at_shutdown()`. Not
`Hub::isOpenApproval` (it treats CANCELLED as open).

**2.7** `cx_finalize(false)` split into `cx_mark_rejected($c,$note)` (conditional UPDATE) and `cx_after_rejected($c,$note)` (audit,
notification); behaviour unchanged.

**2.8 `cx_close($c)`**: START TRANSACTION (false → `save`); `cx_mark_rejected` (false → ROLLBACK `save`; `!==1` → ROLLBACK `done`);
`approval_close_open` (`'moved'` → ROLLBACK `done`; false → ROLLBACK `save`); COMMIT (false → ROLLBACK `save`); then `cx_after_rejected`,
audit `correction_closed`, return `closed`. `pending_hr`: the engine stays APPROVED (as an HR rejection today). Visible outcome unchanged.

**2.9 `cx_withdraw`** uses `approval_close_open` (`'moved'` → `done`, false → `save`): fixes withdrawing a `pending_hr` request (Q1).

**2.10** `cx_error_message('save')`: "The decision could not be saved; nothing was changed. Please try again."

**2.11 F05, `swap_apply`**: read and `checkStillCurrent` as today; START TRANSACTION; claim first `swap_close(id,'Accepted',$r->status)`
(false → `save`; `!==1` → `expired`); two conditional UPDATEs in ascending id order, WHERE id, employee_id, work_date and the status read
(false → ROLLBACK `save`; `!==1` → ROLLBACK `changed`); COMMIT (false → `save`). The existing `changed` code and message are reused; the
swap stays Pending (Awaiting in approval mode), nothing applied, no completed notification or audit.

## 3. Transactions, locks, concurrency
No new named lock: every guard is a conditional UPDATE inside one transaction (the other schedule writers take no lock). InnoDB: the
second writer waits on the row lock, then matches 0 rows; SQLite: the row count refuses; MyISAM: no rollback (existing risk).
`cx_close` runs before `cx_finalize`'s own transaction and after `approval_act` has committed (no nesting). Swap deadlock or lock timeout
→ a query returns false → rollback, `save`.

## 4. Upgrade
No migration. Pending requests made correctable by F06: at approval `cx_should_close` now holds → closed automatically (rejected with the
note, nothing applied, engine cancelled if open, employee told); reject and withdraw work as normal; HR's own corrections never closed.
Requests approved before 3.31.117: not repaired automatically (Q2). Version 3.31.117.

## 5. Off by default / unaffected
Corrections off: nothing runs. Overtime off: leave days were already `no_work`. Only days that are a personal leave in the schedule **and**
have approved overtime **and** nothing recorded change. Swaps: same success path; a lost race now returns `changed`/`expired`. Leave,
overtime and advance withdraws untouched.

## 6. Edge cases
F06: leave+overtime+Sign In stays correctable (fitting kinds only); leave+holiday+overtime and leave on a weekly day off+overtime not
correctable; holiday+overtime and day off+overtime correctable; a pending leave is not a leave (closed at decision if approved by then);
a cancelled leave is correctable again; hand-set `Vacation` counts; custom `leave`-rule types count (inactive included); a deleted type
name does not unless literally `Leave`; business trip / training not a leave.
F07: no workflow → no engine request; NONE with a leftover `pending` (engine APPROVED) skipped; levels → instances cancelled; HR closing a
`pending` request in wp-admin: same.
F05: row deleted in between → `changed`; an edit keeping the status still matches; approval APPROVED but swap `changed` → stays Awaiting
(Q4); two accepts → one `accepted`, one `expired`.

## 7. Tests
- Unit `CorrectionRulesTest::testNoWork` truth table.
- `e2e_corrections.py`: leave+overtime nothing recorded (no Correct, `no_work` Web/API, `can_request=false`); leave+holiday+overtime,
  leave on a day off+overtime; leave+overtime+Sign In correctable; holiday+overtime still correctable; upgrade rows (`pending` closed on
  approve, `pending_hr` closed by HR, reject keeps the note); F07: LEVEL_1 close cancels the engine and instance; NONE+`needs_hr` closed
  (engine stays APPROVED, notified, audit); stale `$c` → `done`, engine unchanged; `break_writes` on approval requests / corrections →
  `save`, nothing changed; new `tests/e2e_corrections_mu.php` mode `engine-moved` → `done`, rolled back.
- `e2e_my_approvals.py`: withdraw a `pending_hr` correction with an active workflow.
- `e2e_swap.py` with new `tests/e2e_swap_mu.php` (stale read of one schedule row): accept → `changed`, nothing changed (requester's and
  target's row, admin page, approval mode); `break_writes('ews_schedule')` / `('ews_schedule_swaps')` → `save`, claim rolled back.
- Also: unit, phpstan, phpcs, modules, translations, e2e overtime, leave, approval engine, request races.

## 8. Risks
Some leave days become non-correctable (owner decision; the message says ask HR). Leave read from the schedule differs from the shown
result on leave+holiday days (intended). `approval_close_open` limited to corrections. Cancelled levels shown as "Withdrawn by the
employee" on an auto-closed correction (Q3, existing). Swap deadlocks surface as `save`. MyISAM. Out of scope, noted:
`leave_apply_final_approval` restores by snapshot and id (a swap in between is lost on a later leave cancel); overtime on leave days (Q5);
a swap stuck Awaiting with its engine APPROVED after `changed` (Q4).

## 9. Owner questions (recommended first)
- Q1 withdrawing a correction waiting for HR (fails today): **fix in this PR**.
- Q2 corrections already approved on a leave day with overtime: **no automatic repair**; CHANGELOG tells HR how to find them.
- Q3 auto-closed correction shown as "Withdrawn by the employee": **show "Closed automatically"** (display only) / leave as is.
- Q4 a swap refused with `changed`: **keep it Pending/Awaiting** (can be rejected or cancelled); auto-expire later as its own decision.
- Q5 overtime requests on a leave day: **not refused in this PR**; separate decision.
- Q6 the auto-close note for upgrade-case requests: **keep the existing text**.
