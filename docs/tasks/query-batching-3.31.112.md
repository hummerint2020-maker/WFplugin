# 3.31.112: batch the two repeated queries (design)

Found by the plugin's own System health screen on the live site (2026-10-19). No schema change, no new option or switch;
both paths already run only when their feature is on.

## Fix 1: `ews_health_rollup` (`health_build_daily`, includes/trait-system-health.php)
One INSERT per (route, release) row, about 43 for two days. Change: keep the SELECT, the sort and the DELETE; build the rows in PHP;
de-duplicate on (route, final version) keeping the first (the table has `UNIQUE route_day_version`; today the second single insert fails
silently, and in a multi-row INSERT it would lose the whole day); write in chunks of 100 with one `INSERT ... VALUES (...),(...)`;
if a chunk returns false, insert that chunk row by row as today. About 6 queries per rollup instead of about 47.
The purge loop is batched by design (2000 rows, at most 25 rounds on a backlog) and stays.

## Fix 2: My requests / My approvals (`approval_path()`, includes/trait-approvals.php)
Both pages call `approval_path()` per row, which runs `approval_find_request`, an `instances` SELECT and several name lookups.
Change: `approval_find_requests(keys)` (one query, `OR` of grouped `IN` lists, first row per key, chunks of 500) and
`approval_paths(keys, now)` (one query for requests, one for instances, one for employees by user id, `cache_users`, one for
`for_employee_id`); the loop body of `approval_path` becomes `approval_path_build`, and `approval_path($wk,$et,$id)` stays as a one-key
wrapper, so one code path serves both. `requests_content()` and `my_approvals_items()` batch once. No cache inside
`approval_find_request`: the decision handlers re-read it right after `approval_act` (money for advances).

## Proof
Fix 1: e2e_system_health seeds 40 routes (one with two releases, one with versions '' and EWS_VERSION), counts INSERT statements with a
`query` filter (at most days × ceil(rows/100)), and checks the rows and sums. Fix 2: a test-only mu-plugin counts queries by caller;
30 requests must add no per-row queries (total at N=30 minus N=5 under 25); parity: `approval_path` equals `approval_paths` for every key.

## Risks
A stale cache in the decision handlers (avoided); duplicate-key or packet limit in the multi-row INSERT (de-duplicate, chunk, fallback);
output drift (one shared builder, parity test); permissions unchanged (the batch gets the keys the pages already compute).

## Owner's answers
(recorded below)
