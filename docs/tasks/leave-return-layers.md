# Leave return: moved into layers (3.31.164): design

**Status:** written by the read-only architect on `main` b6f7ec7 (3.31.162), saved before implementation (CLAUDE.md). Owner request
(2026-11-24): "ابدا ب 1", the planned move-only release for the 3.31.161 leave-return code (PR 62). It assumes PR #64 (3.31.163,
attendance corrections balance) is merged first; that PR does not touch the leave files, so the line numbers below should still hold.
Recheck them after the rebase.

This is a **move only**:
- the same SQL text and order, the same locks, transactions, audit rows, notifications, redirects and messages;
- no new option, no change to `ews_schema_target()`, no new hook.

`tests/golden/expected.json` changes only its `"release"` (to `3.31.164`); `pages`, `actions` and `hooks_added` stay empty. Precedent:
the `leave.holidays` slice (3.31.162: `HolidayRefundService` + `WordPressLeaveHolidayStore` + `HolidayRefundPorts`). Format as in
`attendance-auto-manual.md`.

## 0. What exists today (measured)

### 0.1 The leave-return path, function by function

"L1" is what `tests/check_layers.py` counts in that function body today (references to layered tables). `leave_changes` is not layered
today, so it is not counted yet; its references are given in brackets. Kind: **M** moved (the trait keeps the name and signature as a
delegator), **R** moved and removed (no caller left outside the service), **C** controller kept (its SQL and logic go to the service),
**L** left as it is.

**`includes/trait-leave.php`**

| Function (line) | What it does | Queries | Tables | L1 | Kind |
|---|---|---|---|---|---|
| `leave_held_days` :83 | Days held by an approved leave (grid lock); already `services()->leaveDays()->heldDays` | 0 | — | 0 | L |
| `leave_held_audit` :89 | `schedule_leave_refused` audit | 0 | — | 0 | L |
| `leave_without_levels` :130 | Levels per round | 0 | — | 0 | L |
| `leave_request_row` :146, `leave_request_balance` :155 | General helpers (also HolidayRefundPorts) | 1 / 1–2 | requests, types / balances | 2 / 1 | L |
| `leave_cancel_from_day` :270 | First day cancelled | 0 | — | 0 | L (port) |
| `leave_apply_cancellation` :279 | Dispatch to the return (switch on); off: whole-leave cancel :288-298 | :289, :292, :295 | balances, snapshots, requests | 3 | M (Reflection `e2e_leave_type.py:85`, `$bal=null`) |
| `leave_restore_days` :302 | Schedule back from snapshots; `$drop` deletes the snapshot | :305, :306, :308 | schedule, snapshots | 3 | R |
| `leave_apply_cancellation_from` :318 | 3.31.158 partial cancel (switch off) | :322, :326, :329, :333, :337 | requests, snapshots, balances | 4 | R |
| `leave_lock_row` :350 | `SELECT r.*,lt.… LIMIT 1 FOR UPDATE` | :353 | requests, types | 2 | M |
| `leave_changes` :357 | Rounds, newest first | :359 | leave_changes | 0 [1] | R |
| `leave_change_current` :363 | Newest round | 0 | — | 0 | M |
| `leave_round_matches` :372 | `$_POST['round']` vs current round | 0 | — | 0 | L |
| `leave_round_token` :381, `leave_round_has_approval` :391 | Read the current round | 0 | — | 0 | L (api) |
| `leave_return_days_from` :398 | Snapshot days from a day, or working-days fallback | 0 | — | 0 | R |
| `leave_change_insert` :404 | New round row | :406, :411 | leave_changes | 0 [2] | R |
| `leave_change_close` :415 | Ends a Pending round | :419 | leave_changes | 0 [1] | R |
| `leave_days_from` :423 | COUNT snapshots ≥ day | :425 | snapshots | 1 | M |
| `leave_return_asked_day` :432 | `cancel_from_day ?? start` | 0 | — | 0 | L (port) |
| `leave_return_restore_day` :441 | Keeps a manager's edit or restores | 0 | — | 0 | M (HolidayRefundPorts) |
| `leave_carry_top_up` :454 | Next year's carried days follow a late return | :458, :460, :464 | balances | 1 | M (HolidayRefundPorts) |
| `leave_return_apply` :476 | **The return** | :492, :501, :506, :510, :517, :530, :535 | requests, snapshots, balances, leave_changes | 3 [1] | R |
| `leave_return_message` :544 | Texts | 0 | — | 0 | L |
| `leave_reject_cancellation` :556 | Rejected; closes the round | :558 | requests | 1 | M |
| `leave_mark_cancellation_pending` :566 | Pending | :569 / :570 | requests | 2 | M |
| `leave_cancel_request` :730 | Hook; switch on → `leave_return_ask` | :737 | requests | 1 | C |
| `leave_return_ask` :795 | Employee round | :816 | requests | 1 | C |
| `leave_cancel_respond` :837 | Hook; lock → round → `approval_act` → apply/reject | 0 | — | 0 | L |
| `leave_admin_decide` :904 | Hub override, own transaction | tx only | — | 0 | L |
| `leave_approval_card` :972 | Card | :1000 | requests | 1 | L |
| `leave_legacy_approvals` :1023, `leave_my_request_item` :1069, `leave_change_offer` :1109 | Read through helpers | — | — | — | L |
| `leave_cancel_withdraw` :1131 | Withdraw a change (api) | :1133, :1134 | requests | 1 | M |

