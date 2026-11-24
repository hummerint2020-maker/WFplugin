# Company holidays are not leave days

Status: owner approved all 8 recommendations (2026-11-24). Mockup: docs/mockups/leave-holidays/index.html. Starts after the leave-cancellation PR is merged.

## Bug

`WorkingDays::dates/count` (src/Leave/WorkingDays.php) skips only non-working weekdays and never reads the company calendar (`ews_company_calendar`, `general_leave`, active=1). Leave Sun 4 – Thu 8 Oct 2026 with 6 Oct a company holiday deducts 5 days instead of 4. Payroll and reports are not affected (DayMetrics: the holiday wins over the leave); only the balance, and balance checks that can wrongly refuse a request.

## Owner decision (2026-11-24)

Option 2: fix going forward, and an HR report to restore the days wrongly deducted in the current leave year, with an audit entry. Not chosen: reacting automatically when a holiday is added after a leave was approved.

## Design

1. **Pure function.** `WorkingDays::dates/count(..., array $holidays = [])` skips those Y-m-d days; empty keeps today's behaviour. New pure `src/Leave/HolidayRefund.php`: `candidates(countedDates, holidays, alreadyRefunded, requestedDays)`, `bucket(status)`.
2. **Fix going forward.** New `includes/trait-leave-holidays.php` with `leave_holiday_dates($start,$end)` (`[]` with no query when the switch is off). One-line edits in trait-leave.php: `vacation_working_days_count` (covers create, HR record, approval card, cancellation fallback) and `leave_apply_final_approval` (no snapshot / no Vacation on a holiday). New column `ews_leave_requests.holidays_excluded` set at creation when the fix is on; such leaves are never in the report.
3. **Data.** New table `ews_leave_holiday_refunds` (leave_request_id, employee_id, work_date, days, balance_id, bucket, calendar_id, created_by, created_at) with `UNIQUE(leave_request_id, work_date)`: a day is never restored twice. Created in `ensure_leave_schema`; nothing backfilled.
4. **Report and restore.** A section on the wp-admin Leaves page, switch on only, capability `ews_manage_settings`. Lists leaves with `holidays_excluded=0`, a type that deducts, a balance in the current leave year: Approved (counted days = snapshots; also partly cancelled leaves), cancellation pending shown but not restorable. Restore (one or several): under the `leave_emp_<id>` lock and a transaction, insert refund rows, compare-and-set `requested_days`, lower `used`, put the holiday's schedule back from its snapshot and delete that snapshot (so a later partial cancel cannot return it again), week-lock record, audit `leave_holiday_refund`, notify the employee.
5. **Conflicts with the cancellation thread:** `vacation_working_days_count`, `leave_apply_final_approval`, `leave_insert_request`, `leave_restore_days`, and `leave_apply_cancellation_from` counting snapshot rows. Rebase onto their merged work.

## Edge cases

Holiday on a weekend: ignored. Two events on one date: one day. Holiday deactivated after a refund: refund stays. Leave spanning two years: charged to the start year as today. Closed payroll period: unaffected (the holiday already wins). Leave whose only working day was a holiday: new ones refused (no working days); an old one drops to 0 days.

## Tests

Unit: LeaveRulesTest (4–8 Oct example = 4, duplicates, weekend holiday), new HolidayRefundTest. E2E: e2e_leave_requests (create/approve across a holiday, partial cancel after restore), e2e_leave_admin (HR record, report, restore twice = no-op, audit, capability), e2e_schema, e2e_features (switch off changes nothing), e2e_request_races. Plus phpstan, phpcs, modules, translations.

## Open questions for the owner (recommendation)

1. Switch: one switch "Company holidays are not leave days", off by default, for both the fix and the report. (Recommended.)
2. Schedule of a restored holiday goes back to how it was before the leave. (Recommended.)
3. Pending leaves: not in the report; they show once decided. Avoids a race with approval in the other thread's code. (Recommended.)
4. Capability: `ews_manage_settings`. (Recommended.)
5. A leave that drops to 0 days stays Approved with 0 days. (Recommended.)
6. Leave types that do not deduct are left out. (Recommended.)
7. The employee is notified when days come back. (Recommended.)
8. Current leave year only. (Recommended.)

## Implementation notes (3.31.162): where the code differs from the design

- The leave keeps the holidays it was counted without (`ews_leave_requests.holidays_skipped`, comma-separated dates, NULL = none) instead of a 0/1 flag. Its approval skips exactly those, so its leave days always match what it charges, even when the calendar or the switch changed between the request and the approval (review finding 3). The switch is read once at the insert (finding 4): a count that matches neither reading is not saved.
- So the report no longer needs to leave out leaves counted with the switch on: any approved leave with a leave day on an active company holiday is listed. That covers leaves asked before the switch, and a holiday added after a leave was asked. It stays a manual HR action; nothing changes automatically (option 3 not chosen).
- Leave year: this year or a later one (a leave in January already asked is charged to next year), never a past year (owner question 8). The e2e suite then works whatever day it runs.
- Pending leaves are not in the report (owner, question 3), so the approval needs no change for a refund racing it.
- The refund table keeps the holiday's title instead of a calendar id, and no bucket (only approved leaves, so always `used`).
- The employee's notification is stored in English, as the other leave notifications.
- Next year's carried days follow a refund through the existing `leave_carry_top_up()` (Leave return), as for a late return.
- Tests: one e2e suite, `tests/e2e_leave_holidays.py` (off, on, an old pending leave, a holiday added after asking, giving back once, a change waiting, a later cancellation, off again), and `tests/unit/LeaveHolidaysTest.php` for the pure parts; the other suites named in the design are unchanged.
- A very old leave with no leave days (snapshots) falls back to counting working days on a later change; with the switch on that count skips holidays. Such leaves predate 3.31.1 and are not in the report.
- Layers (owner's layered-modules rule, 2026-11-24): the feature is a layered slice, `leave.holidays` in modules.json. Controller: `includes/trait-leave-holidays.php` (switch, capability, nonce, POST, redirect; no `$wpdb`). Service: `src/Leave/HolidayRefundService.php` (report, give back; owns each leave's transaction). Store: `src/Leave/Data/WordPressLeaveHolidayStore.php` (the feature's only SQL). Rules: `HolidayRefund` (candidates, restorable, skipped). What it needs from the rest of the plugin (calendar, lock, balance, schedule, carry, week lock, notifications) comes through `HolidayRefundPorts`, built in trait-services.php. `WordPressLeaveDayQuery` is registered as the slice's read model.
- Enrolling the leave tables in check_layers made it count, for the first time, the references to them that the older leave code already had (88 in 12 files: trait-leave.php 5 → 52, …). The L1 baseline was rewritten with `--allow-increase` for that: no new SQL, the old debt is now measured and can only go down.
