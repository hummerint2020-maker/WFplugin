# Changelog

## 3.31.34
Kiosks, QR codes and Presence Verification (`includes/trait-presence.php`) moved to the new
structure.

### Fixed
- **Links sent outside the app opened the home page.** Push notifications (presence requests,
  breaks, tasks, face resets, notifications) and redirects without a referring page went to
  `/?ews_view=…`. On a site where the app has its own page (e.g. `/app/`), the home page does
  not show the app, so the employee landed on the website instead of the request. They now go to
  the page that holds `[employee_app]`, or to the home page when there is none.
- **The Presence Verification page showed any text put in its link.** Its error message came from
  the link itself (`presence_error=<text>`), so a forged link could show a fake message on the
  page. The link now carries only a code, and the page shows only its own messages.

### Internal
- `src/Presence/QrCode.php` (QR payload, parsing, slot freshness, signature; unit tested) and
  `src/Presence/Hooks.php`. HTML in `templates/admin/presence-kiosks.php`, `templates/kiosk.php` and
  `templates/app/presence.php`. The kiosk script and style (`assets/js/kiosk.js`,
  `assets/css/kiosk.css`) and the camera scanner (`assets/js/presence-scan.js`) are files instead of
  inline code.
- `app_home_url()` (the app page, remembered in `ews_app_page_id`).
- `tests/e2e_presence.py` (25 checks): kiosks (create, secret stored as a hash, audit, disable)
  and the presence request flow (notification, wrong location, invalid or expired codes, double
  verification, expiry, forged messages).

## 3.31.33
Work Locations (`includes/trait-locations.php`) moved to the new structure.

### Fixed (wp-admin → Work Locations)
- **Archiving the default location left it marked as the default.** The next active location
  became the default too, so two rows carried the flag (one archived). Archiving now clears it and
  hands the default to the next active location straight away; the list shows "Default" only on an
  active location.
- The employee location list said "No default location" for an employee without an assigned
  location; it now says "Default location", which is what Sign In uses for them.

### Removed
- The old single-location "Location Settings" page and its save action. The page had no menu entry
  (Work Locations replaced it), so it could not be opened. Its saved values are still used, as before,
  when no work location exists.

### Internal
- `src/Locations/LocationRules.php` (coordinates, radius 10–5,000 m, seats, default; unit tested)
  and `src/Locations/Hooks.php`; HTML in `templates/admin/locations.php`; the Archive confirmation in
  `assets/js/admin-locations.js` (was inline).
- `tests/e2e_locations.py` (21 checks): add, validation, default, edit, seats, employee assignment,
  archive, access.

## 3.31.32
Internal: `includes/trait-core.php` (1,462 lines) split by topic. No behaviour change: the 109
methods and properties were moved, not edited, and a check confirmed each one is byte-for-byte the
same before and after.

### Internal
- `trait-core.php` keeps the request caches and small shared helpers (316 lines). New:
  `trait-schema.php` (tables and the schema upgrade), `trait-work-time.php` (shifts, working hours,
  working days, holidays), `trait-schedule-types.php`, `trait-breaks.php`, `trait-face.php`,
  `trait-permissions.php` and `trait-profile-account.php`.
- CI reads the schema version from `trait-schema.php`.

## 3.31.31
The approval engine (`includes/trait-approvals.php`) moved to the new structure, with its
wp-admin page.

### Fixed (wp-admin → Approval Workflows)
- **Settings that did nothing are gone.** The page offered Early Leave and Shift Swap approval,
  but neither module uses approval workflows: whatever was saved was ignored. They are now named
  in one line that says managers decide them.
- **Only the modes a workflow supports are offered.** Vacation and Overtime act only on
  "Level 1" and "Level 1 + Level 2". "Sequential" or "Peer" could be saved for them, and then
  the workflow was silently ignored and any manager could approve. They now offer No approval,
  Level 1 and Level 1 + Level 2; Face Reset also offers Sequential. Saving another mode is refused
  with the reason, and a mode saved by an older version is pointed out on its card.
- **"No approval" says what it does.** It differs per module: a leave is still decided by
  managers (no approval chain), while overtime and face resets are approved automatically. The
  page used to say "proceeds without approval" for all of them.

### Internal
- `src/Approvals/Workflows.php` (modes, checks, steps) and `StateMachine.php` (request and step
  states), unit tested; `src/Approvals/Hooks.php`; HTML in `templates/admin/approvals.php`, the
  page script in `assets/js/admin-approvals.js` (was inline). An unused helper was removed.
- `tests/e2e_approvals.py` (26 checks): the settings page, supervisors, and the two-level flow
  through Leave (order of levels, double decisions, rejection, missing or unlinked approvers).

