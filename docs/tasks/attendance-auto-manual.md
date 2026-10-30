# L5c Auto attendance and manual records (Controllers → Services → Stores): design

**Status:** design by the read-only architect on main b685bb7 (L5a and L5b merged). Part of `attendance-store.md` (§1: L5c). A move only: the same SQL text and order, locks, audits, cron events, redirects and messages. No new option, so `tests/golden/expected.json` stays empty. Quirks are moved as they are (owner decision 2 in `attendance-store.md` §13) and fixed later, each in its own release. Owner questions: §i.

## a. Scope (M = moved, trait keeps a delegator or a thin controller; C = controller kept, its SQL leaves; L = left)

**`includes/trait-auto-attendance.php`**

| Function (line) | Kind | What happens |
|---|---|---|
| `ensure_auto_attendance_schema` :14-38 | L | Schema. |
| `auto_attendance_schedule` :40-43, `auto_attendance_cron` :45 | L | Cron registration and hook target. |
| `auto_attendance_days_label` :49, `rule_applies` :56 | L | Pure. |
| `auto_attendance_working_schedule` :60-69 | M | Becomes the private `AutoAttendanceService::workingDay`; the status list moves to `AutoRules::schedulable(status)`; the function is removed. |
| `auto_attendance_event` :75-81 | M | Delegator, same name and signature (`e2e_auto_attendance.py:147` calls it through Reflection). |
| `auto_attendance_first_kept` :84, `auto_attendance_event_locked` :93-136 | M | Into `AutoAttendanceService::event`; removed (no other callers). |
| `process_auto_attendance` :139-170 | C | Reads `today`, `yesterday`, `current_time('H:i')`, calls `tick()`. |
| `auto_attendance_save` :172-202 | C | Keeps `can`, nonce, `$_POST`, validation, `working_days`, `people_active_id`, the attendance check, `wp_die` texts, `$data` (both `current_time` reads, `get_current_user_id`), `auto_attendance_schedule()`, the redirect. Write and audit go to `saveRule`. |
| `auto_attendance_toggle` :204, `auto_attendance_delete` :215 | C | Keep `can`, `$_GET`, nonce, `wp_die('Rule not found.')`, redirect. |
| `auto_attendance_bulk_sign_out` :224-294 | C | Keeps `can`, nonce, `$date`, `$now`, redirect. The rest goes to `bulkSignOut`. |
| `admin_auto_attendance` :297-319 | L | The `edit_rule` SELECT and the JOIN with employees stay (people crossing, page read model; L5d). |

**`includes/trait-time-report.php`**

| Function (line) | Kind | What happens |
|---|---|---|
| `time_report_redirect` :14, `time_report_site` :20 | L | Called through a port. |
| `time_report_scope_sql` :31, `time_report_may_manage` :39 | L | Department scope stays in the controller (employees SQL); `may_manage` is reached through a port. |
| `admin_time_report` :48-163, `admin_time_csv` :235 | L | L5d. |
| `admin_time_save` :165-212 | C | Keeps `can`, nonce, `$_POST`, `eventAt`, `$back`, the label, lat/lng/accuracy parsing, redirects. |
| `admin_time_reset` :214-233 | C | Keeps `can`, nonce, `$_POST`, the invalid and scope redirects, the final redirect. |

`ManualRecordRules`, `AutoRules`, `AutoHooks` stay; `AutoRules` gains the pure `schedulable()`.

## b. New classes and methods (`WorkforceOne\Attendance`)

**`Data/AutoRuleStore` + `WordPressAutoRuleStore`** (table `$db->prefix.'ews_auto_attendance_rules'`; no method computes a time)

| Method | Exact call | Returns |
|---|---|---|
| `dueRules(today,yesterday)` | `get_results(prepare("SELECT * FROM {$t} WHERE enabled=1 AND ((recurrence='one_time' AND run_date IN (%s,%s)) OR recurrence='weekly') ORDER BY id ASC",$today,$yesterday))` | `object[]` (`(array)` cast replaces the old `foreach(null)` warning on a failed query) |
| `disable(id,now)` | `update($t,['enabled'=>0,'updated_at'=>$now],['id'=>$id],['%d','%s'],['%d'])` | void |
| `enabledOf(id)` | `get_row(prepare("SELECT enabled FROM {$t} WHERE id=%d",$id))` | `?object` |
| `setEnabled(id,v,now)` | same `update` with formats as `disable` | void |
| `insert(row)` | `insert($t,$row)`, no formats | int (`insert_id`, 0 on false) |
| `update(id,row)` | `update($t,$row,['id'=>$id])`, no formats | bool (false only on error) |
| `remove(id)` | `delete($t,['id'=>$id],['%d'])` | int affected |
| `lastError()` | `last_error` | string |

