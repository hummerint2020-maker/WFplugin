# Changelog

## 3.31.113
### New: Mobile app API 1 (off by default)
First step of preparing Workforce One for the Android and iPhone apps: WordPress stays the backend and the admin, the apps use the REST API.
Nothing changes in any business process, permission or existing endpoint. Design and owner decisions: `docs/tasks/mobile-api-1.md`.
- **Switch:** Feature Configuration → Mobile app API (off by default). Off, the three new routes answer 403 `FORBIDDEN` with `details.reason: "disabled"`;
  `/meta`, `/me`, sign-in and the Web are unchanged.
- **`GET /me/bootstrap`:** the `/me` document (unchanged) plus the features (the same flags as `/meta`), the screens of the user's phone menu (the same
  items, order and labels as the Web's View Navigation, without links or badge counts) and facts about the site (time zone, today, working days).
- **`GET /me/schedule?from=&to=`:** the signed-in employee's own days: status, shift and times (ISO 8601 with the site's offset; an overnight shift ends the
  next day; shift history applies on every date) and branch (only where the Web shows one). Default: the Web's current week; at most 42 days.
- **`GET /attendance/today`:** the current work day (an overnight shift stays on the day it started), the Sign In / Out / break state and what could be done now.
  Built by `AttendanceService::today()`, which asks the same rules with the same facts as recording (shared fact builders), so the two cannot drift apart.
- **The employee is always the one of the token:** a parameter that names another person (`employee_id`, `employee`, `user_id`, `user`, `eid`) is refused with 400.
- **Contract:** `docs/api/openapi.yaml` (OpenAPI 3.1: every native v1 route, full schemas and examples for the new ones, the error codes, and the client rules for
  refreshing tokens); `tests/check_openapi.py` keeps it in step with the routes and the error codes, and validates every example.
- The employee's shift is read once per request (memoized, cleared when it is written), so 42 days cost the same queries as 7.
- Not in this release (on purpose): the app UI, recording Sign In/Out, requests and approvals, and the refresh-token concurrency fix (a separate security release).

## 3.31.111
### Bug round (the list in docs/tasks/bug-review-2026-10.md)
- **Payroll** (owner's decisions): the deduction limit now covers attendance only (absence, late, early leave); unpaid
  leave is always deducted in full. A first or last month is paid by the month's real days (monthly pay ÷ days in the
  month × days worked), so 2 February and 2 March are treated alike. The payslip shows the rate actually used, and the
  limit as a credit line, so the lines add up to the net pay.
  **Upgrade note:** months that are not closed yet, and months that are reopened, are worked out again with these rules;
  closed months keep their saved payslips.
- **Approving your own request without a workflow:** a manager is refused unless the feature's setting allows it
  (overtime and leave use the workflow's rule "If the approver is the requester", now also shown when there is no
  workflow; Early Leave and Daily Workers have their own switch). Administrators still may; every self-decision is in the
  Audit Log. Defaults keep today's behaviour. The approval of a leave cancellation follows the same rule.
- **Daily Workers:** HR can no longer set today in wp-admin → Days before the site has saved it (it locked the foreman and
  the workers' own Sign In out); an invalid date for the payout PDF is refused.
- **Attendance corrections:** the monthly limit and the lists end the month on its real last day (no 31 February); a
  request that does not go through no longer leaves its photo on disk.
- **App login:** a normal WordPress 6.8 login no longer signs the user out of every phone; a refresh token used twice
  within 30 seconds is a retry, not theft; the per-account login limit counts the user name and the e-mail of one person
  together (whatever the spelling); the limit can see the real client address behind a proxy or CDN when
  `WORKFORCE_ONE_TRUSTED_PROXIES` is set in wp-config.php (off by default); a malformed request is 400, not 403.
- **App links:** menu links no longer carry another page's filters (site, start, dw_day…); the Tasks "Show all" choice
  survives a reload.
- Closed months' detail page uses the day divisor the month was closed with.

## 3.31.110
### New: System health (off by default)
Owner decisions 2026-10-19 (docs/tasks/system-health.md), mockup docs/mockups/system-health/. Turned on in
wp-admin → Features → System health. The data stays in this site's database; nothing is sent anywhere.

- **What it measures** for Workforce One's wp-admin pages, app views, actions, API routes and scheduled jobs: how
  long each takes (typical, slow 1 in 20, worst), database queries per load, peak memory, failed requests,
  PHP warnings and errors from the plugin's own code, and for each scheduled job its last run and whether it is
  late. Names carry no personal data: a page, a view, an action or a route, never a URL, a user or a value.
- **Queries:** from a share of requests (10% by default; always payroll close, exports and scheduled jobs) only the
  *shape* of a query is kept, with its literals removed, with the function it came from. A query repeated many
  times in one load (a loop doing one query per row) and a slow query are found this way.
- **Wp-admin → Settings → System health:** "Look at this first" (ranked: fatal errors, late or failed jobs, failed
  requests, slow pages, pages slower since a release, repeated queries, many queries, memory, new warnings), a
  table of pages and actions with a 14-day line (a dotted line marks a new version), one page in detail, scheduled
  jobs, queries, PHP errors, thresholds and "Delete collected data".
- **Alerts:** a notice on Workforce One pages and the Dashboard (hide for 7 days); an optional email (off by
  default, names and numbers only, new alerts only, at most 5 a day).
- **Cost:** one UPDATE per request at shutdown; about +1 query and a few milliseconds, measured in
  tests/e2e_system_health.py. Off, it adds nothing: no hook, no table, no cron.
- **Data kept:** hourly rows 7 days, daily rows 90 days (30, 90 or 180), query and error detail 30 days; a hard
  cap of 300,000 rows. Who sees it: site administrators (permission "View System Health"); on a network, super
  admins only.
- Not measured, on purpose: network and phone rendering time, and whether the site as a whole is reachable.
- **After an independent review of this release** (fixed before delivery):
  - A query shape could keep a long or odd value: a quoted value over about 10 KB, or a "#" or "--" inside a value, left
    the text in the stored shape. Strings and comments are now found in one pass, an open quote is a value, and a text
    that cannot be parsed is stored as a fixed mark, never as typed.
  - A fatal error was not recorded in wp-admin or on most pages, because WordPress ends the request inside its own
    shutdown function. It is now written from WordPress's fatal-error filter.
  - A PHP error message could keep an unquoted value or a server path (PHP 7 "Undefined index: …", exception traces).
  - A scheduled job is marked failed only by a PHP error or warning, not by a notice or a deprecation.
  - Other plugins' REST calls and actions are recorded only when sampled; sampling is forced for a heavy operation only
    for a signed-in person; a cron request writes only when one of the plugin's jobs ran, with that job's queries.
  - A "new PHP warning" is new once, not every day; the email names no function or file; a job is "late" only when it
    was late at two summaries in a row; the memory limit is the larger of the ordinary and the admin one.
  - The rollup is registered whenever the feature is on (and repairs the schema); a missing table or old schema version
    stops collecting and breaks nothing; days missed while cron was off are built; a day keeps the release it was built
    under; the row limit also stops query rows.
  - Saving Features while it is off writes no option; on a network only a super admin switches it; deactivating the
    plugin clears the rollup; the settings form unslashes its input.
- **After a second review** (fixed before delivery):
  - Jobs: the arguments of a scheduled job are kept apart (a hook run three times is three runs); queue jobs
    that wait for work show "Waits for work", not "never ran".
  - The speed histogram has 20 buckets, so a slow request is no longer hidden in the last bucket.
  - A release in the middle of a day gives one daily row per release, so "slower since the update" compares like with like.
  - Other plugins' routes get no slow, failure, regression or memory finding.
  - Memory is judged by the highest percentage of the limit seen in one request, not by an average.
  - The overhead of the collector is now measured in the tests (requests off, at 10% and at 100%).

## 3.31.109
### New: salary advances (approvals stage 5, off by default)
Owner decisions 2026-10-17 (docs/tasks/approvals.md), mockup docs/mockups/new-types/. Turned on in
wp-admin → Features → Salary advances.

- **Asking.** My requests → New request → **Salary advance**: the amount and the months to pay it
  back over, with what comes off each month as the person types. The limits are settings:
  - the most per advance, in months of salary (1 by default; basic + allowances on file today);
  - a fixed most (none by default);
  - up to 12 instalments;
  - one open advance at a time (on by default);
  - months with the company (none by default).
  Without a salary on file the type says so and has no form.
- **Deciding.** On the approval engine (Approval Workflows → Salary Advance). Never saved: the
  direct manager, then Finance. The card in My approvals shows the salary, the share of the limit,
  any advance still open, the last one and the time with the company. A waiting advance can be
  withdrawn from My requests.
- **Paying.** wp-admin → **Advances** (also for the Finance user of Approval Workflows with no other
  access): approved advances waiting to be paid, recorded with the day and how (cash or transfer).
  The instalments are planned from the month after, equal, the last one taking the rounding.
- **Paying back.** Each instalment is a deduction line in Payroll and on the payslip ("Advance
  instalment (1 of 4)"), taken once its month is closed. One month's instalment can move to after
  the last one (Advances page or the Payroll month), not once its month is closed.
- **Leaving.** Made inactive with an advance open: the Employees page says what is left; the rest
  comes off the pay of the last month, in one line.
- Amounts are not in the Audit Log. Tests: tests/e2e_advances.py, tests/unit/AdvancesTest.php.
- **After an independent review of this release** (all fixed before delivery):
  - An instalment now counts as taken only when the closed month's payslip carries it (`taken_run_id`,
    set inside the close transaction, released when the month is reopened). Before, it counted as taken
    as soon as its month was closed, so an instalment that missed the payslip (paid late, no salary that
    month, the feature switched off for a while) was written off silently. Such an instalment now comes
    off the next month computed, marked "due" with its month.
  - A rest taken from the last pay settles the instalments it covers, so an employee made active
    again is not charged twice.
  - Finance cannot record or move their own advance; an advance for someone who has left is not paid;
    moving an instalment to the end is limited per advance (a setting, once by default; 0 = no limit).
  - The Archive button warns about an advance still owed, like the Employees form; the Advances page
    marks employees who left.
  - Instalments are split in whole cents; an instalment is at least one cent and an advance at most
    9,999,999.99.
  - The Advances menu shows only to who may pay; the Advances page runs fewer queries.

## 3.31.108
### Fixed (owner report): changing an employee's shift changed old reports
Every report counted every past day with the shift the employee was on **now**: lateness, overtime,
early leave, payroll hours, the daily log, the Excel files. Owner decisions 2026-10-17, mockup
docs/mockups/shift-history/.

- **Shift periods per employee**, from a day to a day, the last one open.
  - A new period ends the one before on its own, so periods never overlap and leave no gap.
  - Changing the shift on the Employees page counts from today.
  - wp-admin → Employee Profile → **Shift history** lists the periods and changes the shift from any
    day:
    - a later day waits until then, and can be removed before it starts;
    - a past day is for HR only (to fix a mistake), and counts those days again. Closed payroll months
      do not change.
- **A shift's times have dates too.** Schedule Configuration asks from which day new times count
  (today by default), for the shifts and for the company working hours. The days before keep the old
  times; the page lists the dated times.
- **Every day is counted with the shift and the times of that day:** Sign In lateness, the reports
  and their Excel files, overtime, corrections, the Sign In / Out report, payroll.
- **The employee sees an upcoming change** on My Profile ("From 1 Nov: Evening").
- **On update:** every employee gets one period with their shift of now, from the beginning. Changes
  made before this version were not recorded, so they cannot be brought back. HR can add them by hand
  with a past day.

## 3.31.107
A substitute while a manager is away (approvals stage 4; owner decisions 2026-10-17, docs/tasks/approvals.md,
mockup docs/mockups/substitutes/). **Off by default** (wp-admin → Features → A substitute while a manager
is away); nothing changes until it is turned on.

### Added
- **While a manager is away, the requests that would wait for them wait for their substitute**, marked
  "for" the manager. This covers every request type (leave, cancellation, overtime, correction, swap,
  face reset):
  - new requests;
  - the next level of a request;
  - the ones already waiting when the absence starts.
- **Three ways to set one:**
  - **With a leave request.** A manager is asked "Who decides for you while you are away?". Their own
    manager and the managers in their department are suggested first. It starts when the leave is
    approved and stops if the leave is rejected, withdrawn or cancelled.
  - **"I'll be away" in My approvals**, with a start and an end date and time (a business trip, a
    course). The manager can end it or cancel it there.
  - **wp-admin → Substitutes (HR)**, for any manager. The page also lists every substitute with its
    status and how many requests it decided, and can end or cancel one.
- **On the substitute's side.** My approvals shows "Deciding for Mona Adel until …", and each card
  shows "For Mona Adel". A notification arrives when it is set and when it starts.
- **On the manager's side.**
  - They still see those requests, marked "With Sami while you are away", and can decide them (a
    setting).
  - When it ends, what still waits goes back to them (or stays with the substitute, a setting), and
    they are told what was decided.
- **The path in My requests and My approvals** shows "Sami Ali for Mona Adel".
- **Refusals:**
  - a manager as their own substitute;
  - a substitute who is away then (on leave, or with a substitute of their own);
  - a second substitute for the same time;
  - a person the "who can be a substitute" setting does not allow (any active employee / managers
    only / the same department).
- **A substitute's own request stays with the manager.** A request that moves up after its deadline
  moves up from the manager, not from the substitute.
- **Every change is in the Audit Log** (set, started, ended, cancelled).

## 3.31.106
### Fixed (owner report on 3.31.105)
- **My requests and My approvals were missing on the phone.** They were desktop-only by default, so the
  phone menu had no way to them. They now show under **More** on the phone. A site that already saved
  View Navigation gets them turned on once; an administrator can still hide them there.

## 3.31.105
"+ New request" on My requests (owner decisions 2026-10-16, mockup docs/mockups/new-request/). It uses the
same switch as My approvals and My requests (off by default).

### Added
- **"+ New request"** on My requests opens a sheet with every request the person can make: leave, early
  leave, overtime, attendance correction, shift swap and face reset. Picking one opens its form in the
  same sheet, with Back to the list; after sending, the app comes back to My requests with the new request
  in the list.
  - The forms are the ones on each page (same checks, balance, working days, limits); the forms on the
    Leave, Overtime, Schedule and Attendance pages stay.
  - A type the person can't use now shows why (no department for a swap, no working day in the next two
    weeks, a face reset already asked for).
  - Shift swap: pick the day, then a colleague from the same team or department (teammates first; never
    another department). Colleagues who can't swap that day, or already have a swap waiting with you, are
    shown but can't be picked.
  - Face reset is offered only when a face is registered.
