# Workforce One as a multi-tenant SaaS

Owner request, 2026-11-10: a plan to run the plugin as a SaaS with many companies (tenants), and a first review of what stands in the way. Nothing here is implemented. Product decisions are the owner's (§4).

## 1. Recommendation: one WordPress Multisite, one site per company

| | Approach | Isolation | Work | Verdict |
|---|---|---|---|---|
| A | **Multisite: a site per company** | Its own tables (`$wpdb->prefix`), options, roles, cron | Medium | Recommended |
| B | A `tenant_id` column on every table | Weak: one query without the condition leaks payroll | Rewrite 62 tables and every query | No |
| C | A separate WordPress per company | Strongest | Little code, heavy operations | For large customers only |

Why A fits this code: every table is named with `$wpdb->prefix` (62 tables), every setting is a per-site option (about 150), roles and capabilities are per site, activation and uninstall already walk the network's sites, and a new site installs its schema on its first request (`maybe_upgrade_schema()` calls `activate()` when the employees table is missing, `includes/trait-schema.php:359`).

## 2. Phases

1. **Safe on Multisite** (this document, §3): fix what is shared between sites; an e2e suite with two companies on one network proving neither sees the other (pages, API, files, kiosk, notifications, cron).
2. **The platform owner's console** (Network Admin): create a company (site, administrator, defaults), suspend / resume / delete with an export, the list of companies (employees, last activity, version).
3. **Plans and limits**: a plan per company (employee limit, features). Every feature already has a switch; the platform owner, not the company's administrator, holds the ones the plan decides. Subscription states: trial, active, past due, suspended (read only).
4. **Sign-up and payment**: self sign-up with a trial; a payment gateway.
5. **Domains and the app**: `company.app.com` (and a custom domain on request); the PWA and the native app ask for the company first; logo and colours per company (Appearance already is per site).
6. **Operations**: a real cron for many sites, backup and restore of one company, schema upgrades rolled out site by site, System health across companies.

## 3. Phase 1 review: what is shared between sites today

A first pass over the code (2026-11-10, main at 3.31.145), by reading, not by running a network. CONFIRMED = traced in the code; PLAUSIBLE = needs the two-company e2e to settle.

### Must fix before a second company exists

1. **CONFIRMED · one company's cleanup deletes another's Face photos.** With `WFO_PRIVATE_DIR` set (one constant for the whole network) every site stores private files in the same folders (`includes/trait-private-files.php:20-43`). The daily evidence cleanup removes every `.jpg`/`.webp` in `workforce-one-face-photos` older than the site's own retention, whether or not the file is this site's (`includes/trait-signin-evidence.php:248-252`, called from `src/Attendance/EvidenceService.php:132`). A company keeping photos 7 days deletes the photos of one keeping them 90. Fix: a folder per site (`{base}/sites/{blog_id}/…`) for every private folder; the main site keeps today's path.
2. **CONFIRMED · uninstalling (or deleting) one site removes every company's private files and user settings.** `uninstall.php:24-32` empties the shared `WFO_PRIVATE_DIR` folders, and `uninstall.php:74` deletes every `ews_%` user meta of the whole network, once per site. Same fix as 1 for the files; user meta by site (4).
3. **CONFIRMED · an administrator of one company can link an employee to any user of the network.** Saving an employee takes `wp_user_id` from the form (`includes/trait-employee-admin.php:44`) and nothing checks the user is a member of this site. The picker lists this site's users only, but a posted id is accepted. That shows another company's user (name, login) in this company and makes them an employee here. Fix: `is_user_member_of_blog()` on every place a user id is saved (employees, approval relationships `includes/trait-employees.php:61`, approvers `includes/trait-approvals.php:662`).
4. **CONFIRMED · nine user-meta keys are not per site**, so a person with an account in two companies carries state across: `ews_face_recent` and `ews_face_verify_failures` (Face Sign In lock-out and recent matches, `includes/trait-frontend.php:38-84`), `ews_report_views`, `ews_smart_nudge_dismissed`, `ews_smart_nudge_pushed`, `ews_occasion_moment`, `ews_occasion_hidden`, `ews_occasion_hidden_birthday`, `ews_health_dismissed`. Only `ews_api_sessions_before` is prefixed by site (`includes/trait-api-auth.php:753`). Fix: `get_user_option()` / `update_user_option()` (site-prefixed) with a one-time copy. Whether one person may be in two companies at all is the owner's (§4.6).
5. **CONFIRMED · the Nginx rule the plugin tells the administrator to add does not cover a network's uploads.** It matches `/wp-content/uploads/workforce-one-…/` (`includes/trait-private-files.php:152`); on Multisite the folders are under `/wp-content/uploads/sites/{id}/`. On Nginx without `WFO_PRIVATE_DIR`, private files of every site but the main one stay open by direct link. Fix: the rule with `(sites/\d+/)?`; the platform should set `WFO_PRIVATE_DIR` anyway (after 1).

