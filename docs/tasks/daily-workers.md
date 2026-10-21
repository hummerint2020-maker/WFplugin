# Task: daily workers (عمالة يومية)

Owner request (2026-10-09). Contractors, farms and factories employ day labourers who have no email,
often no smartphone, change every few days and are paid in cash. Today Workforce One has no place for
them. Goal: register a worker in a minute, record attendance on site, pay daily or weekly, and show
the labour cost of every project.

## Step 1 — HTML mockups first, then STOP

**No PHP, no schema change before the owner approves the mockups.**

1. Build static HTML mockups of every screen below in `docs/mockups/daily-workers/`, one file per
   screen plus an `index.html` that links them.
2. They must look like the final product: the plugin's real stylesheets (`workforce-one/assets/css/`,
   `app-shell.css` and the `-rtl.css` files), real icons and fonts, the app's colours and spacing.
   Realistic Egyptian sample data (worker names, trades, sites, EGP amounts); every screen in
   **Arabic (RTL)** and **English**; foreman screens at phone width (390 px), wp-admin screens at
   desktop width; empty, error and success states. The payout sheet as it will print (A4, Arabic).
3. Commit, push, give the owner a way to open them (publish as a page or attach the files), then
   **wait for approval or changes**; iterate until approved. Only then start Step 2.

Screens:
- Foreman, app: my sites; "Today at site X": worker list with present / half day / absent / extra
  hours, the group photo, the confirmation; quick-add worker; move a worker to another site.
- Foreman or cashier, app: the payout screen (what each worker is owed, advances, pay, upload the
  signed sheet photo).
- HR / site manager, wp-admin: workers list (search, trade, subcontractor, site, status, rating);
  worker profile (with the masked national ID); settings; reports (labour cost per project / site /
  subcontractor / trade, attendance per site and day).
- The printed **payout sheet** (كشف اليومية): site, period, rows with name, days, rate, amount,
  advance, net, and an empty signature / thumbprint column.

## Step 2 — build

### A worker is an employee of type "daily"

`ews_employees.wp_user_id` is already nullable: a worker is an employee row with a new type
(`staff` | `daily`, default `staff` for every existing row) and **no WordPress user**. `domain_name`
is unique and NOT NULL, so generate one (e.g. `dw-<id>`). Daily workers are left out of everything
built for staff unless a screen says otherwise: Sign In rules, absence and lateness, monthly
Payroll, leave balances, achievements, push, staff reports and counts. Check every place that lists
employees (pick lists, Attendance, Team Schedule, reports, Payroll, exports) and test it.

### Fields

Name, mobile, trade (list managed by the admin: carpenter, steel fixer, helper …), daily rate,
optional hourly rate for extra hours, photo (for the future gate tablet with face recognition),
subcontractor (optional, managed list), notes, rating 1–5, status (active / do not rehire), and the
**national ID**.

### National ID (owner decision: store it)

- Egyptian national ID: 14 digits, validated (format, century digit, birth date, governorate code)
  with a clear message; other nationalities: free text.
- **Encrypted at rest** with authenticated encryption (libsodium `sodium_crypto_secretbox`, or
  AES-256-GCM), key kept outside the database like the face templates' key
  (`face_biometric_key()` in `includes/trait-face.php`; that code uses AES-256-CBC without a MAC,
  do better here). Never in plain text in the database, logs, the Audit Log or default exports.
- Shown **masked** (`2900••••••1234`); full number only to a new permission "View national IDs",
  and every reveal is written to the Audit Log (who, which worker, when).
- A search by the full number matches through a keyed hash (HMAC) column, never by decrypting
  every row.
- Deleted with the worker; included in uninstall cleanup.

### Who records attendance (owner decision: configurable)

Setting per company, overridable per site: **foreman only**, **worker self-service** (a worker with a
smartphone gets a simple login by mobile + PIN, no email), or **both**.
- Foreman: a new role / permission "Foreman", assigned to sites. "Today at site X" marks each worker
  present / half day / absent and extra hours. Saving requires the foreman's GPS inside the site
  (same integrity rules as Sign In, `src/Attendance/LocationAssessment.php`) and a **group photo**
  taken with the camera (not the gallery), stored outside the public uploads like task files.
- Corrections to a past day by the foreman follow the attendance-corrections rules (original kept,
  reason, approval) where that feature exists.

### Sites and projects

A site is a work location (`ews_locations`) flagged as a project site, with an optional project
name and dates. A worker can work at different sites on different days; moving is one action and
keeps the history.

### Pay and payout

- Amount per day: full day = daily rate, half day = rate ÷ 2, extra hours × hourly rate.
- Period: daily or weekly (setting, per site); advances recorded and deducted from the next payout.
- **Payout sheet PDF** (Arabic, with the existing PDF engine `src/Pdf/`): printed, signed or
  thumb-printed by each worker when paid; the cashier marks the period paid and uploads a photo of
  the signed sheet. A paid period is locked.
- Amounts in the Audit Log only as "payout recorded", never the figures (same rule as salaries).

### Reports

Labour cost per project / site / subcontractor / trade / period ("Fifth Settlement project: EGP
284,000 on labour so far"), days and workers per site and day, unpaid balances. Excel and PDF.

### Social insurance

Egypt has rules for irregular workers. Do **not** calculate insurance now; provide the report the
accountant needs (worker, national ID — only for users allowed to see it —, days, amounts per month).

### API

Endpoints for the Flutter app later: foreman's sites, day sheet read / save with photo, workers
quick-add, payouts; token-authenticated, same rules as the Web.

### Tests

New `tests/e2e_daily_workers.py` in CI: quick-add without a WordPress user; national ID validation,
encryption at rest (the raw column never contains the digits), masking, reveal permission and its
audit entry, search by full number; foreman can only record his sites and only from inside the
site; camera photo required; each recording mode (foreman / self / both); pay amounts for full, half
and extra hours; advances; payout lock; daily workers absent from staff pick lists, Attendance,
Payroll and staff reports; cost report totals equal the sum of the days.

### Constraints

PHP 7.4+, PHPStan and phpcs clean, every UI text translated (Arabic), CHANGELOG entry and version
bump, database version bump with an upgrade step, uninstall removes the new tables and files.
