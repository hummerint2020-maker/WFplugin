# Workforce One – code structure and refactoring guide

The plugin started as one class (`EWS_Manager_V31_1`) built from 19 traits in
`workforce-one/includes/`, with HTML, CSS and JavaScript inside PHP strings. It is being
moved, **one module at a time and without behaviour changes**, to the structure below.
Done so far: Sign In / Sign Out (reference module), Leave (`includes/trait-leave.php`, wp-admin Leaves page in `includes/trait-leave-admin.php`) and Overtime + Early Leave (`includes/trait-overtime.php`), Schedule Swaps (`includes/trait-swap.php`), Schedule Configuration (`includes/trait-schedule-config.php`), Feature Configuration (`includes/trait-features.php`), Employees and the employee profile (`includes/trait-employee-admin.php`, `includes/trait-employee-profile.php`), Sign In / Out Report (`includes/trait-time-report.php`), Roles & Permissions and Notification Settings (`includes/trait-settings-pages.php`), Achievements (`includes/trait-achievements-admin.php`), the admin home and Audit Log (`includes/trait-admin-dashboard.php`), Attendance Insights in wp-admin and in the app (`includes/trait-attendance-insights.php`), the app's Report Center (`includes/trait-reports.php`, engine in `src/Reports/`), the employee app's Team Schedule (`includes/trait-schedule-view.php`) and managers' Attendance grid (`includes/trait-attendance-grid.php`), the app's Leave and Overtime pages (`includes/trait-leave-view.php`), Dashboard (`includes/trait-dashboard-view.php`), My Profile (`includes/trait-my-profile.php`) People (`includes/trait-people-view.php`) and the app frame and login (`includes/trait-app-layout.php`), Smart Nudges / Moments / Employee Profile / View Navigation / Recognition (`includes/trait-engagement-admin.php`), and the wp-admin request pages: Requests Hub (`includes/trait-admin-requests.php`) and Face Reset Requests (`includes/trait-face-reset.php`), and the approval engine with its wp-admin page (`includes/trait-approvals.php`, rules in `src/Approvals/`), and Work Locations (`includes/trait-locations.php`, rules in `src/Locations/`), and kiosks / QR / Presence Verification (`includes/trait-presence.php`, rules in `src/Presence/`), and Departments and Teams (`includes/trait-departments.php`, `includes/trait-teams.php`, rules in `src/Organization/`), and Auto Attendance (`includes/trait-auto-attendance.php`, rules in `src/Attendance/AutoRules.php`), and Employee Polls (`includes/trait-polls.php`, rules in `src/Polls/`), and the installable app / PWA (`includes/trait-pwa.php`, values in `src/Pwa/`, output in `templates/pwa/`). The wp-admin Requests page reuses the module operations instead of its own copies.