### To settle with the two-company e2e

6. **PLAUSIBLE · a user of company B signed in on company A's site.** WordPress accepts any network user's password on any site. The app looks the employee up in this site's table (`current_employee()`), and capabilities are per site, so they should get "no employee record" and nothing else. 78 `admin_post_` actions and the REST API need checking for ones that test only `is_user_logged_in()`. The API login (`includes/trait-api-auth.php:339`) authenticates network-wide: confirm it refuses a user with no employee here, with the same answer and timing as a wrong password.
7. **PLAUSIBLE · memory kept between sites in one request.** About 25 `static` caches are not keyed by site (`includes/trait-core.php:8-23`: current employee, shifts, working hours, company leave; `includes/trait-notifications.php:15-17`: the VAPID key and the push queue). They matter only where one request serves two sites (`switch_to_blog`, the platform console, a network cron). Phase 2 code must not render one company's data after another's without clearing them; the stores in `src/` are already one per prefix.
8. **PLAUSIBLE · names and logins from the shared users table.** Tasks, task comments and the Audit Log join `wp_users` by ids already in this site's rows (`includes/trait-tasks.php:284`, `includes/trait-admin-dashboard.php:114-125`). Safe while 3 holds.
9. **PLAUSIBLE · System health** is super-admin only on a network (`includes/trait-system-health.php:40`), but its collector and its email are per site. Phase 6 decides one view for the platform.

### Not a leak, but missing for a SaaS

- **No way to create accounts.** The plugin never creates WordPress users (no `wp_insert_user` anywhere); the company's administrator links employees to users made in wp-admin. On a network, site administrators cannot create users by default. A company needs "add employee with an account" (or invitations) inside the plugin.
- **Network activation** creates the main site's data only; other sites install on their first request (fine), but their cron events start then too. A network cron runner is needed (phase 6).
- **Nothing about plans, limits, billing or sign-up** exists.
- **File keys** are 32 random characters and are read through this site's rows, so sharing a folder does not show one company's file to another; the damage is deletion (1, 2).
- **Kiosk keys, VAPID keys, the app's `/app/` page, the service worker, transients and options** are per site already.

## 4. Open questions for the owner

1. Isolation: Multisite (A), or do some customers need their own database (C)?
2. Addresses: `company.app.com` or `app.com/company`? Custom domains?
3. Pricing: per employee, fixed plans, or by feature? When a subscription ends: read only, or closed?
4. Payment: which gateway, or manual at first?
5. Sign-up: self-service with a trial, or the platform owner creates each company?
6. May one person (one email) have an account in two companies?
7. Today's customers on their own sites: stay as they are, or move to the platform?
8. Where are the servers and the data (Face photos and payroll are sensitive)?
9. Who creates employee accounts in a company, and how do employees get their password (invitation email, a code from HR)?

## 5. Next step proposed

Phase 1 as its own release, off by nature (a single site sees no change): per-site private folders (1, 2), the membership check (3), per-site user options (4), the Nginx rule (5), then the two-company e2e for 6 to 9. Needs only question 1 answered.

## 6. Owner decision (2026-11-10): the current company must not be affected

"مش عايزه ياثر على الشركه اللي انا شغال معاها حاليا". This binds every phase:

- The company running today on its own WordPress stays as it is. Nothing moves it to a network; that would be its own decision later (§4.7).
- Every SaaS change is inert on a single site: the same pages, writes, file paths and user settings as before, proven by the golden comparison and the existing e2e suites on a single site, as for any feature that is off.
- Where a fix changes where something is stored (private folders per site, user settings per site), a single site keeps today's paths and keys; only sites of a network get the new ones.
- Platform features (console, plans, billing, sign-up) exist only on a network and load nothing on a single site.