**`includes/trait-leave-admin.php`**

| Function (line) | What it does | Queries | Tables | L1 | Kind |
|---|---|---|---|---|---|
| `admin_leaves` :20 | Page router | — | — | 4 | L |
| `leave_admin_return_error` :153 | Texts | 0 | — | 0 | L |
| `leave_return_preview` :165 | Preview | 0 | — | 0 | R |
| `leave_admin_return_view` :181 | "Changed leave days" and "Back at work" | :214; read model :192 | requests, types | 2 | C |
| `leave_admin_is_self` :230 | HR's own leave | 0 | — | 0 | L |
| `admin_leave_return_record` :238 | Hook: checks, lock, tx, notifications | tx | — | 0 | C |
| `leave_admin_return_locked` :279 | Lock row, closed months, recheck, rounds, `leave_return_apply('hr')` | 0 | — | 0 | R |
| `admin_leave_day_restore` :301 | Hook: put the day back | :310, :316 | snapshots | 2 | C |

**Unchanged:** the leave-day lock in `trait-attendance.php` (grid :234/:283, no-JS :336/:354, `att_single` :381, CSV :430/:447) already
goes through the leave api and the `WordPressLeaveDayQuery::heldDays` read model; `trait-payroll.php` `payroll_closed_months` (payroll
api, reached by a port); `trait-leave-view.php`; `src/Leave/ReturnRules.php` (pure). `src/Leave/Data/WordPressChangedDaysQuery.php` is
listed today as a `reads` entry of `schedule.days`.

### 0.2 What the checks count today

`check_layers.py`: 303 references in 48 files. `trait-leave.php` 52 (23 of them in the functions this release moves), `trait-leave-admin.php`
12, `trait-schema.php` 30 (31 once `leave_changes` is layered: its CREATE at :141). `check_modules.py`: `leave->schedule` tables = 4.

## 1. Target shape

### 1.1 `modules.json`

A second slice in `leave.layered`:

