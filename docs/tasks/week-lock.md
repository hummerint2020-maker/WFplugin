# Week lock (external review F03): design

Design by the architect (read-only), saved before implementation. A new feature behind a switch, off by default (owner,
2026-10-21). The owner's answers are added at the end.

## 0. What exists today
- No week lock, freeze, publish or cutoff for the weekly schedule anywhere (code, docs, git history). "Cutoff" in the code is the
  Sign In window (`sign_in_cutoff_minutes`). F03 most likely came from the Office Minimum reminder ("Thursday 12:00, set next
  week", `src/Settings/OfficeMinimumSettings.php:17-19`) or the capability `ews_manage_schedule` ("Manage Weekly Schedule",
  `trait-permissions.php:15`), granted to Manager and Supervisor but checked nowhere.
- A closed payroll month does not block schedule or attendance changes: payslips are frozen, a correction in a closed month carries
  its pay difference to the next open month (`trait-corrections.php:495-531`). A week lock must not contradict this.
- Any manager in scope can rewrite any past or future week of the schedule today (the grid has no date limit). Only swaps refuse
  past days (`SwapRules::checkNotPast`).
- The week starts on the first configured working day (`week_dates_configured`, `trait-work-time.php:277-294`); time is the site
  clock (`current_time()`), no per-user timezone.

### Write paths that change a schedule day or a week's attendance
| # | Path | Function | Human / system |
|---|---|---|---|
| S1 | Schedule grid (concurrent path) | `att_grid_save` → `save_att_if_unchanged` (`trait-attendance.php:317`) | human |
| S2 | Grid, no-JS fallback | `att_grid_save` → `save_att` (`:382`) | human |
| S3 | Single-cell handler (no form uses it) | `att_single` (`:405`) | human |
| S4 | CSV schedule import (preview, apply) | `parse_csv` `:412`, `att_import` `:454` | human |
| S5 | Branch planned for the day | `branch_plan_day` (`trait-branches.php:159`), from the grid | human (with S1) |
| S6 | Leave approval writes Vacation + snapshot | `leave_apply_final_approval` (`trait-leave.php:152-183`); HR records a leave (`trait-leave-admin.php:96`) | system |
| S7 | Leave cancellation restores the snapshot | `leave_apply_cancellation` (`trait-leave.php:~197-212`) | system |
| S8 | Legacy vacation decided in wp-admin | `legacy_vacation_admin_decide` (`trait-admin-requests.php:140`) | system |
| S9 | Swap applied | `swap_apply_rows` (`trait-swap.php:79-100`) | system |
| S10 | Swap request created | `swap_request_create` (`trait-swap.php:113`) | human request |
| A1-A5 | Sign In/Out (today only), HR record edit/reset, approved correction, auto attendance | `AttendanceService`, `trait-time-report.php`, `cx_finalize`, `trait-auto-attendance.php` | mixed |
| D1-D5 | Dated shift periods, company/shift times, general leave, overtime/early leave, org moves | various | dated |
Daily workers have their own sheet/payout locks; the mobile API writes only corrections and daily workers.

## 1. Candidate meanings
- **(a) Cutoff lock on the coming week (recommended).** From a weekly cutoff (e.g. Thursday 17:00, site time) the next week's
  schedule is final; it stays final, so the current and past weeks are locked too. Guards S1-S5, S9-S10, and S6-S8 per owner Q4.
  Attendance is not locked: corrections stay the way to fix it.
- **(b) Closed past weeks lock schedule and attendance.** Overlaps payroll close and the corrections deadline; blocks system
  effects; large and money-heavy.
- **(c) Manual "lock this week" per department.** Flexible, needs a table, a toggle UI and an unlock flow; no automatic guarantee.

## 2. Design for (a)
### Data model and upgrade
- Options only in release 1, registered in `src/Settings/Options.php`: `ews_feature_week_lock` (bool, default off, Feature
  Configuration) and `ews_week_lock` (grouped: `cutoff_day`, `cutoff_time`, `past`, `requests`, `employee_banner`), sanitised by a
  pure `src/Settings/WeekLockSettings.php` (as `OfficeMinimumSettings`), saved in `features_save_handler`, audited `week_lock_update`.
- No table and no DB version bump in release 1 (a `ews_week_locks` table only if Q7 wants a reopen, following the 3.31.73 rules).
- Upgrade: the switch is off, nothing changes.

