# System health: database time vs time outside it (design)

Owner request 2026-11-15: "a small addition to System health: record, for each load, how much of its time went to the database
and how much outside, so the screen can say 'slow because of the database' or 'slow because of PHP / the server'. Behind the
existing System health switch." Performance 2 (3.31.149, exports) is being built in parallel on another branch: this change must
not touch its files. Read-only design by the architect; claims are VERIFIED (read in the code) unless marked ASSUMED.

## Why
The live Sign In / Out page (`admin:ews31-time-report`): typical 300 ms, slow (1 in 20) 3.0 s, 136 queries per load, but its query
table sums to about 40 ms. The screen cannot say where the other 2.9 s of a slow load goes.

## Today
- **Timer.** Starts at `REQUEST_TIME_FLOAT` (`includes/trait-system-health.php:149`), stops at shutdown (`:287`). `health_boot()`
  runs from the plugin constructor (`employee-schedule-manager.php:126`), returns at once while the feature is off (`:138`), and
  returns with no collector while `ews_health_schema` is not `HealthSettings::SCHEMA` (`:147`).
- **Query count.** Every request: `$wpdb->num_queries`, read before the collector's own writes (`:292`).
- **Query time, sampled requests only.** Sampled at `ews_health_sample_rate` (10% by default; cron and `Routes::ALWAYS_SAMPLE`
  always, `src/Health/Routes.php:22`; the Sign In / Out page is not on that list). A sampled request defines `SAVEQUERIES` (`:157`);
  `health_fold()` (`:182-197`) folds `$wpdb->queries` into `agg[fp]` (count, total µs, max µs). Queries before the plugin loads are
  not logged.
- **Stored per request.** One UPDATE into `ews_health_hourly` (`health_record()`, `:338-343`); columns from
  `health_counter_columns()` (`:54-59`): `n, sampled, failed, fatal, ms_sum, ms_max, b0..b19, q_sum, q_max, pq_sum, pq_us_sum, pq_n,
  mem_*, err`. `pq_*` covers **plugin-called** queries only (`:329`).
- **No total DB time anywhere.** Core and other plugins' queries are folded but never summed; the query table keeps at most 10-20
  shapes per sampled load (`:378-383`), which is one reason it shows about 40 ms.
- **Daily rollup.** `health_build_daily()` (`:438-481`) sums `health_sum_columns()` (`:398-402`) and takes MAX of `*_max`; a new
  summed column needs only to be listed there.
- **Findings.** `health_findings_input()` (`:528-570`); `Findings::evaluate()` adds `slow` when `Stats::percentile(hist,0.95)` is
  above `slow_ms` / `heavy_ms` (`src/Health/Findings.php:43-45`); words in `health_finding_text()` 'slow' (`:603`); alert key
  `slow:<route>` (`src/Health/Alerts.php:24`); the email uses the title only (`:666`).
- **Route Details line.** `templates/admin/system-health.php:108`, fed by `health_route_stats()` (`:689-705`).
- **Schema.** Health tables have their own version (`HealthSettings::SCHEMA = '1'`), created by `ensure_health_schema()`
  (`:62-121`), never through `ews_schema_target()`. The rollup (`:407`) and the screen (`:764`) repair a stale schema.
- **wpdb** (ASSUMED, core is not in the repo): `timer_start()/timer_stop()` and `log_query()` only with `SAVEQUERIES`; the stored
  time covers `mysqli_query` alone; there is no hook after a query.
- **Golden** never enables health: unaffected. **Multisite:** per-site tables (`$wpdb->prefix`), option and cron; `uninstall.php:55`.

## Can DB time be measured on every request?
Not cheaply and reliably: gaps between `query` filter calls mix in PHP time; `SAVEQUERIES` on every request costs a backtrace per
query and is a global other tools see; a `db.php` drop-in or replacing `$wpdb` is too invasive; `performance_schema` needs privileges
and is absent on SQLite. **Proposal:** carry the split on sampled loads only, from the `agg` already collected, and always show how
many loads it rests on. At 10% sampling the Sign In / Out page gets about 11 sampled loads a week (a fair typical split) and 0-1
sampled slow loads (the slow split will mostly read "too few measured" unless the sample rate is raised; open question 2).

## Design
1. **Collect.** In `health_finish_inner()` (`:325-330`), in the sampled branch's existing `foreach($agg)`, also sum
   `$db_us += $a['us']` over **all** shapes; pass `$db_us` (or `null` when not sampled) to `health_record()` as a new last parameter.
   Cron: the same per job from `$d['agg']` (`:308-311`).
2. **`health_record()`.** Only when `$db_us!==null` and the split columns exist: `db_n=1, db_ms_sum=$ms, db_us_sum=$db_us`; when the
   load was over the route's limit (`Routes::heavy($route)?heavy_ms:slow_ms`) also `slow_n=1, slow_ms_sum=$ms, slow_db_us_sum=$db_us`.
   A load that was not sampled sends exactly today's SQL.
3. **Columns** added to `health_counter_columns()` and `health_sum_columns()`; rollup, `health_route_stats()` and
   `health_findings_input()` carry them with no further change.
4. **Pure logic.** `Stats::split(int $n,int $ms_sum,int $db_us_sum,int $min_n,int $mostly_pct): ?array` returns
   `['n','total_ms','db_ms','out_ms','cause'=>'db'|'outside'|'mixed']`, or `null` when `$n<$min_n`.
5. **Findings.** 'slow' gains args `cause, db_ms, out_ms, split_n` from the slow split (`cause=''` when thin). Key, severity, impact
   unchanged: no new alert or email. Old stored findings have no `cause` and read as today.
