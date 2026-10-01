# Changelog

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