```
workforce-one/
  employee-schedule-manager.php   bootstrap: autoloader, traits, hooks
  src/                            namespaced classes (WorkforceOne\…), PSR-4, src/autoload.php
    Support/Geo.php
    Attendance/SignInRules.php         business rules – pure PHP, no WordPress
    Attendance/LocationAssessment.php  business rules – pure PHP, no WordPress
    Attendance/Hooks.php               the module's add_action() calls
    Attendance/ManualRecordRules.php   admin-entered Sign In / Out records
    Attendance/Insights.php            planned category / actual status / rates (Attendance Insights, Attendance grid)
    Attendance/GridRules.php           Attendance grid badges and save/import messages
    Attendance/TodayStatus.php         today's On Time / Late / No Show (admin home)
    Attendance/ShiftDay.php            which day the current (overnight) shift belongs to
    Attendance/Lateness.php            the one "late" rule (shift start + grace), used by the reports, payroll and sign_in_classification()
    Attendance/AttendanceService.php   Sign In / Out, QR Sign-In and breaks: the one place that decides (Web handlers are adapters; the API will be another)
    Attendance/AttendanceContext.php   what the service may touch on the site (the plugin's helpers, as callables; fakes in tests)
    Attendance/AttendanceCommand.php, AttendanceResult.php  the request as facts; the outcome as a code + details
    Attendance/BreakRules.php, FaceMatch.php   break start / resume checks; Face match and failed-attempt limit
    Api/Hooks.php, Routes.php          REST transport: one route table under workforce-one/v1 (today the Face routes)
    Api/Response.php, ErrorMap.php     response envelope and request ids; domain codes → stable API codes + HTTP status
    Attendance/AutoRules.php, AutoHooks.php  Auto Attendance rules: days, overnight, what is due
    Audit/AuditFilters.php             Audit Log date range and paging
    Support/Csv.php                    CSV export cells (formula-injection safe)
    Support/Format.php                 number, hours and duration formats
    Reports/DayMetrics.php, Summary.php, EmployeeSummary.php, Timesheet.php, Workforce.php, Definitions.php  the report engine: one employee-day measured (incl. overtime), day counts, per-employee figures, payroll timesheet, figure definitions
    Reports/OvertimeReport.php, LeaveReport.php, Trend.php, SavedViews.php, Capacity.php  overtime and leave & balances reports, daily attendance trend, saved report views, location capacity
    Leave/RequestRules.php, CancellationRules.php, Balance.php, WorkingDays.php, AdminRecordRules.php, Hooks.php
    Overtime/RequestRules.php, Hooks.php        EarlyLeave/RequestRules.php
    Schedule/SwapRules.php, Hooks.php, ConfigRules.php, ConfigHooks.php, TeamOrder.php (Team Schedule order)
    Achievements/ManualGrant.php      manual grants, progress cells
    Employees/EmployeeRules.php       add / update employee checks
    Employees/ProfileSummary.php      profile initials, today's result, 30-day stats
    Settings/RolePermissions.php      role → permission matrix (off = removed, never stored false)
    Settings/NotificationSettings.php retention, VAPID subject, policy
    Notifications/PushEndpoint.php    which Web Push endpoints may be contacted (public HTTPS only; SSRF guard)
    Settings/Navigation.php, Moments.php, SmartNudges.php, EngagementHooks.php   engagement settings
    Settings/FeatureSettings.php      Feature Configuration values (defaults, ranges, confirmations)
    Settings/Options.php, OverviewHooks.php  every stored setting (label, page, default); Settings Overview + export
    Payroll/PayCalculator.php, PayRules.php, Hooks.php  a month's pay from attendance and adjustments; salary, rules and adjustment forms
    Payroll/PayslipPdf.php            a closed month's payslip as a PDF
    Pdf/Document.php, TrueTypeFont.php, ArabicText.php  small PDF writer: embedded font subset, Arabic joining and right-to-left order
    Requests/Hub.php, Hooks.php        presentation rules of the admin request pages
    Approvals/Workflows.php, StateMachine.php, Hooks.php  approval modes per workflow, request/step states
    Locations/LocationRules.php, Hooks.php  work location checks (coordinates, radius, seats, default)
    Presence/QrCode.php, Hooks.php         kiosk QR payload, parsing, freshness and signature
    Organization/OrgRules.php, Hooks.php   department and team checks and messages
    Polls/PollRules.php, Hooks.php         poll state, audience, voting, results visibility, percentages
    Pwa/Manifest.php, Hooks.php            installable app: manifest and service-worker values (phones rely on them)
  includes/trait-*.php            legacy code; shrinks as modules move out
    shared building blocks (split out of trait-core.php in 3.31.32, code unchanged):
    trait-core.php            request caches, current employee, render_template(), plugin_url(), audit(), dates, schedule saves
    trait-schema.php          ensure_*_schema(), ews_schema_target(), maybe_upgrade_schema()
    trait-work-time.php       shifts, working hours, grace, Sign In window, working days, holidays, attendance_day()
    trait-schedule-types.php  schedule types (Office, WFH, …) and what each requires
    trait-breaks.php          breaks (settings, sessions, reminders)
    trait-face.php            Face Sign In templates and tokens
    trait-permissions.php     capabilities, roles, can()
    trait-profile-account.php avatar, password, profile photo, profile settings
  templates/app/*.php             page HTML; receives prepared variables only
  templates/admin/*.php           wp-admin page HTML (same rule)
  assets/js/*.js, assets/css/*    page scripts/styles, enqueued by the view that needs them
tests/
  unit/                           PHPUnit for src/ (fast, no WordPress)
  e2e_*.py (+ e2e_support.py), smoke.py, browser_smoke.js   run against a real WordPress + MySQL in CI
```