## 3.31.30
Reports: Location Capacity (the last report of the plan's phase 2).

### Added
- **Seats per location** (wp-admin → Work Locations): how many people a location holds; empty
  means no limit. Shown in the locations list.
- **Location Capacity report** (employee app → Reports): for each active location and working day,
  the people planned there against its seats, the occupancy and, on past days, how many of them
  signed in. A day is "near capacity" from 90 % and "over capacity" above the seats. Without dates
  it shows the next two weeks (a plan; "Next 2 Weeks" quick range). A per-location summary gives
  the peak and the days over / near capacity. CSV and Excel (one row per location and day).
  - Who takes a seat: an employee scheduled with a type that requires the location (e.g. Office),
    at their assigned location (or the default location, as at Sign In). WFH, leave, missions and
    company holidays take none.
  - A department manager sees the whole location (the seats are shared) and how many of the people
    are their own employees.

### Internal
- `src/Reports/Capacity.php` (unit tested); `seats` column on the locations table (schema 3.31.12).
- `tests/e2e_capacity.py` (19 checks).

## 3.31.29
Reports, phase 2: the Overtime and Leave & Balances reports, overtime on days off, the daily
attendance trend and saved views.

### Added (employee app → Reports)
- **Overtime report** (when the Overtime feature is on): per employee, the requests of the period
  by status, approved hours, the hours actually worked in them, utilisation (worked / approved),
  days worked and unapproved extra time after the shift. Only employees with overtime are listed.
  CSV and Excel.
- **Leave & Balances report**: per employee, the days on leave in the period by type, the leave
  requests overlapping the period (approved / pending, with their days), and the remaining balance
  of every active leave type for the period's year ("16 / 21", with entitlement, used and pending on
  hover; overdrawn balances in red). CSV and Excel.
- **Daily attendance trend** on the Attendance Summary: one bar per working day, coloured by the
  rate (90 %+, 75 %+, below), with the day's present / late / absent counts on hover.
- **Saved views**: save the report shown, with its filters, under a name; it is listed above every
  report for you (up to 12; same name replaces). A quick range (This Month, Last Week, …) is saved
  as the range, so the view always opens on the current period; other periods keep their dates.

### Fixed (Reports → Timesheet)
- **Work on a day off was missing from the Timesheet.** Only configured working days were counted,
  so hours and approved overtime on a weekend did not reach payroll. Days off on which the employee
  signed in or had approved overtime now count (net hours, worked days, overtime), with no expected
  hours. The Daily Log, Summary and Workforce reports still list working days only.
- **Overtime on a company holiday or a day off** counted only the part outside the normal shift
  hours. There is no shift on those days, so the whole approved window now counts.

### Internal
- `src/Reports/OvertimeReport.php`, `LeaveReport.php`, `Trend.php`, `SavedViews.php` and
  `DayMetrics::offDayOvertime()` (unit tested). Saved views are user meta `ews_report_views`.
- `tests/e2e_reports_more.py` (27 checks).

## 3.31.28
### Fixed (Sign In / Out)
- **QR Sign In works on sites outside UTC (Egypt, the Gulf, …) (K3).** The phone's location time
  (Unix time, UTC) was compared with WordPress *local* time, so on a UTC+3 site every location
  looked three hours old: QR Sign In was refused with "Location access is required", and every
  Sign In was saved as "unreliable / stale_timestamp", so the integrity column of the
  Sign In / Out Report was wrong. The comparison now uses `time()`.
- **Existing records are repaired once.** On upgrade, rows saved as "stale_timestamp" are
  re-checked against their own time in UTC; rows that really were stale stay that way.
- **Sign Out after midnight on an overnight shift (K4).** After midnight the app looked only at
  the new day, found no Sign In and refused the Sign Out (the Sign In page showed no actions at
  all). An overnight shift now belongs to the day it started: after midnight, and after its end
  while it is still open, the Sign In page, Sign Out, breaks and the holiday check use that day,
  and the Sign Out is recorded on it.

### Internal
- `src/Attendance/ShiftDay.php` (which day the current shift belongs to; unit tested) and
  `attendance_day()`; schema target 3.31.11 runs the one-time integrity repair.
- `tests/e2e_timezone.py` runs on Cairo time: Sign In integrity, QR Sign In, Sign Out after
  midnight and after the shift end, and the repair. `tests/e2e_setup.php` resets the site to UTC.

## 3.31.27
Reports, phase 1 / round 4: the Workforce report on the engine, and clean-up. This completes
phase 1 of the reports plan (engine, Attendance Summary, Daily Log, Timesheet, Excel).

### Fixed (employee app → Reports → Workforce)
- **Every schedule type is counted.** The report had a fixed list of types, so a type you added
  (e.g. Field Visit) disappeared from the counts. Types are now grouped as Office, WFH, Leave,
  Business Trip (incl. Training Course), Other and Not Set.
- **Company holidays have their own column** (the holiday was counted as an ordinary Office day).
- **Actual absences are shown next to the plan** (an absent employee planned for Office was not
  counted as absent).
- The CSV and Excel export the same columns as the screen.
- The Export CSV / Export Excel buttons no longer grow to giant size on narrow screens.

### Fixed (Schedule → Send schedules)
- A department manager's "Send schedules" emailed every employee in the company; it now emails
  the employees the sender manages (administrators: everyone).

### Internal
- `src/Reports/Workforce.php` (unit tested). The Daily Log and Workforce views moved to
  `templates/app/report-daily.php` and `templates/app/report-workforce.php`. Removed the never-shown
  `overtime_report_content()` (an Overtime report on the engine is planned for phase 2).

## 3.31.26
Reports, phase 1 / round 3: the Timesheet and a fuller Excel export.