6. **Finding text.** One clause when `cause!==''`: "Measured on %1$d slow loads: database %2$s, outside the database %3$s: mostly
   outside the database (PHP or the server)." / "mostly database queries" / "both". Title and email unchanged.
7. **Route Details.** One line under the stat line (`health_route_stats()` adds `split` and `slow_split`, each `null` when thin):
   - "Measured on %1$d loads with query detail: on average %2$s, of which database %3$s and outside the database %4$s."
   - "Loads longer than %1$s (%2$d measured): database %3$s, outside %4$s."
   - Thin: "Too few loads with query detail to split the time (%1$d of at least %2$d). Raise “Detailed query data” on Features to
     measure more."
   - With `SAVEQUERIES` forced false by the site, the existing `detail_off` note covers it and no split line is shown.
   - The overview is untouched (open question 3).

## Data model and upgrade path
- Both `ews_health_hourly` and `ews_health_daily`: `db_n INT UNSIGNED, db_ms_sum BIGINT UNSIGNED, db_us_sum BIGINT UNSIGNED,
  slow_n INT UNSIGNED, slow_ms_sum BIGINT UNSIGNED, slow_db_us_sum BIGINT UNSIGNED`, all `NOT NULL DEFAULT 0`, via `dbDelta` in
  `ensure_health_schema()`.
- `HealthSettings::SCHEMA` '1' → '2'. `ews_schema_target()` does **not** move (Performance 2 does not move it either).
- **No collection gap:** `:147` accepts '1' or `SCHEMA`; `$this->health['split']=(schema===SCHEMA)`; `health_record()` writes the
  new columns only when that flag is true. The rollup and the screen repair as today (once per site, never in a burst of requests).
- Old rows have `db_n=0` and add nothing; the split divides by `db_n`, never by `n`, so old rows never show 0 ms.
- Retention, purge and "Delete collected data" unchanged.

## Off by default / nothing changes when off
- Off: `health_boot()` returns at `:138`: no hook, no `SAVEQUERIES`, no write, no `dbDelta`.
- On, not sampled: identical SQL.
- On, sampled: one integer addition per folded shape and six more `SET col=col+%d` in the existing UPDATE; no extra query
  (ASSUMED well under 0.1 ms; the e2e overhead block bounds it).

## Edge cases
- Queries before the plugin loads are not logged: DB time is a slight underestimate (wording, open question 5).
- `SAVEQUERIES`' own backtrace falls inside "outside" (ASSUMED 10-30 µs per query, 1-4 ms at 136 queries): biases toward
  "outside"; the e2e reports its size.
- The site already set `SAVEQUERIES`: the collector only reads; totals stay right.
- Over 500 queries: folding is incremental, the sum is unaffected. Fatal requests still sum `agg`.
- Threshold changed mid-week: "slow" is decided at record time; the help text says so.
- Multisite: per site, as today. SQLite: `dbDelta` ADD COLUMN on the drop-in ASSUMED to work; covered by e2e.

## Tests
- Unit: `tests/unit/HealthStatsTest.php` (`split()`: below min → null, cause bands at their edges, no division by zero, `db_us` above
  ms clamped so `out_ms` ≥ 0); `tests/unit/HealthFindingsTest.php` (slow args carry `cause` with enough data, `''` when thin; key and
  severity unchanged).
- E2E, `tests/e2e_system_health.py` (+ `tests/e2e_health_mu.php`):
  1. Schema '1' → '2' via the rollup; rows collected on '1' get no new values and the collector never stops.
  2. At 100% sampling `db_n` = sampled count, `db_us_sum` > 0 and ≥ the query rows' `us_sum` for the same loads.
  3. At 1% sampling a load that was not sampled leaves the six columns at 0 and its UPDATE text unchanged.
  4. A tagged request with `usleep(2.2 s)` and a low `slow_ms` → `slow_n` grows, screen says "mostly outside the database";
     `SELECT SLEEP(x)` (MySQL only) → "mostly database".
  5. Thin data shows the "too few" line, never "0 ms".
  6. Off: no columns written.
  7. The overhead block still passes; an INFO line for the sampled split.
- Golden: no impact. phpstan, phpcs, translations, modules.

## New strings
The three Details lines and the three finding clauses above, with translators comments; Arabic in `languages/` (.po, .mo,
.l10n.php, .pot).

## Risks
- Thin data at 10%: an honest but empty slow split (the "too few" wording and the sample-rate hint).
- Bias toward "outside" from `SAVEQUERIES` overhead.
- `dbDelta` on a large `daily` table: one ALTER in cron, tens of thousands of rows (ASSUMED small).

## Merge plan versus Performance 2
Its own branch from main (`c3a2262`). No file in common with Performance 2's list. Neither moves `ews_schema_target()`. Expected
conflicts only in the version (`employee-schedule-manager.php:5,14`; bumped in a last, separate commit after whichever lands first),
`workforce-one/CHANGELOG.md` (own section at the top) and the generated language files (regenerated after the merge, never
hand-merged). Fetch and merge; never force-push.

## Open questions for the owner
1. **"Mostly".** Database share at or above which "mostly database", at or below which "mostly outside", "both" in between
   (suggested 60% / 40%).
2. **Thin data.** Minimum measured loads before a split shows (suggested 5 slow, 10 typical). Suggest raising the sample rate on
   the screen, or always sample slow admin pages?
3. **Overview.** Show the split on the overview too, or only on Details and in the finding?
4. **"Slow load" for the split.** The route's limit (2.0 s), as proposed, or the 1-in-20 time?
5. **Extras, not in this design:** time before the plugin starts (WordPress bootstrap, free on every request); outside HTTP time
   (`pre_http_request` / `http_api_debug`); peak memory on slow loads; wording DB time as "at least".
6. **Version:** 3.31.150 after Performance 2 (3.31.149), or the other way round.
