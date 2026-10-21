# Modules (modular monolith)

Workforce One is one WordPress plugin, and it should stay one: one install, one database, one
release. The aim is a *modular* monolith: the code is split into modules that each own their tables
and offer a small set of functions to the others, so a change in one module cannot quietly break
another, and a module can later be moved, replaced or switched off on its own.

## Where we are (step 1, October 2026)

The [Notifications inbox extraction](notification-inbox.md) is the first database-backed
application slice moved behind an explicit service/store contract. The legacy ratchet below
still measures trait dependencies; an additional CI check guards this extracted slice.

- **Pure rules are already split by module** in `src/<Module>/` (24 folders, about 9,500 lines). They
  make no database calls and have unit tests.
- **The code that runs the plugin is not split yet.** `includes/trait-*.php` holds 62 traits (about
  17,000 lines), all used by one class (`EWS_Manager_V31_1` in `employee-schedule-manager.php`). They
  share one object, so any trait can call any other trait's private functions or query any table.
- **The map:** [`modules.json`](modules.json) puts every trait in one of 16 modules and gives every
  table one owning module.
- **The check:** `tests/check_modules.py` (in CI, job "PHPStan + PHP compatibility") counts, for each
  module:
  - the tables of another module it uses directly;
  - the functions of another module it calls.

  Calls into `platform` (settings, permissions, audit, templates) are the shared kernel, and calls
  to a module's public functions (its `api` in `modules.json`) are the way modules should talk:
  both are allowed. The counts are kept in [`module-baseline.json`](module-baseline.json) and may only go
  down: the check fails when a pair of modules crosses more than before or a new pair appears.

Starting point (October 2026, after 3.31.95): 295 uses of another module's table and 623 calls to another module's
functions. `python3 tests/check_modules.py --report out.json` lists every one, by pair and by
table / function.

## The modules

| Module | Owns | Traits |
|---|---|---|
| platform | audit_log | core, schema, permissions, features, settings-overview, settings-pages, private-files, privacy |
| shell | (none) | app-layout, appearance, admin, admin-nav, admin-dashboard, dashboard-view, frontend |
| people | employees, departments, teams, team_members, employee_relationships, org_moves | employees, employee-admin, employee-profile, people-view, my-profile, profile-account, departments, teams, org-chart, org-moves, org-import |
| schedule | schedule, schedule_swaps, company_calendar | work-time, schedule-config, schedule-types, schedule-view, swap, attendance-grid, office-minimum |
| locations | locations, employee_locations_v321, employee_branches, kiosks, presence_verifications | locations, branches, presence |
| attendance | time_logs, break_sessions, face_profiles, auto_attendance_rules, attendance_corrections, signin_attempts | attendance, auto-attendance, breaks, face, face-reset, signin-evidence, time-report, corrections, attendance-insights |
| leave | leave_requests, leave_balances, leave_types, leave_schedule_snapshots, vacation_requests | leave, leave-admin, leave-view |
| overtime | overtime_requests, early_leave_requests | overtime |
| approvals | approval_workflows, approval_workflow_steps, approval_requests, approval_steps | approvals, admin-requests |
| notifications | notifications, push_subscriptions | notifications |
| tasks | tasks, task_items, task_comments, task_activity | tasks, task-extras |
| payroll | pay_rates, pay_adjustments, payroll_runs, payslips | payroll |
| daily_workers | dw_* (10 tables) | daily-workers |
| engagement | achievements, employee_achievements, kudos, polls, poll_options, poll_votes | achievements, achievements-admin, recognition, engagement-admin, polls |
| reports | (none: reads the others) | reports |
| app | api_tokens, api_devices, api_rate_limits, api_idempotency | pwa, offline-screen, api-auth |

`shell` and `reports` own no tables: the shell puts pages together, and reports read everyone's data.
Both will go through the other modules' functions rather than their tables.

## Next steps

1. **People owns the employees table.** Started in 3.31.96: People's functions (an employee by id
   or user, the name, the user, in a department, the active list, ids, users and counts) replaced 62
   direct uses, 295 → 239 table crossings. What is left are joins that put an employee's name next to
   rows in lists and reports (time report, corrections, the dashboard's Audit labels, tasks); they
   need a shared read for names that keeps one query per page, the next part of this step.
2. **Move modules out of the shared object, least tied first:** notifications, polls (engagement),
   daily workers, the installed app, then leave, overtime and attendance, last payroll and reports.
   Each becomes a class in `src/<Module>/` with its own hook registration and public functions; the
   other modules call those functions only.
3. **Events instead of direct calls:** a module announces what happened (`wfo/leave/approved`), and
   notifications and the Audit Log listen, instead of being called by every module.
4. **Each module creates its own tables** (today `trait-schema.php` creates all of them).

Each step is one release, with no change in behaviour, protected by the existing e2e and unit tests;
after each one `--update` lowers the baseline.

## Limits of the check

- It reads `includes/trait-*.php` only: the templates (which can call `$this->…`) and `src/` are not
  counted yet.
- It finds tables through the plugin's own ways of naming them (`$this->employees`, helpers like
  `cx_table()`, `ews_*` right after the table prefix); a table name built in another way is missed.
- A function defined in two modules is not counted.