### Added (employee app → Reports)
- **Timesheet** tab: worked hours per employee for payroll — worked days, net and expected hours
  (also as decimals, e.g. 7:10 = 7.17), the balance, overtime approved / worked / unapproved extra
  (when Overtime is on), late and early-leave minutes, absent days, leave days with the leave taken
  by type (e.g. "Sick Leave 1; Vacation 2") and holidays.
- **Excel exports have more sheets.** The Attendance Summary and the Timesheet add a "Daily Details"
  sheet (every employee-day behind the totals), and every export ends with a "Definitions" sheet
  explaining each figure. Decimal hours are real numbers, so Excel can add them up.
- **Exports are recorded in the Audit Log** (report, format, period and filters).

### Fixed
- Overtime worked used the first Sign Out of the day instead of the last, and was measured
  against the shift of the user looking at it rather than the employee's. Neither showed on the
  screens in use (the Dashboard asks about the signed-in employee; the overtime report that
  asked about others was never displayed), but the Timesheet relies on it. Overtime now has one
  rule set (`DayMetrics::overtime()`, unit tested) used by the Dashboard and the reports.
- Excel files declare a default cell style (some readers warned that it was missing).

### Internal
- `src/Reports/Timesheet.php` and `src/Reports/Definitions.php`; `report_days()` adds approved,
  worked and unapproved overtime to every day; the Excel writer builds any number of sheets.

## 3.31.25
Reports, phase 1 / round 2: the Report Center and the Attendance Summary.

### Added (employee app → Reports)
- **Report Center.** One page with the reports as tabs (Attendance Summary, Daily Log, Workforce)
  and one shared filter bar: period with quick ranges, team, employee and employee status (the
  result filter appears on the Daily Log only). Reports opens on the Attendance Summary.
- **Attendance Summary.** One row per employee: expected days, present / late / absent / leave,
  attendance and punctuality rates, late and early-leave minutes, missing Sign-outs, average first
  Sign In, net and expected hours and the balance between them. Columns sort by clicking their title.
  Every count opens the Daily Log days behind it. A rate with no days behind it shows "—", not 0%.
- **KPIs compared with the previous period** of the same length (e.g. "▲ 3 pts vs previous");
  when that period has no data the card says so instead of showing a made-up change.
- CSV and Excel export of the summary, with the same columns and cards.

### Internal
- `src/Reports/EmployeeSummary.php` (per-employee and total figures, previous period) is unit
  tested; the shell and the summary are `templates/app/reports.php` and
  `templates/app/report-summary.php`; sorting is `assets/js/reports.js`. The Daily Log and
  Workforce reports no longer carry their own copies of the filter form.

## 3.31.24
Reports, phase 1 / round 1: one calculation engine for report days, and the numbers it fixes.

### Fixed (employee app → Reports → Attendance, and its CSV / Excel exports)
- **Working hours now deduct breaks.** A 09:05–17:00 day with a 45-minute break showed 7 h 55 min;
  it now shows 7:10 net. Overnight shifts get their hours (they showed nothing).
- **A company holiday is "Holiday", not "Leave".** Every employee's holiday counted as a leave day,
  so the Leave card was inflated (7 instead of 1 in the test week); holidays have their own card
  and expect no hours.
- **The Excel summary shows the screen's numbers.** It recomputed its own cards from the CSV text
  (Office / WFH from the plan, Leave from Vacation only) and disagreed with the screen.
- New columns on screen and in the exports: late minutes (from the shift start), early-leave
  minutes (before the shift end), break minutes, net hours and expected hours (shift length minus
  the allowed break).
- Business-trip types such as Training Course count under Business Trip.
- The report opens on this month to date (it opened on today only), using the site's time zone.

### Internal
- `src/Reports/DayMetrics.php` measures one employee-day (result, late / early minutes, net and
  expected minutes, missing Sign Out) and `src/Reports/Summary.php` counts them; both are unit
  tested. `report_days()` loads schedules, first Sign In, last Sign Out and closed breaks for a
  period and runs every day through the engine. The CSV and the Excel file are built from one
  `report_export_table()`. Removed the unused `report_csv()` and `report_xlsx_cell()`.
- `tests/e2e_reports.py` pins the report, its CSV and Excel, the default period and department scope.

## 3.31.23
### Changed (employee app)
- The profile menu (picture / initials at the top right, with My Profile and Log out) now shows
  on desktop too. It was shown only on phones, so on a computer My Profile could not be reached
  from the menu.

## 3.31.22
### Fixed (employee app → login and frame)
- **A wrong password now stays in the app.** Nothing ever sent a failed login back to the app's login
  page, so employees landed on the plain WordPress login screen and the app's "The username or
  password is incorrect" message never appeared. A failed login from the app's form now returns
  to it with that message.
- **"You have been logged out successfully" now appears.** Log out links returned to the app
  without the "logged out" flag the login page waits for.
- A page you may not open (e.g. Attendance for an employee) showed the Dashboard but kept the
  other page's title; the title now matches what is shown.
- The menu highlights People on a colleague's profile, and the right item when the address uses
  capitals (e.g. `ews_view=Schedule`).
- The profile menu initials use first and last name, as on the profiles.