**TimeLogStore, reused (same text):** `liveDay` (:99), `insert`+`lastInsertId`+`lastError` (manual add, no formats; the service reads `lastInsertId()` after a failed insert: AT-h), `delete` (:89), `firstEventId` (:87; the prepared `IN ('sign_out')` / `IN ('sign_in','late_sign_in')` is the same text as the old literals, the contract test asserts it through the `query` filter; this replaces the `firstOfKinds` of the first design).

**TimeLogStore, new:** `insertAuto(row): int` (the 14 formats of :129 and :266; null stays NULL); `anyOfKinds(eid,day,types): bool` (:161, same text); `openSignInsOn(date): object[]` (:230-238 verbatim, `live('s.id')`, the NOT EXISTS without the live filter); `byId(id): ?object` (:241); `signOutIdOn(eid,date)` (:249, `='sign_out'`, separate because it is not the same text as `IN ('sign_out')`); `idEmployee(id): ?object` (:175); `othersOn(eid,day,exceptId): object[]` (:185 + `live()`); `update(id,row): bool` (:198, no formats); `deleteDay(eid,day): int` (`(int)query(prepare("DELETE FROM {$t} WHERE employee_id=%d AND work_date=%s"))`).

**BreakStore:** reused `openOn` (:278) and `close`; new `idsOn(eid,day): string[]` (`get_col(prepare("SELECT id FROM {$t} WHERE employee_id=%d AND work_date=%s"))`) and `deleteDay(eid,day): int`.

**Ports:** reused `EmployeeLock`, `AuditLog`, `Platform\Clock` (the in-loop `current_time('mysql')` reads at the same moments), `Platform\People::activeEmployee`, `ScheduleDayService::day` (same text as :62), `AttendanceSite::attendanceEnabled` / `::employee`. `AttendanceSite` gains `generalLeave(day)` (via `company_leave_dates`, whose static cache must keep its query pattern), `requiresSignIn(status)`, `breakEnabled()`, `clearBreakEvents(breakId)` (the two `wp_clear_scheduled_hook` calls, reminder then escalation), `ensureBreakTable()`. New `ManualRecordSite` in `AttendancePorts` as `manual` (adapter in `trait-services.php`): `mayManage(eid)` → `time_report_may_manage`, `workingHours(eid,date)`, `workSite(eid)` → `time_report_site`.

**Services:** `AutoAttendanceService(AutoRuleStore, TimeLogStore, BreakStore, EmployeeLock, ScheduleDayService, People, AttendanceSite, AuditLog, Clock)`, memoised in `Services::autoAttendance()`: `tick(today,yesterday,nowHm)`, `event(eid,date,type,targetTime,sch,ruleId,?eventDate): array` (same shapes: `ok`, `reason` or `id`), `bulkSignOut(date,now): array{signed_out,skipped,failed}` (writes the summary audit), `saveRule(id,data,...)`, `toggleRule(id): ?int`, `deleteRule(id)`. The `$wpdb->insert_id=$new_id` restore (:133) is not kept: a provable no-op (nothing between the insert and it sets `insert_id`; the audit receives `$new_id`). `ManualRecordService(TimeLogStore, BreakStore, ScheduleDayService, People, AttendanceSite, ManualRecordSite, AuditLog)`: `save(id,employeeId,workDate,type,?eventAt,lat,lng,acc,label,actorId): array{error:?string,id:int}` (codes are the `time_error` values), `reset(eid,date): array{0:int,1:int}`. `$data` keeps its key order (the column order of the SQL). Both services are free of `current_time`, `get_current_user_id`, `$_POST`, `date(`; `strtotime` only as the old expressions used it.

## c. Order the trace must pin

