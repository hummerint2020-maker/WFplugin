# Changelog

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
