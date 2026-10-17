# Task: attendance correction requests

Owner request (2026-10-09). An employee who forgot to sign out (or in), or whose time is wrong,
asks for a correction; the manager approves; nothing in the original records is ever changed.

## Step 1 — HTML mockups first, then STOP

**No PHP, no schema change before the owner approves the mockups.**

1. Build static HTML mockups of every screen below in `docs/mockups/attendance-corrections/`, one
   file per screen plus an `index.html` that links them.
2. They must look like the final product: use the plugin's real stylesheets (`workforce-one/assets/css/`,
   the app frame `assets/css/app-shell.css` and the matching `-rtl.css` files), real icons, real
   fonts (`assets/fonts/`), the app's colours and spacing. Realistic Arabic sample data (names,
   dates, times); every screen in **Arabic (RTL)** and **English**; app screens at phone width
   (390 px), wp-admin screens at desktop width. Show the empty, error and success states.
3. Commit, push, and give the owner a way to open them (publish them as a page or attach the
   files), then **wait for approval or changes**. Apply the owner's changes to the mockups and ask
   again until approved. Only then start Step 2.

Screens:
- Employee, app: day history with the "Request correction" action on a day; the request form (type,
  correct time, reason, optional photo); "my requests" list with status; the end-of-day push and
  what it opens.
- Manager, app: the request card in Approvals with the **evidence panel**; approve / reject with a
  note.
- HR, wp-admin: the requests list (filters: status, type, department, employee, month); a direct
  correction by HR (reason required); the settings page; the corrections report.
- The day as shown afterwards in Attendance / reports (original + correction, who approved).

## Step 2 — build

### Types

Forgot Sign Out; forgot Sign In; wrong time (app failed, no network); whole day missing (was on a
mission or at a customer). Each type can be switched off.

### Settings (all configurable, owner decision)

wp-admin → Workforce One → a new **Attendance corrections** settings section, listed on Settings
Overview:
- feature on/off and which types are enabled;
- deadline: a request must be made within **N days** of the day (default 7);
- monthly limit per employee (default 3) and what happens above it: refused, or allowed but sent to
  HR / a second level;
- a request that moves Sign In **earlier** (removes lateness) needs the second level: on/off;
- photo attachment: off / optional / required, per type;
- end-of-day reminder ("You did not sign out today. Request a correction?"): on/off and time.

### Approval

A new workflow key `attendance_correction` in `src/Approvals/Workflows.php` (`IN_USE`), handled
for `NONE`, `LEVEL_1`, `LEVEL_2` like Vacation and Overtime, with its "No approval" meaning written
in `NONE_MEANS`. Shows on the Requests Hub and in the app's Approvals.

### Evidence panel (what the system knows about that day)

Shown to the approver, read-only: the day's events with times and location / integrity results,
the last device location seen that day and its time, presence-check results, kiosk or QR events,
the schedule and shift of the day, and the employee's correction count this month. The approver
decides knowing the facts, not on trust.

### Never change the original

- The original `ews_time_logs` rows are **never updated or deleted** by a correction.
- An approved correction is recorded as its own event linked to the request, the approver, the time
  and, when it replaces a time, the row it supersedes (add what is needed, e.g. a `source` column
  `app|kiosk|qr|auto|import|correction|admin` and a `corrects_id`; your design).
- One function returns the **effective day** (originals with superseded rows ignored and corrections
  applied), and **every reader uses it**. There are ~13 files that read `ews_time_logs` today
  (`grep -rln "ews_time_logs\|->time_logs" workforce-one`): attendance rules, reports, insights,
  payroll, the attendance grid, the dashboard, exports. Missing one means two answers for the same
  day; list them in the PR and test them.
- HR's direct correction in wp-admin uses the same path with a mandatory reason.
- Audit Log: request, decision, direct correction (who, what, why).

### Payroll

- Days in Payroll's "needs review" list ("No Sign Out") are resolved by corrections.
- A **closed** month (`trait-payroll.php`, closed runs keep their figures) is never reopened by a
  correction: the difference is carried as an adjustment into the next open month, shown with its
  reason on that payslip.

### Reports

Corrections per employee / department / month, by type and outcome. Frequent corrections are a
pattern worth seeing (links to the Fraud detection idea in `docs/ideas.md`).

### API

Add the endpoints the Flutter app will need (list days, create request, list my requests, approve /
reject) to the native API (`src/Api/Routes.php`), token-authenticated, same rules as the Web.

### Tests

New `tests/e2e_corrections.py` in CI: each type end to end; deadline and monthly limit (both
outcomes of "above the limit"); the second level for an earlier Sign In; original rows untouched
after approval; every report, payroll and the attendance grid read the corrected day; a closed
month gets a next-month adjustment; rejection changes nothing; permissions (an employee cannot
approve their own, a manager only their people). Unit tests for the effective-day function.

### Constraints

PHP 7.4+, PHPStan and phpcs clean, every UI text translated (Arabic), CHANGELOG entry and version
bump, database version bump with an upgrade step for existing sites.
