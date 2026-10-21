# 3.31.116: payroll integrity (F11, F02, F01) — design

PR A of `docs/tasks/review-codex-3.31.115.md`. Fixes to existing behaviour: no new feature, no switch, no schema change. Base: main a3c6c60.

## 1. What is versioned and what is live
- `ews_shift_versions` (`trait-shift-history.php:49-62`) stores per shift and date: start, end, **grace**, sign-in cutoff, **overnight**;
  `ShiftPeriods::timesChanged()` already compares grace and overnight. Shift 0 = company hours. So grace and overnight are already dated in
  the model; the handler goes around it.
- The newest version follows the live options: `shift_times_on()` (`:117-125`) returns the stored row only for older versions; for the
  newest it returns `company_hours_now()` (`:86-91`: `ews_working_hours`, `ews_grace_period`, `ews_allow_overnight_shift`) or `shift_saved()`.
- `ews_absent_after_minutes` is live only, correctly: it only sets today's "Absent" cut-off for shift employees (`trait-work-time.php:184`,
  `DayMetrics.php:51`). It stays undated but is written only after every check.

## 2. Root causes
**F11** (`trait-schedule-config.php` `working_hours_save_handler`):
1. Overnight, grace, absent_after are written (`:53-55`) before `times_from` is checked (`:57-58`); a refused request keeps them.
2. `$before` (`:59`) is read after those writes, so it already has the new grace/overnight: a grace-only or overnight-only change is never
   dated and applies to every past day (lateness, reports, open payroll months; closed months keep their payslips); with a start/end change the
   BEGINNING version is written with the new grace/overnight.
3. Since 3.31.108 (also in the per-shift path, `trait-shift-history.php:256`): when a dated change arrives, the version that was the newest
   until now is not refreshed with the values it counts with (live), so after an undated change the past switches back to stale stored values.
The per-shift save (`shifts_save_handler`) checks everything before writing; only gap 3 applies there.

**F02** (`trait-payroll.php:306-318`): release instalments, delete payslips, delete run, all unchecked, no transaction, no lock;
`advance_release_run()` returns nothing; two reopens both report success.

**F01** (`trait-advances.php:316-327`, `trait-payroll.php:267-304`): `advance_mark_taken` returns success for 0 or partial rows; late
instalments appear in every open month (`taken_run_id=0 AND month<=`); two months closed at the same time both deduct the same instalment
(InnoDB: the second UPDATE waits, re-reads, matches 0 rows, "success"). Moving `payroll_month()` into the transaction would not fix it
(REPEATABLE READ snapshot; `ensure_payroll_schema` can run dbDelta, which commits) — do not call it inside the transaction.
The same month closed twice is already safe (UNIQUE month).

## 3. Changes
**3.1 F11 — `working_hours_save_handler`:** all checks first (hours, `times_from`, Q2 `times_later`), then reads (`$live=company_hours_now()`,
`$before=shift_times_on(0,$from)?:$live`), then writes: if `timesChanged($before,$after)`: `shift_version_settle(0,$from,$live)` then
`shift_version_insert(0,$from,$after)`; then the options; cache invalidation; audit with grace/overnight. Versions before options on purpose: a
failed option write then counts nothing differently. Option writes stay non-atomic (F12, PR D).

**3.2 New `shift_version_settle($shift_id,$from,array $live)`**, decided by a pure `ShiftPeriods::settle(versions,from,live)`:
`beginning` (no version: insert BEGINNING with live), `rewrite` (the newest starts before `$from` and its stored values differ from live:
update only its time columns with live, keeping id/created_by/created_at — changes no figure), `none` (the newest starts on `$from`),
`later` (Q2). Used by the working-hours handler and by `shift_versions_record()` (replacing `:256`).

**3.3 F02 — `payroll_reopen`:** `ews_with_lock('payroll', fn()=>payroll_reopen_locked($month), ['error'=>'busy'], 20)`; locked part returns
codes and never redirects (redirect exits and would skip `finally`): read the run again; START TRANSACTION; delete run (`false`→`reopen_save`,
`!==1`→`not_closed`); delete payslips (`false`→`reopen_save`); `advance_release_run()` now returns `$wpdb->query()` (`false`→`reopen_save`,
any count incl. 0 is fine, also with advances off); COMMIT; audit after commit.

