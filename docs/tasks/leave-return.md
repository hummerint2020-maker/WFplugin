# Leave return: changing a leave more than once, returns that HR records, and locked leave days (3.31.161): design

The architect wrote this design without editing any files. It was saved before implementation. The owner reported this as a bug on 2026-11-24. The owner's decisions 1–11 are fixed (see the brief). The questions that are still open are in §11. Base: `origin/main` at 3.31.159. PR #61 (3.31.160) is still open. This design does not touch `src/Schedule/ScheduleDayService.php` or `includes/trait-substitutes.php`.

## 0. What happens today (the cause)

The scenario: an employee has a 3-day approved leave. He uses "Change the leave" to cancel 2 days. That is approved and the balance is correct. After that:

1. **He cannot change it again.** There are four blocks:
   - The first approved change sets `cancellation_status='Approved'` and leaves the leave `Approved` with a shorter `end_date` (`leave_apply_cancellation_from`, `includes/trait-leave.php:285-313`). After that, `CancellationRules::checkChange()` / `check()` return `ALREADY_CANCELLED` (`src/Leave/CancellationRules.php:31-35, 52-55`). `Rejected`, `Withdrawn` and `Ended` are refused the same way ("a leave has one change").
   - The back day must be `> start_date` (`checkChange` :36, `firstBackDay` :44). So a leave with one day left, or a leave on its first day, has no option. `leave_cancel_from_day()` (:239) also treats `cancel_from == start_date` as "whole leave". That is correct, but the UI can never send it.
   - `leave_mark_cancellation_pending()` (:323) only moves on from `NULL`/`Not Requested`.
   - The approval engine refuses a second request for the same entity:
     - `ApprovalEngine::start()` → `existingId()` → `approval_already_started` (`src/Approvals/ApprovalEngine.php:88-89`, `Data/WordPressApprovalRequestStore.php:66-70`).
     - **The table also has `UNIQUE KEY entity_workflow(entity_type,entity_id,workflow_id)`** (`includes/trait-approvals.php:88`). So changing only the engine code is not enough: the second INSERT fails at the database.
2. **The manager's workaround.** The manager changed the remaining day in the Attendance grid. There are four write paths:
   - the grid's normal path (`att_grid_save` → `save_att_if_unchanged`, `includes/trait-attendance.php:277-281`);
   - the grid's no-JavaScript path (`:344-350`);
   - `att_single` (:375);
   - CSV import (`parse_csv` :397-425, `att_import` :428).

   None of them knows about leave. The snapshot rows (`ews_leave_schedule_snapshots`) stay, so:
   - The balance stays used.
   - Payroll still pays the day as leave. `payroll_month()` reads snapshots of `Approved` requests (`includes/trait-payroll.php:137`).
   - "Leave type on the Attendance page" still shows it (`WordPressLeaveDayQuery::typesByDay`).
   - If a later cancellation is approved, `leave_restore_days()` (:269-279) writes the pre-leave status over the manager's edit.
3. **Other faults found while reading the code (all about money):**
   - **F-a. A full cancellation returns `requested_days`, not the days actually given back.** It keeps the snapshots (`leave_apply_cancellation` :252-263). A partial one computes `back` from a snapshot SELECT made before any lock (:293-297). Two returns at the same moment (approver + Requests Hub override; and later HR) can both read the same rows and both credit `used`.
   - **F-b. Closed payroll months are ignored.** A cancellation decided late (or one with an old `cancel_from`) returns days and restores the schedule in a month whose payslips are already frozen: the person keeps the leave pay and also gets the days back.
   - **F-c. A late return is never carried into next year.** If days go back to year Y after the Y+1 balance row exists, Y+1's `carried` was computed once in `ensure_leave_balance()` (:38-56) and is never revisited.
   - **F-d. Once rounds exist, levels are detected wrongly.** `leave_without_levels('leave_cancellation',id)` (:112) and `leave_legacy_approvals()` (:703-727) decide "has its levels" by whether *any* approval exists for the leave. A round 2 asked while approvals were off would then be hidden and could not be decided.
   - **F-e. An approver can decide the wrong round.** The card posts only `request_id` (the leave id). An approver whose page showed round 1 could decide round 2 if round 1 was withdrawn and round 2 asked in between.

## 1. Bug fix and feature: what is switched (open question 1)

| Part | Proposal | Why |
|---|---|---|
| R1. Lock the leave row; count returned days from the snapshot rows actually deleted (decision 10, F-a) | **Always on** | Money. When nothing races, the result is identical to today (the whole leave returns `requested_days` as before) |
| R2. Closed payroll month rule (decision 5, F-b) | **Always on** | Money. Inert unless a returned day falls in a closed month |
| R3. Carry-over top-up (decision 6, F-c) | **Always on** | Money. Inert unless the next year's row exists |
| R4. Engine/table allow a new `leave_cancellation` round after a final one; level detection per round (F-d); round check on the decision (F-e) | Always on (code) | Inert until a second round exists, and that only happens with the switch on |
| S1. Back on any day including the first and a single remaining day; more than one change; re-ask after Rejected or Withdrawn | **Switch** `ews_feature_leave_return` | Changes what employees can do. Only works while Leave in My requests is on (the "Change the leave" UI lives there) |
| S2. Approved leave days locked in the grid, single-day edit and import | **Switch** (same) | If the lock were on without S3, a manager could no longer work around a leave stuck on "one change", and HR would have no tool |
| S3. HR records "back at work on day X" (wp-admin Leaves) | Switch (same) | New tool |
| S4. HR report of changed leave days, with its two actions | Switch (same) | New page |

