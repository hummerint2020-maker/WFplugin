# Workforce One – code structure and refactoring guide

The plugin started as one class (`EWS_Manager_V31_1`) built from 19 traits in
`workforce-one/includes/`, with HTML, CSS and JavaScript inside PHP strings. It is being
moved, **one module at a time and without behaviour changes**, to the structure below.
Done so far: Sign In / Sign Out (reference module), Leave (`includes/trait-leave.php`) and Overtime + Early Leave (`includes/trait-overtime.php`) Schedule Swaps (`includes/trait-swap.php`), and the wp-admin request pages: Requests Hub (`includes/trait-admin-requests.php`) and Face Reset Requests (`includes/trait-face-reset.php`). The wp-admin Requests page reuses the module operations instead of its own copies.

```
workforce-one/
  employee-schedule-manager.php   bootstrap: autoloader, traits, hooks
  src/                            namespaced classes (WorkforceOne\…), PSR-4, src/autoload.php
    Support/Geo.php
    Attendance/SignInRules.php         business rules – pure PHP, no WordPress
    Attendance/LocationAssessment.php  business rules – pure PHP, no WordPress
    Attendance/Hooks.php               the module's add_action() calls
    Leave/RequestRules.php, CancellationRules.php, Balance.php, WorkingDays.php, Hooks.php
    Overtime/RequestRules.php, Hooks.php        EarlyLeave/RequestRules.php
    Schedule/SwapRules.php, Hooks.php
    Requests/Hub.php, Hooks.php        presentation rules of the admin request pages
  includes/trait-*.php            legacy code; shrinks as modules move out
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
- Schema changes go through `ensure_*_schema()` + a bump of `ews_schema_target()`.
- No external CDNs; vendor libraries live in `assets/vendor/` with their licences.