### Off changes nothing
The guard's first line returns '' when the switch is off: no query, no read. Templates show nothing; the API adds no field.

### Pure rules: `src/Schedule/WeekLock.php`
`weekStart`, `lockedFrom` (the last cutoff weekday strictly before the week starts, at the cutoff time), `isLocked` (now >= that
instant; `past` open/locked), `decide` (ALLOW / LOCKED / OVERRIDE from enabled, locked, source, override, requests policy),
`message`. Edge cases: a working-days change never unlocks a locked day; non-working days are locked too; the boundary second
counts as locked.

### The guard (`includes/trait-week-lock.php`, schedule module api)
`week_lock_now()` (one instant per request), `week_lock_refusal($employee,$date,$source)` ('' or 'week_locked'; an override is
allowed and audited `schedule_locked_edit`), `week_lock_state($dates)` for templates. Every guarded path checks before its first
write, inside its transaction, so a refusal never leaves a half-applied leave or swap.
| Path | When locked |
|---|---|
| S1/S2/S5 grid | the cell is not saved; "N changes were not saved: the week of X is final since Thu 17:00" |
| S3 | refused |
| S4 import | row error in the preview, re-checked at apply |
| S10 swap request | `swap_error=week_locked` |
| S9 swap apply | not applied; per Q4 |
| S6/S7/S8 leave | per Q4 (write + audit, or refuse before any write) |
| A*, D* | not guarded in (a) |

### Concurrency
Cells are judged at submit against one instant; a save that started a second before the cutoff completes (documented). Swap/leave
checks run inside the existing transactions; the swap claim and conditional updates still settle races. Settings are read once per
request; the lock is computed, not stored.

### Permissions and audit
New capability `ews_week_lock_override` "Edit a locked week" (WordPress administrators and the EWS Administrator role by default,
per Q3), through `ensure_roles_permissions()`. `ews_manage_schedule` stays unused unless the owner says otherwise. Audit actions:
`week_lock_update`, `schedule_locked_edit`, `schedule_locked_refused` (one per request), `schedule_locked_by_leave` / `_by_swap`
(if Q4 = write), added to the audit filters.

### UI (mockups first)
1. Feature Configuration: Week lock card (switch, cutoff day and time with the site timezone, past weeks, requests policy,
   employee banner).
2. Schedule grid: locked-week banner, read-only cells with a lock for non-override users, a warning for override users, the
   refused-count notice.
3. CSV import preview: "locked" row errors.
4. Employee schedule: a "Final" chip per week, swap unavailable on locked days, the swap error.
5. Leave/swap approval cards touching a locked week: warning or refusal per Q4.
6. Optional: the next cutoff in the Office Minimum reminder; mobile API `locked` per day (release 2).

### Tests
Unit `WeekLockTest`, `WeekLockSettingsTest`, `OptionsTest`. e2e `tests/e2e_week_lock.py`: off = today's behaviour on every path;
on = each path S1-S10 (refused, override audited, cutoff passing between load and submit, import preview vs apply, swap/leave per
Q4 with nothing half-applied), next-next week still editable, `past=open`. Existing grid, leave, swap, request races and approval
engine suites with the switch off and on. phpstan, phpcs, modules, translations.

### Risks
Locking the current week also locks today (a manager cannot mark someone sick in the grid without HR: Q6); approved leave either
breaks "final" or strands a real absence (Q4); the week anchor depends on working days; the site timezone must be right; a swap
created before and approved after the cutoff; audit volume (one row per request); override edits still change pay of open months
(as today, now audited).

### Release split
R1: switch, settings, rules, guards S1-S10 with the Q4 policy, banners, override, audit, translations, tests. R2 if wanted: change
requests for a locked day (a new approval type, its own release), HR reopen/lock a specific week or department, the API field,
attendance lock for past weeks.

## 3. Open questions for the owner (recommended first)
1. Meaning: (a) coming week final at a weekly cutoff / (b) past weeks locked for schedule and attendance / (c) manual lock per
   department.
2. Cutoff: one company-wide day and time in the site timezone (default Thursday 17:00) / per department / N days before the week.
3. Who may change a locked week: administrators + EWS Administrator via a new "Edit a locked week" permission / WordPress
   administrators only / "Manage Weekly Schedule" holders.