### Internal
- The frame moved to `includes/trait-app-layout.php` (which view is shown and who may open it is
  now one list, used by both the page and the menu), HTML to `templates/app/layout.php` and
  `templates/app/login.php`, the login styles to `assets/css/workforce-one.css` (+ RTL), and the
  frame's inline script (profile menu, notices) to `assets/js/workforce-one.js`. Behaviour is
  pinned by `tests/e2e_layout.py`. `includes/trait-frontend.php` is now 441 lines (from 2061): the
  face REST routes, small shared helpers, asset registration, Smart Nudges and the Sign In view.

## 3.31.21
### Fixed (employee app → People and a colleague's profile)
- **Profiles broke when "Show supervisor" was on.** The page asked the database for a
  `supervisor_id` column that does not exist (supervisors are approval relationships), so every
  profile said "Employee not found". The supervisor now comes from the same relationship as
  wp-admin → Employees.
- **Kudos explain why they were refused.** Over the weekly limit, a duplicate, an unknown category
  or an unavailable colleague all showed "Unable to send Kudos"; the reason was dropped on the
  way back. Each now has its own message.
- Kudos can no longer be sent while profiles hide the Recognition section (the form is not shown
  then, but the action accepted direct requests).
- A colleague's profile said "Your earned milestones" / "Your achievements will appear here", and
  showed Achievements even with the Achievements feature switched off.
- Initials use first and last name, as on the other profiles.

### Internal
- People and the colleague profile moved to `includes/trait-people-view.php`, HTML to
  `templates/app/people.php` and `templates/app/employee.php`, styles to `assets/css/workforce-one.css`
  (+ RTL), the Kudos toggle (inline `onclick`) to `assets/js/people.js`. Behaviour is pinned by
  `tests/e2e_people.py`. `includes/trait-frontend.php` is now 691 lines.

## 3.31.20
### Fixed (employee app → Dashboard)
- **The managers' snapshot counts the right people.** Archived employees' schedules were counted
  in "Office Today"; Training Course and other mission/leave types were left out of
  "Leave / Mission" (only Vacation and Business Trip counted); a department manager saw the whole
  company. Now: active employees of the manager's department, every leave and mission type.
- On a company holiday nobody is counted in the Office or at home, and the holiday is named.
- The managers' dashboard closed one `<div>` too many, breaking the page around it.
- The employee's "Today's Schedule" and "My Week" show a company holiday as General Leave
  (they showed the planned Office/WFH and "Working day").

### Fixed (employee app → My Profile)
- "View Leave" opened the Dashboard (it linked to a view that does not exist).
- Recent Attendance showed every Sign In as "Present" even when late, and an older-style late
  Sign In as "No Sign In"; the result now follows the employee's shift.
- On a company holiday "Today" and "My Week" show General Leave (not "Not Signed In").
- Leave balances show 19.5 instead of 19.50; initials follow the admin profile (first + last name).

### Internal
- Dashboard moved to `includes/trait-dashboard-view.php` + `templates/app/dashboard.php` (the
  snapshot rule is `Insights::snapshot()`, unit tested); My Profile to `includes/trait-my-profile.php`
  + `templates/app/my-profile.php`, its styles to `assets/css/workforce-one.css` (+ RTL) and its
  script to `assets/js/my-profile.js`. Behaviour is pinned by `tests/e2e_dashboard_view.py` and
  `tests/e2e_my_profile.py`. `includes/trait-frontend.php` is now 817 lines (from 2061).

## 3.31.19
### Fixed (employee app → Leave and Overtime)
- **Managers' Leave page layout.** With no cancellation requests waiting, the "Cancellation Requests"
  card was never closed, so the cards after it (Pending Early Leave) were drawn inside it. Empty
  manager lists now say so ("No Pending Leave Requests", "No Cancellation Requests").
- **Results were shown twice.** Every result (leave sent, errors, Early Leave errors, overtime sent…)
  appeared both as the layout's pop-up and again inside the page — an Early Leave error opened two
  dialogs. Only the pop-up remains.
- "View all leave requests" appeared with exactly six requests (nothing more to show); it now
  appears only when there are more.
- The Early Leave allowance showed hours like "1.6666666666667"; it now shows "1.67".
- Durations read "1 hour 30 min" in English only; they now use the translated "1 h 30 min" also
  used by the Overtime form. The working-day counter, "working day(s)", "day(s)", the approval
  "Level" and the overtime reason placeholder are translated too (Arabic added).
- Cancelled leave requests are shown in grey instead of red (as if rejected).