- **Home shortcut** "New request" when the feature is on.

## 3.31.104
### Fixed (owner report on 3.31.103)
- **My requests → Withdraw** opened a blank page: it now comes back to My requests in the app.
- **"All"** on My requests (and on My approvals) kept the filter that was on, so it showed the same list.
- **Pressing Approve showed both Approve and Reject as working.** Only the pressed button shows it now;
  the others are still disabled until the page answers (every form of the app).
- My requests called a leave cancelled after approval, and an expired swap, "Withdrawn". Now: Withdrawn
  (by the employee before a decision), Cancelled, Expired.

## 3.31.103
My approvals and My requests (owner decisions 2026-10-16, docs/tasks/approvals.md, stage 3 of the
approval engine; mockup docs/mockups/my-approvals/). **Off by default** (wp-admin → Features → My
approvals and My requests); nothing changes until it is turned on.

### Added
- **My approvals** (app): every request waiting for the person's decision, of every type (leave, leave
  cancellation, overtime, attendance correction, shift swap after the colleague, face reset).
  - Each card shows who asked and what, "Level 2 of 3", how long it has waited in working hours, and
    "moved up from" when it came from another approver. Overdue requests are marked and listed first.
  - Details show the facts the approver needs (the days, the balance after, others in the department
    away, the reason) and the path, level by level.
  - A decision goes through the request's own handler (the same rules, notifications and Audit Log as
    its page) and comes back here. Face reset is decided on its wp-admin page, linked from the card.
  - Administrators also see the levels waiting for them (nobody else to decide them).
  - **Select** approves several at once, up to 20; rejecting stays one at a time.
  - The menu item shows how many wait, the home page has a card, and the Leave, Overtime, Corrections
    and Schedule pages link to it above their own lists, which stay.
- **My requests** (app): the employee's leave, overtime, corrections and swaps, newest first, each with
  who decides it and where it is, and the reason of a rejection. A leave, overtime or correction still
  waiting can be **withdrawn**: the leave's days are no longer reserved, and its approval is closed.
- **Approval Workflows, per type:** "A reason is needed to reject" (by default only Attendance
  Correction, as before) and "Mark it overdue after N working hours" (0 = the reminder time, or 24).
  An administrator deciding on the Requests Hub is not asked for a reason.

## 3.31.102
### Fixed
- **Requests Hub, leave and overtime: a decision is all or nothing.** The approval and the request itself
  (the leave's status and balance, the overtime's status) are saved together. Before, a failure in
  between could keep the approval while the leave stayed Pending with its balance unchanged, or the other
  way round.
- Administrators are told that a request waits for them only once it is saved: a request rolled back by
  its module no longer sends them a message.
- A leave level waiting for administrators no longer tries to message "no approver" (user 0).

## 3.31.101
### Fixed
- **A request is never refused because a level has nobody to decide it.** Before, someone without a
  manager (the owner, the general manager, a new employee) could not ask for leave, an attendance
  correction or overtime when a level was "Direct manager": they saw "The approval could not be recorded.
  Please try again". Now a level with nobody to decide it (no manager, no department or team manager, HR
  or Finance not set, an approver without a WordPress user) is skipped and the next level decides; when it
  was the last level, administrators decide it and are told (wp-admin → Requests Hub). The Org chart
  already said so for people without a manager. A shift swap still needs its colleague.

## 3.31.100
The approval engine (owner decisions 2026-10-14, docs/tasks/approvals.md, stage 2). Nothing changes
until a workflow is changed: the saved modes, approvers and the Own requests choice work as before,
and nobody is reminded or moved up until hours are set. Database: `ews_approval_workflow_steps.min_amount`,
`ews_approval_steps` (skip_reason, pending_since, reminded_at, escalations, escalated_from_wp_user_id).

### Added
- **Up to four levels in every request type** (Leave, Overtime, Attendance Correction, Shift Swap after
  the colleague, Face Reset): Levels 1, 2 and 3, or Levels 1 to 4, besides the one or two before.
- **New approvers:** the manager's manager, the department manager, HR and Finance (wp-admin → Approval
  Workflows → HR, Finance and the working day). When one of these has nobody for an employee (the top of
  the chart, a department without a manager, HR or Finance not set), administrators decide that level
  instead of the request being refused; the older approvers still refuse it, as before (changed in 3.31.101).
- **A condition per level** for Leave (days) and Overtime (hours): "only if above N"; otherwise the level
  is skipped. A request that no level applies to is approved at once.
- **If the approver is the requester:** they decide it (as before when allowed), it goes to the next level
  (administrators when there is none), or it goes to administrators (as before when not allowed).
- **If nobody decides:** remind the approver after N working hours, and move the request up after M:
  to the approver's own manager, or to the administrators when they have none. Working hours are the
  working day's hours (09:00–17:00 by default) on the working days. The first approver is told, the new
  one too, and the Audit Log keeps every move. Hourly job `ews_approvals_tick`.
- The Approval Workflows page: the request types with their approval and deadlines at the top, a
  numbered card per level, and the path the request takes, updated as you choose.

### Changed
- The Supervisor Relationships rows wrap on a phone instead of running off the screen.

## 3.31.99
Leave types, planned and unplanned (owner decisions 2026-10-14, docs/tasks/approvals.md, stage 1 of the
approval engine). Nothing changes until a leave type is changed: every type stays planned with nothing
carried over, as before. Database: `ews_leave_types` (kind, after_days, carry_mode, carry_max),
`ews_leave_balances.carried`.

### Added
- **Planned / unplanned per leave type** (wp-admin → Leaves). Planned leave is asked before its day
  starts (from tomorrow), as every leave was. Unplanned leave (sick, emergency) can be asked on its
  own, on the day or up to the days the type allows after it (0 = on the day only, at most 60); it uses
  its own balance. A request later than that is refused with its own message.
- **Left at year end per leave type:** not carried over (the default), all carried over, or carried
  over up to a number of days. The balance renews every 1 January; the carried days are kept apart, so
  a year's own days are never added up year after year.
- The app's Request Leave form: the dates start at the first day the chosen type allows, and a line
  says whether it is planned or unplanned and how late it can be asked.
- Leave type changes are in the Audit Log with the new rules.

## 3.31.98
Organization chart, stage 3 (owner decisions 2026-10-14, docs/tasks/org-chart.md): moving people, the
managers file in Excel, Needs attention. Everything is part of the Org chart (off by default).
Database: new table `ews_org_moves` (the history of moves).

### Added
- **Move** (Org chart → a person → Move, or "Set manager" under Needs attention): a new manager or none,
  from a date, and a reason kept in the Audit Log. The dialog says, before saving, that the people under
  them move with them and how many approvals waiting for the current manager go to the new one; it
  refuses a manager who reports to them, or the manager they already have.
  - Approvals waiting for the old manager (their open requests, at any level) now wait for the new
    manager, who is told. Approvals already decided keep who decided them.
  - A date after today is a **scheduled move**, done on that day (the daily job, and at the latest
    within an hour of the first visit). Scheduled moves are listed and can be cancelled. One that can
    no longer be done on its day (the employee or new manager archived, or it would make a loop) is
    not done and shows under Needs attention.
  - Every move is kept: each person's last moves (date, from → to, reason, where it was made) are in
    their panel.
  - While the chart is on, a supervisor changed on the Employees page is a move too (from today, no
    reason), with the waiting approvals moved.
- **Excel**: Export (every active employee: ID, name, domain name, job title, department, manager ID,
  name and domain name, with a "How to import" sheet). Import the changed file (.xlsx, or .csv with
  commas or semicolons): a preview of every new manager and job title, and every row that cannot be used
  and why (unknown or archived employee or manager, twice in the file, their own manager, or a loop,
  checked with every other change in the file), before anything is saved; then saved as moves from the
  chosen date with the chosen reason. A file can reorder people in any row order.
- **Needs attention**: people without a manager, people whose manager was archived (with who it was),
  managers going round in a circle in old data, and scheduled moves that could not be done, each with
  the way to fix it.

## 3.31.97
Organization chart, stage 2 (owner-approved mockup docs/mockups/org-chart/, docs/tasks/org-chart.md).
**Off by default** (Feature Configuration → Organization Chart). Database: `ews_employees.job_title`.

### Added
- **wp-admin → People → Org chart**: the chart built on each employee's Direct Supervisor (nothing
  entered twice). Counts (in the chart, managers, levels, without a manager), the people without a
  manager with a link to set one, managers with more direct reports than the chosen limit, a search
  that opens the branches down to each match, a person panel (department, manager, direct reports,
  everyone under them, the reporting line, open profile, change manager), zoom. Each branch is drawn
  when it is opened, so a large company stays fast. Arabic reads from the right; a phone browses one
  person at a time.
- **The app → My team** (menu item, desktop): the reporting line above, the direct reports, the
  teammates; tap anyone to go to them. Who may open it: administrators and managers, or everyone
  (setting); what an employee sees: their line, teammates and the people under them, or the whole
  company (setting).
- **Job title** on the Employees page while the chart is on (titles in use suggested); can be made
  required when adding an employee.
- Feature Configuration → Organization Chart: on/off, who sees it, what an employee sees, flag a
  manager with more than N direct reports (default 8), job title required. Changes go to the Audit Log.

### Changed
- A supervisor is refused when they report to the employee at any level (before: only two people
  supervising each other). A loop already in old data is shown as people without a manager.
- `employee_picture_url()` moved to People (modular monolith); boundary baseline lowered.

## 3.31.96
Modular monolith, step 2 (docs/architecture/modules.md): People owns the employees table. No change in
behaviour or in the database.

### Changed
- People has functions for the other modules (`includes/trait-employees.php`): an employee by id or by
  user, active or not, the name, the WordPress user, in a department, the active list, the active ids,
  users and counts. 62 places in 30 other files used the table directly and now ask People; the rest
  are joins that put the employee's name next to rows in lists and reports, for a later step.
- `tests/check_modules.py` counts calls to a module's public functions (its `api` in
  `docs/architecture/modules.json`) as allowed. Crossings: 295 → 239 uses of another module's table.