The new option is `ews_feature_leave_return` ("Leave return: back at work on any day, more than one change, HR records a return, approved leave days locked in the schedule, the changed-days report"): `group features`, `kind bool`, `default false`, `page ews31-features`. It is registered in `src/Settings/Options.php` next to `ews_feature_my_requests_leave` (line 90; PR #61 also touches this file, so a small rebase is expected). It is saved in `features_save_handler` (`includes/trait-features.php:~152`, the same pattern as `my_requests_leave`), audited `leave_return_switch`, and its row goes in the My approvals section of Features. The new helper is `leave_return_on()` in `includes/trait-leave-view.php` next to `my_requests_leave_on()` (:94). The employee part (S1) needs `leave_return_on() && my_requests_leave_on()`. S2–S4 need only `leave_return_on()`.

**Off changes nothing visible:**
- R1–R4 only change internals.
- Off, the full cancellation still returns `requested_days` and changes the leave to `Cancelled`, as today.
- Snapshots of a fully cancelled leave are deleted instead of kept. Nothing reads snapshots of non-`Approved` leaves: payroll, LeaveDayQuery and the reports all filter `r.status='Approved'`.
- With the switch off, no rows are written to the new history table.

## 2. Data model and upgrade (schema 3.31.161)

`ews_schema_target()` (`includes/trait-schema.php:341`) becomes `'3.31.161'`, with the comment "Leave return: leave change rounds (ews_leave_changes), approval rounds (ews_approval_requests.entity_round), snapshot day index". PR #61 does not bump it.

1. **New table `{prefix}ews_leave_changes`.** It is the history of a leave's change rounds and is created in `ensure_leave_schema()` (:110) by dbDelta. It belongs to the leave module: add it to `docs/architecture/modules.json` leave.tables and to `uninstall.php:59`.
   ```
   id BIGINT UNSIGNED AUTO_INCREMENT, leave_request_id BIGINT UNSIGNED NOT NULL, employee_id BIGINT UNSIGNED NOT NULL,
   round SMALLINT UNSIGNED NOT NULL DEFAULT 1,
   source VARCHAR(10) NOT NULL DEFAULT 'employee',          -- employee | hr
   asked_from DATE NOT NULL,                                -- the day back at work asked / recorded (start_date = all of it)
   applied_from DATE NULL,                                  -- the first day actually returned (after the closed-month rule)
   status VARCHAR(20) NOT NULL DEFAULT 'Pending',           -- Pending | Approved | Rejected | Withdrawn | Ended | Closed | Recorded
   days_back DECIMAL(8,2) NOT NULL DEFAULT 0, carry_top_up DECIMAL(8,2) NOT NULL DEFAULT 0,
   end_before DATE NOT NULL, end_after DATE NULL,           -- the leave's last day before / after this round
   reason TEXT NULL,                                        -- required for source=hr
   approval_request_id BIGINT UNSIGNED NULL,                -- NULL = asked without approval levels
   requested_by BIGINT UNSIGNED NOT NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   decided_by BIGINT UNSIGNED NULL, decided_at DATETIME NULL,
   PRIMARY KEY(id), KEY leave_round(leave_request_id,id), KEY employee(employee_id,requested_at)
   ```
   - The leave row stays the source of truth for the current round: `cancellation_status`, `cancel_from`, `end_date`, `requested_days`, `cancelled_by/at`. Every existing reader (Hub reports, My approvals, the Leave page) keeps working.
   - The history row adds four things: the per-round approval id (for F-d), the round check (F-e), HR's reason and source, and earlier rounds for display.
   - Leaves changed before 3.31.161 have no rows. Readers fall back to the leave row (no backfill).
2. **`ews_approval_requests.entity_round SMALLINT UNSIGNED NOT NULL DEFAULT 1`**, in `ensure_approval_schema()` (`includes/trait-approvals.php:24`, CREATE at :77-92):
   - The CREATE text gets the column and `UNIQUE KEY entity_round(entity_type,entity_id,workflow_id,entity_round)` instead of `entity_workflow`.
   - On upgrade, dbDelta adds the column and the new key. Then an explicit, guarded `ALTER TABLE … DROP INDEX entity_workflow` runs (only if `SHOW INDEX … WHERE Key_name='entity_workflow'` returns rows). dbDelta never drops indexes, and a key with the same name but other columns would make dbDelta fail.
   - Every existing row is round 1, so the uniqueness of every other request type is unchanged.
3. **Snapshots: `KEY employee_date(employee_id,work_date)`** added to the snapshots CREATE (`trait-schema.php:134-137`). The grid lock read and the report filter by employee or date; today's only key is `request_date(leave_request_id,work_date)`.
4. **Upgrade path.** The switch is off, so nothing changes visibly. `maybe_upgrade_schema()` runs `ensure_leave_schema()` and `ensure_approval_schema()` once under its lock (3.31.151). `tests/e2e_schema.py` checks the new column, key, table and the dropped key on an upgraded and on a fresh site.
   - On SQLite (`DB_ENGINE==='sqlite'`, as handled by `ews_with_lock` at `trait-attendance.php:169-172`), there is no DROP INDEX on a composite unique key and no `FOR UPDATE`. Skip both there, as the lock already does, and document it.

## 3. State machine of a leave's change rounds

Leave `status`: `Pending → Approved → (Cancelled | stays Approved with a shorter end_date)`, plus `Rejected` and `Cancelled` (withdrawn) before approval. These are unchanged.

Current round on the leave row (`cancellation_status`, `cancel_from`):

```
                 employee asks (S1: back day b, start<=b<=end, b>=today)          HR records (S3, any day in [start,end])
 NULL/Not Requested ──────────────────────────────► Pending ◄───────────────┐            │
   ▲  ▲  ▲  ▲                                         │                     │            │
   │  │  │  │   withdraw (employee)                   ├──► Withdrawn ───────┤ re-ask (S1)│
   │  │  │  │   reject (approver)                     ├──► Rejected  ───────┤ re-ask (S1)│
   │  │  │  │   approve, applied_from > end           ├──► Ended (nothing returned; no days left, no re-ask)
   │  │  │  │   approve, some days returned           ├──► Approved, status Approved, end_date shortened ─┤ re-ask (S1)
   │  │  │  │   approve, every remaining day          └──► Approved, status Cancelled (final)
   │  │  │  │
   └──┴──┴──┴─ (S1 re-ask resets cancel_from to the new b; cancellation_status=Pending; a new history row round n+1)
 HR record: from any of NULL / Pending (round closed → history 'Closed', approval CANCELLED skip_reason 'closed') /
            Approved-shortened / Rejected / Withdrawn  ──► Approved (shortened) or Approved+Cancelled; history row source=hr 'Recorded'
```

The rules, pure, live in the new `src/Leave/ReturnRules.php` (§4.1):

- **Asking is allowed when all of these hold:**
  - status `Approved`;
  - cancellation not `Pending`;
  - with the switch, `Approved`, `Rejected` and `Withdrawn` (and `Ended`) no longer block;
  - `max(start,today) <= b <= end`;
  - `b` falls within remaining leave days, i.e. at least one snapshot is on or after `b`.

  "All of it" sends `b = start` (only possible while `today <= start`). Back on the first day (today == start) equals all of it: decision 1.
- **One round at a time.** This is guaranteed by the conditional UPDATE in `leave_mark_cancellation_pending` (now `… AND cancellation_status IN (…allowed…)`), and by the engine refusing a second **open** round.
- **No limit on the number of rounds** (open question 2).
- **Switch off:** `CancellationRules::check/checkChange` keep today's matrix exactly (one change, `b > start`).

## 4. Files and functions

### 4.1 Pure rules (src/, unit tested, no WordPress)

**`src/Leave/ReturnRules.php` (new).** Constants: `NOT_APPROVED, ALREADY_PENDING, DAY, NO_DAYS, CLOSED_PAYROLL, REASON, SELF, ENDED`.

- `checkBack(string $status, ?string $cancellation, string $start, string $end, string $today, string $back, int $daysFromBack): ?string`: the employee's ask (decision 1, 2). `$daysFromBack` is the number of snapshot days on or after `$back`.
- `backRange(string $start, string $end, string $today): ?array{min,max,whole}`, used by My requests:
  - `min = max(start, today)`;
  - `max = end`;
  - `whole = today <= start`;
  - null when `min > end`.
- `effectiveFrom(string $asked, string $end, array $closedMonths): ?string` (decision 5): the first day `>= $asked` whose `Y-m` is not closed, moving to the first day of the next month while a month is closed. Null when that day is `> $end`.
- `daysBack(int $deleted, int $remainingAfter, float $requestedDays, bool $hasSnapshots, int $workingDaysFallback): float` (decision 10):
  - nothing remains → `requestedDays` (all that is still charged, same as today's full cancellation);
  - else → `min(requestedDays, deleted)`;
  - leaves without any snapshot (very old data) → today's working-days fallback.
- `carryTopUp(array $typeConfig, float $entitlement, float $usedAfter, float $pending, float $storedCarried, float $back): float` (decision 6): `max(0, min($back, TypeRules::carried(config, ent, usedAfter, pending) - storedCarried))`. The same rule and cap as `TypeRules::carried()`; it never decreases.
- `hrCheck(array $facts): ?string` (decision 4): reason not empty (`REASON`), not own leave (`SELF`), approved, `start <= back <= end`, `effectiveFrom` not null (`CLOSED_PAYROLL` or `ENDED`).
- `message(string $code): string`, the English source text, translated where it is shown.

**`src/Leave/CancellationRules.php`:** unchanged; it remains the switch-off matrix.

**`src/Leave/MyRequestsLeave.php::itemState()`.** Takes an optional `$rounds` (int) / `$lastSource`. It adds `cancel_decided='recorded'` (HR) and `reaskable` (bool) for the switch-on notes. With the switch off it returns exactly what it returns today.

**`src/Approvals/Workflows.php`:** `restartsAfterFinal(string $entityType): bool` returns `$entityType === 'leave_cancellation'`. Scoped to that one type only (decision 2).

**`src/Approvals/ApprovalEngine.php::start()` (:88-89).**
- If `Workflows::restartsAfterFinal($entityType)`:
  - refuse when `$this->requests->existingOpenId(...)` finds a request that is not `APPROVED`/`REJECTED`/`CANCELLED`;
  - otherwise insert with `entity_round = $this->requests->nextRound(...)` (only when greater than 1, so round-1 SQL stays identical).
- Every other type keeps `existingId()` byte for byte (golden traces).
- If two rounds start at the same moment, the unique key refuses the second. Map the duplicate-key insert failure to `approval_already_started`.

**`src/Approvals/Data/ApprovalRequestStore.php` + `WordPressApprovalRequestStore.php`:** add `existingOpenId(string $entityType, int $entityId, int $workflowId): ?string` and `nextRound(...): int` (`COALESCE(MAX(entity_round),0)+1`). Update the fake `tests/unit/Fakes/MemoryApprovalRequestStore.php` (it implements `existingId` at :75). `findByKey` / `findByKeyGroups` already order `ar.id DESC`, so every reader already sees the newest round (`ApprovalReads::findRequests` keeps the first: `src/Approvals/ApprovalReads.php:53-56`).

**`src/Leave/Data/LeaveDayQuery.php` + `WordPressLeaveDayQuery.php`:** add `heldDays(array $employeeIds, string $from, string $to): array` (employee id → Y-m-d → leave request id) for snapshot days of `Approved` requests. It is the same join as `typesByDay` (:23-31), selecting `r.id`.

**`src/Leave/Data/ChangedDaysQuery.php` + `WordPressChangedDaysQuery.php` (new, leave module):** the report's read, see §4.6. Services: `leaveChangedDays()` in `src/Platform/Services.php` (next to `leaveDays()` at :342).

**`src/Attendance/GridRules.php::message()` (:78).** Add `grid_leave`:
- the status-line text "N change(s) not saved: the day is approved leave. The employee changes the leave, or HR records the return.";
- `error => true`.

This keeps the pop-up out of `trait-frontend.php` (PR #61 conflict), except for one key in `redirect()`'s list (§4.5).

### 4.2 The return itself (includes/trait-leave.php): one function for every path (R1–R3)

**`leave_lock_row($id)` (new).** `SELECT r.*,lt.name type_name,lt.deduct_balance,lt.carry_mode,lt.carry_max,lt.kind FROM … WHERE r.id=%d FOR UPDATE`, without `FOR UPDATE` on SQLite. It is called **first inside the transaction** in each of these:
- `leave_cancel_respond` (:546), before `approval_act`;
- `leave_admin_decide` (:605), before `approval_act`, in its transaction and in `$commit`;
- the HR record (§4.4);
- the report's "put back" (§4.6).

The lock order "the caller's own row first, then the levels, then the request" matches `ApprovalEngine`'s documented order (header comment, :17-21). Each path then re-checks the expected state on the locked row:
- `status='Approved' AND cancellation_status='Pending'`;
- the posted round (§4.3);
- if the state differs, return `'decided'` / `'moved'`.

**`leave_return_from($r, $emp, $bal, $now, $asked, $source, $round_id)` (new).** It replaces the body of `leave_apply_cancellation` (:248) and `leave_apply_cancellation_from` (:285). Both stay as thin wrappers: the first computes `$asked` with `leave_cancel_from_day()` (`null` → `start_date`), as today. Steps:
1. `$eff = ReturnRules::effectiveFrom($asked, end_date, $this->payroll_closed_months($asked, end_date))`. If null, close as `Ended`: conditional UPDATE as at :289. The history row gets `Ended` and a note when a closed payroll month was the reason.
2. `SELECT * FROM snapshots WHERE leave_request_id=%d AND work_date>=%s ORDER BY work_date FOR UPDATE`.
3. For each row, restore the day (`leave_restore_days`, :269). Then `$n=$wpdb->delete(snapshot id)` and `$deleted += ($n===1)`.
   - Restore keeps a manager's edit when the day is no longer `Vacation` (open question 3, recommended): it skips the schedule write and audits `leave_restore_kept`.
4. `$remaining = COUNT(*)` of the leave's snapshots. Then `$back = ReturnRules::daysBack(...)`.
5. If the type deducts and `$back > 0`, run `UPDATE balances SET used=GREATEST(0,used-%f)` on `$bal->id`. Then the carry top-up (§4.2a).
6. Leave row, conditional on the locked state:
   - `$remaining===0` → `status='Cancelled'`, `cancellation_status='Approved'` (today's full-cancel values);
   - else `end_date = eff-1`, `requested_days -= $back`, `cancellation_status='Approved'`.
   - In both cases set `cancelled_by/at`, and `cancel_from = $asked` (unchanged).
7. Substitutes: unchanged calls (`followLeaveEnd` :305, or `substitutes_sync_at_shutdown`). `week_lock_record_request` and the audit `leave_cancelled_from` run as today, plus `days_back`, `applied_from` and the closed months.
8. When the switch is on, update the round's history row: `applied_from`, `days_back`, `carry_top_up`, `end_after`, `decided_by/at`, status (`Approved` / `Recorded`).
9. `$this->leave_cancel_outcome` becomes `'from:'.$eff`, `'all'` or `'ended'`. The employee's message in `leave_cancel_respond` (:586-592) already handles `from:`/`ended`. Add the "closed payroll month" wording when `$eff > $asked`.

**4.2a `leave_carry_top_up($r, $bal, $back, $now)` (new, decision 6).**
- Find the balance row for `leave_year+1` of the same employee and type. If none, do nothing: `ensure_leave_balance()` will compute `carried` from the corrected figures when the row is created.
- If it exists: `$add = ReturnRules::carryTopUp(TypeRules::config($type), year-Y figures after step 5, stored carried of Y+1, $back)`. If `$add>0`, run `UPDATE … SET carried=carried+%f, entitlement=entitlement+%f`.
- Audit `leave_carry_topped_up` with employee, type, Y→Y+1, `+add`. Store it in the history row.
- It runs in the same transaction. Y+2 is not cascaded (open question 7).

**`payroll_closed_months($from,$to)` (new, `includes/trait-payroll.php` near `payroll_run` :214).** One query on `payroll_tables()['runs']` for months between the two dates; returns `string[] 'Y-m'`. Add it to the payroll module api in `modules.json`.

### 4.3 Asking, deciding, withdrawing (includes/trait-leave.php)

**`leave_cancel_request()` (:487).** With `leave_return_on() && my_requests_leave_on()`:
- `lr_mode=all` → `b = start_date`; `back` → the posted `back_on`.
- Rule: `ReturnRules::checkBack(...)` with `$daysFromBack` from one COUNT on snapshots.
- `leave_mark_cancellation_pending($id,$b,true)`. With the new 3rd argument `$again`, the WHERE accepts `cancellation_status IS NULL OR IN ('Not Requested','Approved','Rejected','Withdrawn')` with `status='Approved'`, and `cancel_from` is always set.
- Insert the history row (round = previous + 1, `approval_request_id` = the started approval or NULL) in the same transaction.
- New refusal messages, through `leave_cancel_error` → the My requests pop-up: `day`, `no_days`.
- The approver notification text is unchanged. When `b == start`, word it as "back at work on the first day: the whole leave".
- With the switch off, the code is exactly as it is now.

**Round check (F-e).** `leave_approval_card()` (:666) adds `fields['round']` (history id, or `cancel_from` when there is no history). `leave_cancel_respond` and `leave_admin_decide` compare it after `leave_lock_row`; a different round → `leave_error=moved` ("This request changed since you opened it"; message exists).

**`leave_without_levels()` (:112) and `leave_legacy_approvals()` (:703-727) (F-d).** For `leave_cancellation`, "has its levels" means the current round's history row has an `approval_request_id`, or there is no history and an approval exists (today's rule). The same applies to the `approval` key in `leave_my_request_item` (:770): no approval path when the round has none.

**`leave_approval_card()` (:666), decision 5 shown to the approver.**
- The days back come from the snapshot count on or after `effectiveFrom`. Today it uses `vacation_working_days_count`.
- New facts: "Payroll of {month} is closed: days before {date} stay leave", "Change {n}", and "Next year's balance +X" when the top-up would apply.
- The card is only built where it is shown (`$light` returns early, :670).

**`leave_cancel_withdraw()` (:789).** Comment and audit stay. Set the history row to `Withdrawn`. With the switch on, the confirmation text in `templates/app/requests.php:70` no longer says "only once".

**`leave_my_request_item()` (:749) / `leave_change_offer()` (:777).** With the switch on:
- the offer comes from `ReturnRules::backRange` and the leave's remaining snapshot days;
- the offer is shown for `Approved` + not `Pending` (including after Approved-shortened, Rejected, Withdrawn).

New notes:
- "Shortened at your request. You can change it again."
- "Cancelling it was refused. You can ask again."
- "HR recorded you back at work on %s: %s day(s) back. Reason: %s"
- "Earlier changes: …" (the last 3 history rows, newest first).
- A closed-month note.

With the switch off, the notes stay byte-identical.

**`templates/app/requests.php` (:74-95) and `assets/js/my-requests.js` (:85-104).**
- The date `min` may equal `start`.
- A fixed single day shows as text, with a hidden input.
- The sum line for `b == start` reads "The whole leave is cancelled; N days go back".
- The "This is the one change this leave can have" line is swapped for "You can change it again later while days remain" when the switch is on.

`tests/leave_change_browser.js` is extended.

### 4.4 HR records "back at work" (includes/trait-leave-admin.php, decision 4)

- **UI.** wp-admin → Leaves gets a new section/tab, "Back at work" (`?page=ews31-leaves&tab=return`):
  - Pick an employee, then the leave (approved leaves with snapshot days in open months, the newest first), the day and a **required** reason.
  - **Preview** (GET) shows: the days returned (range, count), the closed-month shift, the balance after, the next year's top-up, and the employee's pending change that will be closed. HR then confirms.
- **Handler.** `admin_leave_return_record()`, admin-post action `ews_leave_return_record`, registered in `src/Leave/Hooks.php` (golden: new admin-post name).
  - Checks: `can('ews_manage_settings')`, nonce, `leave_return_on()`.
  - `ReturnRules::hrCheck()`. `SELF`: the leave's employee `wp_user_id == get_current_user_id()`.
  - The POST carries the previewed `applied_from` and `days_back`. They are recomputed under the lock; if they differ → "The leave changed since the preview; check it again".
  - `ews_with_lock('leave_emp_'.$emp->id, …)` (as at `trait-leave-admin.php:79`), then a transaction:
    1. `leave_lock_row`.
    2. If `cancellation_status='Pending'`: `approval_close_open('vacation','leave_cancellation',$id,true)`. `'moved'` → roll back, "decided meanwhile". Then mark the history row `Closed`.
    3. Insert the history row (`source=hr`, reason).
    4. `leave_return_from(..., 'hr', ...)`.
    5. Commit.
- **After the commit:**
  - Notify the employee with "HR recorded that you were back at work on X: the days from Y on went back to your balance (N). Reason: …". If a pending change was closed: "Your change request was closed because HR recorded your return."
  - Notify their manager: `people_manager_of()` → that manager's `wp_user_id`, skipped when it is HR themself.
  - Audit `leave_return_recorded`, with the HR name, reason, days and applied_from.

### 4.5 Approved leave days locked in the schedule (includes/trait-attendance.php, decision 3)

`leave_held_days(array $eids,$from,$to)` is a new function in `trait-leave.php` wrapping `heldDays`; add it to the leave module api in `modules.json`. It returns `[]` when the switch is off, with no query. Read once per request, then:

| Path | Where | When the day is held by an approved leave |
|---|---|---|
| Grid, normal path | `att_grid_save` after the week-lock check (:277-279) | `$leave_locked++; continue;` before `save_att_if_unchanged`. Also skip `branch_plan_day`. The override permission does not bypass it |
| Grid, no-JavaScript path | :344-350 | Skip the day completely (status and note); count it only if the posted status differs |
| Single day | `att_single` (:375) | `wp_die` with the message, after audit |
| CSV preview | `parse_csv` (:415-418) | Row error "Approved leave (#id): HR records the return" (one held read for all rows' employees, min..max date) |
| CSV import | `att_import` (:428) | Re-read; skip and count (`$bad++`, `$leave_locked++`) |

- The redirect adds `grid_leave=N`. Add `'grid_leave'` to `redirect()`'s list of one-time keys (`includes/trait-frontend.php:666`; one word; rebase on PR #61).
- Audit: one `schedule_leave_refused` row per request, with the source and up to 20 `eid date` pairs.
- **UI** (`includes/trait-attendance-grid.php::attendance_content()` passes `held` for the page's employees and dates, one read):
  - `templates/app/attendance.php`: a held cell's chip (:287) renders `disabled aria-disabled`, with a lock icon (as `wfo-wl-cell-lock`) and `title="Approved leave – HR records the return"`, plus `data-held="1"` on the td.
  - `assets/js/attendance-grid.js`: `cells` (:15) excludes `[data-held]` from fill-day (:167-176), fill-branch and undo.
  - The CSV help text gets one line.

  There is a single grid copy today (one picker for desktop and phone). The legacy `mobile_att` merge exists only server-side and is covered by the fallback guard.
- **Pending leave days** have no snapshot, so they stay editable (decision 3).
- **Residual race.** A leave approved between the held read and an import or no-JavaScript save can be overwritten. The normal grid path is safe (its `original` no longer matches → conflict), and §4.6 catches the rest.

### 4.6 HR report "Changed leave days" (decision 7; place: open question 4)

- **Place.** wp-admin → Leaves → tab "Changed leave days" (`tab=changed`); capability `ews_manage_settings` (HR's page; the Requests Hub reports are administrators only).
- **Read.** `ChangedDaysQuery::page($from,$to,$employee,$dept,$limit,$offset)`, using the new `employee_date` key:
  ```
  SELECT s.id snap_id,s.employee_id,s.work_date,r.id leave_id,r.start_date,r.end_date,t.name type_name,
         sc.id sched_id,sc.status,sc.updated_by,sc.updated_at
  FROM snapshots s JOIN leave_requests r ON r.id=s.leave_request_id AND r.status='Approved'
  LEFT JOIN leave_types t … LEFT JOIN schedule sc ON sc.employee_id=s.employee_id AND sc.work_date=s.work_date
  WHERE s.work_date BETWEEN %s AND %s AND s.work_date<%s(today) AND (sc.id IS NULL OR sc.status<>'Vacation')
  ORDER BY s.work_date DESC LIMIT 50 OFFSET …
  ```
- **Columns:**
  - employee (department);
  - leave (type, dates, #id);
  - the day;
  - schedule now (status / "No schedule row");
  - changed by / when (`updated_by` display name and `updated_at`; the matching `attendance_grid`/`attendance_single`/`attendance_bulk_import` audit row when there is one);
  - actual attendance (sign-in/out times, or "No record"), from `insights_load()` (add it to the attendance module api; the attendance grid already uses it);
  - payroll month (Open / Closed).
- **Actions (open months only; closed months show "Payroll closed: stays leave"):**
  - "Back at work from this day" → the §4.4 preview, prefilled. It returns this day **and every later day of the leave** (open question 6).
  - "Put the day back to leave": admin-post `ews_leave_day_restore`, nonce. Under the employee lock and a transaction: `leave_lock_row`, then check that the snapshot still exists and the status is not `Vacation`. Write `status='Vacation'`, `note='Approved leave #id'` directly, as `leave_apply_final_approval` does (:206/210), with no `save_att` notification. Then `week_lock_record_request`, and audit `leave_day_restored` (old → Vacation).
- **Filters:** period (default: the last 3 months), department, employee; 50 per page. CSV export: open question 14.

### 4.7 Hub reports and other places that list requests (decision 2, "newest round")

- **Already correct:**
  - `WordPressHubReportQuery` (:34-37) picks the newest approval's `created_at`, and the status comes from the leave row (the current round).
  - My approvals and the inbox read `findByKey` / `findRequests` (newest).
  - Approval deadlines read only open steps.
- **Additions:**
  - `HubReport` row note: "change {n}" when `round > 1`; "Recorded by HR: {reason}" for `source=hr`. It is read from `ews_leave_changes` in `rows()` (:110-113) with one extra query per page.
  - "Decided by" for a round without levels uses `cancelled_by` (not the old round's approver): check `created_at < history.requested_at`.
  - `HubReport::STATUS_MAP['leave_cancellation']` is unchanged; the HR round's leave row is `Approved`.

## 5. Module, layer and other repository checks

- `docs/architecture/modules.json` (PR #61 touches it):
  - leave tables += `leave_changes`;
  - leave api += `leave_held_days`, `leave_return_on`;
  - payroll api += `payroll_closed_months`;
  - attendance api += `insights_load`.
  - Then run `tests/check_modules.py` and `tests/check_layers.py`. The new src classes are pure (Leave) or Data adapters (Leave/Data).
- `tests/golden/expected.json` (PR #61 touches it): the `ews31-leaves` and `ews31-features` pages, the attendance page (switch on only), the approvals action traces for `leave_cancellation`, and new admin-post names `ews_leave_return_record`, `ews_leave_day_restore`.
- `CHANGELOG.md` 3.31.161; plugin version in `employee-schedule-manager.php`.
- No new trait file, so the `use` line at :107 is unchanged.
- Translations: every new string, plus Arabic. Check with `tests/check_translations.py`, and regenerate `.pot` / `.json` for my-requests.js and attendance-grid.js.
- HTML mockups first (CLAUDE.md): the locked grid cell, My requests change sheet (first day, single day, second change), the HR preview and confirmation, the changed-days report. Save them under `docs/mockups/leave-return/`.

## 6. Edge cases

1. **Back on the first day while today == start.** The whole leave is cancelled on approval. If the approval comes days later, the result is still the whole leave (decision 1: "from that day even if approved later"), unless that day's month has closed meanwhile → partial from the first open day.
2. **One remaining day** (the owner's case after the first change): `b = start = end` → the whole leave, so the status becomes `Cancelled` and 1 day is returned.
3. **Shortened, then re-asked before the original first day.** `start` is unchanged. "All of it" is offered only while `today <= start`.
4. **A leave across two months, one of them closed.** Only the open month's days are returned. The approver/HR see it before confirming. If every remaining day is in closed months → `Ended` ("payroll closed").
5. **Payroll closes while a return runs.** It is not locked against the return: payroll close does not lock leave rows. Risk R3; re-read the closed months inside the transaction just before the DELETEs.
6. **A payroll month reopened later.** Days that were kept stay leave; HR can record the return again (a new round).
7. **`deduct_balance=0` types.** `back` is computed and audited, but no balance UPDATE runs. The snapshots are deleted, so payroll treats the days as normal work days (decision 9). Same for `paid_percent<100`.
8. **Leaves without snapshots (pre-snapshot data).** Today's working-days fallback (:296). The grid lock cannot see them, and neither can the report.
9. **The legacy `ews_vacation_requests` table.** No snapshots, so not locked and not reported.
10. **Approvals were off when round 1 was asked and on for round 2 (and the reverse).** F-d: per-round `approval_request_id`.
11. **Withdraw, then re-ask while the old approval's levels are being decided by someone else.** `closeWithdrawn` decides; the new round can start only after the old approval is `CANCELLED` (`existingOpenId`).
12. **Approver and HR at the same moment.** Both lock the leave row first. The second finds the state changed → `decided` / `moved`. The balance is returned once (decision 10).
13. **Approver and Requests Hub override.** Same as 12.
14. **HR records on their own leave** → `SELF`.
15. **An archived employee.** `leave_active_employee` (:142) keeps today's behaviour (`people_employee(...,true)`).
16. **The manager is the HR person recording.** They are not notified twice.
17. **Week lock.** Returns still write and are recorded (`week_lock_record_request`). The held-day lock applies even with "Edit a locked week". "Put back" is recorded like a leave write.
18. **A held day that a manager changed before 3.31.161.** It stays locked. HR fixes it from the report.
19. **The next year's row exists and its entitlement was set by hand** (`admin_leave_balance_save`): the top-up still adds on top, audited.
20. **Year Y+2.** Not cascaded (open question 7).
21. **Substitutes.** `followLeaveEnd` runs on every shortening and `substitutes_sync_at_shutdown` on a full cancel. No edit to `trait-substitutes.php`.
22. **The Leave page (old) with the switch on.** It keeps its "cancel before start" form (`leave_cancellation_allowed` :482). Rounds are offered only in My requests.
23. **Double click / two tabs asking a round.** The conditional UPDATE plus the engine's open-round check and the unique key.
24. **Off changes nothing.** The grid has no held read, `CancellationRules` is unchanged, and no history rows are written. The only off-differences are the internal ones: lock, snapshot deletion on a full cancel, closed months, carry.

## 7. Tests

**Unit (tests/unit):**
- `ReturnRulesTest`:
  - the `checkBack` matrix (first day, single day, yesterday, after the end, Pending, Approved-shortened, Rejected, Withdrawn, Ended, Cancelled, no snapshot on or after b);
  - `backRange`;
  - `effectiveFrom` (no closed month, the asked month closed, two consecutive closed months, all closed → null, the month boundary);
  - `daysBack` (emptied, partial, fallback, deleted > requested);
  - `carryTopUp` (none / all / max, already at cap, headroom smaller than back, back 0);
  - `hrCheck` (reason, self, not approved, out of range, closed).
- `LeaveRulesTest`: `CancellationRules` unchanged (pins the switch-off behaviour).
- `MyRequestsLeaveTest`: recorded, reaskable; byte-identical when off.
- `ApprovalEngineTest` + `MemoryApprovalRequestStore`:
  - a second `leave_cancellation` is refused while the first is open and allowed after APPROVED/REJECTED/CANCELLED with `entity_round=2`;
  - `leave` and `overtime_request` are still refused after final;
  - a duplicate-key insert → `approval_already_started`.
- `AttendanceGridTest`: `GridRules::message` with `grid_leave`.
- `HubReportTest`: note for round > 1 and HR.
- `OptionsTest`: the new option.

**End-to-end, `tests/e2e_leave_return.py` (new).** With the switch on (and off for the "nothing changes" pass):
- The owner's scenario: 3 days; back on day 2 → approved (+2); ask again for the remaining day (b = start) → approved → `Cancelled`, +1, snapshots gone, payroll pays nothing as leave.
- With levels on: two approval requests with `entity_round` 1 and 2; My requests and My approvals show the newest; Hub reports show one row, "change 2".
- Re-ask after Rejected and after Withdrawn.
- Back on the first day today.
- The approver's stale round → `moved`.
- Closed payroll month (late decision and HR past date) → only open-month days are returned, the card shows it, all closed → `Ended`.
- Carry top-up when Y+1 exists (`none` / `max` / `all`) and its audit; no Y+1 row → nothing.
- `deduct_balance=0`.
- HR record:
  - reason required;
  - own leave refused;
  - closes a pending round (the approval is `CANCELLED` with `skip_reason closed`, the employee is told);
  - the employee and manager are notified;
  - audit has HR's name;
  - preview mismatch refused.
- Concurrency (as `e2e_request_races.py`): an HR record and an approval in parallel, and an approval and a Hub override in parallel → `used` decreases exactly once.
- Upgrade: `entity_round` added, `entity_workflow` dropped, `entity_round` key present (also in `e2e_schema.py`).

**End-to-end, `tests/e2e_leave_lock.py` (new), or added to `e2e_attendance_grid.py`:**
- Grid normal path refused for a held day (a manager, and a user with "Edit a locked week").
- Grid no-JavaScript path skips held days (the note is untouched too).
- `att_single` refused.
- CSV preview row error and import skip.
- A pending leave day stays editable.
- `grid_leave` message and audit.
- Held cell disabled in the HTML.
- Switch off → everything editable as before.

**Browser:**
- `tests/leave_change_browser.js`: the first-day option, a single fixed day, the second change.
- A grid browser check: fill-day skips held cells; a held chip cannot be clicked (desktop and 390 px).

**Existing suites, run with the switch off and on:** `e2e_leave.py`, `e2e_leave_requests.py`, `e2e_leave_admin.py`, `e2e_leave_view.py`, `e2e_my_approvals.py`, `e2e_my_approvals_legacy.py`, `e2e_approval_engine.py`, `e2e_approvals.py`, `e2e_self_approval.py`, `e2e_week_lock.py`, `e2e_payroll.py`, `e2e_payroll_close.py`, `e2e_money_safety.py`, `e2e_hub_reports.py`, `e2e_admin_requests.py`, `e2e_attendance_grid.py`, `e2e_leave_type.py`, `e2e_request_races.py`, `e2e_schema.py`, `e2e_features.py`, `e2e_settings_overview.py`, golden.

**Checks:** phpunit, phpstan, phpcs, `check_modules.py`, `check_layers.py`, `check_translations.py`.

## 8. Risks

1. **Money: the full-cancel change (R1).** Deleting snapshots and computing from the deleted rows must equal today's `requested_days` result when nothing races. `daysBack` returns `requestedDays` when nothing remains, and a test pins it.
2. **Approval table upgrade.** Dropping `entity_workflow` removes the guard for every type at the database level for a moment. `entity_round` keeps uniqueness for the other types, which are always round 1. On a failed ALTER, keep the old key and refuse round 2 (engine error) rather than allow duplicates. On SQLite the key cannot be swapped, so a round 2 is refused there: document it.
3. **Payroll close race** (edge case 5): narrowed by re-reading inside the transaction, but not closed. Payroll close would have to lock leave rows, which is out of scope.
4. **Lock order.** Every path that takes the leave row lock must take it before `approval_act`, `closeOpen` or `closeWithdrawn`, or it can deadlock with a decision. Reviewers should check each call site (§4.2).
5. **Grid lock with no way out.** If the switch were split and S2 on without S3, managers would have no remedy. This is why one switch is proposed.
6. **Pre-release inconsistencies.** Held days changed before the release are paid as leave until HR acts on the report.
7. **Restore keeping the manager's edit** (if chosen) diverges from today's behaviour for pre-release edits.
8. **Unlimited rounds** may flood approvers (open question 2).
9. **Merge conflicts with PR #61:** `Options.php`, `trait-frontend.php` (one key), `modules.json`, `golden/expected.json`, CHANGELOG, the main file version.
10. **The report query on large snapshot tables.** Mitigated by the new `employee_date` index and paging; the period default is limited.
11. **The SQLite path** lacks `FOR UPDATE`. The employee leave lock (`ews_with_lock`) remains the only serializer there, and it is unsupported on SQLite as well.

## 9. Release split

One release, 3.31.161: §2–§7. Not in it: the check-in-on-leave alert (decision 8), returning a single middle day without the later days, cascading the carry top-up beyond the next year, and a mobile API field for held days.

## 10. Order of work

1. Mockups.
2. Pure rules and unit tests.
3. Schema plus the engine and store (`entity_round`).
4. `leave_lock_row` and `leave_return_from` (R1–R3), with existing suites green and the switch off.
5. Rounds (S1) and the round check / levels per round.
6. Grid lock (S2).
7. HR record (S3).
8. Report (S4).
9. Hub notes.
10. Translations, golden, modules, CHANGELOG.

## 11. Open questions for the owner (recommended first)

1. **Switch.** (a) Money fixes always on (count returned days from the snapshots actually deleted under a row lock; closed payroll months stay leave; next year's carried topped up). Everything else (more than one change, first and last day, locked leave days in the schedule, HR records the return, the report) behind one new switch, off by default. / (b) Everything behind the switch. / (c) Everything always on as a bug fix.
2. **Rounds.** (a) No limit; asking again is allowed after approved, rejected or withdrawn while days remain. / (b) At most N changes per leave. / (c) No new ask after a rejection.
3. **When a cancellation restores a day that a manager already changed (only possible for edits made before this release):** (a) keep the manager's status and record it in the Audit Log / (b) restore the pre-leave schedule over it (today's behaviour).
4. **Where the "Changed leave days" report goes:** (a) wp-admin → Leaves, a new tab (HR, Manage Settings) / (b) Requests Hub → Reports (administrators only) / (c) both.
5. **Report scope:** (a) past days only (as asked) / (b) past and future days, the future ones marked "changed before the lock".
6. **"Back at work from this day" on a day in the middle of a leave.** It also cancels every later day of that leave. (a) Accept that, shown in the preview / (b) also add "return this one day only" (it splits the leave in two; a larger change, a later release).
7. **Carry top-up:** (a) never more than the days returned, and only into the next year / (b) whatever the rule now allows (could exceed the days returned when other figures changed) / (c) cascade to later years too.
8. **HR records the return on a leave that has already ended** (all days in the past, open months): (a) allowed / (b) only while the leave is current or future.
9. **The employee's day back:** (a) today or later only (as now) / (b) also a past day of the current leave, for example yesterday.
10. **Administrators in the Attendance grid:** (a) locked like everyone (decision 3 read strictly) / (b) WordPress administrators may still edit, audited.
11. **Days of the old vacation table (before leave types):** (a) stay editable and are not reported / (b) treated like approved leave.
12. **Report export:** (a) CSV and Excel like Hub reports / (b) none in this release.

### Critical Files for Implementation
- /home/user/wfplugin/workforce-one/includes/trait-leave.php
- /home/user/wfplugin/workforce-one/includes/trait-attendance.php
- /home/user/wfplugin/workforce-one/includes/trait-leave-admin.php
- /home/user/wfplugin/workforce-one/src/Approvals/ApprovalEngine.php (+ src/Approvals/Data/WordPressApprovalRequestStore.php, includes/trait-approvals.php schema)
- /home/user/wfplugin/workforce-one/includes/trait-schema.php (+ new /home/user/wfplugin/workforce-one/src/Leave/ReturnRules.php)
