# System health (design, awaiting the owner's answers)

Status: designed, not built. Next: the owner answers section 10, then an HTML mockup in `docs/mockups/system-health/`, then the build.

## 1. Goal and non-goals

A collector on the owner's own server that measures this plugin's pages, app views, admin-post actions, REST routes and cron jobs. It keeps small rollups in the site's database, shows them on one wp-admin screen with a ranked "Look at this first" list, and raises alerts past a threshold. Nothing is sent to an outside service (the optional email alert goes through the site's own `wp_mail`, see question 1).

What an on-site collector cannot do, and the screen must say so plainly:

- No browser-side time: server time only, from `REQUEST_TIME_FLOAT` to `shutdown`. Time queued in nginx/PHP-FPM before PHP starts is not included.
- It cannot see an outage: if PHP, the database or the site is down, so is the collector. No uptime alert.
- No server metrics (CPU, disk, MySQL slow log, other sites' load).
- No full attribution between this plugin, the theme and other plugins; plugin queries are told apart from the backtrace.
- Some hard failures leave no trace (FPM kill, OOM, SIGKILL).
- Rare routes have few samples; heavy operations (payroll close, exports) are always sampled.

## 2. What is measured and how

**Entry (only when ON):** `Health\Hooks::register($this)` returns at once unless `health_enabled()` (the option is already primed, no query). When ON: a `shutdown` callback at `PHP_INT_MAX`; an error handler that chains and returns `false`; the `wp_should_handle_php_error` filter (WordPress's fatal handler exits inside its own shutdown function, so the filter is where a fatal is recorded; with `WP_DISABLE_FATAL_ERROR_HANDLER` the shutdown path uses `error_get_last()`); `rest_dispatch_request` to note the matched pattern; start/end callbacks (`PHP_INT_MIN` / `PHP_INT_MAX`) on each plugin cron hook, only when `wp_doing_cron()`; the admin notice and menu item for users with the capability; a reserved 64 KB string freed first in fatal/shutdown so an out-of-memory fatal can still be recorded.

**Timer:** requests from `REQUEST_TIME_FLOAT` to `microtime(true)` at the start of shutdown, before the collector's own work. Each cron job is timed alone; a job with no end marker, or with a plugin E_ERROR/E_WARNING during it, is `failed`. Peak memory in KB at shutdown. HTTP 500+ or a fatal counts as failed.

**Queries:** every request counts `$wpdb->num_queries` (free). Only sampled requests define `SAVEQUERIES` (unless the site set it false: then no detail, and the screen says so), fold `$wpdb->queries` into an in-memory aggregate every 500 queries, and keep per fingerprint (`md5(shape|caller)`, first 16 hex): executions, total ms, max ms. `QueryShape::normalize` turns literals into `?` (strings, numbers, hex, `IN (...)` → `IN (?)`, `LIKE '%…%'`), strips comments, collapses whitespace, replaces the prefix with `{p}`, cuts to 500 characters. The caller is the last backtrace frame in `EWS_Manager_V31_1->` or `WorkforceOne\`. A repeated query (N+1) is the same fingerprint at least `repeat` times (default 25) in one request. At most 10 fingerprints are written per sampled request.

**Sampling:** default 10% (1, 5, 10, 25, 50, 100). Always sampled: payroll close/reopen/export/pdf, report xlsx/download/email, attendance import, org import apply, daily-workers export, every cron job. Unsampled requests still give run count, duration, total queries, memory, status and PHP errors.

**Route name** (stable, no personal data, `Health\Routes::classify`): `admin:ews31-…`, `post:ews_…` (only when an `admin_post_` hook exists), `app:<view>` (known views only, else `app:other`), `rest:METHOD /pattern/{id}` (namespace `workforce-one/v1`, named groups become `{id}`), `cron:<hook>`, `other:front|admin` (only when sampled, question 6). Each value must match `^[a-z0-9_-]{1,60}$`; a failed check falls back to `<area>:other`, so the route count stays bounded. `REQUEST_URI` and query strings are never stored.

**PHP errors from plugin code:** only when `error_reporting()` includes the level and not under `@`; only files under the plugin directory. Stored: level, plugin-relative file, line, message normalized (quoted text → `'?'`, numbers → `N`, 255 chars), fingerprint `md5(level|file|line)`; at most 20 distinct per request, combined in memory.

**Privacy:** no user ids, employee ids, distinct-user counts, IPs, user agents, request bodies, parameter values, cookies, URLs, logins or emails. No finding needs to know who made a request, and hashes of a 3,000-person id space can be reversed.

## 3. Data model

Per site, created by `ensure_health_schema()` in dbDelta format **only when the feature is first switched on**; not in `maybe_upgrade_schema()` and not in `SchemaCheck::TABLES`. UTC in the tables, site time on screen.

- `ews_health_hourly`: `hour`, `route`, `slot` (0–3 at random per request, so a Sign In burst does not queue on one row); counters `n`, `sampled`, `failed`, `fatal`; `ms_sum`, `ms_max`, histogram `b0…b11` (edges 50, 100, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000 ms, above); `q_sum`, `q_max`; `pq_sum`, `pq_ms_sum`, `pq_n`; `mem_sum`, `mem_max`, `mem_n`; `err`; `last_at`. Unique `(route,hour,slot)`.
- `ews_health_daily`: `day`, `route`, `version` (from `EWS_VERSION`, so a release that made a route slower shows), same aggregates. Unique `(route,day,version)`.
- `ews_health_queries`: `day`, `route`, `fp`, `caller`, `shape`, `n`, `requests`, `ms_sum`, `ms_max`, `max_repeat`. Unique `(day,route,fp)`.
- `ews_health_errors`: `day`, `fp`, `level`, `file`, `line`, `message`, `route`, `n`, `first_at`, `last_at`. Unique `(day,fp,route)`.
- Cron last state: option `ews_health_cron_last` (not autoloaded).

Writes use portable SQL: `UPDATE … SET n=n+1` on the unique key, `INSERT IGNORE` if 0 rows changed, `UPDATE` again if the insert lost a race. No `ON DUPLICATE KEY`, no `DELETE … LIMIT`. Hour/day keys are computed in PHP.

**Rollup:** hourly cron `ews_health_rollup`, scheduled only while ON: rebuild today's and yesterday's daily rows; purge once a day (hourly > 7 days; daily > retention, default 90; queries and errors > min(30, retention)), in id batches; a 300,000-row cap sets `ews_health_full` (counters go on, new query/error rows stop, a notice shows); compute findings; send alerts; run the health schema upgrade when `ews_health_schema` differs from `Health\Settings::SCHEMA` (its own version, so enabling never touches the global schema target).

**Size (3,000 employees):** about 30,000 plugin requests a day plus about 1,200 cron runs; rows do not grow with request volume. Typical 10 MB, worst case under 35 MB. About 0.35 single-row UPDATEs a second on average, about 5 a second in a Sign In burst.

**Upgrade/uninstall:** sites that keep it off get no tables, no options written, no cron. Enabling creates the tables, sets `ews_health_schema`, schedules the rollup, writes an audit entry. `uninstall.php` always unschedules `ews_health_rollup` and drops the four tables when "delete data" is set (also in the multisite `wp_uninitialize_site` list).

## 4. The screen (wp-admin → Workforce One → System health, `ews31-system-health`)

English, every string through `__()`, status as icon plus word, in the Settings group of the navigation.

- **Top bar:** period (24 h / 7 d / 30 d / 90 d), area (All / wp-admin / App / Actions / API / Scheduled jobs / Other), facts (collecting since, sample rate, stored size, last rollup, collector cost as the route `health:collector`), a note when query detail is unavailable.
- **A. Look at this first** (up to 7 cards; severity, route, one sentence, 2–3 numbers, "since version X", link). A route needs `min_runs` (20) runs to be judged except fatals and cron.

| Rule | Severity | Default |
|---|---|---|
| Fatal from plugin code, 24 h | Critical | any |
| Cron job overdue | Critical | due > 30 min ago |
| Cron job failed last run | Critical | any |
| Failure rate (5xx or fatal) | Critical | ≥ 2% |
| Slow p95 | Warning | 2,000 ms (20,000 ms heavy routes and cron) |
| Slower since a release | Warning | median +50% and +300 ms vs the 7 days before |
| Repeated query (N+1) | Warning | ≥ 25 in one request |
| Many plugin queries | Warning | average ≥ 150 per request |
| Slow single query | Warning | ≥ 1,000 ms |
| Peak memory | Warning | ≥ 80% of `memory_limit` |
| New PHP warning/notice | Note | first seen in 24 h |
| Cost on other pages | Note | plugin queries on `other:*` average ≥ 20 |

  Ranked by severity, then impact (`runs × (p95 − limit)`). Regression always compares the last 3 days with the 7 before. Rules are pure, in `Health\Findings::evaluate()`.
- **B. Pages and actions:** sortable table (route + label, area, runs, typical p50, slow p95, worst, queries per request, query time, peak memory, errors, failed, 14-day sparkline with version markers); default sort by time cost.
- **C. Route detail:** daily p50/p95 chart with version markers, queries-per-request chart, the route's top queries and errors.
- **D. Slowest and repeated queries.** **E. PHP errors and warnings.** **F. Scheduled jobs** (last run, duration, p95, runs in 24 h, failures in 7 days, next due, status; notes `DISABLE_WP_CRON`; overdue computed live from `_get_cron_array()`).
- **G. Settings and data:** thresholds, alerts, retention, size, "Delete collected data" (confirmation, audit entry).

## 5. Alerts

Evaluated only by the hourly rollup. Alert key `rule:route`; open alerts in `ews_health_alerts`; an alert closes after 24 h without a match. Admin notice on Workforce One pages (and the WordPress Dashboard, question 8), dismiss for 7 days per user. Optional email (off by default): one digest of newly opened alerts, a still-open alert repeated at most every 24 h, at most 5 a day, labels and numbers and a link only (never SQL shapes or file paths), through `wp_mail`. Settings in `ews_health_settings`, each clamped: `slow_ms` 2000, `heavy_ms` 20000, `queries` 150, `repeat` 25, `slow_query_ms` 1000, `memory_pct` 80, `regression_pct` 50, `regression_ms` 300, `fail_pct` 2, `cron_late_min` 30, `min_runs` 20, `notice` 1, `email` 0, `email_to` ''.

## 6. Files

New: `src/Health/{Settings,Routes,QueryShape,Messages,Stats,Findings,Alerts,Hooks}.php`, `includes/trait-system-health.php`, `templates/admin/system-health.php`, `assets/css/admin-health.css` (+ RTL), `assets/js/admin-health.js` (sorting, inline SVG sparklines, no CDN), mockup, unit tests (`HealthRoutesTest`, `HealthQueryShapeTest`, `HealthStatsTest`, `HealthFindingsTest`, `HealthAlertsTest`, `HealthSettingsTest`, `HealthMessagesTest`), `tests/e2e_system_health.py`, `tests/health_overhead.py`, a test-only mu-plugin that records `num_queries`, time and captured mail.

Changed: bootstrap (trait in the `use` list, `Hooks::register`, define `WFO_PLUGIN_DIR`), `trait-admin.php` (menu only when enabled and permitted), `AdminNav` + `trait-admin-nav.php`, `trait-features.php` + `features.php` (section `#ews-system-health`: switch, sample rate, retention), `trait-permissions.php` (`ews_view_system_health`; administrators only by default), `Options.php`, `uninstall.php`, `modules.json` (new module `health`), `ARCHITECTURE.md`, `CHANGELOG.md`, CI (new e2e step on MySQL), `.pot`.

Options (autoload false): `ews_feature_health` (false), `ews_health_sample_rate` (10), `ews_health_retention_days` (90), `ews_health_settings`, and internal `ews_health_schema`, `ews_health_cron_last`, `ews_health_alerts`, `ews_health_findings`, `ews_health_full`.

Capability: `ews_view_system_health` to view; changing thresholds or deleting data also needs `ews_manage_settings`. The screen shows code paths, SQL shapes and slow endpoints, which helps an attacker.

Module `health`: traits `system-health`; tables the four above; api `health_enabled`, `health_settings`, `health_can_view`, `health_switch_changed`. It reads no other module's tables (the app page id is an option, views come from `Settings\Navigation`, routes from `Api\Routes::table()`), so `check_modules.py` adds no crossings.

## 7. Inert when off; overhead when on

Off: `Hooks::register` returns before any hook; no menu, notice, cron, tables; `SAVEQUERIES` never defined; no added query (the flag comes from the existing priming query); the global schema target is not bumped. The only visible change is the Features section.

On, budget (asserted in tests): a request that is not the plugin's and not sampled adds 0 queries and ≤ 0.1 ms; a plugin request not sampled adds ≤ 1 query and ≤ 1 ms (p95); a sampled request ≤ 25 writes and ≤ 15 ms with bounded memory; average over plugin requests at 10% sampling ≤ 2 ms and ≤ 3 queries. Writes happen in `shutdown`; `fastcgi_finish_request()` is deliberately not called, so the write time is part of the response time and is kept to one row.

## 8. Test plan

- **Unit:** routes (every area, rejects foreign actions/odd characters/over-long, `app:other`, REST normalisation, no `?`/`=`/value leaks); query shapes (escaped quotes, `LIKE '%x%'`, numbers, hex, `IN` lists, comments, prefix, caller extraction incl. closures and static calls); messages; stats (bucket edges, percentiles incl. empty and open last bucket, merge, regression); findings (each rule just under/over its threshold, `min_runs`, ranking, version in the text, cron overdue with `DISABLE_WP_CRON`, empty data); alerts (open/repeat/close, 24 h re-email, daily cap, digest, dismiss); settings clamping; `OptionsTest`.
- **e2e (MySQL in CI, SQLite locally):** off is inert (no tables, no event, no shutdown callback, screen denied, `num_queries` equal to a never-enabled run, an upgrade creates no health table); switch on creates tables/event/audit; at 100% sampling the expected route names and callers appear; privacy scan with a unique marker in a field, parameter, cookie and the seeded employee's name/login/email finds no match in any text column or option; lowered thresholds give findings, notice for an admin only, one captured email, none on the second rollup, dismiss hides it; a plugin PHP error is stored normalized and a foreign one ignored; cron overdue and failed; retention purge and "Delete collected data"; capability checks; switch off; uninstall with delete-data; modules, translations, phpstan and phpcs pass.
- **Overhead (`tests/health_overhead.py`):** 200 requests to one app view and one wp-admin page with the feature off, on at 10%, on at 100%: median server time +≤ max(3 ms, 5%), +≤ 3 queries on average, +0 queries for unsampled foreign requests, peak memory +≤ 8 MB at 100% on the biggest page. Looser timing limit in CI.
- **SQLite vs MySQL:** portable SQL only; check the SQLite drop-in counts `num_queries` and honours `SAVEQUERIES` (if not, query-detail checks are skipped on SQLite only and the screen says so); `INSERT IGNORE` translation to be verified; `SHOW TABLE STATUS` only on MySQL; a parallel burst asserts `n` equals the requests sent.

## 9. Risks

- `SAVEQUERIES` is global once defined (other tools see it; backtraces cost CPU): sampled requests only, the log is folded and trimmed, the site's own setting is respected; sampled requests are slightly slower.
- WordPress's fatal handler exits inside a shutdown function: recording fatals relies on `wp_should_handle_php_error`; another plugin replacing the handler can hide fatals; OOM and hard kills may still be lost.
- `wp_die('Access denied')` sends 500, so permission refusals count as failed: a rate threshold, and later fix the refusals to send 403.
- Other plugins may set their own error handler after ours; we chain and return `false`.
- Hot rows in a Sign In burst: 4 slots; measure with `tools/load_signin.py`.
- Privacy leaks if normalisation misses a form (an unquoted value in hand-built SQL, a message quoting a value): hostile unit tests, the e2e marker scan, shapes cut to 500, shapes never emailed.
- Whole-request time on `other:*` and wp-admin pages includes the theme and other plugins: findings use plugin queries and do not blame the plugin for total time.
- Storage growth if the route allowlist is wrong: fallback to `<area>:other`, row cap, full flag.
- Multisite: each subsite runs its own rollup; with `DISABLE_WP_CRON` and no network cron the rollup does not run and findings go stale ("last rollup …" shown); tables are bound at plugin load.
- Email leaves the server (labels and numbers only): question 1.
- Health tables are outside `SchemaCheck` and the global version: a failure shows only on the health screen; the rollup re-checks `ews_health_schema`.

## 10. Open questions for the owner (recommended default first)

1. Optional email alerts (page names and numbers only, through the site's mail)? Recommended: yes, off by default.
2. A new permission "View System Health", held by default only by WordPress administrators, grantable on Roles & Permissions? Recommended: yes.
3. On a network (multisite): only super admins see it? Recommended: yes.
4. Default sampling for query detail: 5%, 10% or 25%? Recommended: 10%.
5. Default retention of the daily trend: 30, 90 or 180 days (hourly detail fixed at 7 days, queries and errors at 30)? Recommended: 90.
6. Measure the plugin's cost on other pages of the site (sampled only, shown as "Other pages")? Recommended: yes.
7. Switching it off keeps the collected data until "Delete collected data" or uninstall? Recommended: keep.
8. Admin notice on Workforce One pages and the WordPress Dashboard, or Workforce One pages only? Recommended: both.