```json
{"slice": "return",
 "tables": ["leave_changes", "leave_requests", "leave_balances", "leave_types", "leave_schedule_snapshots"],
 "store": "workforce-one/src/Leave/Data/WordPressLeaveReturnStore.php",
 "services": ["workforce-one/src/Leave/LeaveReturnService.php"],
 "reads": [{"file": "workforce-one/src/Leave/Data/WordPressChangedDaysQuery.php",
            "tables": ["leave_schedule_snapshots", "leave_requests", "leave_types", "schedule", "employees"]}],
 "moved": ["leave_lock_row", "leave_change_current", "leave_days_from", "leave_return_restore_day", "leave_carry_top_up",
           "leave_apply_cancellation", "leave_reject_cancellation", "leave_mark_cancellation_pending", "leave_cancel_request",
           "leave_return_ask", "leave_cancel_withdraw", "leave_admin_return_view", "admin_leave_return_record", "admin_leave_day_restore"],
 "traits_clean": []}
```

The `WordPressChangedDaysQuery` entry leaves `schedule.days.reads`. No leave `exports`; no new trait file.

### 1.2 New classes (`WorkforceOne\Leave`, PHP 7.4, no `strict_types`)

- **`Data/LeaveReturnStore.php` + `Data/WordPressLeaveReturnStore.php`**: the only SQL for the five tables, every string, array and
  format array copied byte for byte. Methods: `lockLeave` (:353), `findOwn` (:737), `changes` (:359), `changeCount` (:406), `insertChange`
  (:411), `closeChange` (:419), `finishChange` (:535), `daysFrom` (:425), `leaveDays` (:292), `leaveDaysFrom` (:326), `lockLeaveDaysFrom`
  (:501), `countLeaveDays` (:329/:510), `deleteLeaveDay` (:308/:506), `snapshot` / `snapshotId` (admin :310/:316), `lowerUsed`
  (:289/:333/:517), `balanceFor` (:458), `balanceById` (:460), `addCarried` (:464), `cancelWhole` (:295), `endLegacy` (:322), `shorten`
  (:337), `endPending` (:492), `applyReturn` (:525-530, the dynamic SET verbatim), `rejectCancellation` (:558), `markPending` (:569/:570),
  `askAgain` (:816), `withdrawCancellation` (:1134), `approvedLeaves` (admin :214).
- **`Data/ChangedDaysQuery.php`** (interface) implemented by `WordPressChangedDaysQuery` unchanged; `Services::leaveChangedDays()` replaces
  the inline `new` at admin :192.
- **`LeaveReturnPorts.php`** + adapter `leave_return_ports()` in `trait-services.php` (closure style of `leave_holiday_ports()`):
  `cancelFromDay`, `today`, `closedMonths`, `workingDaysCount`, `balance`, `substitutesSyncAtShutdown`, `followLeaveEnd`,
  `weekLockRecord`, `withEmployeeLock`, `closeOpenApproval`, `closeWithdrawnApproval`, `approvalDecided`, `startRound`,
  `notifyPendingApprovers`, `notifyDeciders`, `notify`, `isOwnLeave`, `managerUserId`, `signInOut`, `userName`, `actorId`, `actorName`.
- **`LeaveReturnService.php`**: `(LeaveReturnStore, ChangedDaysQuery, LeaveReturnPorts, ScheduleDayService, Transaction, AuditLog, Clock)`;
  the schedule service property is named `$days` (a property named `schedule` is counted as a table). Stateless. Methods: `lockLeave`,
  `currentChange`, `daysFrom`, `restoreDay`, `carryTopUp`, `applyCancellation`, `applyReturn`, `rejectCancellation`, `markPending`,
  `findOwn`, `askLocked`, `notifyAsked`, `checkBack`, `withdrawCancellation`, `preview`, `hrCheck`, `approvedLeaves`, `changedDays`,
  `recordReturn`, `putBackDay`.
