# Mobile API, PR 1: bootstrap, my schedule, today's attendance (design)

Base: commit `ebc679d` (3.31.111, the last commit whose CI and local checks were green). WordPress stays the backend; the apps use REST.
No business rule is copied, no schema change in this PR, no change to any existing endpoint. Read-only. A new switch
`ews_feature_app_api` (off by default): off, the three routes answer 403 FORBIDDEN `{"reason":"disabled"}` and `/me`, `/meta`, auth and the Web are unchanged.

Out of scope: the app UI, recording Sign In/Out, requests and approvals, any broad refactoring. The refresh-token race is a separate release (below).

## Contracts (all `GET`, `access: token`, `kind: native`, the existing envelope, `Cache-Control: no-store`)
Every handler first: (1) the switch; (2) refuses `employee_id`, `employee`, `user_id`, `user`, `eid` with 400 VALIDATION_FAILED
`fields.employee_id=not_allowed`; (3) schedule and today: no employee in the session is 403 ACCOUNT_NOT_LINKED. The employee comes only from
`self::$api_session['employee']`. No new error codes. Labels come in the site locale (Web parity); machine fields are never translated.

1. **GET /me/bootstrap**: the `/me` document (unchanged route; its body moves to `api_me_document()`) plus `features` (same keys as `/meta`),
   `screens` (`app_navigation()` for this user without badge counts: key, label, mobile visible/order), `site` (timezone, locale, today, working_days) and
   `limits.schedule_max_days`. An admin with no employee gets `employee: null`.
2. **GET /me/schedule?from=&to=**: every calendar day of the range (default: the Web's current week; `from` only = 7 days; `to` only = 400;
   max 42 days; dates 2000..2099), each with `date, weekday, working_weekday, status, label, requires_sign_in, general_leave`, a `shift`
   (id, name, start, end, overnight, grace_minutes, starts_at, ends_at in ISO 8601 with the site offset; from `working_hours()` so shift history applies)
   and a `branch` (only when the Web would show one). No schedule: 200, every day Not Set.
3. **GET /attendance/today**: `today`, `work_date`, `carried_over`, `day_type` (working, general_leave, not_working, attendance_disabled), `clock`
   (out/in/break/done), schedule, shift, sign-in window, branch, sign_in/sign_out, breaks (enabled, per_day, duration, used, remaining, open, sessions),
   `requirements` (face, location) and `actions` (sign_in, sign_out, break_start, break_end, each `allowed` + the ErrorMap reason `AttendanceService` would give now;
   hints only, the future write endpoints re-check). Built by a new `AttendanceService::today()` that shares the facts builders with `record()`.

## Time rules (same as the Web)
Rules work in local wall-clock time; ISO conversion only at output with `wp_timezone()`. `attendance_day()` → `ShiftDay::resolve`: overnight shift, now <= end →
yesterday; now < start and yesterday open → yesterday; else today. Night 22:00-06:00: Tue 01:00 is Mon's work day; Tue 06:31 after Sign Out is Tue. The API sends no durations
(a DST night is 9 real hours; the Web counts 8). Egypt has DST (2026: 24 Apr, 29 Oct): the DST-free tests use Asia/Riyadh.

## Files
Routes (3 rows); pure `Api/Period`, `Api/LocalTime`, `Api/Bootstrap`, `Api/AttendanceToday`, `Schedule/ScheduleDays`, `Attendance/TodayState`;
`AttendanceService::today()` (shared builders), context gets `workingHours`, `breakDetails`, `branchToday`; traits `trait-api-me.php` (app), `trait-schedule-api.php`
(schedule), `trait-attendance-api.php` (attendance); `api_feature_flags()` shared with `/meta`; `app_screens()`; `branch_days()`; `Options.php` switch; Features screen switch
(needs a small HTML mockup first); modules.json (new traits and api entries); ARCHITECTURE, CHANGELOG, translations.

## Tests
Unit: Period, LocalTime (Cairo DST, Riyadh, UTC), ScheduleDays, AttendanceToday (parity with the service per fixture), Bootstrap, route list.
E2E `tests/e2e_api_app.py` (CI after e2e_api_auth): switch off; bootstrap equals `/me` on shared keys for employee, manager, admin; screens follow the switches;
admin-only account; inactive account (403 ACCOUNT_DISABLED); another employee's id refused and never leaked; no schedule; range limits; overnight cases compared with
`attendance_day()`; time zones; Web parity for four states; breaks on/off; query-count budgets (same count for 7 and 42 days, unchanged with 1,500 employees); headers; every body
validated against the OpenAPI file.

## OpenAPI
`docs/api/openapi.yaml` (3.1): every native v1 route (full schemas for `/meta`, auth, `/me`, devices and the three new ones; corrections and daily-workers marked
`x-wfo-schema: pending`; Face routes left out, they use cookies), shared Envelope/Meta/Error/ErrorCode, the client rules. `tests/check_openapi.py` in the static CI job: paths and methods match `Routes.php`
both ways, the error enum matches ErrorMap, every example validates against its schema (small in-house validator, PyYAML only).

## Risks
Data leaks (identity only from the session; test with another employee); permissions equal `/me` and `app_view_allowed()`; the one real-logic refactor is the facts builder
in `AttendanceService` (guarded by unit tests, `e2e_attendance_parity`, `e2e_api_foundation`); performance (every query is for one employee); Web quirks kept on purpose.

## Refresh-token race (separate security release, after this PR)
Measured with real concurrency (4 PHP workers, SQLite): 2 simultaneous refreshes with one token succeed, 3: the third is treated as theft and the whole device session is revoked, 5: several revoked.
Cause: `revoked_at` on the used token is a one-use grace flag. Also: a database error is treated as reuse (`false !== 1`), and the "revoke the device's access tokens" step can kill a parallel grace pair.
Fix: `grace_uses` column on `ews_api_tokens` (dbDelta, schema bump), `Tokens::REFRESH_GRACE_USES = 4` within 30 s, one atomic conditional UPDATE, a database error returns 500 and never revokes,
and the access-token revoke only touches tokens older than the rotated refresh token. Trade-off: a thief replaying within 30 s can mint up to 4 pairs instead of 1; detection after that is unchanged.
Client rule (OpenAPI): one refresh in flight per device (shared lock, also across extensions), save the new refresh token before using the new access token, retry the same token within 30 s on a network
failure, wipe and log out on REFRESH_REUSED / REFRESH_INVALID / DEVICE_REVOKED / ACCOUNT_DISABLED, refresh ahead at expires_in - 60.
Test: `tests/e2e_api_refresh_race.py` with a multi-worker PHP server in CI (`PHP_CLI_SERVER_WORKERS=6`, port 8081): n = 2, 3, 5 all succeed and the device stays; n = 6: five succeed and one is REFRESH_REUSED; sequential uses 2-5 accepted, the 6th reused; `used_at` 31 s ago is reused; never revoked by a database error.

## Owner's answers
(recorded below)