## 3.31.95
Choose the page each kind of notification opens (owner's request, mockup docs/mockups/notification-links/).
No database change.

### Added
- **Notification Settings → Opens** (wp-admin, Notification Policy): for each kind of notification, the
  page it opens from the phone and from the app's Notifications page. "Recommended" (the default) is
  the list agreed in 3.31.94 and shows what it opens; it opens the exact item when there is one (the
  task, the presence check, the Sign Out form for that day). Another app page (Home, Schedule, Sign
  In / Out, Leave, Overtime, Corrections, Tasks, My Pay, My Profile, Polls, Presence, Notifications)
  opens that page only. "A link…" takes a link on this site only; one to another site is refused and
  the page says so. Someone who may not open the chosen page still sees the notification on the
  Notifications page. Smart Nudges keep their own place per reminder. Applies to notifications sent
  from then on; changes are in the Audit Log (`notification_links_update`), the choices in Settings
  Overview (`ews_notification_links`).
- **Sign Out reminder** has its own row in Notification Settings (it shared Attendance Corrections'):
  its own In-App / Push switches and Opens. A site that had set the corrections switches keeps them for
  the reminder.

### Tests
- `tests/e2e_notification_links.py` (29): the Opens column, saving (a link elsewhere refused, the Audit
  Log), a choice used from the phone and the list, Recommended unchanged, the Sign Out reminder.
- `tests/unit/NotificationLinksTest.php`: the choices, links on this site only, applying a choice.

## 3.31.94
Where each notification takes its reader (owner-approved list), and the app is named Workforce One.
Database: column `ews_notifications.link` (schema 3.31.94).

### Changed
- **The app is named Workforce One** (was Employee Hub): the name under the icon, the offline screen
  and the app info in the API. The app's id is unchanged, so installed apps stay the same app (an iPhone
  keeps the name it was installed with until the app is added again).
- **A notification opens the same page from the phone and from the Notifications page**, saved with
  the notification (`link`). Without a link of its own it opens the app page of its kind
  (`src/Notifications/Links.php`):
  - Schedule: schedule changes (they opened the Notifications page) and shift swaps, including swaps to
    approve (they opened Home);
  - Leave: leave, its cancellation and early leave, decisions and requests to approve (some opened Home);
  - Corrections, My Pay, My Profile (achievements; Kudos when People is off), Polls, Presence, My sites,
    the attendance planner (office minimum), and the task's details for a task.
- **No more white WordPress pages.** A link into wp-admin (Face Reset Requests, Daily Workers' changes,
  the Sign In / Out report for refused Sign Ins, the Requests Hub) is kept only for someone who may
  open that page; anyone else gets the app's Notifications page with the notification shown. An app
  page that needs a permission (the attendance planner) does the same.
- **Opened from the phone, a notification is marked read.**
- **Refused Sign In, new polls and presence checks reach the phone**: they were missing from the
  Notifications settings, so they were never pushed. Now listed there, pushed by default.

### Fixed
- Opening a notification from the list without one (or one that is gone) ended on an error page.
- Smart reminders sent by the cron job linked to `wp-cron.php?ews_view=…` (a white page); the test
  push from Smart Nudges linked into wp-admin. Both now open the app.

### Tests
- New `tests/e2e_notification_links.py` (17, in CI): every kind of notification, followed as its
  reader, from the phone and from the list; never a white page; marked read; the open handler.
- New `tests/unit/NotificationLinksTest.php`; `e2e_pwa` and `ManifestTest` check the new name.

## 3.31.93
The installed app's own offline screen instead of the browser's blank page (off until turned on in
Features, owner-approved mockup docs/mockups/offline-page/). No database change.

### Added
- **Offline screen** (Features → Installed App: Offline Screen, off by default). When an app page cannot
  be loaded, the installed app shows its own screen, in English:
  - **No internet** ("No internet connection"), with the employee's schedule for today and the days
    after it, as last seen with the internet on;
  - **The server not responding** ("Workforce One is not responding": the internet works, the server
    does not). It is shown when opening a page meets no network, a server error, or no answer within
    the set time (20 seconds by default, 5 to 60). A Sign In / Out form is never timed out, as the
    server may still record it: only no network or a server down (502/503/504) shows the screen;
  - **Sign In / Out pressed with no connection** ("You are not signed in", with the time of the press
    and a clock), using the note kept by Refused Sign In Attempts (3.31.92);
  - **Back online**: the screen checks every 5 seconds and opens the app again by itself; the app then
    says "You are back online", or that the Sign In / Out of 8:04 was sent to the manager.
- **Every text on the screen can be changed** in Features (badge, title, message and button of each
  state, the schedule card's words, the "back online" messages), each with "Reset to default"; an empty
  box uses the default. `{time}`, `{action}` and `{updated}` are filled in. Also: the time to wait for
  the server, the schedule card and the "keep the app open" note can be turned off. A live preview of
  the real page, with sample data, follows what is typed. The app's name and colour are the ones on its
  icon. Changes are written to the Audit Log (`offline_screen_update`).
- The screen's page (`?ews_pwa=offline`) holds no employee data and is the same for everyone; the
  service worker keeps it with the app's files and fetches it again when a text changes (the page's
  revision is part of the service worker). The schedule is kept on the phone only while the screen and
  its schedule card are on, and is removed when nobody is signed in on the app page.
- Tests: `tests/e2e_offline_screen.py` (settings, page, service worker, preview access),
  `tests/offline_browser.js` (Chromium with the service worker: no internet, Sign In pressed, server
  down, slow server, back online, a changed text, logout, the preview), `tests/unit/OfflineScreenTest.php`.

## 3.31.92
Sign In evidence (two features, both off until turned on in Features, owner-approved mockups
docs/mockups/signin-attempts/ and face-photo/), the IP in the Sign In / Out Report, and the last four
fixes from the second code review (items 10–13). Database: table `ews_signin_attempts`, column
`ews_time_logs.photo_key` (schema 3.31.92).

### Added
- **Refused Sign In attempts** (Features → Refused Sign In Attempts, off by default): every Sign In /
  Out the app refuses is kept with the reason, the time, where the phone was and how far, the device
  ("iPhone · Safari") and the IP; face checks too (matched or not). In Sign In / Out: "Show" recorded /
  refused / both, refused rows marked with the reason, a summary by employee, **Record it manually**
  (the add form filled in with the employee, the event and the time), and each employee's day as a
  timeline (click the name). The department's managers are told when one employee is refused N times
  in a day (3 by default; 0 = never). Kept 60 days by default.
- **A Sign In pressed with no network**: the phone notes it when the button is pressed and sends it the
  next time the app opens. Shown as "Did not reach the server", apart from records and never counted as
  attendance. Its time is counted from the server's clock when the page opened plus the time since
  (changing the phone's clock does not move it); the phone's own time is kept and a gap of more than 2
  minutes is shown ("the phone's clock was off by 47 min"). One that did reach the server is not kept
  again; at most 5 a day per employee.
- **Photo with Face Sign In** (Features → Face Verification, off by default; needs Face Sign In): a small
  photo from the same camera frame as the face check is kept with the Sign In / Out, and with a refused
  face check. Seen by HR and the employee's department manager, or HR only; every opening is in the
  Audit Log; never stored by the browser. Kept 30 days by default. The camera screen tells the employee.
  Stored as a private file (trait-private-files.php: the direct-link check and WFO_PRIVATE_DIR cover it).
- **The IP of each record** in the Sign In / Out Report and its CSV (it was saved, never shown).
- **In the Audit Log too** (owner's request): `signin_refused` (who, Sign In / Out, why, device, IP),
  `face_check_failed`, and `signin_not_reached` (the real time from the server's clock, what the phone
  said, and the gap when its clock was changed). A matched face check is not written there.

### Fixed
- **Service worker** (10): on update it deleted every cache of the site, including other apps' on the
  same domain. Only its own older caches now.
- **Database update** (11): it was marked done even when a table or a column could not be created. Each
  step's database errors are collected and the core tables and later columns are checked; on a failure
  it is not marked done, it is tried again after 10 minutes, and administrators see what failed.
- **Overnight shifts** (12): an administrator could not add a Sign Out after midnight on the Sign In /
  Out Report ("must be on the Work Date"); for a shift that ends after midnight the next calendar day
  is allowed. A correction could also add a Sign Out later than now on such a shift.
- **Overtime after an overnight shift** (13): a window after a 22:00–06:00 shift (06:00–08:00) was put
  before the shift on the same day and never counted as worked. It is now the next morning.

### Tests
- New `tests/e2e_signin_evidence.py` (48), in CI. `e2e_schema` (an update that fails), `e2e_pwa`
  (caches), `e2e_time_report` (IP). Unit: `SignInEvidenceTest`, `DeviceLabelTest`, `SchemaCheckTest`,
  `DayMetricsOvernightOvertimeTest`, overnight cases in `ManualRecordRulesTest` and `CorrectionRulesTest`.

## 3.31.91
Security fixes from the second code review (docs/tasks/bug-review-2026-10.md, items 1, 2, 6, 14, 17). No
database change. The photo kept with each Face Sign In (the owner's decision for item 1) is a mockup
waiting for approval (docs/mockups/face-photo/), not in this release.

### Fixed
- **Face Sign In replay.** The browser works out the face descriptor, so one recorded once could be sent
  again to sign in. Now each verification needs a single-use code from the server (new route
  `GET /face/challenge`, valid 2 minutes, asked for by the page right before the check), and a
  descriptor already seen (the enrolled one, or one accepted in the last 30 days) is refused like a
  mismatch and written to the Audit Log ("face_replay"). Two live captures are never that close. The
  recent descriptors are kept encrypted and deleted with the face.
- **Private files on servers that ignore .htaccess (Nginx).** Correction photos, task files and daily
  workers' photos are checked once a week (and on "Check again"): when one opens by a direct link, an
  administrator sees a warning on Workforce One pages with the Nginx rule to add, or the option to set
  `WFO_PRIVATE_DIR` in wp-config.php. With that set, new files go there and older ones are moved there
  (the move is in the Audit Log); uninstall removes them there too. By default files stay in uploads, so
  site backups keep them.
- **Two leave requests sent at the same moment** could both pass the overlap and balance checks. Leave is
  saved under a per-employee lock; inside the transaction the overlap is checked again and the days are
  reserved only while the balance has them (a conditional update). The same for HR recording a leave.
- **Two overtime requests sent at the same moment** could both pass the overlap check (minutes counted
  twice). Overtime is saved under a per-employee lock and checked again after the insert.
- **Private photos stayed in the browser's cache** after logging out or losing access (and a replaced
  photo showed old for a day). Photos are sent with `no-store`, and logging out sends
  `Clear-Site-Data: "cache"` (the cache only: the installed app and push notifications stay).

### Tests
- New `tests/e2e_request_races.py` (12) and `tests/e2e_private_files.py` (14), in CI.
- `e2e_api_foundation`: challenge and replay checks; the Face tests send live-like descriptors and a
  challenge. Unit: `FaceReplayTest`.

## 3.31.90
Money and permission fixes from the second code review (docs/tasks/bug-review-2026-10.md, items 3, 4, 5,
7, 8, 9, 15, 16). Database: a new employee column, `last_working_day`.

### Fixed
- **Closing a payroll month is all or nothing.** The run and every payslip are saved in one transaction;
  if one payslip cannot be saved nothing is closed and the page says so ("try again"). Before, the month
  could be left closed with payslips missing.
- **An archived employee is paid up to their last working day.** Archive now asks for the last working
  day (today suggested). Payroll includes an archived employee in every month not closed yet up to that
  day, paid for the days to it (day value × days, as for a mid-month start), and days after it are not
  counted. The payroll list marks them "Archived · last working day …"; payslip, PDF and Excel show
  "Paid from … to …". Employees archived before this version have no last working day: when they signed
  in during a month, Payroll names them in a warning until it is set (Employees → Inactive → Last
  working day).
- **Sign In / Out Report: a manager sees and changes their own department only** (list, add / edit,
  Reset Day, CSV), archived employees included. Administrators see everyone. A manager without a
  department sees nothing.
- **Daily workers payout**: every line and every day link is checked; the first failure undoes the
  payout and removes its photo. The payout is worked out again under a lock, so an advance is deducted
  once even when two payouts are saved at the same moment, and a change approved in between is either
  in the payout or refused.
- **Daily workers change approved after the day was paid**: refused ("already paid"); the day changes
  only while it is still unpaid, in one transaction. Days set in wp-admin take the same lock.
- **Correction carried to the next payroll month**: added once (a retry never adds it twice); when the
  adjustment cannot be saved the correction says "NOT CARRIED" and the Audit Log asks HR to add it by
  hand, instead of saying it was carried.
- **Push after switching accounts on the same phone**: the app re-saves the device for the account now
  signed in, at once (before, it stayed with the previous account for up to 3 days). Logging out alone
  changes nothing: the phone keeps receiving the last account's notifications (owner's decision).

### Tests
- New `tests/e2e_money_safety.py` (28): payroll close with a payslip that fails, archived employee pay,
  department scope of the report, correction carry-over. Writes are made to fail on purpose with
  `tests/e2e_break_writes.php` (copied into mu-plugins only while a check needs it).
- `tests/e2e_daily_workers.py`: a payout line that fails, a change approved after the payout.
- `tests/push_sync_browser.js`: account switch on the same phone (15 checks).
- Unit: last working day in `PayCalculator`.

## 3.31.89
Approval Workflows: what is really in force, and Shift Swap approvals. No database change.

### Fixed
- **"No approval" was shown for workflows that were never saved, while something else applied.** A
  workflow is created inactive; until its card is saved each module uses its own rule (Attendance
  Correction and Overtime: managers with Manage Time decide; Face Reset: Manage Settings), which is not
  what "No approval" does once saved. A card never saved now says "Not set yet" and what happens until
  it is saved.
- The note about the workflows not in use said managers decide Shift Swaps; it is Early Leave only now
  (managers with Manage Time in the app, administrators on the Requests Hub).

### Added
- **Attendance Correction card**: which requests go to HR whatever the mode (a Sign In moved earlier,
  above the monthly limit), from Attendance Corrections → Settings, with a link there.
- **Shift Swap approvals.** Approval Workflows → Shift Swap: "Colleague only" (as before: the days are
  swapped when the colleague accepts), "Colleague, then Level 1" or "Colleague, then Level 1 + Level 2".
  With levels, an accepted swap waits for approval (both see it in its own "Waiting for approval" group); the
  approver gets a notification and decides in App → Schedule ("To approve"); the days are swapped only
  when the last level approves, and only if neither schedule changed. A rejection closes it and tells
  both. Administrators can still decide it on the Requests Hub (the approval request is closed too).
  No second request for the same day while one waits.

### Tests
- tests/e2e_swap.py (37), tests/e2e_approvals.py (35).

## 3.31.88
wp-admin menu in sections (owner-approved mockup, docs/mockups/admin-menu/). No database change.

### Changed
- **The menu is "Workforce One" with 8 sections instead of 32 items**: Home, People, Attendance,
  Requests, Schedule & places, Pay, Engagement, Settings. Each section opens its first page, and every
  page of a section shows the section's pages as tabs at the top (none for a section with one page).
  The section of the page being shown stays highlighted, also for pages opened from another page (an
  employee's profile is under People → Employees).
- **Every page keeps its address and its permission.** Old links, bookmarks and links in emails and
  notifications still open the same page, now inside its section. A person sees only the sections and
  tabs they may open (a manager allowed only Sign In / Out and reports sees Home and Attendance).
- **A tab whose feature is off is not shown** (Tasks, Recognition, Face resets; Corrections and Daily
  workers as before); its page still opens from a link.
- Section and tab names are translated (Arabic).

### Tests
- tests/unit/AdminNavTest.php; tests/e2e_admin_nav.py (18 checks, in CI).

## 3.31.87
Push notifications that stopped reaching some employees. No database change.

### Fixed
- **A notification was dropped if the phone did not take it within 5 minutes.** Every push was sent
  with `TTL: 300`, so a phone asleep in a pocket, in battery saving or briefly offline never got it.
  Now the push service keeps it for a day (`TTL: 86400`) and delivers it with high urgency.
- **Push stopped for good when the browser renewed the subscription.** Browsers replace a
  subscription from time to time (and after an update, a reinstall or cleared data); the server only
  learned of a subscription when the user pressed "Enable", kept sending to the old one, got "gone"
  (410) and removed it, while the phone still showed notifications as on. Now:
  - the service worker handles `pushsubscriptionchange`: it subscribes again and reports the new
    subscription (new action `ews_push_resubscribe`; no login needed, the old endpoint is the proof;
    an unknown old endpoint changes nothing);
  - the app re-saves its subscription by itself when permission is granted: every 3 days, 1 to 5
    minutes after it opens (at random, so it never adds to the rush at shift start), or at once when the
    server has no device for the user;
  - a subscription made with other server keys (the site's push keys were renewed) is replaced instead
    of reused, which could never receive;
  - turning push off on a device is remembered, so the app does not turn it back on.

### Tests
- tests/e2e_push.py: renewed subscription keeps the device and user, unknown / private / missing old
  endpoint refused, one row per endpoint, the app page and service worker carry the sync, TTL a day.
- tests/push_sync_browser.js (Chromium, in CI): saved at once when the server has none, not again
  within 3 days, then after a 1–5 minute wait; off is kept; a subscription with other keys is replaced.

## 3.31.86
Review of 3.31.78–3.31.85. No database change.

### Fixed
- **An earlier day of a site opened "Page not found"** (App → My sites → ‹): the link used `?day=`,
  which WordPress reads as a date archive. It is now `?dw_day=`. wp-admin → Daily Workers → Days is
  not affected.
- **Daily workers test (CI)**: the Paid form is shown only once a period can be paid (3.31.83), so the
  test took its nonce from a form that was not on the page and failed on every day but the period's
  last. It now asks for the nonce directly, and checks that an earlier day is a page, not a 404.

## 3.31.85
Daily workers: earlier days. No database change.

### Added
- **Earlier days in the app.** A site's day sheet has ‹ Today › arrows: an earlier day shows as it was
  recorded (workers, marks, extra hours, cost, group photo), read-only. A wrong day is fixed with
  "Ask to change" (⋮), the same request a manager approves in Changes. Before, the page showed today
  only, so an earlier day could not even be asked to change.
- **wp-admin → Daily Workers → Days.** HR picks a site and a day (today or earlier) and sets each
  worker's attendance and extra hours: a day the foreman did not record (no position or photo; the
  app shows "Set by … in wp-admin"), or a correction. A reason is required; every changed worker gets
  an approved entry in Changes ("Not recorded → Present", by whom, why) and the Audit Log. A paid
  period, a day recorded at another site and a day with a change waiting cannot be set there.

## 3.31.84
### Fixed
- **Day sheet and payout pages were slow, and a photo could not be opened on a phone.** The small photo
  on the page was the full camera photo (often several MB), sent as a download with caching turned
  off, so it was fetched again on every visit; tapping it opened a download, which the installed app
  on phones ignores. Now:
  - the page shows a small copy (made the first time it is asked for, also for photos already saved);
  - photos are sent to be shown, not downloaded, and the browser keeps them for a day;
  - a new photo is stored at most 2000 px on its long side (still sharp for a signed sheet), upright;
  - tapping a photo opens it over the page (day sheet, signed payout sheet, a correction's photo).

## 3.31.83
Daily workers review. No database change.

### Fixed
- **The day sheet could not be sent from a browser** ("Confirm the day" did nothing; the same for a
  worker's "I am at the site"): the page reads the GPS first and then sends the form again, and the
  app's double-submit guard counted the first try and blocked the real send. The guard now runs after
  each form's own handlers and ignores a send that a handler held back (also for confirmations).
- **Paying a period before it ended locked the rest of it**: paying a week on Wednesday made Thursday's
  and Friday's sheets (and self sign-ins) fail with "This period is paid and locked". A period is now
  paid on its last day or later, and on its last day only after that day's sheet is saved; the Payout
  page says so instead of showing the Paid button.
- **Worker sign-in (mobile + PIN)**: the 5-tries limit was per phone *and* network, so trying from
  several networks could go on. A mobile number is now also locked after 10 wrong PINs in 15 minutes.
- **Advances** can be recorded only for a worker of that site (there today, or with unpaid days there).
- **A site whose work location was switched off** no longer breaks "My sites" when opened from an old link.
- **The worker's page** shows what the next payout will hand over (unpaid days less advances not yet
  deducted), not the days alone.
- A change decision other than approve / reject is refused.
- Going back to the day sheet in the browser reads the position again before the next send.

### Changed
- Neutral wording for workers: "The worker is in today's list for this site", "self sign-in",
  "Days at the old site stay in the worker's history…", and the others that said he / his / him.

## 3.31.82
### Fixed
- **A popup on every page after Daily Workers** ("Worker added", "Day sheet saved", "Worker moved"…) and
  after a few other forms (Overtime updated / rejected, Kudos sent, Polls): the result stayed in the
  address and the menu links kept it. The menu links and the address now drop any one-time result of a
  form (a key ending in _error, _sent, _done, _saved, _added…), in one place, so a new feature cannot
  forget it again.

## 3.31.81
### Changed
- **Face Sign In off means no Face card.** With "Face Verification for Sign In" off (Feature
  Configuration), Sign In / Out no longer shows the Face Sign In card ("available but currently
  optional"), its camera dialog or the "Face check" line. Turning it back on shows them again; enrolled
  faces are kept.

## 3.31.80
### Changed
- **A correction request offers only what fits the day.** Before, every enabled kind could be picked for
  any day and the request was refused only after sending (e.g. "Forgot Sign Out" on a day that has a
  Sign Out). Now: Forgot Sign Out needs a Sign In and no Sign Out, Forgot Sign In needs no Sign In,
  Whole day missing needs neither, Wrong time needs a recorded time (and only that time can be chosen).
  The others are greyed out, and the first that fits is chosen.
- **"Correct" only on days that can be corrected.** A day where no enabled kind fits (with only the two
  "Forgot" kinds on, a complete day) has no button, and is "nothing to correct" in the form's day list.
  If no day fits, the form says so and Send is off. The server still refuses anything that does not fit.
- API `GET corrections/days`: each day has `kinds`; `can_request` is false when none fit.

## 3.31.79
### Fixed
- **Sign In / Out layout**: "My recent days" sat against the left edge and was wider than the cards above
  and below it (the app's `.wfo-app .wfo-panel{margin:0}` overrode its centring). It is now centred at
  the same 760px width.

## 3.31.78
Two fixes in the employee app. No database change.

### Fixed
- **Approve / Reject did nothing useful** (Corrections, and every app form with Approve / Reject buttons):
  the double-submit guard disabled the clicked button before the browser read it, so the choice
  (`decision=approve`) was not sent and the page answered "Something went wrong". The clicked button's
  value is now carried in a hidden field.
- **A corrections pop-up on every page**: after a request or a decision, the result (`cx_error`, `cx_done`,
  `cx_sent`) stayed in the address, and the menu links kept it, so "Not saved" came back on each page.
  The menu links and the address now drop it, as they already did for Leave, Overtime and the others.
- **"My recent days" said "On time" for a day with no Sign In** (Sign In / Out): only a day with a Sign In
  is "On time"; otherwise the day says why none was needed: Not scheduled, On leave, the holiday,
  Day off, Not signed in yet, or the planned status (a business trip). "none" is red only on a day that
  needs a correction.

## 3.31.77
Office minimum (owner-approved mockups, docs/mockups/office-minimum/): a fixed number of people planned
in the office every working day, for the whole company. Whoever sets the schedule is warned, never
blocked. Off by default: Feature Configuration → Office Minimum. No database change.

### Added
- **Attendance page**: under each day "in the office / minimum" (red when below, green otherwise),
  also in the "Set… for the whole day" row; it updates live while cells are changed, before saving.
  A card above the grid lists only the days below the minimum ("This is only a warning: the schedule
  still saves"). Saving shows nothing extra.
- **Teams on a short day** (a sheet from the card): each team's share, how many it has in the office and
  how many it is short or above, biggest shortfall first, with who could come in (people working from
  home that day, never people on leave). A team's share is proportional to its size, rounded (largest
  remainder) so the shares add up to exactly the minimum; people in no team share one line.
- **Approving leave** (App → Leave): when approval would leave a day below the minimum, the manager sees
  "Approving makes Thu 15 Oct 9 of 12 in the office" and the employee's team against its share. Only a
  warning; the buttons stay.
- **Weekly reminder** (on by default when the feature is on; day and time in the settings): schedule
  managers get a notification with next week's days below the minimum, once a week. New notification
  category "Office minimum".
- **Feature Configuration → Office Minimum**: the switch, the minimum, a different number per weekday
  (empty = the minimum, 0 = no minimum that day), which statuses count as in the office (Office by
  default; business trips are not counted unless ticked), a fixed number per team (the rest is shared by
  the others), and the reminder. Invalid values are refused and nothing is saved; changes are in the
  Audit Log.
- Company holidays and days that are not working days are never checked.

### Not in this release
- The Location Capacity report line and a "days below the minimum" report, and warnings on swap and
  work-from-home requests (only leave approval warns for now).

### Tests
- tests/unit/OfficeMinimumTest.php (shares, fixed teams, ties, weekday numbers, the day check).
- tests/e2e_office_minimum.py (43 checks), run in CI.

## 3.31.76
Daily workers (owner-approved mockups, docs/mockups/daily-workers/): day labourers with no email and
often no smartphone, recorded on site by a foreman (or by themselves with a mobile + PIN), paid daily or
weekly in cash, with the labour cost of every project. Off by default: Feature Configuration → Daily
Workers. Database version 3.31.76 (ten new tables ews_dw_*).

### Design decision
- **Workers live in their own tables, not in ews_employees.** The brief suggested an employee row of type
  "daily"; 141 queries in 37 files list employees (pick lists, Attendance, Team Schedule, reports,
  Payroll, exports, counts, push, achievements…), and every one of them would have needed a filter and a
  test. Separate tables make it impossible for a daily worker to appear in any staff screen, and nothing
  built for staff changed. A future gate tablet can still link a worker to a face profile by worker id.

### Added
- **App → My sites** (foremen): each assigned site with today's state; **the site's day sheet**: every
  worker present / half day / absent with extra hours, the live counts and cost of the day, a group photo
  from the camera, and "Confirm today's sheet", which needs the foreman's position inside the site (the
  same integrity rules as Sign In: no position, a stale phone clock or an impossible movement is
  refused). A saved day is locked; a later change is a request with a reason that a manager approves in
  wp-admin (the original values stay with the request). Quick-add a worker (no account) and move a
  worker to another site from today or tomorrow (the history stays).
- **App → Payout** (foremen, cashiers, managers): what each worker is owed for the period, advances
  deducted (up to the payout; the rest waits), earlier unpaid periods, record an advance, print the
  **payout sheet** (A4 PDF, Arabic, with a signature / thumbprint column), then "Paid · lock the period"
  with a photo of the signed sheet. A paid period is locked (no new days, no changes).
- **The worker's own page** (?ews_view=worker, "Daily worker? Sign in with your mobile" on the login
  page when a site allows it): mobile + PIN, "I am at the site" (GPS inside the site), recent days and
  what he is owed. Recording mode per company, overridable per site: foreman only, workers themselves,
  or both (the foreman's sheet then sets the day).
- **wp-admin → Daily Workers**: the list (search by name, mobile, code or the full national ID; trade,
  subcontractor, site, status, rating), the profile (sites, payouts, owed, advances), add / edit (PIN,
  photo, rating, "Do not rehire"), delete (only without paid days), change requests, settings (mode,
  pay period and week start, default hourly rate, group photo, project sites with project name, dates,
  foremen / cashiers, mode and period per site; trades and subcontractors), reports (labour cost per
  project / site / subcontractor / trade, the project so far, owed, workers per site and day, unpaid
  balances) with Excel, PDF and the **social insurance report** for the accountant (CSV per month).
- **National ID**: Egyptian numbers checked (14 digits, century, birth date, governorate) with a clear
  message; other nationalities as text. Sealed with libsodium secretbox (authenticated) under a key
  derived from the wp-config salts (never stored); searched by a keyed hash; shown masked
  (2900••••••1234). The full number only with the new permission "View national IDs" (not given to EWS
  Administrator by default), and every reveal or full export is in the Audit Log. Never in plain text in
  the database, logs or the Audit Log.
- Permissions: Manage Daily Workers, Foreman (daily workers), Pay daily workers, View national IDs; role
  "EWS Site Foreman". Notifications category "Daily Workers".
- API (token): GET /daily-workers/sites, GET/POST /daily-workers/sites/{id}/day, POST
  /daily-workers/sites/{id}/workers, GET/POST /daily-workers/sites/{id}/payout — the same rules as the Web.
- Pay: present = daily rate, half day = rate ÷ 2, extra hours × the worker's hourly rate (else the
  company default); the rates are kept on each day. Amounts never go to the Audit Log ("payout
  recorded", "advance recorded").

### Tests
- tests/e2e_daily_workers.py (CI), tests/unit/DailyWorkersTest.php (national ID, pay, periods, payout
  with advances, site rules, settings).

## 3.31.75
Self-approval in approval workflows: each workflow says whether the person who made a request may
approve it when they are its approver (for example the owner or the head of HR, who has nobody above).
Allowed by default, so nothing changes for anyone until a workflow turns it off. Every self-decision is
marked. Database version 3.31.75 (ews_approval_steps.acted_by_wp_user_id, self_decision, rerouted).

### Added
- **Approval Workflows → each workflow → "Allow the requester to approve their own request"**
  (on by default; option ews_approval_allow_self). Off: a level whose approver is the requester goes
  to the site's administrators instead (Requests Hub, "For an administrator (the approver made the
  request)"); administrators get an "Approval needed" notification; the requester cannot decide it,
  administrators included. Peer approval (swaps) is not affected. Changing it is in the Audit Log.
- **Self-decisions** on the Approval Workflows page: the latest 50 decisions taken by the requester
  (date, employee, workflow, request, level, Self-approved / Self-rejected), and an Audit Log entry
  `approval_self_decision` for each.
- Every decided step records **who actually decided** it (`acted_by_wp_user_id`), so a decision an
  administrator takes for the approver no longer looks like the approver's own.

### Tests
- tests/e2e_self_approval.py (CI).

## 3.31.74
Attendance corrections (owner-approved mockups, docs/mockups/attendance-corrections/): an employee who
forgot to sign in or out, or whose time is wrong, asks for a correction; the manager decides with the
day's evidence; HR can correct directly. Recorded events are never changed or deleted. Off by default:
Feature Configuration → Attendance Corrections. Database version 3.31.74 (ews_attendance_corrections;
ews_time_logs.source, corrects_id, correction_id).

### Added
- **Sign In / Out → My recent days**: the days within the deadline; a day without a Sign Out (or Sign
  In) has the yellow "Request correction" button, the others "Correct". The request sheet: the day's
  record, the kind (forgot Sign Out, forgot Sign In, wrong time, whole day missing), the correct
  time(s), the reason and a photo (off / optional / required per kind).
- **Corrections page** (app): my requests (waiting / decided, the monthly count) and, for managers and
  HR, the requests to decide with **what the system knows about the day** (the day's events with
  their location and device checks, presence checks, the last device location, the schedule and
  shift, this month's count, the photo); approve, or reject with a note (required).
- **Approval**: workflow `attendance_correction` (No approval / Level 1 / Level 1 + 2) on Approval
  Workflows; without it, managers with Manage Time decide for their department. A second level (HR,
  the new permission "Manage Attendance Corrections") for a Sign In moved earlier and, if set, above
  the monthly limit. Never one's own request. The Requests Hub lists and decides them.
- **wp-admin → Attendance Corrections**: requests (status, kind, department, employee, month),
  a direct correction by HR (reason required, no deadline), settings (kinds, photo, deadline, monthly
  limit and what happens above it, earlier Sign In to HR, end-of-day reminder), and a report by
  employee and department with "frequent" flagged, as CSV.
- **End-of-day reminder** (19:00 by default): a notification to anyone still without a Sign Out;
  it opens the form on today with "Forgot Sign Out".
- **API**: GET /corrections/days, GET and POST /corrections, GET /corrections/pending,
  POST /corrections/{id}/decision (token, the same rules as the Web).

### Changed
- Every reader of ews_time_logs skips an event a correction replaced (reports, payroll, insights,
  dashboard, My Profile, employee profile, achievements, overtime, auto attendance, Sign In).
- The Sign In / Out report and its CSV show where each event came from (App, QR, Automatic, Admin,
  Correction) and mark a replaced original "(kept)".
- Payroll: a correction in a closed month never reopens it; the difference goes to the next open
  month as an adjustment with its reason. Days to review (no Sign Out) clear once corrected.

### Tests
- tests/e2e_corrections.py (75 checks, CI) and tests/unit/CorrectionRulesTest.php.

## 3.31.73
Database upgrade fix (found by the load test): many tables were written on one line, which WordPress
dbDelta() cannot read, so every upgrade sent broken ALTERs, logged database errors and never added
indexes introduced after a site was installed. Database version 3.31.73 (indexes only, no data change).

### Fixed
- Every plugin CREATE TABLE goes through src/Support/SchemaSql.php (the dbdelta_queries filter): one
  column or key per line, "KEY name (cols)", "PRIMARY KEY  (id)", no "IF NOT EXISTS", and integer
  columns with their display width (MariaDB and MySQL before 8.0.17 report it; without it every
  integer column was changed on every upgrade). On a 3,000-employee MariaDB site an upgrade went from
  239 ALTERs and 124 database errors to none; a second upgrade changes nothing.
- Installed sites now get the indexes they missed: notifications user_read_date, leave requests
  employee_status and type_year, employees default_shift_id. The base tables (employees, schedule,
  time logs, leave requests, audit log, tasks, notifications, push subscriptions) are re-checked on
  each schema upgrade, not only on activation.
- Leave requests status is VARCHAR(30) in both definitions (they changed it back and forth); the
  achievements icon has no emoji default (MariaDB stored it as "?" and it was reset on every upgrade).

### Tests
- tests/e2e_schema.py (CI, MySQL 8): drops the indexes older versions missed, upgrades, and checks no
  database errors, every index each CREATE TABLE defines exists with its columns, and a second upgrade
  sends no ALTER. tests/unit/SchemaSqlTest.php.

## 3.31.72
Branches (owner-approved design): an employee can work at more than one branch (a branch is a Work
Location), and each company chooses how the branch is decided. Database version 3.31.72
(ews_employee_branches; ews_schedule.location_id; ews_time_logs.location_id and branch_flag).

### Added
- **Feature Configuration → Branches** (src/Settings/BranchSettings.php, option ews_branch_settings):
  **One branch** (as before, the built-in choice), **Any of their branches** (the branch they were at
  is recorded) or **By the schedule** (each Office day has a branch). Options: also allow their other
  branches (accepted and flagged), a kiosk QR counts at any branch allowed today, show today's branch
  to employees, managers see their department's branches only.
- **Employee Branches** (Work Locations, and its own page): main branch + other branches, 50 a page
  with a search. A new permission, **Manage Employee Branches**: administrators always; a manager
  given it changes their department's people only, with the branches they may give. Every change is
  in the Audit Log.
- **Attendance grid** ("By the schedule"): each Office day shows its branch; the picker offers that
  employee's branches; "Branch…" above a day sets it for everyone who has it; a Branch filter.
- **Sign In** (src/Attendance/BranchRules.php): checked against the day's branch; at another of their
  branches it is recorded there (and flagged "At Another Branch" by the schedule, the employee is
  told); at a branch that is not theirs the expected branch's rule decides, as before.
- **Home and Sign In** show today's branch ("Planned for today"); upcoming days show theirs.
- **Reports**: "At Another Branch" in the daily log (filter and flag) and a column in the Attendance
  Summary; Location Capacity counts planned at the day's branch and actual where they signed in.

### Tests
- New `tests/e2e_branches.py` (30, in CI) and `tests/unit/BranchRulesTest.php`; AttendanceService and
  Capacity unit tests cover branches; `e2e_locations` follows the Employee Branches list.

## 3.31.71
Large companies: found with a load test (nginx + PHP-FPM + MariaDB on 2 cores, 1,000 and 3,000
employees with 3 months of data). Signing in at the start of a shift was already fast and did not
depend on the number of employees; some manager and admin pages grew with it, and two ran out of
memory. No database change.

### Fixed
- **wp-admin → Employees** and **Approval Workflows** no longer run out of memory with thousands of
  employees: every row had a list of every employee (3,000 × 3,000 options). The WordPress user and
  the supervisor are now typed into a field that suggests from one shared list (src/Support/Picker.php,
  "Name · #id"); a name typed but not picked is refused. Both pages show 50 employees at a time with a
  search, and saving returns to the same page and search. A WordPress user that does not exist is
  refused.
- **Team Schedule** and **Attendance** (app): above 60 people, the search and the team filter run on
  the server and the page shows 50 people at a time (src/Ui/ListPage.php); the cards and day counts
  still count everyone, the swap form still offers everyone. Smaller teams see no change.
- **Team Schedule for employees** opens on **My team** (the people in their teams) with a **My
  department** switch for everyone in their department (owner request). Employees without a team and
  administrators see the list as before. The PDF follows what is shown; the swap form still offers the
  whole department.
- **wp-admin → Sign In / Out Report**: 100 records a page with a search by employee (a month for
  3,000 people was 30,000 rows in one page).
- **Push notifications** to many devices go out 20 at a time instead of one by one, and the VAPID
  token is signed once per push service per batch (30 devices: about 2 s instead of one request
  time each).

### Measured (3,000 employees, 2 cores)
| Page | Before | After |
|---|---|---|
| wp-admin Employees | out of memory | 0.06 s, 621 KB |
| wp-admin Approval Workflows | out of memory | 0.06 s, 515 KB |
| Attendance (app, manager) | 1.5 s, 13.7 MB | 0.47 s, 303 KB |
| Team Schedule (app, manager) | 0.66 s, 11.7 MB | 0.11 s, 263 KB |
| Team Schedule (app, employee) | 0.14 s, 1.2 MB | 0.11 s, 274 KB |
| wp-admin Sign In / Out Report | 2.1 s, 31 MB | 0.19 s, 192 KB |

### Tests
- New `tests/e2e_big_lists.py` (in CI), `tests/unit/PickerTest.php`; `e2e_employees`,
  `e2e_approvals` and `e2e_push` cover the pick lists and the parallel sending.

## 3.31.70
Tasks: the five features from the owner-approved designs, each one switched on or off and tuned in
the new wp-admin → Workforce One → **Tasks** page (src/Settings/TaskSettings.php, option
ews_tasks_settings). Database version 3.31.70 (new task columns; ews_task_items, ews_task_comments,
ews_task_activity).

### Added
- **Task details** (a task opens in a sheet): a checklist with a progress bar, comments between the
  people on the task with a file (types and size set by the admin; kept outside the public uploads
  space and sent only to people who can see the task), and the activity (who created, moved, edited,
  ticked or commented, and when). People on the task are told about a new comment.
- **New task**: a manager can send a task to a whole team (a copy each, or one task the first person
  takes; "Take it"), make it repeat (daily, chosen week days, a day of the month; each date gets its
  own task, a missed date is not made up, "Stop repeating"), add a due time and a reminder before it.
  The repeats and reminders run every 5 minutes (and on a page view, at most once a minute).
- **Workload** tab for managers (or administrators only): open, overdue and finished tasks per
  person, the team's totals and on-time rate.
- **My tasks today** on Home: overdue, due today and in progress, with the next step in one tap
  (the task reminders are not repeated there as separate cards).
- **Drag** a card to the next column on a computer (only the allowed moves accept it).
- Admin settings also cover personal tasks, the Done list length, the Home card size and whether the
  creator is told about a new status.

### Fixed
- The overdue / due today task reminders now reach employees whose attendance is not tracked (all
  reminders were skipped for them), and their text is translated (no emoji).

### Tests
- New tests/e2e_tasks_more.py (41 checks, in CI) and tests/unit/TaskSettingsTest.php; the
  tests/e2e_tasks_view.py parser follows the task title link.

## 3.31.69
The last app pages in the new look, from the owner-approved HTML designs ("B" for Overtime, the same
look for swap requests). Every new text is translated (Arabic).

### Changed
- **One request look** (assets/css/app-requests.css, assets/js/sheet.js): a dark summary card, the
  requests grouped (waiting / decided) and the form in a sheet (`<dialog>`): from the bottom on a
  phone, in the middle on a computer; Esc, ✕ or a tap outside closes it.
- **Overtime:** the month's approved total and what is waiting; Today / Tomorrow buttons; a manager
  sees the requests waiting for a decision first (Approve / Reject). A refused request opens the form
  again.
- **Swap requests (Schedule):** waiting for you (Accept / Reject), sent by you (Cancel), decided; the
  form in the same sheet, opened from My week.
- **Tasks:** a board by status on a computer, one list on a phone (In progress first); one button for
  the next step (Start / Done), a menu for the rest (Back to To Do, Reopen, Edit, Delete, asked
  first); search, priority filter, overdue count, Done list cut at 8 (Show all). The page moved from
  PHP strings with an inline `<style>` to templates/app/tasks.php; its notifications are translated.
- **Presence check:** a ring with the time left (counts down; the page reloads when it runs out), the
  camera with a frame, the location status in colour, and clear "verified" / "nothing open" cards.
  A request past its time shows as expired.
- **My Pay:** dark net-pay card, coloured month figures, chevrons instead of text arrows, all text
  translated.
- **Employees (in the app):** a list with search, initials, email and team, rows open the profile;
  Add employee beside it.
- **Kiosk screen:** the Appearance colours, logo, location, a clock, three steps for the employee,
  a large QR and a bar until the next code; side by side on a landscape screen, stacked on a tablet.

### Tests
- New tests/e2e_tasks_view.py (28 checks, in CI): create, refuse an empty title, Start / Done /
  Reopen, To Do cannot jump to Done, Edit, priority filter, a manager assigns, the assignee cannot
  edit or delete, delete, switched off.

## 3.31.68
Attendance page (manager grid) made light, as shown in the owner-approved HTML preview. Measured on
the local test site with 300 employees: page 4.5 MB → 2.0 MB, 4,222 dropdowns (25,327 options) → 8
(43), 830 → 160 ms.

### Changed
- **One picker instead of a dropdown in every day.** Each day is now a coloured button with its plan;
  tapping it opens one shared list (next to the day on a computer, a sheet from the bottom on a
  phone). Keyboard: arrows move, Enter picks, Esc closes.
- **The grid is built once.** The phone layout was a second copy of the whole grid; the same grid now
  turns into one card per employee at 700px and narrower (seven days in a row, an empty day shows a
  dash).
- **Save bar:** counts the days changed and not saved yet, has **Undo** (back to how the page opened),
  and the browser asks before leaving with unsaved changes. Changed days get an amber ring.
- Unchanged: the statuses, "Set…" for a whole day, search, team filter, CSV import and saving.
  The page still sends only the changed days with the value each had when it opened, so a stale
  page cannot overwrite another manager's newer change (reported as a schedule conflict).

## 3.31.67
Performance, after the owner asked for it. Nothing a user does changes; pages need fewer database
queries and the app downloads less. Measured on the local test site (PHP built-in server).

### Changed
- **Settings in one query.** Most Workforce One settings are not autoloaded, so every page read
  them one by one (about 20 queries per page). They are now loaded together at start-up
  (`prime_options()`, WordPress 6.4+). App pages: 40–60 → 22–40 queries.
- **No query per employee in lists.** With 300 employees:
  - Attendance and Attendance Insights: 361 → 30 queries (each employee's shift came from its own
    query; the shifts now come with the employee list).
  - wp-admin → Employees: 655 → 28 queries, 433 → 167 ms (supervisor and teams per employee are now
    two queries for the whole list).
  - wp-admin → Approvals: 346 → 31 queries, 333 → 159 ms (supervisors in one query).
- **Polls** are looked up once per page instead of up to three times (Home, menu, Polls page).
- **The app downloads less:** 433 KB in 14 files → 291 KB in 9 files on Home.
  - Employees no longer get the WordPress toolbar on the app page (it covered the app's header and
    cost two stylesheets, about 80 KB, plus a few queries). Site administrators keep it.
  - The full-screen app page no longer downloads the site theme's fonts (about 50 KB); the app uses
    its own.

### Checked, no change needed
- Indexes on the large tables (attendance, schedule, notifications, audit log, swaps, leave) cover
  the queries the pages run.
- The old stylesheet is already compact (about 21 KB once the server compresses it).

## 3.31.66
New look: the Notifications page (look A, chosen by the owner). Opening a notification, Mark read,
Mark all read, All / Unread and turning phone alerts on or off work as before (same admin-post
actions and nonces; the push tests pass unchanged: 26).

### Changed
- Notifications are **grouped by day** (Today, Yesterday, Earlier), newest first, each with an icon
  and colour for what it is about (leave, swap, schedule, attendance, poll, kudos, tasks, pay…),
  the message, and when it came ("5 minutes ago", "Yesterday · 16:20", "Sun 4 Oct · 11:35").
- Unread ones are bold with a dot; the **dot is the Mark read button**. The whole row opens the
  notification (a real link now, so it also works with the keyboard and screen readers).
- **All / Unread** switch with the unread count, and **Mark all read** (an icon button on phones).
- **Alerts on this device** is a small strip at the top with Turn on / Turn off; it stays hidden
  where the browser cannot receive alerts.
- No emoji, no intro text; translated (Arabic). The page's script moved from inline code to
  `assets/js/notifications.js`; styles in `assets/css/app-notifications.css`.

### Tests
- New `tests/e2e_notifications_view.py` (15): grouping and order, only my notifications, unread
  count and dots, icons, kept formatting, times, Unread filter, Mark read, opening marks read,
  Mark all read (only mine), empty states.

## 3.31.65
New look: the employee login (look C, chosen by the owner), set up from wp-admin. Signing in, a
wrong password coming back to the app, "logged out" and the Remember me / redirect behaviour work
as before (same wp_login_form fields; the layout tests pass unchanged: 14).

### Added
- **wp-admin → Appearance → Login screen:** background (theme colours, one colour, or a picture,
  darkened so the white text stays readable), welcome title, subtitle and a help line under the
  form, and switches for "Remember me", "Forgot password?", the app name / company / tagline, and the
  show-password eye. Empty title and subtitle use the built-in text. A colour too light for white
  text is refused, like the header colours.

### Changed
- The login is a white card on the brand colours with the logo (or the app's initials), the app
  name and "company · tagline" above it; fields with icons, an eye button to show the password,
  "Remember me" and "Forgot password?" on one row, and a Sign In button in the highlight colour.
  It uses the Appearance colours, font and corners, on phones and computers.
- The login's text is translated (Arabic: "أهلًا بعودتك", "تسجيل الدخول", …); before it was English
  only, and the company name was written into the page instead of taken from Appearance.
- "You are already signed in" uses the same card.

### Fixed
- wp-admin → Appearance: the segmented choices (Corners, and now the login background) showed their
  labels in the top corner of each pill instead of centred.

## 3.31.64
Zoom on phones, after the owner asked to stop zoom in / out.

### Changed
- **No accidental zoom:** on phones every field in the app (search, selects, forms) uses 16px text,
  so iPhones no longer zoom into the page when a field is tapped; double-tapping no longer zooms
  either (and taps answer without the short delay).
- **New setting, wp-admin → Appearance → "Stop pinch-zoom on phones"** (off by default): turns off
  pinch-zoom on the full-screen app page. Android honours it; iPhones always allow pinch-zoom
  (Apple ignores this since iOS 10). Leave it off if some employees need to zoom to read.

## 3.31.63
The Team Schedule page again, after the owner's review on a phone (design C, "slim cards").

### Changed
- **No explanation text:** the lines under Team Schedule ("Everyone can view…"), in the swap panel
  ("Choose a colleague… No approval is required.") and at its end ("Use this shared schedule…") are
  gone, on phones and computers.
- **My week** is a dark card in the app's header colours with white day tiles; each day shows its
  status as a coloured word, today is outlined, and **Request a swap** is the only swap button.
- The **swap panel** only appears when there are swap requests this week (or while the form is
  open); the big "No Swap Requests" box and the second "Request Swap" button are gone. People
  without a My week card (no employee record in the list) still get the panel's own button.
- **Colleague cards** are slimmer: name, manager badge and team on one line, the week in one row
  of small coloured boxes.
- On phones the search and the **PDF**, **WhatsApp** and **Email** buttons share one row (square
  buttons, icon over a short label).

### Fixed
- On some phones the page was wider than the screen (header, week switcher and My week cut off on
  the right): the three action buttons could not shrink. Everything now fits from 360px up.

## 3.31.62
Fix for 3.31.61, after the owner's test on the live site.

### Fixed
- **The WhatsApp / PDF button made the site hang** ("The web page loads very slowly … 32000ms").
  A cache, minify or compression plugin (or the host) buffers every response and rewrites it; the
  PDF reached the phone shorter than the size announced in its header, so the phone kept waiting
  for the rest until the host gave up. Files are now sent with every output buffer dropped first,
  and the size header only when nothing can change the bytes (new `Support\Download`). The same fix
  covers the payslip PDF, the payroll Excel and the report Excel downloads, which had the same risk.
- The WhatsApp button no longer leaves the page when something goes wrong: it gives up after
  20 seconds and shows "The PDF could not be prepared. Please try again." (it used to open the PDF
  link inside the installed app, with no way back). When the phone refuses to share the file, the
  PDF is downloaded and WhatsApp opens instead; a cancelled share does nothing.
- Native app sign-in: `expires_in` could read 899 instead of 900 when the clock crossed a second
  while the tokens were being issued; the lifetimes are now counted from the moment of issue.

## 3.31.61
The employees' Team Schedule page, after review (design A), and a working WhatsApp button.

### Changed
- On phones and tablets (820px and narrower) every colleague has **their own card**: picture, name,
  team and the week as small day boxes in one row (status in words, today outlined). The wide table
  stays on computers and in print.
- **My week** comes first: your own days, today's status and a **Request a swap** button that opens
  the swap form. Swap requests follow, then the team.
- The team part has a **colleague search** (cards and table alike) and labelled **PDF**,
  **WhatsApp** and (managers) **Email everyone** buttons. The legend and the "Team Schedule" blurb
  are hidden on phones.

### Fixed
- **WhatsApp did not send a PDF.** It opened the browser's print dialog (which often does nothing in
  the installed app) and then WhatsApp with text only, because a WhatsApp link cannot carry a file.
  The week is now made into a real PDF on the server (new `SchedulePdf`, endpoint
  `admin-post.php?action=ews_schedule_pdf`, signed-in users, nonce-checked, Arabic shaped). On a
  phone the WhatsApp button hands the PDF to the share sheet, so it is sent as a file in WhatsApp;
  where files cannot be shared (most computers) the PDF is downloaded and WhatsApp opens with a note
  to attach it. The PDF button downloads the same file.

## 3.31.60
### Added
- **Full-screen app page** (wp-admin → Appearance → Brand, on by default): the page that holds the
  app shows only the app, without the site theme's header, footer and page spacing. This removes the
  empty band above the app header, most visible in the installed app. The theme's and other
  plugins' head / footer code still runs (styles, the install tags). Untick it to keep the theme's
  header and footer around the app.

### Changed
- Home's cards use the full width of the app, matching the header.

## 3.31.59
The managers' Attendance page on phones and tablets (700px and narrower), after review. Only the
look changed; the grid tests pass unchanged (23).

### Changed
- An employee's card opens to the week's days **side by side in one row** (day, date, the planned
  select and the result under it), like the earlier design; today's column is highlighted, and on a
  narrow phone the row scrolls sideways.
- The "Set…" boxes for whole days are one scrolling row too.
- The stat tiles fit in one row on a tablet (icon above the number) and two per row on a phone.
- Fixed: the Schedule Controls title was pushed aside on tablets.

## 3.31.58
New look, step 11: Attendance Insights. The week and day switchers, team and day filters and the
names drawer behind every count work exactly as before (same data attributes and script; the
insights tests pass unchanged: 13 + 12).

### Changed
- **Switchers**: week and day as two cards with arrows, a Today button when another day is shown,
  and the team / day pickers beside them.
- **Workforce Overview** tiles with icons (Office, WFH, Leave, Business Trip, Other, Absent,
  Not Set) instead of emoji; **Attendance** results as coloured tiles with an icon + word.
- **Weekly table**: row icons, the chosen day's column highlighted, counts as tappable pills
  (zeros greyed out).
- **Names drawer** slides in from the side on desktop and up from the bottom on phones.
- Remaining texts translatable (Arabic added).

## 3.31.57
New look, step 10: the Report Center. Every report, filter, quick range, saved view, CSV / Excel
export and sortable column works exactly as before (same tables, columns and data attributes; the
report tests pass unchanged: 41 + 27 + 23).

### Changed
- **Report tabs** as a row of pills with icons (scrolls sideways on a phone), the report's
  description under them.
- **Saved views** as chips with a remove button.
- **Report Period** card: quick ranges as pills, then From / To, team, employee, status and result
  side by side with one Generate Report button. "Save this report as a view" folds away under a
  link.
- **Report cards**: summary tiles with icons instead of symbols, KPI tiles with the change versus
  the previous period, the daily attendance chart in the theme colours, and tables with sticky
  headers and status chips with icons (Present, Late, Absent, Leave…). The planned status uses
  the same chips as the Schedule page.
- Export buttons with icons; the filter texts are translatable (Arabic added).

## 3.31.56
New look, step 9: the managers' Attendance page. Planning the week, "Set…" for a whole day, search
and team filter, saving only the changed cells (with the stale-page conflict check), and the CSV
preview / import work exactly as before (same form, fields and the classes attendance-grid.js uses;
the grid tests pass unchanged: 23).

### Changed
- **Week switcher** card (arrows and the date range, tap it to pick a date) beside a short intro.
- **Five stat tiles** with icons: employees, present, late and absent days, attendance rate.
- **Schedule Controls**: search with an icon, the team filter, one "Set…" box per day (today
  outlined) and a colour key.
- **The grid**: pictures or coloured initials, You / Manager badges, the planned type coloured in
  each select, and the result under it as an icon + word (Present, Late, Absent, Leave, Awaiting
  sign in, Not scheduled, trips) instead of symbols; the names column and the day headers stay in
  place while scrolling.
- **Save bar** stays at the bottom of the screen while you edit, with one yellow Save button.
- **Phones**: one card per employee that opens to two-column day boxes.
- The CSV preview and the Bulk CSV Import section are cards in the same style.
- All texts are translatable (Arabic added).

## 3.31.55
New look, step 8: People and a colleague's profile. Searching, the team filter, the profile
sections chosen in wp-admin → Employee Profile, and Kudos work exactly as before (same form and
fields; the People tests pass unchanged: 15).

### Changed
- **People**: one search bar (name, team, Search / Clear), the number of people found, and
  cards with each person's picture or avatar (or coloured initials), name and login.
- **A colleague's profile** uses the My Profile look: a header card with the picture in a ring,
  name and team; **Work Profile** (teams, a clickable work email, supervisor); **Achievements**;
  **Recognition** with a Give Kudos button, the Kudos form and the received Kudos as cards.
- All texts are translatable (Arabic added).

### Fixed
- Home: the "No upcoming schedule" message in My Week had oversized text; it now uses the
  same empty-state style as the other pages.

## 3.31.54
New look, step 7: My Profile. Changing the picture (avatar, photo upload, initials) and the
password work exactly as before (same forms, fields and the ids the page script uses; the profile
tests pass unchanged: 12, plus 3 new checks).

### Changed
- **Header card** with your picture large in a ring (photo, avatar or initials), an edit button on
  it, your name, email, and Active / team chips.
- **Today**: three tiles for the planned status, attendance and working hours.
- **My Information** as a clean two-column list; **Security** with a Reset Password button;
  **Achievements** as small cards.
- **My Week** uses the same seven-day strip as the Schedule page (icon + word, today outlined).
- **Leave Balance**: days available, a bar and "Used · Pending · % used" per leave type.
- **Recent Attendance** table restyled; the status shows a dot + word.
- **Picture dialog**: centred on desktop, a sheet from the bottom on phones; avatar filters as
  pills and round avatar choices with a check mark; the password dialog in the same style.
- All texts are translatable (Arabic added); emails, times and English names line up correctly
  on the Arabic app.

## 3.31.53
New look, step 6: Polls (the Polls page and the poll card on Home). Voting, changing a vote, the
results rules (after voting / when the poll closes / admins only) and anonymous polls work exactly
as before (same form and fields; the poll tests pass unchanged: 33, plus 3 new checks).

### Changed
- **Poll card**: an icon, "Employee poll · Open/Closed", the question and its description, and
  chips for "Closes in 2 days" and "Anonymous" (icons instead of emoji).
- **Choices** are large tappable rows (two per row on wide screens, one on phones) grouped as a
  labelled set for screen readers; one main Vote button.
- **Results**: one row per choice with the percentage, a bar and the vote count; your choice is
  outlined and marked "your vote"; a closed poll says "Here's the final result".
- **Polls page**: "Open polls" and "Past polls" with their counts, cards side by side on wide
  screens, and a friendly empty state.
- The Polls texts are translatable (Arabic added, with Arabic plural forms); the page title and
  the menu item "Polls" are translated too. Questions typed in English keep their punctuation
  in the right place on the Arabic app.

## 3.31.52
New look, step 5: the Schedule page. The team schedule, week switching, PDF / WhatsApp / Send
schedules, and swap requests (send, accept, reject, cancel) work exactly as before (same forms and
fields; the schedule and swap tests pass unchanged: 13 + 26, plus 6 new checks).

### Changed
- **Week switcher** as one card (arrows, "This week", the dates), with the date picker and the
  PDF / WhatsApp / Send schedules buttons beside it (two rows on phones).
- **My week**: a new strip with your seven days, each with an icon and the word (Office, WFH, …);
  today is outlined.
- **Team table**: each person with their picture or avatar (or initials), team name and the
  You / Manager badges; every day is a status chip with an icon + word (no emoji); today's column is
  highlighted; the names column stays in place while the days scroll sideways on a phone.
- **Swap panel**: requests show who asked whom, the day and the two statuses being traded, with
  Accept / Reject / Cancel buttons and a status chip (Pending, Accepted, Rejected, Cancelled).
- The Schedule page texts are now translatable (Arabic added).

### Fixed
- On themes that limit page content to a narrow column (block themes such as Twenty Twenty-Five,
  ~645px), the app was squeezed on desktop; it now uses up to 1440px.

## 3.31.51
New look, step 4: the Leave page. Requesting, cancelling and approving leave and Early Leave work
exactly as before (same forms and fields; the leave tests pass unchanged: 77 + 17 + 26).

### Changed
- **Balances first**: one card per leave type with the days available (or "No balance deduction").
- **Request Leave** and **My Leave Requests** side by side on wide screens, stacked on phones;
  the form has larger fields, the live working-day count and one main button.
- Each request shows the leave type, dates as "25 Oct – 27 Oct 2026", working days and reason, and
  its status as an icon + word (Approved, Pending, Rejected, Cancelled); cancellation notes and the
  Request Cancellation button keep their rules.
- Managers' lists (leave approvals, cancellations, pending Early Leave) use the same rows with
  Approve / Reject buttons; Early Leave is a card in the same style.
- The page's own header card was removed (the app header already says Leave).

## 3.31.50
New look, step 3: the Sign In / Out page. Signing in and out work exactly as before (same forms,
location check, Face, QR, breaks; the attendance behaviour trace is identical to 3.31.47).

### Changed
- **Your picture in the middle**: your photo or avatar (or initials) inside a ring. Before Sign In
  the ring shows how much of the Sign In window has passed; signed in, how much of the shift you
  have worked; on a break it turns amber; signed out it is full. A small badge on the picture says
  where you are (not signed in, signed in, on a break, signed out). Name, schedule (icon + word).
- **The time**: before Sign In the site's current time (it keeps ticking, in the site's time zone,
  not the phone's); then your Sign In or Sign Out time.
- **One main button**: Sign In before, Sign Out after (the other form stays in the page, hidden,
  exactly as the server would refuse it anyway); nothing after Sign Out. The window message
  (available / not yet / no longer / signed in / signed out) sits under your name.
- **Checks**: location needed or not, the Sign-in window (open until …, closed, or your result),
  and Face check (optional, camera opens on Sign In, or set up your face first).
- Breaks, Today's Record (a small timeline), QR Sign In, Presence Verification and Face Sign In
  are cards in the same style; the location request and the Face / QR camera dialogs too.
- No emoji on the page (the Face button no longer adds a camera emoji either).

## 3.31.49
New look, step 2: the Home page (Dashboard). Same information, same links, nothing removed.

### Changed
- **Greeting in the header**: "Good to see you, Mona." with today's date; the page's cards sit over
  the header's lower edge.
- **Employees**: a "Today" card with today's schedule (icon + word), location, Sign In / Sign Out
  times and one main button (Sign In / Out, or View Attendance once signed in); Smart Nudges as
  compact cards with their action and Dismiss; **quick tiles** for the pages you may open (from
  the menu, so permissions and View Navigation apply) plus Notifications with the unread count;
  My Week with each day's status as an icon + word; Overtime Today; Today's Moments; the poll.
- **Managers**: four numbers over the header (active employees, office, WFH, leave / mission),
  the same tiles, Today at a glance (bars labelled with icon, word and number), This Week with the
  working days and View Schedule.
- No emoji on the page (SVG icons); the bell's list uses the SVG icon too.
- The page's styles load in the <head> (no unstyled flash).

## 3.31.48
New look, step 1: the app frame and the theme. Every page keeps what it does (same forms, links,
permissions and results); pages themselves are redesigned in the next steps.

### Added
- **wp-admin → Workforce One → Appearance** (needs Manage settings): company name, app name,
  tagline and logo; four ready themes (Indigo Night, Nile Teal, Royal Blue, Sunset) or your own
  four colours (header start / end, highlight, main colour); gradient or solid header; font
  (Alexandria, Cairo, IBM Plex Sans Arabic, Tajawal, or the device font); corners (sharp, soft,
  round). A live preview follows every change. Colours that would make text hard to read (WCAG
  contrast below 4.5 : 1) are refused and the previous colour kept, with a message saying why.
  Restore the built-in look at any time. Saves and resets are in the Audit Log; the setting is in
  Settings Overview and its export.
- **Fonts are part of the plugin** (`assets/fonts/`, SIL Open Font License); the app never loads
  fonts from Google or any other site.

### Changed
- **App frame**: on desktop a narrow menu rail with line icons; a coloured header with the page
  title, the bell and your picture. On phones a new bottom bar: Sign In / Out is the big middle
  button, with a dot showing whether you are signed in (green), not yet (amber) or signed out;
  the active page is highlighted; items that do not fit, My Profile and Log out are in a **More**
  sheet. Which items appear, their names and order still come from View Navigation.
- Menu, bell and profile-menu icons are SVG instead of emoji.
- Buttons and accents on the older pages follow the theme colour.
- The phone's "Add to Home Screen" hint (previously never shown) is in the More sheet, and hidden
  once the app is installed.

### Not changed
Page content, forms, field names, links, permissions, notifications and every action. The
Sign In / Out page, reports and wp-admin pages work as in 3.31.47.

## 3.31.47
API Phase 0B: sign-in for the future Android / iOS apps. Nothing changes for the Web app or
wp-admin: they keep the WordPress login. No attendance, schedule or other data endpoints yet.

### Added
- **Native sign-in** with the WordPress user name and password (`wp_authenticate()`, so lock-out and
  two-factor plugins on the WordPress login still apply; WordPress application passwords are not
  accepted). Allowed: an active employee linked to the user, or a Workforce One administrator or
  manager without an employee record. An archived employee gets ACCOUNT_DISABLED, a WordPress user
  with neither gets ACCOUNT_NOT_LINKED.
- **Tokens**: an access token (15 minutes) and a refresh token (60 days, never past 180 days after the
  sign-in), both random opaque strings tied to the site, the user and one device session. Only
  HMACs of them are stored. Every refresh replaces both tokens; presenting a refresh token a second
  time is treated as theft: the device session ends, it is written to the Audit Log, and the app
  must sign in again.
- **Device sessions**: one per app installation (installation id, platform, model, app version, first
  and last seen). The user can list their devices and sign one out; signing out (or signing in again
  on the same installation) ends that session and its tokens. The browser login is never affected.
- **Endpoints** (`/wp-json/workforce-one/v1/`): `GET /meta` (public: product, site, API and app
  versions, feature flags, branding), `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`,
  `GET /me`, `GET /me/devices`, `DELETE /me/devices/{id}`. Every answer uses the API envelope with
  stable codes (INVALID_CREDENTIALS, RATE_LIMITED, TOKEN_MISSING, TOKEN_INVALID, TOKEN_EXPIRED,
  REFRESH_INVALID, REFRESH_REUSED, DEVICE_REVOKED, ACCOUNT_DISABLED, ACCOUNT_NOT_LINKED,
  HTTPS_REQUIRED, ...), `Cache-Control: no-store`, and never a database error or file path.
- **Login limits**: 5 failed sign-ins per user name and 20 per address in 15 minutes; then every
  attempt is answered 429 RATE_LIMITED with Retry-After, the same for existing and unknown users.
- **HTTPS required** for sign-in and every authenticated request (for development only:
  `define('WORKFORCE_ONE_API_ALLOW_HTTP', true);` in wp-config.php).

### Isolation
An access token is read only by the permission check of the native routes above (the
`Authorization: Bearer` header, or `X-WFO-Authorization: Bearer` where hosts strip Authorization;
never the URL, a cookie or the body). The plugin does not hook `determine_current_user`, so a token
does not sign anyone in to wp-admin, admin-post.php, `/wp/v2`, the Web Face routes or other
plugins' routes, and the WordPress user it sets for a native request is put back as soon as the
handler returns.

### Sessions end when
the user signs out of that device or signs it out from another one; signs in again on the same
installation; a refresh token is reused; the password is changed or reset (WordPress
`wp_set_password`; on WordPress 6.0 / 6.1 `after_password_reset` and a changed hash on
`profile_update`); the WordPress user is deleted or removed from the site; the employee is archived,
made inactive or linked to another user on the Employees page; the 180 days are over. An account
that stops qualifying in any other way (role removed, employee made inactive directly in the
database) is refused on its next request.

### Database
Schema version 3.31.47: `ews_api_devices`, `ews_api_tokens`, `ews_api_rate_limits` (the idempotency
table of 3.31.46 is reused). Expired tokens, old device sessions (90 days after they ended) and old
rate-limit buckets are removed by the daily cleanup; uninstall drops the tables.

### Not changed on purpose
The Web app, wp-admin, the WordPress login, the Face routes and Sign In / Out (the attendance parity
trace is identical to 3.31.45 and 3.31.46).

## 3.31.46
API foundation, Phase 0A: security fixes, the Attendance service and the API skeleton. No new
endpoints, no change to what employees see on the Sign In page (except one Face message), no
change to any attendance rule.

### Security
- **Face verification no longer returns the match distance.** `POST /face/verify` answered with
  the distance and the threshold, so a script could adjust a face descriptor step by step until it
  matched (a similarity oracle). It now returns only `{ok}` (plus the single-use token on a match).
  After **5 failed attempts in 10 minutes** a user gets HTTP 429 with `Retry-After` until the 10
  minutes are over, and the lock-out is logged (`face_verify_locked`). A successful verification
  clears the count. Enrolment, the token (120 s, single use) and Sign In are unchanged; the page
  now says "Face did not match. Please try again." without a number.
- **No more double Sign In / Sign Out / break.** The "already signed in?" check and the insert were
  separate steps, so two requests at the same moment (a double tap, a retry on a slow network)
  could both be recorded: 6 simultaneous Sign Ins left 2-3 rows on 3.31.45. Now each employee's
  attendance writes run one at a time (MySQL / MariaDB `GET_LOCK`, named per site and employee, no
  table or privilege needed, released automatically), and every insert is checked afterwards: if
  another request recorded the same event first, the later row removes itself and that request
  gets the usual "You have already signed in today." The check works on any database, including
  those without `GET_LOCK` (SQLite, some clusters). The same applies to Sign Out, to starting a break
  (one open break) and to Resume Work (a break ends once: one notification, one Audit Log row).

### Changed (internal; the Web behaves as before)
- **Attendance service** (`src/Attendance/AttendanceService.php`): Sign In, Sign Out, QR Sign-In and
  breaks are decided in one place. The Web handlers (`time_event()`, `presence_qr_signin()`,
  `break_start()`, `break_resume()`) only read the form, call the service and show the same
  messages as before. The service sees the site only through `AttendanceContext` (the plugin's
  existing helpers), so the future API will call the same code. Break rules are in
  `src/Attendance/BreakRules.php`, Face decisions in `src/Attendance/FaceMatch.php`.
- **Idempotency keys** (for the API; the Web does not send them): a request with a key returns the
  stored result of the first request with that key (24 hours), a different request with the same
  key is refused, and a failed save is not kept so a retry can succeed. New table
  `ews_api_idempotency` (schema version 3.31.46).
- **API skeleton** (`src/Api/`): one route table under `/wp-json/workforce-one/v1/` (today only the
  four Face routes, same paths and authentication as before), the response envelope and request
  ids (`Response`), and stable error codes with HTTP statuses for every attendance, break and
  location result (`ErrorMap`). No new endpoints.

### Proof that the Web did not change
`tests/e2e_attendance_parity.py` runs 46 steps (normal day, duplicates, grace, WFH, Office,
leave, General Leave, attendance off, the Sign In window, geofence inside / outside / no GPS,
stale and inaccurate locations, Face required and the token's timing, QR Sign-In, breaks, an
overnight shift after midnight, achievements) and records what each did: message, time log rows,
Audit Log rows, break sessions, notifications, achievement awards, cron events. The trace of
3.31.45 and of 3.31.46 are identical.

### Not changed on purpose
- A normal Sign In still records location integrity (stale, inaccurate, impossible movement)
  without blocking it, as before; QR Sign-In and Presence still block. That is a product decision.
- No token authentication, no attendance / leave / manager / payroll endpoints, no FCM, no native
  Face, no QR window or Presence changes (Phase 0B and later).

## 3.31.45
A targeted hardening release: security, speed and correctness fixes found in the architecture audit.
No new features, no database changes, no settings removed.

### Security
- **Web Push endpoints can no longer point inside the server's network (SSRF).** Any signed-in user
  could save a "push endpoint" such as `http://169.254.169.254/…` or `https://127.0.0.1/…`, and the
  server would later POST to it. Now an endpoint must be HTTPS on the standard port, without a user
  name or password, with a real public host name (not `localhost`, `.local`, `.internal`, a single
  word or a disguised number such as `127.1`); an IP address must be public (loopback, private,
  link-local, carrier-grade NAT, multicast, documentation and other reserved IPv4/IPv6 ranges are
  refused, including IPv4 inside IPv6). Checked when subscribing and again just before every
  delivery: the host name is resolved, every address must be public, and cURL connects only to the
  address that was checked (no second DNS answer), HTTPS only, no redirects. There is no list of
  allowed providers, so every standard push service keeps working (FCM, Mozilla, Apple, Windows).
  Subscriptions saved earlier with an endpoint that can never be valid are removed at their next
  delivery and logged in the Audit Log (`push_endpoint_removed`).
- **Presence Verification now checks where the phone is.** Scanning the right QR was enough, so a
  photo of the kiosk screen sent to someone at home verified their "presence". The employee's own
  device location must now be inside the requested work location (its radius), with the same rules
  as QR Sign In (`LocationAssessment`: a missing or stale location is refused, impossible movement
  counts as outside). The QR signature, its 15-second freshness, the request's expiry and the
  location of the QR are checked as before. The page asks for the location when it opens and says
  so if the permission is denied; the Audit Log has the reason and the distance.

### Fixed
- **Payroll → Close month now sends the "Payslip ready" push** (as the Notification Policy's
  Payroll row, on by default, says). It only created the in-app notification.
- **A slow or dead push service no longer freezes actions.** Pushes were sent during the request,
  each device with a 15-second timeout. They are now sent right after the response (WP-Cron), with a
  3-second connect / 5-second total limit. The last delivery (sent, failed, last errors) is shown on
  Notification Settings.
- **Reports, payroll and the admin pages no longer look up each employee's shift one by one.** One
  query for the whole list (or none when the employee rows are already loaded). Measured with 60
  employees: Timesheet 123 → 61 queries, Attendance Summary 127 → 66, Payroll month 116 → 56, admin
  dashboard 94 → 35, Sign In / Out report 212 → 153. Shifts, the Company Working Hours fallback and
  the per-request cache work as before.
- The presence request notification was saved with the wrong fields (its id as the category).

### Changed (internal; same results)
- **One rule for "late"** (`src/Attendance/Lateness.php`): the reports and payroll and the dashboard /
  My Profile / Sign In / Out report labels now use the same code. Tested against the old code every
  5 minutes over two days for day, overnight, midnight and no-grace shifts, and at the boundaries
  (exactly at the end of the grace is on time; one second later is late).
- **One way to notify** (`notify()`): saves the in-app notification and queues the push, each as the
  Notification Policy allows. All notifications use it. Differences, by design: a category's push
  setting now also applies where only an in-app notification was sent before (leave / vacation
  decisions and approver steps, overtime approver steps; their push is off by default, so nothing
  changes unless an administrator turned it on), and Kudos follow the Recognition in-app setting.

### Not changed on purpose
- Admin Requests page decisions still do not push (as before).
- Polls and presence requests still have no push: their categories are not in the Notification Policy
  (unchanged; adding them would be a new setting).
- A Sign In entered or imported by an administrator for another day is labelled On Time / Late by
  its time on the dashboard, but by its work day in the reports (as before).

## 3.31.44
Values that were fixed in the code are now settings. Every default is the old value, so nothing
changes until an administrator changes it; all four are on Settings Overview.

### Added
- **Schedule Configuration → Absent After** (default 120 minutes): when a no-show on a shift shows as
  Absent today on dashboards and today's reports (Pending before that). Past days, reports over past
  periods and payroll are not affected: a past day without a Sign In is Absent anyway.
- **Feature Configuration → time to answer a presence request** (default 3 minutes, 1–60); the
  employee's notification says the time.
- **Work Locations → near capacity from** (default 90% of seats, 50–100) for the Location Capacity
  report.
- **Payroll → Rules → late arrival by tiers** as an alternative to by the minute: "more than N minutes
  = a share of a day's pay", the highest tier passed counts.

### Not changed on purpose
- The location checks at Sign In (GPS accuracy 100 m, phone clock within 5 minutes, no "travel"
  faster than 180 km/h) stay fixed: they stop location spoofing.
- The installed app's name and colour stay fixed: changing them can make phones treat it as a new app.

## 3.31.43
### Added
- **Payslip PDF** for every closed month: "Download payslip (PDF)" in My Pay (the employee's own)
  and on an employee's month in wp-admin → Payroll (logged in the Audit Log). Same lines as My Pay:
  net pay, figures, earnings, deductions with the days behind them, and how a day is valued.
  Made on the server (no browser printing), so it downloads the same way on phones, including the
  installed app. Months not yet closed have no PDF.
- Arabic names and reasons print correctly (letters joined, right to left), using the bundled
  DejaVu Sans font; each PDF embeds only the letters it uses (about 25 KB).

### Internal
- `src/Pdf/` (Document, TrueTypeFont, ArabicText), `src/Payroll/PayslipPdf.php`,
  `assets/vendor/dejavu/`. Tests: `tests/unit/PdfTest.php`; `tests/e2e_payroll_close.py` now reads
  the PDF back with `pdftotext` (43 checks; CI installs poppler-utils).

## 3.31.42
Payroll, phase 2.

### Added
- **Bonuses and deductions** for an employee's month, each with the reason the employee sees.
  The limit on deductions covers attendance only, not these. The Audit Log records them without
  amounts.
- **Closing a month:** every payslip is kept as it is at that moment and no longer follows
  attendance, adjustments or rule changes. A month can be closed only after it ends and once no day
  needs review (a missing Sign Out must be fixed first). A closed month can be reopened to correct a
  mistake (logged). Export uses the closed figures.
- **My Pay** in the employee app (English, the employee's own pay only), switched on by an
  administrator under Payroll → Rules (off by default; listed on Settings Overview). It shows closed
  months as final payslips and the current month as an estimate ("Expected net"), with the days
  behind each figure. When a month is closed with My Pay on, each employee is notified (new
  notification category "Payroll").

### Notes
- Database 3.31.15: new tables `ews_pay_adjustments`, `ews_payroll_runs`, `ews_payslips`.
- A PDF payslip is not included.

### Internal
- `tests/e2e_payroll_close.py` (33 checks); `PayCalculator::month()` takes the adjustments.

## 3.31.41
### Added
- **wp-admin → Payroll** (phase 1; WordPress administrators, or a role given the new "Manage
  Payroll" permission on Roles & Permissions — no EWS role has it by default). Employees see nothing.
  - **Salaries:** basic salary and allowances per employee, with the date each salary starts.
    Earlier months keep the salary they had; the history can be opened and a wrong entry removed.
    The Audit Log records that a salary was set, never the amounts.
  - **Monthly Payroll:** each employee's pay for a month, worked out from attendance with the
    report engine's figures: absence, late arrival (from the shift start, once past the grace),
    early leave without an approved request, unpaid leave, and approved overtime actually worked
    (× 1.35 on work days, × 2 on days off and company holidays). Opening an employee shows the days
    behind every figure. Days without a Sign Out are flagged for review (counted as worked).
  - **Rules:** a day's pay (basic + allowances, or basic only, ÷ 30), what an absent day deducts,
    the overtime rates and an optional monthly limit on deductions. Listed on Settings Overview.
  - **Export to Excel:** one row per employee, plus a sheet with every day behind the figures.
- **Leaves → leave types: "Paid %"** (100 = paid, the default for every existing type; 0 = unpaid),
  used by Payroll.

### Notes
- Amounts are before income tax and social insurance. The rates and limits must follow the labour
  law and company policy; check them with HR or the accountant.
- An employee's first salary is paid from its start date (÷ 30 per day); after that, the salary in
  effect on a month's last day covers the whole month.
- Database 3.31.14: new table `ews_pay_rates`, column `paid_percent` on `ews_leave_types`.

### Internal
- `src/Payroll/` (PayCalculator, PayRules, Hooks), `includes/trait-payroll.php`,
  `templates/admin/payroll.php`. Tests: `tests/e2e_payroll.py` (33 checks, a month with every kind of
  day and the net pay worked out by hand), `tests/unit/PayrollTest.php`.

## 3.31.40
### Added
- **wp-admin → Settings Overview** (administrators only): every Workforce One setting in one
  read-only list, grouped by area, with its current value, its default, whether it was changed and
  an Edit link to the page where it is set. "Only changed" shows just the changed ones. Settings the
  plugin writes itself and values kept from older versions are labelled as such; stored settings
  this version does not use are listed by name.
- **Export settings (JSON)** on the same page, for support: every setting with value, default and
  changed, plus the plugin, WordPress and PHP versions. Secrets (push private key, kiosk secrets)
  are never shown or exported, only whether they are set; employee data (birthdays, face reset
  requests) is only counted. Each export is recorded in the Audit Log.

### Internal
- `src/Settings/Options.php` lists all 59 settings with their defaults. Code reads settings through
  `$this->option()`, which uses those defaults, instead of repeating a default at each
  `get_option()` (about 90 places). No default changed: every one was checked against the value
  the code used before.
- `tests/unit/OptionsTest.php` fails when code uses a setting that is not listed, or passes
  `get_option()` a different default. `tests/e2e_settings_overview.py` (27 checks) runs in CI.

## 3.31.39
### Fixed
- The installed app (Add to Home Screen) opened on the site's home page instead of the employee
  app when the `[employee_app]` page is not the front page. It now opens on the app page.
- Phones that already installed the app keep it: the manifest now has an `id` equal to the old start
  URL, which is how phones identify an installed app. Nothing changes on sites whose app page is the
  front page.

### Internal
- `tests/e2e_pwa.py` 27 checks (start page, id, front-page case); `tests/pwa_browser.js` checks the
  id and start page as Chromium reads them.

## 3.31.38
The installable app (PWA, `includes/trait-pwa.php`) moved to the new structure. **No change for
phones:** the manifest, the service worker, the page tags, the splash screen, the install banner and
the push helpers are byte-for-byte the same as before (checked against a snapshot of the old output
for several splash settings, and the full app pages).

### Internal
- `src/Pwa/Manifest.php` (manifest and service-worker values; unit tested) and `src/Pwa/Hooks.php`
  (registered in the same order as before). Output in `templates/pwa/head.php`, `footer.php` and
  `service-worker.php`.
- `tests/e2e_pwa.py` (24 checks: manifest, icons, service worker headers and cache, offline assets,
  head tags, splash settings and escaping, install banner, push helpers, other pages untouched) and
  `tests/pwa_browser.js` (Chromium: the service worker installs, controls the app page and fills its
  cache; the manifest is installable; the splash goes away; no errors). Both run in CI.
- This was the last module with HTML inside PHP strings.

## 3.31.37
Employee Polls: reworked and moved to the new structure (`includes/trait-polls.php`).

### Added
- **Audience:** a poll is for everyone or for one department; other departments neither see it nor
  can vote in it.
- **When results are shown:** after the employee votes (as before), when the poll closes, or to admins
  only. Employees who voted see "Results will be shown when the poll closes" in the meantime.
- **Changing a vote** until the poll closes (optional per poll); the employee's own choice is marked
  in the results.
- **Anonymous or named:** anonymous (the default, as before) exports only the counts; a named poll
  also exports who voted for what.
- **Notify employees** when a poll opens (in-app and push, once), to its audience only.
- **Polls page** in the employee app (menu item "Polls", shown when there is a poll for the
  employee): open polls, unanswered first, then past polls with their results. The Dashboard shows
  the newest poll the employee has not answered, with "See all polls" (before, only the newest poll
  was ever shown, so an older open poll could not be answered).
- **Admin list:** each poll's state (Open, Scheduled, Closed, Hidden, Archived), audience,
  participation ("12 of 40 voted (30%)"), results and **CSV export**. "Closes in 3 days" on open
  polls. Percentages now add up to 100.

### Fixed
- Creating a poll was recorded as an update in the Audit Log.
- A refused vote (poll closed or switched off, not for the employee's department, a second vote)
  showed a bare error page; the employee now goes back to the poll with the reason.
- Archiving a poll now ends it properly: it is shown to its audience under past polls with its
  results, and cannot be voted in or reactivated by mistake.
- The admin page showed any text put in its link as an error; it now shows only its own messages.

### Internal
- `src/Polls/PollRules.php` (state, audience, voting, results visibility, checks, percentages,
  participation; unit tested) and `src/Polls/Hooks.php`. HTML in `templates/admin/polls.php`,
  `templates/app/polls.php` and `templates/app/poll-card.php`; `assets/css/admin-polls.css` and
  `assets/js/admin-polls.js` (were inline). Schema 3.31.13 adds the poll settings columns.
- `tests/e2e_polls.py` (33 checks).

## 3.31.36
Auto Attendance (`includes/trait-auto-attendance.php`) moved to the new structure.

### Fixed
- **Overnight rules never signed out.** A rule such as 22:00 → 06:00 tried to record the 06:00 Sign
  Out on the same day, before the 22:00 Sign In, and skipped it. A Sign Out earlier than the Sign In
  now means an overnight shift: it is recorded the next morning, on the day the shift started (as
  for manual Sign Out after midnight), and a one-time overnight rule switches off after it. The list
  shows "(next day)" next to such a Sign Out.
- **Weekly rules ran on the wrong weekday on sites west of UTC** (the Americas): the weekday was
  read from midnight UTC converted to the site's time zone, i.e. the day before. It now comes from
  the date itself. Sites in Egypt and the Gulf were not affected.
- A one-time rule for a day the employee was off or on leave stays listed as enabled no longer: it
  switches off once its day has passed.

### Internal
- `src/Attendance/AutoRules.php` (days, overnight rules, what is due; unit tested) and
  `src/Attendance/AutoHooks.php`; HTML in `templates/admin/auto-attendance.php`; the confirmations
  in `assets/js/admin-organization.js` (were inline).
- `tests/e2e_auto_attendance.py` (22 checks): saving, the cron (times, no duplicates, leave and
  holidays skipped, weekly days, overnight, a site west of UTC), switch off, delete, bulk Sign Out.

## 3.31.35
Departments and Teams (`includes/trait-departments.php`, `includes/trait-teams.php`) moved to the
new structure.

### Fixed (wp-admin → Departments)
- **The page's checks did not stop anything.** Each check redirected with an error but then carried
  on (`wp_safe_redirect(...) or exit` never reaches `exit`), so a department was saved with a
  duplicate name, with no code, or with a manager from another department, and **a department that
  still had employees or teams was archived** (its employees kept pointing to an archived
  department). Every check now stops the save or the archive.
- **Creating a department was recorded as an update** in the Audit Log (same for teams).
- The page showed any text put in its link as an error; it now shows only its own messages.

### Fixed (wp-admin → Teams)
- **The reason a team could not be saved is shown.** It always said "Please verify the team name,
  manager and members"; it now says which: a missing field, a manager or member from another
  department, or a name already used (also by an archived team: archived names stay reserved, which
  is why creating a team with an archived team's name failed with no explanation).
- The team form lists only the chosen department's employees as manager and members, and each
  employee shows their department. The team list shows each team's department.

### Internal
- `src/Organization/OrgRules.php` (the checks and their messages; unit tested) and
  `src/Organization/Hooks.php`; HTML in `templates/admin/departments.php` and `teams.php`; the
  Archive confirmations and the team form filter in `assets/js/admin-organization.js` (were inline).
- `tests/e2e_organization.py` (26 checks).

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