- The service returns `outcome` / `applied` as `null` when it would not set them; the delegator copies non-null values to
  `$this->leave_cancel_outcome` / `$this->leave_return_applied` at the same points as today (legacy `ended` before the UPDATE result
  check :323; the return's `ended` only after the UPDATE and round close :495-496; `from:` / `''` and `applied` on success :536-537).
- **Schedule writes** (old :305-306) go through the Schedule export: new `ScheduleDayService::restoreRow()` (same `$wpdb->update` without
  formats, no notification) and `ScheduleDayService::removeDay()` → new `ScheduleDayStore::deleteDay()` (same `$wpdb->delete`). Order kept:
  schedule write, then snapshot delete, per row. `MemoryScheduleDayStore` updated.
- **Removed trait functions:** `leave_changes`, `leave_change_insert`, `leave_change_close`, `leave_return_days_from`,
  `leave_return_apply`, `leave_apply_cancellation_from`, `leave_restore_days`, `leave_return_preview`, `leave_admin_return_locked`.
- **Kept names and signatures:** every hook, api, Reflection and HolidayRefundPorts target (`admin_leave_return_record`,
  `admin_leave_day_restore`, `leave_cancel_request`, `leave_cancel_respond`, `leave_cancel_withdraw`, `leave_round_token`,
  `leave_held_days`, `leave_held_audit`, `leave_return_on`, `leave_apply_cancellation`, `leave_return_restore_day`, `leave_carry_top_up`,
  `leave_request_balance`, `leave_lock_row`, `leave_change_current`, `leave_days_from`, `leave_return_asked_day`,
  `leave_reject_cancellation`, `leave_mark_cancellation_pending`).

## 2. Transactions and locks (unchanged)

- **Service-owned** (inside `withEmployeeLock`, as `HolidayRefundService::giveBack`): `recordReturn` (preview/check before the lock;
  GET_LOCK → begin → lock row → closed months → recheck → close a Pending round (`moved`) → insert the HR round → `applyReturn('hr')` →
  commit; notifications after commit), `putBackDay` (snapshot, closed month, lock, begin, lock row, snapshot id, `days->save` as `save_att`
  today, commit, week-lock record, audit), `withdrawCancellation` (begin, UPDATE, close the approval, on failure rollback then
  `approvalDecided`, close the round when on, commit, audit; no employee lock).
- **Caller-owned** (the service never calls `begin()`: a nested `START TRANSACTION` commits the outer one): `askLocked` inside
  `leave_transaction`; `applyCancellation`, `applyReturn`, `rejectCancellation`, `lockLeave` inside `leave_cancel_respond` /
  `leave_admin_decide`, which stay in the trait because `approval_act` must share the transaction.
- **Lock order:** GET_LOCK → leave row FOR UPDATE → approval levels → snapshots FOR UPDATE → schedule rows → balances → conditional
  UPDATE of the leave → change row. SQLite: no FOR UPDATE, as before.

## 3. Cross-module

Schedule (`ScheduleDayService` export: `day`, `save`, new `restoreRow`, `removeDay`); approvals, substitutes, payroll closed months,
week lock, insights sign-in/out, employee lock, people, notifications: ports onto today's api functions; `approval_act` and the level checks
stay in the trait controllers; audit through `AuditLog` (same actions, texts, order); `Clock::now()` for the round insert/close.

## 4. Baselines and checks

- `layer-baseline.json`: `trait-leave.php` 52 → 29, `trait-leave-admin.php` 12 → 8, `trait-schema.php` 30 → 31 (the `ews_leave_changes`
  CREATE counted for the first time; see question 1), total 303 → 277.
- `module-baseline.json`: `leave->schedule` tables 4 → 2.
- `check_layers.py` / `check_modules.py` code unchanged. L5: drop the `global $wpdb;` lines in `leave_cancel_request` and `leave_return_ask`.

## 5. Proof that nothing changed

- **Golden:** new `tests/golden/actions_leave_return.php` (pattern `actions_records.php`), committed **before** the move so base and head
  run it: employee ask (all variants), decisions (whole, partial, closed-month shift, all closed, kept day, carry top-up, no deduct, no
  snapshots), a stale round, Hub override, withdraw, HR record (no reason, own leave, changed, waiting change closed, busy), put back
  (missing, closed, kept, ok), switch-off legacy cancellation (whole, from, ended, `$bal=null`), faults (conditional UPDATE 0, snapshot
  DELETE, balance UPDATE). `pages.json`: Leaves `tab=return` (preview) and `tab=changed`, My requests, My approvals, with the switch on.
  `expected.json`: release only.
- **e2e, unchanged and green:** `e2e_leave_return.py`, `e2e_leave_lock.py`, `e2e_leave_holidays.py`, `e2e_leave_requests.py`,
  `e2e_leave.py`, `e2e_leave_admin.py`, `e2e_leave_type.py`, `e2e_attendance_grid.py`, `e2e_my_approvals*.py`, `e2e_admin_requests.py`,
  `e2e_hub_reports.py`, `e2e_substitutes.py`, `e2e_week_lock.py`, `e2e_payroll_close.py`, `e2e_money_safety.py`, `e2e_request_races.py`,
  `e2e_schema.py`, `e2e_features.py`, `e2e_speed.py`, `e2e_query_batching.py`; `e2e_schedule_store.php` gains a `deleteDay` contract case.
- **Unit:** `tests/unit/LeaveReturnServiceTest.php` + `tests/unit/Fakes/MemoryLeaveReturnStore.php` (call order, tx log, value types of
  the dynamic SET), `ScheduleDayServiceTest` (`restoreRow`, `removeDay`).

## 6. Commits, risks, questions

**Commits:** (1) this design; (2) golden on today's code; (3) Schedule `deleteDay` / `restoreRow` / `removeDay`; (4) store + read-model
interface; (5) ports, adapter, `Services` wiring; (6) reads; (7) the return; (8) employee ask / withdraw; (9) HR record / put back;
(10) unit tests; (11) `modules.json` + baselines; (12) 3.31.164 version, CHANGELOG, `expected.json`.

**Risks:** SQL drift (copy column lists, `LIMIT 1{$lock}`, casts, formats, key order; the holiday store's `lockLeave` has different
text); PHP types decide `%d`/`%f`/`%s` in the dynamic UPDATE (keep untyped parameters where raw values passed); nested transactions;
trait state points; multisite prefix per call; HolidayRefundPorts targets keep their names; no e2e for real races (parity rests on the
golden trace); rebase on #64; SQLite.

**Owner question 1:** `trait-schema.php` 30 → 31. (a) record it once with `--allow-increase`, as 3.31.162 did; or (b) move only that
CREATE into `src/Leave/Data/LeaveReturnSchema.php`, called from `ensure_leave_schema()` at the same place with byte-identical DDL, so the
baseline does not rise. No product questions: nothing visible changes.

## 7. As built (3.31.164)

- Owner question 1: **(b)**, the CREATE moved byte for byte to `src/Leave/Data/LeaveReturnSchema.php` (owner, 2026-11-24). The layer baseline
  only goes down: 303 → 276 (`trait-leave.php` 52 → 29, `trait-leave-admin.php` 12 → 8, `trait-schema.php` unchanged).
- Differences from §1.2, all smaller than the design: the notifications (their English texts) and `ReturnRules::hrCheck` stay in the
  controllers (`leave_return_ask`, `admin_leave_return_record`), which run no SQL; the ports are only what the service itself reaches
  (no `notify*`, `isOwnLeave`, `managerUserId`, `signInOut`, `userName`). The "Changed leave days" mapping stays in
  `leave_admin_return_view`, reading through `Services::leaveChangedDays()`. `putBackDay` reads and saves the day through the injected
  `ScheduleDayService` (`day()`, `save()`), which is what `schedule_for_employee_date()` and `save_att()` already called.
- Tests: `tests/unit/LeaveReturnServiceTest.php` + `tests/unit/Fakes/MemoryLeaveReturnStore.php`; the golden trace
  `tests/golden/actions_leave_return.php` (no new `pages.json` entries). The `e2e_schedule_store.php` `deleteDay` case was not added:
  the golden trace covers its SQL.
