# L5 Attendance move (Controllers → Services → Stores): design

**Status:** design by the read-only architect on main 3.31.134 (with L4c, 3.31.135, in review). Saved before implementation. This is a move only: same SQL text and order, locks, redirects, messages, audit rows, notifications, cron events, hooks, options and schema. No new option, so "off changes nothing" holds trivially and `tests/golden/expected.json` stays empty. Times stay site-local wall clock (`current_time('mysql')`). Model: `people-store.md`, `people-org-writes.md`, `people-employee-writes.md`. Owner questions: §12.

## 0. What exists (measured)

**Module (`modules.json` → `attendance`)**
- Traits: `attendance`, `attendance-api`, `auto-attendance`, `breaks`, `face`, `face-reset`, `signin-evidence`, `time-report`, `corrections`, `attendance-insights`.
- Tables: `time_logs`, `break_sessions`, `face_profiles`, `auto_attendance_rules`, `attendance_corrections`, `signin_attempts`.
- `ews_api_idempotency` belongs to **app** (`trait-schema.php:259`) but Attendance writes it.
- `trait-work-time.php`, `trait-attendance-grid.php`, `trait-attendance-cards.php` belong to **schedule**. Grid and cards have no SQL. `work-time` reads `time_logs` at `:22`, `:51`, `:65`.

**`$wpdb` count:** attendance 44, auto-attendance 41, time-report 31, signin-evidence 30, face 11, breaks 10, insights 6, work-time 7, corrections 111.

**`$this->time_logs`** is fixed when the plugin object is built (`employee-schedule-manager.php:115`). Break, idempotency, attempts and auto-rule tables are built from `$wpdb->prefix` on every call.

**No UNIQUE key on `time_logs`.** Duplicates are prevented only by the per-employee `GET_LOCK` plus the "first row wins" check after the insert.

**`AttendanceService` (3.31.46)** is already pure. It decides; its `AttendanceContext` is a bag of 43 closures built per call in `trait-attendance.php:89-144`. Closures that hold SQL:
- `insertTimeLog`, `insertError`, `lastInsertId`, `firstEventId`, `deleteTimeLog` (`:112-119`)
- `idemSave`, `idemRelease` (`:128-129`)
- `insertBreak`, `firstOpenBreakId`, `deleteBreak`, `closeBreak` (`:133-141`)

Through trait methods it also reaches `lastDeviceLocation` (`:25`), `events` (`today_events`, work-time `:64`), `openBreak` / `breakRemaining` (breaks `:32-44`), `lock` / `unlock` (`:153-167`), `idemFind` / `idemClaim` (`:198-215`).

**Still inline in the traits:** the photo UPDATE after Sign In (`:61`); the break crons (breaks `:110-134`); all of auto attendance (`:45-170` tick, `:172-222` rule CRUD, `:224-294` bulk Sign Out); manual records (time-report `:165-233`); the time report and CSV read model (`:48-163`, `:235-273`); evidence (`:99-301`, `:354-377`).

**Cross-module writers of `time_logs` (stay):** corrections `cx_finalize_rows` (`trait-corrections.php:540-566`, inside its own transaction, without the attendance lock); privacy cleanup (`trait-privacy.php:37`); schema ALTERs and the upgrade UPDATE (`trait-schema.php:43-70`, `:434-440`; `trait-branches.php:37`; `trait-corrections.php:74-80`; `trait-signin-evidence.php:71-73`); `uninstall.php`.

**Cross-module readers (stay; read-model debt):**
- **Money:** `report_days` (`trait-reports.php:284` time_logs, `:289` break_sessions), reached by payroll (`trait-payroll.php:131`); `payroll :192`.
- corrections `cx_day` (`:90`), corrections reminder (`:936`); achievements `:172`, `:318`; admin-dashboard `:43`, `:74`; `my-profile:18`; `employee-profile:34`; reports `:166`.
- **Hidden coupling:** all build the "live" filter through `tl_live()` (`trait-corrections.php:34`); `check_layers` does not count it.

## 1. Scope and slicing (each sub-release ships alone; ordered by risk)