**3.4 F01:** `advance_mark_taken` returns `true` | `'changed'` | `false` (`$n===false`→false; `$n===count($take)`→true; else `'changed'`).
`payroll_close` → `ews_with_lock('payroll', fn()=>payroll_close_locked($month), ['error'=>'busy'], 20)`, same order and checks as today under the
lock; mark taken `false`→`save`, `'changed'`→`changed`, each after ROLLBACK; audit after COMMIT; notifications and redirect after the lock.
New `PayRules::ERRORS`: `busy`, `changed`, `reopen_save`.

## 4. Transactions and the lock
One lock name `payroll` for every month (a late instalment is shared). MySQL/MariaDB: the second request waits up to 20 s, then re-reads and
recomputes, so the instalment is not deducted twice; `busy` on timeout. SQLite and clusters without GET_LOCK: `ews_with_lock` runs unlocked and
the row count protects (the second close gets `changed`; never deducted twice). MyISAM: no rollback (unchanged since 3.31.90, a risk).

## 5. Upgrade
No schema change, no migration. Sites where grace or overnight was changed on its own since 3.31.108: the undated change cannot be found
(the audit stores only start–end), so no automatic repair; `settle()` keeps past days as they are counted today. HR can fix it by hand with two
dated saves (Q1). Closed months are stored payslips. Version 3.31.116.

## 6. Switch
None (fixes to existing behaviour). Advances off: nothing changes, reopen still returns instalments taken earlier.

## 7. Edge cases
Reopen after a move (impossible: a taken instalment cannot be moved); a leaver's rest line comes back as due; reopen M after M+1 closed (M
owned them, they come back to M); M reopened then M+1 closed first (M+1 takes the late instalment, deducted once); no instalments / advances off
(success); retry after a failed close (rollback leaves nothing); same month by two admins (`already_closed`); two months sharing a late instalment
(MySQL: both close, deducted once; SQLite: one `changed`); two reopens (`not_closed`); times saved twice the same day (`none`); new times on a
future day (today keeps the old values); undated then dated change (days before keep the undated values); absent_after only (undated, written
after checks).

## 8. Tests
Unit: `ShiftPeriods::settle` (beginning/rewrite/none/later), `timesChanged` on grace-only and overnight-only; mark-taken count rule; new messages.
Failure injection: `tests/e2e_break_writes.php` also matches `DELETE FROM`; new `tests/e2e_payroll_mu.php` (`X-E2E-Take-None`, `X-E2E-Take-One`,
`X-E2E-Payroll-Slow`). e2e_advances: take-none / take-one refused, nothing changed; reopen with each write broken → `reopen_save`, nothing
changed; reopen with advances off; the concurrent two-month close on the multi-worker server (exactly one payslip deducts). e2e_payroll_close:
a second reopen → `not_closed`. e2e_schedule_config: an invalid `times_from` changes nothing (options and versions). e2e_shift_history: grace-only
from today keeps past lateness and the past month's payroll identical; overnight-only dated; start+grace → BEGINNING keeps the old grace; grace
from a future day; undated then dated change; per-shift grace only. Also unit, phpstan, phpcs, modules, translations.

## 9. Risks
Closes wait in line (20 s, then `busy`); SQLite/clusters rely on the row count (correct: refused, never doubled); MyISAM; `settle rewrite`
changes a stored row (only to what it already counts with; guarded by the "same figures" test); option writes not atomic (PR D).
Out of scope, noted: `advance_move` mid-close can mislabel an instalment's month (money still once); the "Times with dates" list shows the stored
value for the newest row.

## 10. Owner questions (recommended first)
- Q1: past grace/overnight changes made since 3.31.108 — **no automatic repair**; CHANGELOG explains the two-save fix.
- Q2: saving times from a day before a change already saved for later — **refuse** ("New times are already saved from <date>").
- Q3: wait before `busy` — **20 seconds**.