**Tick:** (1) controller: today, yesterday, `H:i`; (2) `dueRules`; (3) per rule (id ASC) and per action of `AutoRules::due` (today's Sign In, today's Sign Out, then the overnight Sign Out for yesterday): `schedule.day`, status check; `generalLeave` (cached), `requiresSignIn` (cached); `event`. (4) A one-time rule: `signOutDate`; `workingDay(run)` (a second `schedule.day`, leave and type); only when the day is not missed: `done(sign_in)` (`anyOfKinds` only when the time is set and reached), `done(sign_out)` only if the first returned true; if missed and its day has not passed keep the rule, else `clock.now()` then `disable`.

**Event:** `lock(eid,5)`; `liveDay`; the four refusals (each unlocks in `finally` when the lock was taken); `clock.now()`; `activeEmployee`, `attendanceEnabled`; `insertAuto` (failure: audit `auto_{type}_failed` "name / Rule #id / lastError", return `db_error`); `firstEventId` (not first: `delete`, return `already_*`); audit `auto_{type}`; unlock.

**Bulk:** `openSignInsOn`; per candidate: `byId` (missing: failed++), `attendanceEnabled` (off: skipped++), `lock(eid,2)`, `signOutIdOn` (found: skipped++), `insertAuto` (fails: failed++), `firstEventId` then `delete` (skipped++), signed_out++, `employee` (non-active), audit `auto_sign_out`, `breakEnabled`, `openOn`, `close`, only on `===1`: `clearBreakEvents`, audit `break_auto_closed`; unlock in `finally`, even on `continue`. Then audit `auto_sign_out_bulk`, the controller redirects.

**Rule save:** controller checks, people reads, `$data`; `insert`/`update`; on false the controller `wp_die`s with `lastError`; else audit create/update, `auto_attendance_schedule()`, redirect. **Toggle:** `enabledOf` (null: `wp_die`), `clock.now()`, `setEnabled`, audit (unconditional), redirect. **Delete:** `remove`; the audit only when a row was affected; redirect.