| Release | What moves | Risk |
|---|---|---|
| **L5a Reads + enrolment** | Today's events, the attendance day's yesterday read, last device location, break reads, overtime's `time_logs` read; enrol `time_logs` and `break_sessions` | S. Payroll's path untouched. |
| **L5b Sign In/Out and breaks writes** | Store-backed context, lock port, idempotency port, photo UPDATE, break crons | M. Concurrency and money rows. |
| **L5c Auto attendance and manual records** | Auto rules store, tick, rule CRUD, bulk Sign Out; manual add/edit/reset | M. Cron, concurrency, money. |
| **L5d Evidence and time report read model** | `signin_attempts` store, cleanup, offline report, photo serve, timeline; `TimeReportQuery` | S–M |
| L5e (own design later) | Corrections (approvals engine, payroll carry, transaction) | L |

**Not moved in any L5:** schema (`ensure_*`, ALTERs); `ews_with_lock` (used by payroll, leave, advances, overtime, daily workers, shift history; later Platform port); `report_days` and the other readers in §0; face profiles and face reset; presence and kiosk; attendance grid and CSV import (schedule); `att_grid_save`'s legacy read of `$this->schedule` (`:382`, L3); insights; privacy cleanup; `admin_auto_attendance`'s JOIN with employees (`:302`, people crossing, kept).

## 2. Scope table for L5a and L5b (M = moved, trait keeps a delegator; C = controller kept, SQL leaves; L = left)

| Code | Kind | What happens |
|---|---|---|
| `trait-attendance.php:25` `last_device_location` | M | `AttendanceLog::lastDeviceLocation`. Presence `:138` keeps calling the same name. |
| `trait-work-time.php:64` `today_events` | M | `AttendanceLog::todayEvents($eid,$day)` including the `$o[$r->event_type]=$r` loop. Definition moves to `trait-attendance.php`, name kept, added to attendance `api`. |
| `trait-work-time.php:40` `attendance_day` | C | Static cache, `current_time`, `ShiftDay` stay. Only the `:51` SQL goes to `AttendanceLog::yesterdayTypes`. Definition moves next to `today_events`. |
| `overtime_attendance_summary` `:22` | C | Only the `time_logs` read moves (`liveInOut`). Overtime SQL at `:21` stays. |
| `trait-breaks.php:32,:37` | M | `breakSessions`, `openBreak` |
| `attendance_context()` `:89` | C | SQL closures replaced by store-backed assembly (§3). Rule and clock closures stay. |
| `attendance_lock` / `unlock` / `lock_name` | M | `EmployeeLock` port implemented in `Data/` |
| `attendance_idempotency_*` (`:198-222`) and the two closures | M | `Idempotency` port. Adapter calls these functions, whose definitions move to `trait-api-auth.php` (app owns the table), names kept. |
| `record_time_event` `:47` | C | Only the `:61` UPDATE goes to `TimeLogStore::setPhoto`. Stays after the service call, outside the lock. |
| `break_duration_reminder`, `break_manager_escalation` | M | `BreakCronService` (`remind`, `escalate`). Controller gives actor, clock, manager ids (`get_users` stays). |
| `time_event`, `break_start`, `break_resume`, message functions, `break_redirect`, `posted_device_location`, `face_consume_token`, `face_photo_take` | L | controllers |

## 3. New classes (`WorkforceOne\Attendance`, `workforce-one/src/Attendance/`)

**Stores.** SQL copied verbatim: same text, same `wpdb` call (`get_row` with `ARRAY_A` at `:27`), formats present or absent exactly as now, raw `wpdb` return types.

