# To do: bugs found in the code review (Oct 2026)

Found on 2026-10-10 by a code review of PR #1 (3.31.45 → 3.31.86), checked against the code. The
owner's decision: **save for later, not urgent.** Nothing here has been changed yet. When this is
picked up, fix it in one release with a test for each item, then tick it here.

Reviewed: the newest work (3.31.74–3.31.86) and the sensitive parts (payroll, Sign In / attendance,
leave, overtime, approvals, API login, permissions). **Not reviewed yet:** reports, schedule,
notifications, employee screens.

## Waiting for the owner's decision (payroll)

- [ ] **Deduction cap** (`max_deduction_days`, `src/Payroll/PayCalculator.php` ~line 128): today it caps
  *all* deductions including unpaid leave, so with a cap of 3 days an employee on 15 days of unpaid
  leave is paid for 12 of them. Proposal: the cap covers attendance only (absence, late, early leave);
  unpaid leave is always deducted in full (what the docblock says).
- [ ] **Joining mid-month** (`PayCalculator.php` ~line 58): pay = calendar days × monthly ÷ 30, capped at
  the month, so starting 2 Feb pays 90% but starting 2 Mar pays 100%. Proposal: prorate by the
  month's real days (or working days).

## Money

- [ ] **Payroll skips employees archived before the month is closed** (`includes/trait-payroll.php`
  ~line 119, `active=1`): someone who left on the 20th and was archived on the 21st gets no payslip
  for 20 worked days. Include anyone active on any day of the month.
- [ ] **Daily workers: HR setting *today* in wp-admin → Days locks the foreman out**
  (`trait-daily-workers.php` `dw_admin_set_day` ~line 1440): it creates today's sheet, so
  `dw_save_sheet` answers "locked" and the other workers are never recorded (not paid), and the period
  looks recorded for payment. Either refuse today in the Days tab, or do not create a sheet for today.
- [ ] **Daily workers payout: a failed line insert is ignored** (`dw_mark_paid` ~line 960): the payout
  and the locked days are committed without that worker's line (missing from the sheet, advance not
  deducted). Check every insert; roll back on failure.
- [ ] **Daily workers payout race** (`dw_mark_paid` ~line 947): the payout is computed before the
  transaction; a change approved in between is locked at the old amount. Re-read inside the
  transaction (lock the days) or refuse when a change is pending in the period.
- [ ] **Payslip PDF arithmetic** (`src/Payroll/PayslipPdf.php` ~line 104): "15 days × 333.33" next to
  7,500.00 when the day value is basic-only. Print the value actually used.

## Permissions

- [ ] **Approving your own request outside a workflow**: with no approval workflow, a manager with
  `ews_manage_time` can approve their own overtime (`trait-overtime.php` ~line 166), and the same in
  `leave_request_respond` / `early_leave_respond`. Apply the self-approval rule (3.31.75) there too.
- [ ] **Daily workers: an administrator can approve their own change** (`dw_decide_change` ~line 830).
  Same rule; mark self-decisions.

## Attendance

- [ ] **Overnight shift: admins cannot add / edit a Sign Out after midnight**
  (`src/Attendance/ManualRecordRules.php` ~line 36, `date_mismatch`): allow the next calendar day for
  a shift that crosses midnight.
- [ ] **Overnight shift: a correction can add a Sign Out in the future**
  (`src/Attendance/CorrectionRules.php` ~line 70): the future-time check runs only when the date is
  today; check the final timestamp instead.
- [ ] **Corrections monthly limit uses `month-31`** (`trait-corrections.php` `cx_month_count` ~line 101):
  `2026-02-31` is not a date; on MySQL the limit may not count. Use the month's last day.

## App login (API)

- [ ] **WordPress 6.8+: a normal login can sign the user out of every phone**
  (`trait-api-auth.php` ~line 486): WP rehashes the password on login through `wp_set_password`, which
  we treat as a password change. Compare the old and new hash / ignore rehash.
- [ ] **Two refreshes at the same moment = "stolen token"** (`trait-api-auth.php` ~line 337): add a
  short grace window for the just-rotated token.
- [ ] **Login limit behind a proxy / CDN** (`trait-api-auth.php` ~line 70): everyone shares one IP, so 20
  bad logins block all app logins for 15 minutes. Use a trusted-proxy setting for the client IP.
- [ ] **Login limit per account can be doubled** (`src/Api/Auth/LoginThrottle.php` ~line 46): user name
  and email have separate buckets. Key on the resolved user id.
- [ ] Native routes turn every WordPress error into 403 FORBIDDEN (`trait-api-auth.php` ~line 137); keep
  400 for validation errors.

## Smaller

- [ ] App menu links carry `site` / `start` / `dw_day` from Daily Workers into other pages (Reports
  opens on the payout's date) (`trait-frontend.php` `app_view_url` ~line 83). Build nav links from a
  clean base with a per-view allowlist.
- [ ] Tasks "Show all" (`task_done`) is dropped on reload by the one-time-result rule
  (`assets/js/workforce-one.js` ~line 128 and `app_view_url`): exclude real filters.
- [ ] Leave notification to user 0 when a step was rerouted to administrators
  (`trait-leave.php` ~line 202).
- [ ] Administrators notified of an approval that is then rolled back (`trait-approvals.php` ~line 409):
  notify after commit.
- [ ] Leave decision from the Requests Hub: the final apply runs outside a transaction
  (`trait-leave.php` ~line 455; same in `overtime_admin_decide`).
- [ ] Worker photo cached for a day after it is replaced (`src/Support/Download.php` ~line 45): add the
  file key to the URL or a shorter cache.
- [ ] Payout PDF accepts an invalid `start` (`dw_payout_pdf` ~line 1003): validate the date.
- [ ] A correction rolled back leaves its photo on disk (`trait-corrections.php` ~line 276).

## Speed (large companies)

- [ ] Leave page: the office-minimum note runs two schedule queries per pending request
  (`trait-leave-view.php` ~line 42). Load once for all dates.
- [ ] Daily workers: one query per site / per worker (`dw_sites_content`, `dw_day_content`, Days tab).
- [ ] My Pay / payroll detail for one employee still loads every employee's rates and adjustments
  (`trait-payroll.php` ~line 123).

## Clean-up (no behaviour change)

- [ ] One private-upload helper for corrections and daily workers (`dw_file_dir` / `cx_file_dir`).
- [ ] One date check (`CorrectionRules::isDate`) instead of three in daily workers.
- [ ] Office minimum: "could come in" depends on the status icon (`om_could_come`); use a real flag.
- [ ] Small duplicates: `$initials` in the admin template, `approval_start` requester lookup.