## Moving a module (checklist)

1. **Pin the behaviour first.** Write e2e tests for the module against the *current* code and
   make them pass (see `tests/e2e_attendance.py`). Commit them before touching the code.
2. **Extract the rules** into `src/<Module>/` as plain PHP classes (no `$wpdb`, no `get_option`):
   the trait gathers facts from WordPress, the class decides. Add PHPUnit tests.
3. **Move the HTML** to `templates/<area>/<page>.php` and render it with
   `$this->render_template('<area>/<page>', compact(...))`. Compute every value the template
   needs in the PHP method; templates must not call `$this->…` (PHPStan enforces private access).
4. **Move inline `<script>`/`<style>`** to `assets/`. Register scripts in
   `enqueue_frontend_assets()`, enqueue them from the view, translate JS strings with
   `wp.i18n` and an explicit `'workforce-one'` domain, then regenerate the `.pot`/`.json`.
5. **Move the module's hooks** to `src/<Module>/Hooks.php`. Keep every action name, option name
   and table name – forms, links, cron events and stored data depend on them.
6. Run everything (`composer test`, PHPStan, the e2e/smoke/browser tests) – CI does the same.

## Rules that stay true
- Stored values (statuses such as `Approved`, `Late Arrival`) stay English; translate on display.
- Schema changes go through `ensure_*_schema()` + a bump of `ews_schema_target()` (both in `includes/trait-schema.php`).
- No external CDNs; vendor libraries live in `assets/vendor/` with their licences.
- Every setting (WordPress option) is listed in `src/Settings/Options.php` with its default. Read it
  with `$this->option('ews_…')`, which falls back to that default; do not pass a default to
  `get_option()` (null, meaning "is it saved?", is the exception). `tests/unit/OptionsTest.php`
  fails on an unlisted option or a different default. A new setting also appears on
  wp-admin → Settings Overview and in its export; mark secrets `secret` and employee data `data`.

## Reading form input

WordPress adds backslashes to `$_POST`, `$_GET` and `$_REQUEST` ("magic quotes"). Always
`wp_unslash()` request values before sanitising them, e.g.
`sanitize_text_field(wp_unslash($_POST['reason'] ?? ''))`; otherwise "Ahmed's" is stored as
"Ahmed\'s".

## Notifications (3.31.45)

Every notification goes through `notify($user_id, $category, $title, $message, $opt)` in
`includes/trait-notifications.php`: it saves the in-app notification and queues the push, each as the
Notification Policy for the category allows. Queued pushes are handed to WP-Cron at the end of the
request (`push_flush()` → event `ews_push_deliver` → `push_deliver()`), so a slow or dead push service
never holds up the action, and a push failure cannot break it. The outcome of the last batch is in
`ews_push_last_delivery` (shown on Notification Settings). Only the jobs that are already background
work (Smart Nudges cron) and the admin test buttons call `push_custom_notification()` directly.
Before each delivery the endpoint is checked again (`push_delivery_target()`), and cURL connects only
to the checked address (`CURLOPT_RESOLVE`), over HTTPS, without redirects, within 5 seconds.

## Attendance service (3.31.46)

```
Web form (admin-post) ─ time_event() / presence_qr_signin() ─┐
                        break_start() / break_resume()       ├─▶ AttendanceService ─▶ SignInRules, BreakRules,
REST (later, Phase 1) ─ AttendanceController ────────────────┘        │                LocationAssessment, Lateness
                                                                      ▼
                                                         AttendanceContext (plugin helpers, $wpdb)
```
The handler reads the request into an `AttendanceCommand` (location, Face token result, QR kiosk),
calls the service, and turns the `AttendanceResult` code into its own output: the Web shows the
messages it always showed; the API will use `Api\ErrorMap` + `Api\Response`. The order of the
checks is behaviour; `tests/e2e_attendance_parity.py` records a normalised trace of every Web
attendance outcome so the two versions of the code can be compared.
Writes for one employee run under `GET_LOCK` (MySQL / MariaDB) and are verified after the insert
(the first row wins) so concurrent duplicates cannot both be recorded on any database.