### Correction (3.31.17)
- The 3.31.17 notes said conflicting grid changes were not reported; they were (the "Schedule
  Conflict" pop-up). The inline sentence added for them duplicated that pop-up and was removed.

### Internal
- Leave and Overtime views moved to `includes/trait-leave-view.php`, HTML to `templates/app/leave.php`
  and `templates/app/overtime.php`, styles to `assets/css/workforce-one.css` (+ RTL), scripts to
  `assets/js/leave.js` and `assets/js/overtime.js`; number/duration formats to
  `src/Support/Format.php` (unit tested). Behaviour is pinned by `tests/e2e_leave_view.py`.
- Removed the unused pre-3.x `vacation_request_create()` / `vacation_request_respond()` handlers
  (the hooks have pointed to the Leave module for a long time).

## 3.31.18
### Fixed (employee app → Attendance Insights)
The app's Attendance Insights page had its own copy of the calculations and disagreed with the
wp-admin page (fixed in 3.31.15). It now uses the same code, so:
- Employees with attendance tracking switched off are left out (they counted as Office and Absent).
- Training Course and other business-trip types count as Business Trip, and custom schedule types
  get their own "Other" row (both counted as "Not Set").
- An impossible date in the address (e.g. 2026-02-31) falls back to today instead of another week.
- In Arabic the employee drawer opens from the left and the first column sticks on the right
  (the page's inline styles were never flipped for RTL).

### Internal
- The page moved to `includes/trait-attendance-insights.php` next to the wp-admin page, which
  now shares `insights_teams()` / `insights_employees()` / `insights_compute()` with it. HTML in
  `templates/app/attendance-insights.php`, styles in `assets/css/workforce-one.css` (+ RTL), the
  drawer and pickers in `assets/js/app-attendance-insights.js`. Behaviour is pinned by
  `tests/e2e_app_insights.py`.

## 3.31.17
### Fixed (employee app → Attendance, the managers' grid)
- **Refused changes are now reported.** A change with an unknown status, or for an employee the
  manager may not manage, was dropped while the page still said "Saved 0 schedule record(s)";
  it now says how many changes could not be saved. (Changes lost to another manager's newer edit
  were already reported by the layout's "Schedule Conflict" pop-up; that pop-up no longer
  reappears after the next successful save.)
- **Department managers can no longer change another department through the no-JavaScript form**
  (the normal grid already refused it).
- A Training Course or other business-trip type showed "— Not scheduled"; it now shows its name.
- A company holiday shows its name under "Leave".
- Day results use the same rules as wp-admin → Attendance Insights, so the two pages agree.

### Internal
- The grid moved to `includes/trait-attendance-grid.php`, HTML to `templates/app/attendance.php`,
  its inline script and `onchange` handlers to `assets/js/attendance-grid.js`, and the badge /
  result-message rules to `src/Attendance/GridRules.php` (unit tested). Behaviour is pinned by
  `tests/e2e_attendance_grid.py` (results, saving and its concurrency guard, department scope, CSV import).

## 3.31.16
### Fixed (employee app → Team Schedule)
- **A day that has already passed can no longer be swapped.** The swap form offered every
  Office/WFH day of the week, including past days, and the server accepted them, so a swap could
  rewrite yesterday's schedule (and its attendance). Requests and acceptances for a past day are now
  refused with a clear message (translated to Arabic); an administrator can still decide from the
  Requests page.
- The week label said "This week" for every week; it now says This week / Next week / Previous
  week, or the date range.
- When no day can be offered, the swap panel says so instead of showing an empty Day list.
- The "No employees found" row spans every working-day column (it was fixed at 6 columns).

### Internal
- The Team Schedule moved to `includes/trait-schedule-view.php`, HTML to `templates/app/schedule.php`,
  its inline scripts and `onclick` handlers (swap form, PDF, WhatsApp, confirm) to
  `assets/js/schedule.js`, and the team ordering to `src/Schedule/TeamOrder.php` (unit tested).
  Behaviour is pinned by `tests/e2e_schedule_view.py`.

## 3.31.15
### Fixed (wp-admin → Attendance Insights)
- **Clicking a count always shows the employees behind it.** The overview tiles, the Workforce
  Distribution rows and the "Review" buttons did nothing; only the weekly table and the attendance
  buttons opened the list. "Review" now opens the employee's profile.
- **Vacation and other leave types are planned as Leave** (the overview tiles counted them as
  "Not Set" while the card above said "Leave"), custom schedule types get their own "Other" row,
  and business-trip types count as Business Trip.
- Employees with attendance tracking switched off are left out (they were Absent every day).
- "Expected to sign in" counts every schedule type that requires Sign In (it counted Office and
  WFH only); the Missing Sign-out list uses the employee's last Sign Out of the day.
- An impossible focus date falls back to today.

### Internal
- Attendance Insights moved to `includes/trait-attendance-insights.php`, HTML to
  `templates/admin/attendance-insights.php`, styles to `assets/css/admin-attendance-insights.css`
  (+ RTL), the drill-down to `assets/js/admin-attendance-insights.js`, rules to
  `src/Attendance/Insights.php` (unit tested). `includes/trait-admin.php` now holds only the menu, the
  shared admin assets and a few one-line pages (124 lines, from 2101).

## 3.31.14
### Fixed (wp-admin home and Audit Log)
- **The admin dashboard counts the right people.** It only looked at Office and WFH days, so
  employees on a custom schedule type that requires Sign In were never counted; employees with
  attendance tracking switched off were counted as No Show every day; and on a company holiday
  everyone scheduled was a No Show. Expected now = active, tracked, and scheduled on a type that
  requires Sign In, on a day that is not a company holiday (which is shown instead).
- **Audit Log:** an Action filter; the Target column now names shift swaps (employees and date),
  leave types, company holidays and Kudos (they showed "—"); a From/To range entered the wrong
  way round is swapped; impossible dates are ignored.

### Internal
- Admin home and Audit Log moved to `includes/trait-admin-dashboard.php`, HTML to
  `templates/admin/dashboard.php` and `templates/admin/audit.php`, rules to
  `src/Attendance/TodayStatus.php` and `src/Audit/AuditFilters.php` (unit tested).

## 3.31.13
### Fixed
- **On Time / Late Arrival now follows the employee's own shift everywhere.** The Sign In / Out
  report, its CSV, the admin dashboard and the employee's own dashboard and Sign In page judged a
  sign-in against the company working hours, so someone on a 10:00 shift who signed in at 10:05 was
  shown as late. (The attendance reports already used the shift.)
- **Employee profile:** today's card now shows "Late" for a late sign-in (it showed "Present"
  unless the record was an old-style late sign-in), and initials of Arabic and other non-Latin
  names are shown (they came out empty).
- The hidden "Employee Profile" menu entry no longer appears in the admin menu on other pages
  (opening it there only said "Employee not found").

### Internal
- Employee profile moved to `includes/trait-employee-profile.php`, HTML to
  `templates/admin/employee-profile.php`, styles to `assets/css/admin-employee-profile.css`
  (+ RTL), summary rules to `src/Employees/ProfileSummary.php` (unit tested).

## 3.31.12
### Fixed
- **View Navigation: the People page can be configured.** It was missing from the admin page
  (the app had its own list), so it could not be renamed, reordered or hidden. The page and the
  app now use one list.
- **Employee Moments: saving no longer erases the dates of archived employees**, which were
  dropped because only the employees shown on the form were kept. Impossible dates (e.g. 30 Feb)
  and birthdays in the future are refused. Birthdays and work anniversaries on 29 February are
  celebrated on 28 February in other years.
- Smart Nudges: sending a test push no longer also says "settings saved".
- Employee Profile, View Navigation and Recognition: changes are posted and then redirected, so
  refreshing the page no longer repeats a save, reset or Kudos removal. Navigation changes are
  audited.

### Internal
- These five pages moved to `includes/trait-engagement-admin.php` with HTML in
  `templates/admin/`, Smart Nudges styles in `assets/css/admin-smart-nudges.css` (+ RTL), rules in
  `src/Settings/{Navigation,Moments,SmartNudges}.php` (unit tested, also used by the app) and
  hooks in `src/Settings/EngagementHooks.php`.

## 3.31.11
### Fixed (wp-admin → Achievements)
- **Manually granted achievements no longer pile up in the "Automatic Achievements" list.**
  Each grant is stored as its own definition, and every one of them was listed there.
- Removing a manual achievement from an employee's profile also retires its definition, and the
  profile now confirms the removal (the message was only on the Achievements page).
- An unsupported badge icon is replaced by the default 🏅 instead of being saved empty.
- The "Earned achievements" total counts Swap achievements too.
- Errors (feature off, no name, unknown employee) are shown on the page instead of a blank
  error screen; the icon picker is labelled "Badge Icon".

### Internal
- Achievements page moved to `includes/trait-achievements-admin.php`, HTML to
  `templates/admin/achievements.php`, styles to `assets/css/admin-achievements.css` (+ RTL), the
  icon picker to `assets/js/admin-achievements.js`, rules to `src/Achievements/ManualGrant.php`.

## 3.31.10
### Fixed
- **Roles & Permissions: users with two roles kept losing permissions.** Switching a permission
  off stored it on the role as an explicit "false", and WordPress lets that "false" override a
  "true" from the user's other role, so e.g. someone who is both EWS Manager and EWS Employee
  lost the Manager's permissions. A switched-off permission is now removed from the role. The
  upgrade converts existing "false" entries (they stay off) and remembers the matrix, so later
  upgrades never turn removed permissions back on.
- Someone who manages roles through an EWS role (not a WordPress administrator) can no longer
  remove "Manage Roles & Permissions" from every role they hold and lock themselves out.
- Notification Settings: the **VAPID Subject** next to "Send Test Push" was never saved. It is
  now saved when the test is sent (only a mailto: address or an https URL, as Web Push requires).
- Notification Settings: saving the retention period now shows a confirmation; policy changes
  are audited.

### Internal
- Both pages moved to `includes/trait-settings-pages.php`, HTML to `templates/admin/roles.php` and
  `templates/admin/notifications.php`, styles to `assets/css/admin-notifications.css` (+ RTL),
  rules to `src/Settings/RolePermissions.php` and `src/Settings/NotificationSettings.php` (unit tested).
- Schema version 3.31.10 (runs the role migration once).

## 3.31.9
### Fixed (wp-admin → Sign In / Out Report)
- **Manual records are checked like real ones:** a second Sign In (or Sign Out) on the same day
  is refused, and a Sign Out must be after the Sign In. Before, any number could be added.
- **Editing a record keeps its employee and type.** The form did not select the record's
  employee, and a Late Sign In could only be saved back as a plain Sign In.
- **Location of manual records** is checked against the employee's Work Location, like a real
  Sign In (it used the old single-location setting). The Radius column shows that location's radius.
- Records of archived employees can be edited.
- **CSV exports are safe to open in Excel:** cells starting with `=`, `+`, `-` or `@` (e.g. a name
  typed as a formula) are written as text instead of being run as formulas. Applies to the
  Sign In / Out and Reports exports.
- The Sign In / Out CSV link is protected against cross-site requests.
- Errors are shown on the page instead of a blank error screen.

### Internal
- Sign In / Out Report moved to `includes/trait-time-report.php`, HTML to
  `templates/admin/time-report.php`, rules to `src/Attendance/ManualRecordRules.php`, CSV helper to
  `src/Support/Csv.php` (both unit tested).

## 3.31.8
### Fixed (wp-admin → Employees)
- **Every check now runs before anything is saved.** Making an employee their own supervisor was
  refused only after the other changes were already saved (and on Add, after the employee was
  created).
- **A WordPress user can be linked to one employee only.** Linking the same user to two
  employees made it unclear who was signing in.
- **A team manager cannot be moved out of their team's Department**, including to "No
  Department". The check read the old department from a query that never selected it.
- Two employees can no longer supervise each other, and an inactive employee cannot be chosen as
  supervisor.
- An employee whose default shift was deactivated keeps it when their row is saved (the
  inactive shift is still listed for them).
- Errors and confirmations are shown on the page instead of a blank error screen.
- The page loads the WordPress user list once instead of once per employee.

### Added
- An **Archive** button per active employee (asks for confirmation when "Delete Employee"
  confirmations are on).

### Internal
- Employees page moved to `includes/trait-employee-admin.php`, HTML to
  `templates/admin/employees.php`, checks to `src/Employees/EmployeeRules.php` (unit tested).

## 3.31.7
### Fixed
- **Apostrophes and quotes are saved as typed everywhere.** Text from forms (leave and overtime
  reasons, employee and department names, task titles, holiday titles, notes, settings…) was
  stored with a backslash: "Ahmed's" became "Ahmed\'s". 77 inputs across the plugin are fixed.
- Feature Configuration: the **"Reject Early Leave Request" confirmation can now be turned off**
  (it was shown but never saved, so it always came back on).
- Feature Configuration: an Early Leave monthly allowance lower than the per-request maximum is
  refused with a message, and nothing is saved.
- Feature Configuration: the face detector input size is a choice of the sizes the model supports
  (any other number was silently replaced by 320); an empty PWA splash title falls back to the default.

### Internal
- Feature Configuration moved to `includes/trait-features.php`, HTML to
  `templates/admin/features.php`, styles to `assets/css/admin-features.css` (+ RTL), value rules to
  `src/Settings/FeatureSettings.php` (unit tested). Face and splash defaults and the confirmation
  list now live in one place.

## 3.31.6
### Fixed (wp-admin → Schedule Configuration)
- **Working hours that end before they start are refused** unless "Allow Overnight Shift" is on
  (e.g. 17:00 → 08:00 was accepted as a normal day). A refused change no longer saves the grace
  period and overnight setting on its own.
- **Shifts:** a shift that ends before it starts must be marked Overnight; two shifts cannot
  share a name; and a new shift never reuses the id of a removed one (employees whose default
  shift was removed were silently moved onto the next shift added).
- **Schedule types:** Office, WFH and Vacation (which the plugin relies on) can no longer be
  renamed or removed, and a type used in employee schedules can only be deactivated, not
  renamed or removed (that left those days with an unknown type). Duplicate names are refused
  instead of silently dropped.
- **General Leave:** a second holiday on the same date is refused.
- Every save now shows a confirmation or a clear error on the page instead of a blank error
  screen; Working Days and Working Hours had no confirmation at all.

### Internal
- Schedule Configuration moved to `includes/trait-schedule-config.php`, HTML to
  `templates/admin/schedule-config.php`, styles to `assets/css/admin-schedule-config.css` (+ RTL),
  rules to `src/Schedule/ConfigRules.php` (unit tested), hooks to `src/Schedule/ConfigHooks.php`.

## 3.31.5
### Fixed (wp-admin → Leaves)
- **Record Leave no longer eats other pending reservations.** Recording a leave subtracted its
  days from the employee's *pending* balance although they had never been reserved, so the days
  held for the employee's own pending requests were lost. The days are now reserved and approved
  through the same path as an employee request.
- Recorded leaves now remember the balance they were charged to. Before, cancelling a leave
  recorded for another year gave the days back to the current year's balance.
- Recording is checked against the balance left for that year (the page already said the normal
  balance rules apply). The message tells how many days are left.
- **Assign Annual Balance** has a Year field (last, this or next year), so next year's
  entitlements can be prepared in December. It no longer accepts a missing employee or leave type.
- Editing a leave type that no longer exists is refused instead of silently doing nothing.
- After saving, the page always returns to Leaves.

### Added
- The Leaves page lists every employee's balances (entitlement, used, pending, remaining) for
  last, this or next year.

### Internal
- Leaves admin page moved to `includes/trait-leave-admin.php` with HTML in
  `templates/admin/leaves.php` and rules in `src/Leave/AdminRecordRules.php` (unit tested).

## 3.31.4
### Fixed
- The **Face Reset Requests** admin page is now in the menu (Employee Schedule → Face Reset
  Requests). It was never registered, so every "Face Reset Request" notification sent to
  managers and approvers linked to a page that answered "not allowed".
- With the Face Reset approval workflow on, an employee can request a reset **again** after an
  earlier one was decided. Before, the second request failed and could only be pushed through by
  an administrator.
- Face Reset decisions from the Requests Hub now tell the employee and are written to the audit
  log in every case (the workflow path did neither), and a failed face removal is reported.
- On the Face Reset page, approving the first of several approval levels shows "moved to the
  next level" instead of "Face reset approved".
- Legacy vacation approvals from the Requests Hub are atomic: if marking a day fails, nothing is
  changed.

### Internal
- Requests Hub moved to `includes/trait-admin-requests.php`, Face Reset to
  `includes/trait-face-reset.php`; page HTML in `templates/admin/`; presentation rules in
  `src/Requests/Hub.php` (unit tested). Every hub decision now calls the owning module's
  operation (`leave_admin_decide`, `overtime_admin_decide`, `early_leave_admin_decide`,
  `swap_admin_decide`, `face_reset_decide`), and the four copies of the Face Reset decision are one.

## 3.31.3
### Fixed
- Pending **schedule swaps now appear on the wp-admin Requests page** and can be approved or
  rejected there. The page read a table that never existed (`ews_shift_swaps`), so swaps were
  never listed and the admin decision could not run.
- An administrator's swap decision now also notifies the colleague whose day changes, and is
  written to the audit log.
- Cancelling a swap without a linked employee account shows the swap error instead of
  redirecting to the Sign In page.

### Internal
- Schedule Swaps moved to `includes/trait-swap.php` with rules in `src/Schedule`; the admin
  Requests page reuses the same swap operation (schedule re-check + transaction).

## 3.31.2
### Fixed
- Leave decisions taken on the wp-admin **Requests** page now use the same rules as the employee
  app: they are charged to the leave's year (the 3.31.1 fix did not apply on that page) and
  cancellations restore balance and schedule through the same code.
- Overtime overlap check compares times consistently (HH:MM:SS) on every database.

### Internal
- Overtime and Early Leave moved to `includes/trait-overtime.php` with rules in `src/Overtime`
  and `src/EarlyLeave`; the admin Requests page reuses them. No behaviour change.

## 3.31.1
### Fixed
- Leave is now charged to the balance of the **year the leave falls in**. Previously every
  request was checked against and charged to the current year's balance (e.g. a December
  request for January used up the old year's days).
- A leave spanning two calendar years is refused with a clear message: submit one request
  per year.
- Each request now records the balance it was charged to (`balance_id`). Requests created
  before this version are settled on the balance of the year they were submitted in, which is
  where their days were reserved, so existing balances stay consistent.

## 3.31.0
- Internal refactor of the Sign In / Sign Out module (rules in `src/`, page template in
  `templates/app/time.php`, page JavaScript in `assets/js/time.js`). No behaviour change.
- Version bump so browsers load the new CSS/JS instead of cached copies.

## 3.30.0
### Fixed (all present in 3.28.0)
- The plugin did not load at all on PHP 7.4–8.0 (PHP 8.1-only syntax in the Excel report).
- Bulk CSV attendance import crashed (missing `parse_csv()` and preview screen). Now supports
  Excel's `;` separator, BOM, duplicates, unknown employees/dates/statuses, department scope.
- Attendance screen fatal error on PHP 8 when no Teams exist.
- Early Leave form validation never ran in the browser (a `<` in inline JS made WordPress
  encode `&&`).
- Face Reset push notification opened a broken link.
- Logged-in employee was not sorted first in their team on the schedule.
- "Back to recent requests" link with an undefined URL; "1 hours" labels.
- Minimum PHP is now 7.4 (Web Push already needed 7.3+).

### i18n
- Translated: Sign In / Out page, Dashboard, Leave, Overtime, Early Leave, navigation and
  header, notifications bell, all confirmation/result dialogs, request statuses, and the
  face/QR JavaScript (`wp.i18n`). Arabic translation: 440+ strings.

### Tooling
- GitHub Actions: PHP 7.4/8.1/8.3/8.4 lint, PHPStan (level 2), PHPCompatibility, and a
  WordPress + MySQL 8 job running the security e2e, an all-pages smoke test, a Chromium
  JavaScript-error check, an Arabic/RTL pass and uninstall checks.

## 3.29.0
### Security
- Face Sign In can no longer be bypassed by posting `face_verified=1`: a successful
  `/face/verify` issues a single-use, user-bound token that Sign In verifies server-side.
- Employees can no longer overwrite or delete their own face enrollment (bypassed the
  admin-approved reset workflow).
- QR Sign-In requires the employee's own GPS to be inside the kiosk's work location
  (a forwarded QR photo no longer works remotely); QR rotates every 15 s.

### Fixed
- Kiosk display always returned "Kiosk access denied".
- QR Sign-In always failed with "The link you followed has expired".
- Face module lost its REST base URL because of a broken HTML attribute.
- Plugin activated via WP-CLI never received the employee profile/department columns.
- Kiosk errors return 403 instead of 500.

### Performance
- No SHOW TABLES / SHOW COLUMNS / dbDelta on normal requests; schema work runs once
  per schema version (`ews_schema_target()`).
- Kiosk fetches its QR payload once per slot instead of twice per second.

### Privacy
- Optional retention period for GPS coordinates and IP address in attendance logs.
- Face templates of archived employees are deleted automatically (default on).
- `uninstall.php`: opt-in removal of all plugin data.
- face-api.js + models, jsQR and the QR generator are bundled; no third-party CDNs or
  QR APIs are contacted.

### i18n
- Text domain `workforce-one`, `.pot` template, Arabic translation for the attendance,
  face, QR, presence and privacy strings, automatic RTL stylesheets.
