# Changelog

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
