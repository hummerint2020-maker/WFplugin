# Task: run many companies on one WordPress (Multisite)

Owner request (Oct 2026): host several small companies on **one** WordPress install, one
**subsite per company** (`https://acme.<base domain>`), instead of one WordPress per company.
Large or regulated customers keep their own install (Custom edition); nothing here may change how a
single-site install behaves.

Do this **after** the dbDelta fix (CREATE TABLE statements in dbDelta format, missing indexes added
by an upgrade step, CI check that a second dbDelta run changes nothing), because every new subsite
runs the full schema install.

## Why Multisite and not a company_id column

The plugin builds every table name from `$wpdb->prefix`. On Multisite that prefix is per subsite
(`wp_2_`, `wp_3_` …), so each company automatically gets its own `ews_*` tables, options, uploads
folder and cron. A `company_id` column on ~49 tables and every query would be a large rewrite where
one missed filter leaks one company's data to another; not now.

## What was checked in the code (3.31.71)

- Multisite is only handled in `uninstall.php` (switch_to_blog loop). Nothing else is network-aware.
- `register_activation_hook` → `EWS_Manager_V31_1::activate()` creates the tables for the **current**
  site only. On Network Activate, other subsites get their tables later from
  `maybe_upgrade_schema()` (trait-schema.php) on their first page load, which then calls
  `self::activate()`. It works, but the first visitor of a new company pays for the whole schema
  install, and a request can race another one.
- Per-site already, by construction: options (`get_option`), `$wpdb->prefix` tables, the attendance
  lock name (`attendance_lock_name()` hashes DB_NAME + prefix), `wp_upload_dir()` (task files go to
  `uploads/sites/N/workforce-one-tasks`), the API site id (`Tokens::siteId(home_url('/'))`), VAPID
  keys (options).
- Shared across the network: `$wpdb->users` / `$wpdb->usermeta`. `current_employee()` looks the user
  up in the **current** site's `ews_employees`, so a user of company A who opens company B's site
  should find no employee. This must be proven by tests, not assumed.
- Queries that JOIN `$wpdb->users` (Audit Log in trait-admin-dashboard.php, Tasks in trait-tasks.php
  and trait-task-extras.php) join by ID only; they stay correct, but check that the Audit Log user
  filter only lists users of this site.
- Task files rely on `.htaccess` / `web.config` to block direct URLs. nginx (CloudPanel) ignores both.
  File names are 32 random characters so they are not guessable, but add the nginx rule below to the
  docs as defense in depth.

## Requirements

1. **New subsite gets its tables at creation**, not on its first visit: hook `wp_initialize_site`
   (WP 5.1+) and, when the plugin is network-active, switch to the new site and run the schema
   install + `prime_options()`. Network Activate must also install every existing subsite (loop over
   `get_sites()` in batches, switch_to_blog / restore_current_blog). Keep `maybe_upgrade_schema()` as
   the safety net.
2. **Deleting a subsite** (`wp_uninitialize_site`) drops that site's `ews_*` tables and options, the
   same way `uninstall.php` does for one site. Never touch other sites.
3. **Users and companies**: an employee belongs to exactly one company site.
   - The app, admin-post.php actions and the REST API refuse a logged-in user who has no employee
     row on the current site (clear message, no data, no PHP notices).
   - wp-admin → Employees: linking a WordPress user that belongs to another site is refused, unless
     a super admin does it on purpose (then add the user to this site).
   - A company administrator (site admin, not super admin) must not see or pick users of other
     companies anywhere (pick lists, Audit Log filter, approvals, tasks).
4. **API / mobile app**: `GET /meta` and tokens stay per subsite (site id from `home_url`). A token
   issued on company A is rejected on company B (TOKEN_INVALID). Login on B with a user of A fails
   like a wrong password (no hint that the user exists elsewhere).
5. **PWA**: manifest, service worker scope and start URL point to the subsite, so two companies
   installed on one phone are two separate apps.
6. **Cron**: with `DISABLE_WP_CRON`, document the system cron line that runs due events for every
   subsite, e.g. `wp site list --field=url | xargs -I{} wp cron event run --due-now --url={}`.
   Add a tools script if it helps (`tools/multisite_cron.sh`).
7. **Tools**: `tools/new_company.sh --multisite --network-path /home/<user>/htdocs/<domain>` creates
   a subsite with `wp site create`, its first admin user, the `/app/` page, timezone and
   `blog_public=0`, and saves the logins the same way as today. Keep the current single-site mode as
   the default.
8. **Load test**: `tools/load_signin.py` gets `--url` per subsite already; check `seed`, `count` and
   `cleanup` with `WP_CLI="wp --url=https://acme.<base>"` and document it. Run one burst on two
   subsites at once and record the numbers in the CHANGELOG.

## Tests (CI)

Add a job **WordPress Multisite e2e** next to the current e2e job: `wp core multisite-install
--subdomains=0` (subdirectory network works on 127.0.0.1), network-activate the plugin, create two
subsites `/a/` and `/b/`, seed one employee in each. New `tests/e2e_multisite.py`:

- Both subsites have their `ews_*` tables right after `wp site create` (before any page load).
- Sign In on A records in A's `ews_time_logs` only; B's table is untouched.
- Employee of A logged in, opening B's app / admin-post / REST: refused, no data from either site.
- A's API token on B: 401 TOKEN_INVALID. A's credentials on B's `/auth/login`: same error as a wrong
  password.
- B's admin: Employees pick list, Audit Log filter and task assignee list contain no user of A.
- Task file uploaded on A cannot be downloaded through B's `ews_task_file`.
- Deleting subsite B drops B's tables and leaves A intact.
- Re-run the existing single-site e2e suite unchanged: it must still pass.

## Docs

- ARCHITECTURE.md: a short "Multisite" section (what is per site, what is shared, the hooks).
- tools/README.md: the multisite options of new_company.sh, the cron line, and this nginx rule for
  task files:
  `location ~* /uploads/(sites/[0-9]+/)?workforce-one-tasks/ { deny all; }`
- CHANGELOG.md entry with the version bump.

## Out of scope

A Network Admin dashboard across companies, billing, moving a company between sites or servers, and
the `company_id` multi-tenant model.

## Constraints

Same as the rest of the project: PHP 7.4+ compatible, PHPStan and phpcs clean, every new UI text
translated (tests/check_translations.py), no change to single-site behaviour or data.