**`Data/TimeLogStore` + `WordPressTimeLogStore`** (table from constructor = the plugin's `$this->time_logs`):
- L5a: `lastDeviceLocation`, `liveDay`, `yesterdayTypes`, `liveInOut`. Private `live($col)` returns exactly `" AND NOT EXISTS (SELECT 1 FROM {$t} tl_cx WHERE tl_cx.corrects_id={$col})"`.
- L5b: `insert(array $row): int` (no formats, 0 on failure); `lastError()`, `lastInsertId()` (`$db->insert_id` read when called); `firstEventId(eid,day,types)` (`:115-118`); `delete(id)` (`['%d']`); `setPhoto(id,key)` (the `$wpdb->update` of `:61`, no formats).
- L5c: `insertAuto(row)` with the 14 formats of `:129`; `firstOfKinds(eid,date,kindsLiteral)` (`:87`; literal IN text differs from `firstEventId`, separate method); `liveDay` reused for `:99`; `anyOfKinds` (`:161`), `openSignInsOn(date)` (`:230-238`), `byId` (`:241`), `signOutIdOn` (`:249`); manual: `idEmployee` (`:175`), `othersOn` (`:185`), `update` (`:198`), `insertManual` (`:203`), `deleteDay` (`:222`).

**`Data/BreakStore` + `WordPressBreakStore`** (`$db->prefix.'ews_break_sessions'`; container is per prefix): `sessionsOn`, `openOn` (same text as `:278`, reused in L5c), `byId`; `insert` (formats `['%d','%d','%s','%s','%s','%s']`), `firstOpenId`, `delete`, `close(id,end,min)` (returns affected count; service compares `===1`); `markReminded`, `markEscalated` (`WHERE id` only, as now); `idsOn`, `deleteDay` (L5c).

**`Data/AutoRuleStore` + `WordPressAutoRuleStore`** (L5c): `dueRules(today,yesterday)` (`:146`), `disable(id,now)`, `find`, `enabledOf`, `insert`, `update`, `remove`, `setEnabled`.

**`Data/WordPressEmployeeLock implements EmployeeLock`:** `lock(eid,wait=5): bool`, `unlock(eid)`. SQLite check, `suppress_errors` and `(string)$got==='1'` kept as now. Name `'wfo_att_'.substr(md5(DB_NAME.'|'.$db->prefix),0,12).'_'.eid`, built on every call.

**Ports:** `EmployeeLock`; `Idempotency` (`find`, `claim`, `save`, `release`); `BreakTimers` (`schedule(id)`: the two `wp_schedule_single_event` calls with `current_time('timestamp',true)` and the minute settings; `clear(id)`); `AttendanceNotifier` (`breakEnded`, `breakTimeEnded`, `extendedBreak`; texts stay in the adapter); reused: `AuditLog`, `Platform\People`, `ScheduleDayService::day` (L5c; `find()` is the same text as `auto_attendance_working_schedule :62`, so the schedule crossing drops by 1).

Holder: `AttendancePorts`. Adapters built only in `includes/trait-services.php`.

**Services**
- `AttendanceLog` (reads).
- `AttendanceService` stays **unchanged byte for byte**.
- New named constructor `AttendanceContext::assemble(array $site, TimeLogStore, BreakStore, EmployeeLock, Idempotency, BreakTimers, AttendanceNotifier)`: fills the 16 data entries from stores and ports; `$site` carries employee, schedule, rules, clock, ip, audit, achievements, `events`, `openBreak`, `breakRemaining` (these three stay site closures because they go through `attendance_day()`). `KEYS` check unchanged.
- `Services::attendance(array $site): AttendanceService` built **fresh on each call**, as `attendance_service()` is today; no memo.
- `BreakCronService` (L5b).
- `AutoAttendanceService` (L5c): `tick(today,yesterday,hhmm,now)`, `event(...)`, `bulkSignOut(date,now)` returning counts.
- `ManualRecordService` (L5c): facts for `ManualRecordRules`, `save`, `reset`.

All free of `current_time` and `get_current_user_id` (passed in).

## 4. Order of reads, writes and locks the golden traces must pin

**Sign In / Out (web)**
1. Controller: login, `event_type`, nonce, `face_consume_token` (transient get and delete), posted location.
2. Service: `employee`, `attendanceEnabled`, [`idemFind` → `idemClaim` ×2], then **`GET_LOCK(name,5)`**.
3. Under the lock:
   1. `attendance_day` (working hours; overnight shift: yesterday-types SELECT)
   2. schedule `find`, events `SELECT * … live`, branches or assigned location
   3. facts: face setting, holidays, schedule type, window; Sign Out: `breakEnabled` and open break SELECT
   4. `SignInRules`
   5. only with latitude and longitude: last-location SELECT
   6. geofence and QR
   7. `INSERT time_logs`; on failure audit `time_*_failed`
   8. `SELECT MIN(id)`; if not ours, DELETE and refuse
   9. classify (Sign In) or achievements (Sign Out)
   10. audit `time_*` with `lastInsertId`
4. `RELEASE_LOCK`, then `idemSave` or `idemRelease`.
5. Controller: refused → attempts INSERT, audit, alert COUNT, notify, redirect; recorded → `face_photo_take` (transient), photo UPDATE, redirect.

**Break start:** setting, employee, attendance check, early rules, [idem], lock, schedule, events, open break, sessions count, INSERT, MIN(id) (DELETE when not ours), the two `wp_schedule_single_event`, audit, unlock.

**Break resume:** under the lock: open break, `UPDATE … WHERE id AND status='Open'` (must be ===1), "Break Ended" notification (inside the lock), audit.

**Auto event:** `GET_LOCK(5)`, live day, checks, `people_employee(active)`, attendance check, INSERT, MIN(id)/DELETE, restore `insert_id`, audit, unlock.

**Tick:** due rules; per action the schedule `find`, holidays and schedule type; then the one-time "done" SELECTs and the unconditional disable UPDATE.

**Bulk Sign Out:** candidate SELECT; per employee: SELECT by id, attendance check, **`GET_LOCK(2)`**, the Sign Out SELECT, INSERT, MIN(id), `people_employee`, audit, open break SELECT, conditional UPDATE, `wp_clear_scheduled_hook` ×2, audit, RELEASE; then summary audit and redirect.

**Transactions:** none. Every attendance write is autocommit. `Transaction` port not used. Services never begin a transaction: corrections calls into this code from inside its own transaction and `START TRANSACTION` would silently commit it.

**Lock order:** attendance lock → `time_logs` rows → `break_sessions` rows. Nothing else locked inside it; achievements and notification inserts are existing behaviour.

**Not drivable in process, and how it is covered**
- Face matching in the browser: seed `ews_face_token_<uid>` with `hash('sha256','T')`, post `face_token=T`; seed `ews_face_photo_<uid>` with a fixed key (no file needed).
- Geolocation: post latitude, longitude, accuracy, `location_timestamp=time()*1000` at call time (row keeps it as placeholder).
- QR: `presence_qr_payload(kid,secret)` through Reflection at call time.
- IP: set `$_SERVER['REMOTE_ADDR']`.
- Real two-connection races stay in e2e: `e2e_attendance_parity.py`, `e2e_auto_attendance.py` (slow-insert mu-plugin).
- Fault injection on the `query` filter: `GET_LOCK` → `SELECT 0` (busy, goes on unlocked) and `SELECT NULL`; `INSERT INTO …ews_time_logs` broken (`SAVE_FAILED`, failure audit, key released); `SELECT MIN(id) FROM …time_logs` → `SELECT 1` (concurrent duplicate removes itself); the same three for `ews_break_sessions`; break close UPDATE → `UPDATE … SET status=status WHERE 1=0` (0 affected); idempotency INSERT broken (`IN_PROGRESS`); photo UPDATE broken (ignored); attempts INSERT broken; L5c: auto INSERT, auto MIN(id), rules UPDATE, bulk lock busy.

## 5. Golden harness additions

**`tests/golden/seed_attendance.php`**: shift times computed from the seed clock ("normal": start = now−1 min, grace 180; "late": start 00:00, grace 0; overnight with yesterday's open Sign In); a work location with enforcement, a second branch, a kiosk; breaks on with 2 per day; face on for one employee; attempts and photos on; an auto rule due now (one-time and weekly), one done, one on a day off; a corrected (superseded) event so the live filter is pinned.

**`actions_attendance.php`** (keys `attendance:*`): recorder, fault filter and `GoldenExit` of `actions_employees.php`, plus the scheduled `ews_break_*` cron entries (timestamps as deltas from now).
- Sign In/Out: each `SignInRules` code, outside the location, QR ok and outside, face required and ok with a photo, branch "other", every fault above.
- Breaks: start, second start, none left, resume, resume twice, faults.
- Idempotency (service through Reflection): replay, conflict, in progress, save failure releases the key.
- Crons: reminder twice, escalation with the employee archived.
- L5c: tick, one-time rule disabled, bulk Sign Out with a break open and with the lock busy, rule save / toggle / delete, manual add / edit / scope / not found / reset with breaks.

**Pages:** app `?ews_view=time` in each state (none, in, on break, out, overnight, face); dashboard, my profile, app bar clock; `GET /attendance/today`; Sign In/Out report (attempts off and on, search, page 2, timeline, edit); auto attendance admin page; payroll for this month (unchanged code, pins the rows payroll counts).

**Sanity and self-test:** `run.py` checks rows were written and the lock was taken. Self-test: one extra query in `today_events`, or a dropped `live()`, must fail the trace. A day boundary between the two sides reruns the job (existing rule).

## 6. Unit and storage tests

Fakes: `MemoryTimeLogStore`, `MemoryBreakStore`, `MemoryAutoRuleStore`, `FakeEmployeeLock` (can be busy, records calls), `FakeIdempotency`, `FakeBreakTimers`, `FakeAttendanceNotifier`.

Unit: `AttendanceLogTest`; `AttendanceAssembleTest` (the `AttendanceServiceTest` scenarios through `assemble()`; `AttendanceServiceTest` itself stays, it patches `ctx->f` through Reflection); `BreakCronServiceTest`; L5c: `AutoAttendanceServiceTest`, `ManualRecordServiceTest`.

Storage contract `tests/e2e_attendance_store.php` with real tables prefixed `att_contract_`: `live()` text identical; NULL with and without formats; `insert_id` after `delete`; `last_error` after a failed insert; the lock name and SQLite returning false; break table following the prefix while `time_logs` stays fixed.

## 7. Layers, baselines, `modules.json`

`check_layers.py`: `HELPERS` += `tl_live`→`time_logs`, `cx_table`→`attendance_corrections`, `attempts_table`→`signin_attempts`, each with a self-test.

Enrolment (L5a): `time_logs` and `break_sessions` enrolled with one `--allow-increase`; real numbers in the PR (`tl_live` raises them); afterwards counts only go down. L5c adds `auto_attendance_rules`; L5d adds `signin_attempts`.

`modules.json` → `attendance`: `layered` slice `logs` (namespace `["Attendance"]`, stores, services, a `moved` list); `traits_clean`: `breaks` after L5b, `auto-attendance` after L5c except its schema (time-report is not clean: scope reads employees); `exports`: `AttendanceService`, `AttendanceLog`; `api` += `today_events`, `attendance_day`, `last_device_location`.

`check_modules` must go down: schedule→attendance −3, attendance→app moves to the app trait, auto→schedule −1.

## 8. DST and time

Events are stored as site-local wall clock; kept. Stores never compute time: every time comes from the controller or the `Clock` / context closures.

**AT-o (documented, not changed):** fall back: in the repeated hour `ORDER BY event_at` can put a later event first, and lateness and the window (`strtotime` of local strings parsed as UTC) see one hour twice. Spring forward: a span across the gap is overstated by 60 minutes, including break `actual_minutes`, worked time in `report_days`, and therefore payroll.

Related: AT-n `evidence_offline_report` converts with today's offset; AT-p break escalation mixes `get_gmt_from_date(start_at)` with UTC now.

`e2e_timezone.py` stays green. Any fix (storing UTC) is a schema and visible change for the owner.

## 9. Quirks preserved (AT-; fixed in their own releases)

- **AT-a** A lock that cannot be taken (timeout, SQLite) does not refuse; the request goes on unlocked and relies on the check after the insert. Web (5 s), auto (5 s), bulk (2 s).
- **AT-b** Checks after the insert (`firstEventId`, `auto_attendance_first_kept`), the tick's `$done` and the bulk `NOT EXISTS` / existing check ignore `tl_live()`, while the checks before the insert apply it.
- **AT-c** The `time_sign_out` audit id is `lastInsertId`; after an achievement award it is the award notification's id.
- **AT-d** The photo UPDATE runs after the lock is released, its failure is ignored, not audited.
- **AT-e** Break reminder and escalation read a flag then UPDATE `WHERE id` unconditionally, so two overlapping runs can notify twice. The reminder skips the attendance-enabled check the escalation has. The escalation sets its flag before checking the employee exists.
- **AT-f** Bulk Sign Out uses the calendar date, not `attendance_day`, so overnight employees are not signed out. It closes breaks without the "Break Ended" notification.
- **AT-g** The tick has no run claim; a one-time rule's disable is unconditional.
- **AT-h** Manual add and edit take no attendance lock and have no check after the insert; they can race a live Sign In. Edit rewrites a row payroll counts. A failed INSERT audits the id of a previous insert.
- **AT-i** Reset Day deletes `time_logs` (including correction rows, leaving corrections' `applied_ids` dangling) and the breaks in two statements, no lock, no transaction. **Money.**
- **AT-j** (PLAUSIBLE, corrections slice) An approved correction inserts today's events in its transaction without the attendance lock, so two Sign Outs are possible.
- **AT-k** Multisite: `time_logs` fixed at construction, while breaks, idempotency, attempts and rules follow the switched prefix.
- **AT-l** Idempotency claims before the lock. A crashed request leaves the key "in progress" until the 24-hour cleanup. `ensure_api_idempotency_schema` can run `dbDelta` in the request path.
- **AT-m** The evidence alert fires only when the count is exactly N; two refusals at once can skip it.
- **AT-n, AT-o, AT-p** See §8.
- **AT-q** The `attendance_day` static cache is process-wide, keyed by minute and not by site, as the People memo is.

## 10. Risks and what stays in controllers

Risks:
- **Money:** every moved write creates rows payroll counts through `report_days`. Pinned by golden rows plus `e2e_payroll`, `e2e_money_safety`, `e2e_corrections`. `report_days` untouched.
- **Concurrency:** lock name text and lock order must not change; `e2e_attendance_parity` and `e2e_auto_attendance` stay green.
- `AttendanceService`'s bytes must not change.
- **`wpdb` state:** `insert_id` and `last_error` read from the same `wpdb` object at the same moment.
- Notifications stay inside the lock, as now.
- Multisite (AT-k): the `*_mu` suites.
- Hook and route names: `ews31_time_event`, `ews_break_*`, `ews_auto_attendance_tick`, `GET /attendance/today`, and Reflection names used by tests (`auto_attendance_event`, `attendance_service`).

Stays in controllers: nonces and `can()`; department scope (`time_report_may_manage`, `time_report_scope_sql`); `$_POST`/`$_SERVER` parsing; face token and photo transients; every message text, redirects, `wp_die`, CSV output, `render_template`; `get_users` for managers; computing today, yesterday and now for the tick.

## 11. Commit order (green at each step)

**L5a:** (1) harness on unchanged code: seed, reads part of `actions_attendance.php`, pages, `run.py` check, self-test; (2) `check_layers` helpers; (3) stores (read methods), fakes, storage contract; (4) `AttendanceLog` and delegators (`today_events`, `attendance_day` move to `trait-attendance`); (5) overtime read and break reads; (6) `modules.json`, baselines (enrolment), docs, CHANGELOG, version.

**L5b:** (1) write actions on unchanged code; (2) ports and adapters (unused); (3) store writes plus contract; (4) `assemble()` and `Services::attendance`; (5) lock and idempotency (definitions move to `trait-api-auth`); (6) photo UPDATE; (7) break crons; (8) docs.

Test plan: unit, phpstan, phpcs, `check_modules` (lower), `check_layers`, translations, golden. e2e: attendance, attendance_parity, auto_attendance, time_report, signin_evidence, branches, presence, presence_face, offline_screen, timezone; corrections, corrections_mu, payroll, payroll_mu, payroll_close, money_safety, overtime; reports, reports_more, insights, app_insights, dashboard_view, my_profile, achievements; api_app, speed, query_batching, schema; every `*_mu`.

## 12. Open questions for the owner (with recommended defaults)

1. **Slicing.** `layered-modules.md` planned 2 releases; this design proposes L5a reads → L5b Sign In/Out and breaks → L5c auto and manual records → L5d evidence and time report read model, corrections as own designed release L5e. *Recommended: the 4+1 split, L5a first (3.31.136).*
2. **Cadence.** May L5a and L5b ship together? *Recommended: separate; L5a is small, L5b is the concurrency core.*
3. **Quirks AT-a…q.** Move as they are and fix each separately? *Recommended: yes. Fix order: AT-i (Reset Day deletes correction rows; money), AT-h (manual records without the lock), AT-j, AT-e, AT-f, then the rest.*
4. **DST (AT-o).** Documented only. *Recommended: keep wall clock; a UTC column would be a separate schema release.*
5. **Other modules' `time_logs` reads** (achievements, dashboard, profiles, reports, payroll `:192`): leave as debt (as People Q4) or route identical-text ones through `AttendanceLog` in L5a? *Recommended: leave them; payroll excluded either way.*

Decided by the architect (technical, no visible effect; owner can overrule): idempotency definitions move to the app trait with names kept; `ews_with_lock` stays and becomes a Platform port later; `Services::attendance()` not memoized; `AttendanceService` unchanged via `AttendanceContext::assemble`; `tl_live` counted by `check_layers`; `admin_auto_attendance`'s employees JOIN stays.

## 13. Owner decisions

1. Slicing: 4+1 releases, L5a first (3.31.136); each ships alone with its own approval.
2. Quirks AT-a…q: move as they are, fix later each in its own release (order AT-i, AT-h, AT-j, AT-e, AT-f, then the rest).
3. DST (AT-o): documentation only; wall-clock storage kept.
4. Other modules' `time_logs` reads: left as known debt (payroll excluded either way).