**Manual save:** controller `$back`; service: `idEmployee` (when there is an id; not found: `not_found`), `mayManage(existing)` then `mayManage(chosen)` (refused: `scope`), `activeEmployee` (add) or `employee` (edit), `workingHours`, `check` (when null: `othersOn`, `check` again), `workSite`, `geofence`, `schedule.day`, `update` or `insert` (`user_id` = the employee's WP user, else `actorId`; `source='admin'`); on false audit `*_failed` with the id (`lastInsertId` on an add) and `lastError ?: 'Database error'`, return `save`; else audit `time_manual_add` / `time_manual_edit`.

**Reset:** controller invalid, scope; service: `tl.deleteDay`, `ensureBreakTable`, `idsOn`, `clearBreakEvents` for each break, `breaks.deleteDay`, audit `time_reset` (entity `time_log`, the entity id is the employee id).

No transactions; lock order as L5b; manual records and Reset take no lock (AT-h, AT-i); no notifications in any of these flows.

## d. Golden harness

New `tests/golden/actions_records.php` (keys `records:*`) reusing the recorder, fault filter, `$cron` and row dump of `actions_signin.php`; `exit.php` gains `golden_get()` (toggle and delete read `$_GET`). The seed adds users `atr_*`, a department manager without `manage_options` but with `ews_manage_time`, an employee in another department, today's plan (Office, Leave, no plan, a type that does not need Sign In) and a company holiday tomorrow. Rules are inserted at run time with times relative to now (clamped to 00:00, as `actions_signin`): due one-time (in = now-10 min, out = now-5 min), weekly all days, overnight yesterday→today, one-time on a Leave day (today kept, yesterday disabled), future time, disabled, attendance off (AT-x), archived employee, Sign Out only.

Cases: the tick twice (the one-time rule is disabled); `event` through Reflection for each reason; tick faults (`GET_LOCK`→`SELECT 0`, INSERT broken, `MIN(id)`→`SELECT 1`, rules UPDATE broken); rules (save insert/update, save of a deleted id (AT-y), insert/update broken, every validation `wp_die`, bad nonce, a user without the capability), toggle twice and not found, delete and delete of a missing id, the `ews_auto_attendance_tick` cron entry; bulk (open break with breaks on and off, attendance off, lock busy, INSERT broken, `MIN(id)` broken, break close → 0 affected, a future manual Sign In (AT-s), the `ews_break_*` crons before and after); manual (add ok, add on an overnight shift, `date_mismatch`, duplicate, order, invalid, archived on add, edit of an archived employee ok, `not_found`, scope refused as the manager for the record and for the chosen employee, INSERT broken (the audit id is the previous insert's, AT-h), UPDATE broken, edit of a correction row (AT-v), a manual Sign Out while a break is open (AT-u)); reset (two breaks and their crons, a correction row (AT-i), invalid, scope); then a tick after the Reset (AT-r). Rows dumped: time_logs, break_sessions, rules, audit, notifications (expected empty).

`run.py` checks: auto Sign In and Sign Out audited; a rule disabled; `bulk_sign_out=N` with `break_auto_closed`; `time_notice=saved`, `time_error=scope`, `time_reset` > 0 with `break_reset` > 0; each fault marker; `GET_LOCK`/`RELEASE_LOCK` in pairs on the tick and bulk paths. Self-test: dropping `live()` from `othersOn`, or calling `ensureBreakTable` before the DELETE, must fail the trace.

Not drivable in process: real two-process races (`e2e_auto_attendance` slow insert, `e2e_attendance_parity`); the real wait of `GET_LOCK` (faked); SQLite without a lock; WP-cron timing (the handler is called directly); a midnight between the `today` and `H:i` reads; DST transitions (`e2e_timezone`).

## e. Layers, baselines, `modules.json`

`modules.json` attendance slice `logs`: `tables += auto_attendance_rules`; `store += WordPressAutoRuleStore`; `services += AutoAttendanceService, ManualRecordService`; `moved +=` the functions that remain and must have no SQL left (`auto_attendance_event`, `process_auto_attendance`, `auto_attendance_save`, `auto_attendance_toggle`, `auto_attendance_delete`, `auto_attendance_bulk_sign_out`, `admin_time_save`, `admin_time_reset`). `traits_clean` unchanged (`auto-attendance` keeps the schema and the page's JOIN; `time-report` keeps the scope SQL). Layer baseline: `trait-auto-attendance` about 15 → 3, `trait-time-report` about 22 → 15; no `--allow-increase` expected. `check_modules`: `attendance→schedule` and `attendance→people` go down.

## f. Quirks of this slice (all moved as they are)

Known ones that apply: AT-a (busy lock goes on unlocked, 5 s and 2 s), AT-b (`done`, `first_kept` and the bulk NOT EXISTS ignore the live filter), AT-f, AT-g, AT-h, AT-i, AT-o. New:

- **AT-r (money, CONFIRMED) Reset Day is undone by a weekly rule.** A weekly rule has in 09:00 and out 17:00. An admin resets the day at 18:00. The tick at 18:05 finds no live event and records both again; payroll counts 8 h.
- **AT-s (money; the write CONFIRMED, the payroll effect PLAUSIBLE) Bulk Sign Out does not check the Sign In time.** A manual Sign In at 17:00, a bulk run at 16:00 writes a Sign Out at 16:00, before the Sign In; `report_days` sees first_in later than last_out. The auto event has a `sign_in_after_target` guard; bulk has none.
- **AT-t (money, PLAUSIBLE) With breaks off, bulk leaves open breaks Open.** Their crons stay; `report_days` skips breaks with `end_at IS NULL`, so those minutes are not deducted.
- **AT-u (money, PLAUSIBLE) A manual Sign Out leaves an open break open.** The employee still gets "Break Time Ended" after being signed out; the break is not deducted.
- **AT-v (money, CONFIRMED) Edit rewrites any row,** including a correction row (`source='correction'`) or a superseded one, outside the corrections approval; it keeps the old `source`, `ip`, `photo_key`, `integrity_status`, `location_timestamp` and `user_id` (moving a row to another employee keeps the first one's `user_id`; a face-verified "App" Sign In edited by 2 hours still shows App / Verified).
- **AT-w (CONFIRMED, low)** A manual add stores the acting admin's user id when the employee has no WordPress user; an edit resets `scheduled_status` from today's plan or to 'Not Scheduled'.
- **AT-x (CONFIRMED) A one-time rule that can never be recorded is never disabled** (attendance off, archived employee, a Sign Out with no Sign In, `sign_in_after_target`, a database error): it retries every 5 minutes while its day is due and then shows "enabled" forever; a one-time rule saved for a past date is never selected.
- **AT-y (CONFIRMED, low)** Saving a rule deleted in another tab updates 0 rows (not false): it audits `auto_attendance_rule_update` and shows "saved". Toggle reads then writes without a condition, so two toggles cancel each other, and it audits even when the UPDATE failed.
- **AT-z (CONFIRMED / PLAUSIBLE)** Reset Day reports a failed DELETE as 0 rows with no failure audit; it leaves the evidence attempts, the stored photos, achievements awarded on that Sign Out, and pending corrections for the date (adds to AT-i).
- Also noted: auto and bulk rows never run achievements, and auto Sign In is never `late_sign_in` (`report_days` re-derives lateness: not money).

## g. DST and clock

Stores never compute time: every value comes from the controller (`today`, `yesterday`, `H:i` for the tick; `date`, `now` for bulk; `updated_at`, `created_at` for rules) or the `Clock` port at the old moments (event `created_at`, rule disable, toggle). The service keeps the old `strtotime` expressions (bulk break minutes, `sign_in_after_target`). `yesterday` stays `date('Y-m-d',strtotime($today.' 12:00:00 -1 day'))` in the controller. AT-o here: spring forward, a rule at 02:30 fires at 03:00 and stores 02:30, a time that did not exist, so worked time is overstated by 60 minutes; fall back, `H:i` repeats an hour but events are not doubled (the events check); bulk break minutes across the gap are overstated by 60. Documentation only (owner decision 3).

## h. Risks, edge cases, tests

Risks: money (auto, bulk, manual and reset rows feed `report_days` and payroll: golden rows plus `e2e_payroll(_mu)`, `money_safety`, `corrections(_mu)`); the `company_leave_dates` and `schedule_type_config` static caches (reach them only through the same functions or the query count changes); `wpdb` state (`lastInsertId` read right after the failed insert, AT-h); the `finally` unlock on `continue`; the 2 s versus 5 s waits; data key order; kept names (`auto_attendance_event`, hooks `ews_auto_attendance_tick`, `admin_post_ews_auto_attendance_*`, `ews31_time_save` / `ews31_time_reset`); multisite AT-k (rules and breaks tables follow the prefix, `time_logs` is fixed).

Unit tests: `AutoAttendanceServiceTest` (every `event` reason, the `db_error` audit text, losing the first-row check deletes the service's own row, a busy lock goes on and never unlocks, an early return unlocks, the tick's skips, the overnight Sign Out to yesterday, the disable cases, every bulk counter, the close only on `===1`, breaks off (AT-t), rule save/toggle/delete); `ManualRecordServiceTest` (each error code, `othersOn` only after the first check passes, the insert-failure id (AT-h), the `user_id` fallback, 'Not Scheduled', reset order and counts). Contract (`e2e_attendance_store.php`): each new method against the old SQL with the text captured through the `query` filter (including `firstEventId` against the literal IN), NULLs under the 14 formats, the no-format WHERE `id='5'`, `deleteDay` counts, the rules table following the prefix. Golden (§d), then e2e `auto_attendance`, `time_report`, `attendance(_parity)`, `corrections(_mu)`, `payroll(_mu,_close)`, `money_safety`, `reports`, `timezone`, `schema`, `speed`, `query_batching`, every `*_mu`; plus unit, phpstan, phpcs, layers, modules, translations.

## i. Open questions for the owner

1. New quirks AT-r…z: move as they are (decision 2) and add them to the fix queue? *Recommended: yes; the money ones AT-r, AT-u, AT-v, AT-s, AT-t right after AT-i and AT-h.*
2. AT-v direction (for its later fix release): should the time report refuse edits of correction rows and superseded rows and send the admin to Corrections? *Recommended: yes, in the AT-h fix release.*
3. AT-r direction (later fix release): Reset Day writes a per-day skip that the tick honours. *Recommended.*
4. One release for both parts (auto + manual) as 3.31.138? *Recommended: yes; they share the stores and neither is an approval type.*

Decided by the architect (technical, no visible effect): `firstEventId` is reused for the post-insert check; the `insert_id` restore is dropped as a no-op; `dueRules` returns `(array)`; `ManualRecordSite` is a new port with the scope check staying in the controller code; `AttendanceSite` gains five methods; `auto-attendance` is not `traits_clean`.

## j. Owner decisions
1. One release for both parts: 3.31.138.
2. The new money quirks (AT-r, AT-s, AT-t, AT-u, AT-v) are moved as they are and join the fix queue right after AT-i and AT-h; the direction of each fix (AT-v: refuse edits of correction rows; AT-r: a per-day skip the tick honours) is asked when its release is designed.