4. Approved requests: leave and its cancellation still write (audited), swaps on locked days refused / all refused until an
   override approves / approved but applied later by HR.
5. Employees see: "Final" mark + swap unavailable + a notification at the cutoff / the same without the notification / nothing.
6. Current and past weeks: locked (schedule only) / only the coming week between cutoff and start / schedule and attendance.
7. HR reopens one week: not in R1 (override instead) / yes, with a reason and an end time.
8. A manager's change on a locked day: refused with a message / becomes a request to HR (later release).

## Owner's answers (2026-10-22)
1. Meaning (a): the coming week's schedule is final from a weekly cutoff, and stays final.
2. One company-wide cutoff day and time in the site timezone (default Thursday 17:00), changeable in the settings.
3. A new permission "Edit a locked week" held by WordPress administrators and the EWS Administrator role by default, which can be
   given to other roles on the Roles page; every change is audited.
4. **Requests are not blocked**: leave and its cancellation, and swaps, are requested, approved and applied automatically as today,
   also on locked days (each write into a locked week is audited). The lock only stops direct edits of the schedule (S1-S5).
   So S6-S10 get an audit row when they write into a locked week, never a refusal.
5. Employees see a "Final" mark on locked weeks and get a notification at the cutoff ("Next week's schedule is final").
6. Current and past weeks stay locked, the schedule only; attendance stays fixable through corrections and HR as today.
7. No "reopen this week" in the first release; the permission holder edits directly (audited).
8. A manager's change on a locked day becomes a request to HR, with a reason. Owner: **two releases** — R1 the lock itself (off by
   default; the manager's change is refused with a message for now), R2 right after it: the "schedule change request" to HR (a new
   approval type, designed then). The owner may leave the switch off until R2 ships.

## Implementation notes (3.31.121)
Where the code differs from the design:
- No `past` or `requests` settings: the owner's answers fixed them (past weeks stay final; requests always write, audited), so
  `ews_week_lock` holds only the cutoff day, time and the employee notification.
- Requests (S6-S10) are never refused; `week_lock_record_request()` adds one Audit Log row per request for the days it wrote into a
  final week. Swap requests on final days are allowed (owner answer 4).
- The grid's whole week is read-only for people without "Edit a locked week" (the lock is per week, so a page is all or nothing);
  the server still checks every cell, so a page opened before the cutoff and saved after it is refused cell by cell.
- The cutoff notification runs on its own five-minute event (`ews_week_lock_tick`, listed in `Support\Lifecycle`), sends only a
  cutoff of the last 24 hours, once (`ews_week_lock_notified`), and switching the lock on marks the cutoff that already passed.
- The schema target moves to 3.31.121 so `ensure_roles_permissions()` gives the EWS Administrator role the new permission on upgrade.
- The single-day handler (`att_single`, no form posts it) refuses a final week with a message; it has no e2e case (its nonce
  needs a page that does not exist).
- Mobile API: no `locked` field yet (release 2 with the change request).

## Independent review (3.31.121)
No money or upgrade problem; off leaves every page byte-identical. Fixed:
1. Feature Configuration let anyone with "Manage Settings" (EWS Manager by default) move the cutoff or switch the lock off and so
   reopen a final week unaudited: only "Edit a locked week" holders can change the Week lock settings now; for others the card is
   read-only and a posted form keeps the saved values.
2. The new Audit Log rows named the employee as a schedule row: they use the entity "employee" now.
3. The "Branch…" fill of a read-only week stayed enabled (it only marked cells that could never be saved): disabled.
4. `att_single` read the day even with the lock off: only for an allowed edit now.
5. The no-JavaScript form rewrote an unchanged day of a final week (its note): left alone now.
6. Two overlapping cron runs could both send the cutoff notification: claimed with one conditional UPDATE.
7. An allowed CSV import into a final week was one summary row: one `schedule_locked_edit` per changed day now.
8. A save with both refused and conflicting cells reported only the refused ones: both now.
9. The unit test used an unsorted working-days list production never passes: sorted, as `working_days()` returns.
10. e2e: a wider margin before midnight, cleanup at exit whatever fails, new cases for 1, 5 and 7.
Not changed: the approval-engine leave, leave cancellation and swap writes have no e2e of their own (they call the same recorder the
tested legacy leave path does); the Arabic dual form keeps its number ("لم يُحفظ %d تغييران") because the translation check
requires the placeholder in every form.
