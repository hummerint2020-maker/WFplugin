# Performance 3 (3.31.151): the schema upgrade's lock, the auto-attendance tick, the substitutes sync

Owner request 2026-11-16: the remaining items of the performance review (`docs/tasks/performance-1.md:72`). Binding rule: the company running today is not affected (same pages, same writes, same reminders and results; golden harness: pages identical, no more queries per page, declared action traces only).

Paths are under `workforce-one/` unless they start with `tests/` or `docs/`. Claims are marked VERIFIED (read in the code) or ASSUMED.

**The three premises, checked**

| Item | Premise | Verdict |
|---|---|---|
| 1 | The schema upgrade has no lock | Right. One step can also write a duplicate data row. |
| 2 | The tick re-checks finished rules | Right for weekly rules (about 4 queries per finished action per tick). Wrong for one-off rules whose date passed: the SQL already limits them to today and yesterday. |
| 3 | The substitutes sync runs inside any request | Right about where (every request type), weaker about how often: at most one request per minute, and only with the feature on. The gain is modest. |

---

## 1. The schema upgrade has no lock

### Today (VERIFIED)
- The plugin object is built when the plugin file loads, on every request except the wp-admin activation request (`employee-schedule-manager.php:380-383`). The constructor calls `maybe_upgrade_schema()` (`employee-schedule-manager.php:125`).
- `includes/trait-schema.php:350-397`: it returns when `ews_schema_version` equals the target (`:353`) or the `ews_schema_retry` transient exists (`:355`). Otherwise it runs `activate()` or `base_tables()` (`:360-361`), 36 `ensure_*` steps (`:373-385`), `repair_stale_location_integrity()` (`:386`) and `SchemaCheck::missing` (`:388`). It then either writes `ews_schema_failed` plus the 10-minute transient (`:391-392`) or the version (`:396`).
- A plugin update through the WordPress updater fires no activation hook, so every request arriving before the first one finishes passes `:353` and runs all of it at once.
- The steps are check-then-change, not atomic: `SHOW COLUMNS` then `ALTER … ADD COLUMN` (`includes/trait-schema.php:18`, `:40`, `:49-59`, `:143-185`). The loser of a race gets "Duplicate column / key name" in `$wpdb->last_error`, so `$failed` is set, the red admin notice shows (`:418-428`) and the retry waits 10 minutes although the schema is complete.
- One step can leave duplicate data: `includes/trait-schema.php:193-194` reads "is there an Annual Leave type" and inserts it if not. Two runs at once on a site without that row insert two. It is rare, because it needs the row to be missing.
- `repair_stale_location_integrity()` (`:432-447`) sets its flag only at the end. Two runs both do the work, with the same result.
- While the version is not current, request paths run their own lazy `ensure_*` (every `if($this->ews_schema_is_current())return;` guard, e.g. `includes/trait-locations.php:62`, `includes/trait-notifications.php:100`). This is how a request on the old schema gets what its path needs today.
- An existing lock helper fits: `ews_with_lock($scope, callable $fn, $busy=null, $wait=10)` at `includes/trait-attendance.php:169-183`.
  - It uses `GET_LOCK`, named `wfo_<md5(DB_NAME|prefix)[0:12]>_<scope>`, so it is per site and per multisite blog (the prefix carries the blog id).
  - The lock is released in `finally`, and by MySQL when the connection closes.
  - On SQLite (`DB_ENGINE`) or where `GET_LOCK` returns NULL, `$fn` runs unlocked.
  - It is used by payroll, leave, advances and shift history, and is listed in `docs/architecture/modules.json:394`. ASSUMED: that list is the allowed "api" list, so calling it from the schema trait adds no module crossing; confirm with `tests/check_modules.py`.
- The add_option mutex (`includes/trait-shift-history.php:303`) and `OnceClaim` (`includes/trait-services.php:139-148`) are one-shot claims with no expiry. They are not suitable: a dead holder would block the upgrade forever.

### Change
Only `includes/trait-schema.php`.

1. Split `maybe_upgrade_schema()`:
   - The body from `:356` to `:396` becomes `private function upgrade_schema_locked($target)`, unchanged.
   - `maybe_upgrade_schema()` keeps the two early returns (`:353`, `:355`), then calls `$this->ews_with_lock('schema', function() use ($target) { … }, 'busy', (int) apply_filters('ews_schema_lock_wait', 10))`.
2. Inside the lock, before any DDL, read again fresh, because the request we waited for has usually just finished:
   - `wp_cache_delete` for `ews_schema_version`, `alloptions` and `notoptions` (group `options`), and for the two transient option keys.
   - If `$this->option('ews_schema_version') === $target`, return. `ews_schema_is_current()` (`:346`) reads the option on each call, so the rest of this request sees the new schema and skips every lazy `ensure_*`.
   - If `get_transient('ews_schema_retry')` is set, return: the holder failed, and the 10-minute rule stands.
   - Otherwise run `upgrade_schema_locked($target)`.
3. A request that does not get the lock in the wait (`'busy'`) returns and carries on exactly as a request does today while another is mid-upgrade. It writes no retry transient, no `ews_schema_failed` and no version, and its lazy `ensure_*` paths are unchanged. It tries again on its next request.
4. No new SQL: the lock SQL is the existing helper's, and the fresh read is a cache delete plus `get_option`.

### Why nothing changes
- A normal request (version current) returns at `:353` before the lock: 0 added queries. VERIFIED.
- The upgrade is the same code in the same order. The only difference is that one request per site runs it at a time.
- A waiting request is no slower than today, when it would have run the whole upgrade itself.

### Edge cases
- **Holder dies** (fatal, timeout, killed): MySQL frees the lock with the connection. There is no expiry to manage and no stale lock. The next request upgrades.
- **Holder fails a step**: as today (`:391-392`). Waiters see the transient after the fresh read and skip.
- **Upgrade longer than the wait**: waiters go on with the old schema and lazy `ensure_*`, as today. The residual collision (a lazy ALTER against the holder's) remains possible only then, and heals at the 10-minute retry, as today.
- **WP-CLI and cron**: same constructor, same path. `wp plugin activate` builds the object while including the file; the tables are missing, so `activate()` runs inside the lock (`:360`).
- **The wp-admin activation request**: the object is not built (`employee-schedule-manager.php:380`). The static `activate()` stays unlocked; it only runs dbDelta and `add_option` and never writes the version.
- **Multisite**: the lock name includes the blog prefix, and each site upgrades on its own first request, as today.
- **SQLite, Galera across nodes, proxies without `GET_LOCK`**: the helper runs the function unlocked, which is today's behaviour exactly.
- **MySQL before 5.7.5**: a second `GET_LOCK` on the same connection releases the first. ASSUMED: no upgrade step takes another named lock. The only `GET_LOCK` sites are `includes/trait-attendance.php:175` and `src/Attendance/Data/WordPressEmployeeLock.php:28`, and neither is reached from an `ensure_*`.
- **Several `new EWS_Manager_V31_1()` in one process** (`tests/schema_check.php`): sequential, and released each time.

### Tests
- **e2e, in `tests/e2e_schema.py` (MySQL):**
  - Another connection holds the lock; the version is deleted and the object built with the wait filter at 1. Expect no CREATE or ALTER (the query log `tests/schema_check.php` already uses), version still empty, no retry transient, no `ews_schema_failed`.
  - After release, the upgrade runs and `IS_FREE_LOCK` is 1. It is also 1 after a failed upgrade (the existing `break_writes` case).
  - Two `wp eval` upgrades in parallel after dropping the Performance 1 indexes: no database errors, version set, no `ews_schema_failed`.
  - Same with the Annual Leave type removed (restore it after): exactly one row.
  - A waiter that gets the lock after the holder finished runs no DDL.
- **Existing checks stay green:** "a second upgrade changes nothing", the 3.31.92 failure block, `tests/e2e_hr_letters_more.py:211-226`, `tests/e2e_timezone.py:135`, CI `ci.yml:106` and `:425`.
- **Golden:** nothing to declare, because the snapshot's version is current.

### Risks
- A request right after an update can wait up to 10 seconds, bounded.
- The fresh read must really bypass the cache, or a waiter repeats the upgrade serially. That is harmless and covered by the test above.

---

## 2. The auto-attendance tick re-checks finished rules

### Today (VERIFIED)
- Every 5 minutes (`includes/trait-auto-attendance.php:40-47`, `:70-75`) the tick runs `AutoAttendanceService::tick()` (`src/Attendance/AutoAttendanceService.php:65-88`).
- One SELECT reads all enabled weekly rules plus one-time rules of today or yesterday (`src/Attendance/Data/WordPressAutoRuleStore.php:20-23`).
- `AutoRules::due()` (`src/Attendance/AutoRules.php:38-51`) returns an action whenever its time is at or before now. Once 09:00 has passed, the 09:00 Sign In is "due" on every tick until midnight (and an overnight Sign Out all the next day).
- Each such action, every tick, costs 4 queries and writes nothing:
  - `workingDay()` (`:54-62`): one schedule SELECT (`src/Schedule/Data/WordPressScheduleDayStore.php:30-34`). The holiday read is memoised per process (`includes/trait-work-time.php:273-280`).
  - `event()` (`:96-104`): `GET_LOCK`, the `liveDay` SELECT (`:112`), a return of `already_signed_in` (`:113`) or `already_signed_out` (`:116`), then `RELEASE_LOCK`.
- A weekly rule with both times, after its Sign Out, costs 8 queries per tick. At 200 such rules that is about 1,600 queries every five minutes through the evening. ASSUMED: how many rules the company has.
- **Premise check.** One-off rules whose date passed are not re-read: the SQL limits them to today and yesterday, and they disable themselves (`:74-86`). Two quirks stay as they are (see open question 4):
  - A one-time rule that never completes (for example a Sign Out with no Sign In) is re-evaluated all of the next day (`:79`, 2-3 queries per tick), then falls out of the query still enabled.
  - An overnight one-time rule on a missed day also stays enabled forever (`:84`).

### Why a "done today" memo is rejected
A stored memo (option, column or transient) would change results:
- The check is on live rows (`src/Attendance/Data/WordPressTimeLogStore.php:24-28`, `:36-39`). If a correction supersedes the automatic Sign In at 10:00, today's next tick records it again. A memo would not.
- A Sign Out refused as `no_sign_in` is recorded later the same day once a Sign In appears (a manual record or a correction). The same goes for a day whose schedule is fixed later, an employee re-activated, or attendance switched back on.
- Rule edits mid-day, the day rollover and timezone changes would each need invalidation.

### Change: one read per tick instead of four per finished action
- **`src/Attendance/Data/TimeLogStore.php` and `WordPressTimeLogStore.php`**: new `liveInOutOn(array $days, array $employeeIds): array`. It returns `employee_id, work_date, event_type` for `work_date IN (…) AND employee_id IN (…) AND event_type IN ('sign_in','late_sign_in','sign_out')` plus the same `live()` predicate as `liveDay`. It is served by `employee_date_type_at`.
- **`src/Attendance/AutoAttendanceService.php::tick()`**:
  1. Read `dueRules()` as today. Compute `AutoRules::due()` for every rule first (pure) and keep the list per rule.
  2. If no rule has a due action, make no extra query. Otherwise make one `liveInOutOn([$today, $yesterday], employee ids with a due action)` and build a set keyed `employee|work_date|kind`, where kind is `in` (sign_in or late_sign_in) or `out`.
  3. Loop over the rules in the same id order. Skip an action when its key is in the set: Sign In when `in` exists, Sign Out when `out` exists. Every other action takes today's path unchanged (`workingDay()`, then `event()` under the lock with its own fresh read).
  4. The one-time "done / disable" block (`:74-86`) is untouched.
- `event()`, `bulkSignOut()`, `AutoRules` and the trait are untouched.

### Why nothing changes
- A skipped action is exactly one that returns without writing today. VERIFIED:
  - A live Sign In returns `already_signed_in` at `:113`.
  - A live Sign Out returns `no_sign_in` (`:115`) or `already_signed_out` (`:116`).
  - All three return before any insert or audit row. `workingDay()` only reads.
- The set is only a shortcut for "already there". Absence from the set proves nothing and changes nothing, since the old path still decides under the lock.
- The skip uses no clock, so there is nothing to invalidate:
  - **Rule edited mid-day**: the row is there, so today refuses it too; a rule moved to another employee has a different key.
  - **DST and site timezone**: `due()` is untouched.
  - **Cron late by hours**: a Sign In and a Sign Out due in the same tick are both absent from the set, so both are recorded on the old path, as today.
  - **Two rules for one employee**: the second is refused under the lock, as today.
  - **After a bulk Sign Out** (`:165`): the rule's Sign Out is in the set and is skipped; today it is `already_signed_out`.
  - **Superseded row**: it is not live, so it is not in the set and is recorded again, as today.

### One declared difference
The set is read at the start of the tick. A row superseded or deleted during that same tick (milliseconds) is re-recorded by the next tick instead of this one. `event_at` is the same (the rule's time); `created_at` is 5 minutes later.

### Queries
- Finished action: 4 per tick becomes 0.
- Tick with any due action: +1.
- Tick with nothing due: unchanged.
- The first tick after a time passes: +1, once.

### Not in this release
A weekly rule on a day off still costs one schedule SELECT per due action per tick (`workingDay()` returns null). Batching it would mean feeding `event()` a different schedule row object. It is the same idea and could follow later.

### Tests
- **Unit (`tests/unit/AutoAttendanceServiceTest.php`, fake stores):**
  - A finished Sign In or Sign Out makes no lock, no `liveDay` and no schedule read.
  - No due action means no prefetch.
  - Sign In and Sign Out due in one late tick are both recorded.
  - A Sign Out with no Sign In still goes through `event()` and is refused as `no_sign_in`.
  - A late_sign_in counts as `in`.
  - A superseded Sign In is recorded again.
  - Yesterday's overnight Sign Out is skipped when present.
  - One-time disable is unchanged.
- **e2e (`tests/e2e_auto_attendance.py`):** the existing ticks stay green. Add:
  - Tick three times after both times: the same time-log and audit rows as after the first, and a small fixed query count for the later ticks.
  - A correction supersedes the automatic Sign In: the next tick records it again.
  - Bulk Sign Out then a tick: nothing new.
  - A rule edited to an earlier time after the Sign In: nothing new.
  - A Sign In added by a manual record after a refused Sign Out: the next tick signs out at the rule's time.
- **Storage contract (`tests/e2e_attendance_store.php`):** `liveInOutOn` returns what `liveDay` returns for each employee and day, for the three kinds.
- **Golden (`tests/golden/actions_records.php:142-165`):** the SQL of `tick_1`, `tick_2` and the `tick_fault_*` traces changes (one prefetch more, the per-action reads fewer). Declare them in `tests/golden/expected.json` "actions" with the reason. Rows and returns must be identical.

### Risks
- The `IN` list of employee ids on a very large company: chunk at 500.
- A wrong key (work date against event date for the overnight Sign Out): the key must be `work_date`, as `event()` receives `$a['work_date']`. Covered by the overnight unit case.

---

## 3. The substitutes sync inside any request

### Today (VERIFIED)
- Registered on `init` priority 30 for every request type: front end, wp-admin, REST, AJAX, cron, login (`employee-schedule-manager.php:245`).
- `substitutes_maybe_sync()` (`includes/trait-substitutes.php:85-91`) returns when the feature is off. The feature is off by default, so then the cost is 0.
- With the feature on it runs at most once a minute, guarded by the autoloaded option `ews_substitutes_next_sync`. The guard read is free.
- The one request per minute that syncs pays:
  - one options UPDATE (which also rewrites the autoload cache);
  - `sync()` (`src/Approvals/SubstituteService.php:94-120`): three SELECTs (`linkedToLeave`, `current`, `active`; `src/Approvals/Data/WordPressSubstituteStore.php:26-40`);
  - one leave-status read per leave-linked row;
  - per active row, an employee read and the UPDATE-with-subquery over the steps (`src/Approvals/Data/WordPressApprovalRequestStore.php:234-240`).
- That is 4 or more queries about 1,440 times a day, almost always finding nothing to do.
- Other callers:
  - the hourly cron `substitutes_tick` (`includes/trait-substitutes.php:82`; `employee-schedule-manager.php:244`, `:287`);
  - the wp-admin Substitutes page (`:286`);
  - a shutdown sync after every decision, withdrawal or close (`SubstituteService.php:85`, `ApprovalEngine.php:394`, `:415`) and after every leave status write (`includes/trait-leave.php:166`, `:198`, `:211`; a withdrawal at `:628` goes through `approval_close`);
  - `create()` syncs itself (`SubstituteService.php:213`).
- Routing already counts a scheduled row whose time came and ignores one whose end passed, whatever its status says (`WordPressSubstituteStore.php:20-24`). It is used for new requests, the next level and a reassignment (`ApprovalEngine.php:180`, `:346`, `:440`). New requests never depended on the sync.
- What the sync adds at a start or end: the status change, the audit row, the notification, and moving the levels already waiting (to the substitute at the start, back at the end; `:130-164`).
- No code changes a row's `starts_at` or `ends_at` after insert (store interface, `src/Approvals/Data/SubstituteStore.php`).
- Moving the sync to the cron only is rejected: an hourly cron is too coarse (the code's own comment, `:84`), and a site with a dead WP-cron works today through page loads.

### Change: a "next change at" guard
A sync still happens at the first request after a start or end. A page between two changes pays nothing.

1. **New autoloaded option `ews_substitutes_due_at`**
   - A local `Y-m-d H:i:s` string, the same clock and string comparison `Substitutes::transition()` uses, so DST and timezone changes behave as the sync itself does.
   - `''` means no change is pending. Absent means never computed.
   - Registered in `src/Settings/Options.php` beside `:118` (internal).
2. **`src/Approvals/Substitutes.php`**: new pure `dueAt(array $rows, string $now): string` over the scheduled and active rows as they stand after the pass.
   - Scheduled and not started: `starts_at`.
   - Active (including one just started): `ends_at`.
   - A row whose transition was attempted and did not return 1 (a database error, or a lost race): `$now` plus 60 seconds, so it is tried again in a minute, as today.
   - Return the minimum, or `''`.
3. **`SubstituteService::sync()`**: collect those outcomes in the `current()` loop (`:108-117`).
   - When `$now` was not supplied by the caller, call a new port `SubstituteDue::set(string $at)`, added to `SubstitutePorts` (`src/Approvals/SubstitutePorts.php`).
   - The adapter in `includes/trait-services.php` is `update_option('ews_substitutes_due_at', $at, true)`. WordPress writes nothing when the value is unchanged, so a decision's shutdown sync normally adds no query.
   - Only tests pass a `$now`. VERIFIED: every production caller passes none.
4. **`substitutes_maybe_sync()`** (`includes/trait-substitutes.php:85-91`):
   - Feature off: return.
   - `$due = get_option('ews_substitutes_due_at', null)`.
   - Absent: honour the old throttle once (`(int) get_option('ews_substitutes_next_sync', 0) > time()` returns), so the first sync after the update happens when the old code's would.
   - `''` or `$due > current_time('mysql')`: return, with 0 queries.
   - Otherwise `substitutes_sync()`.
   - `ews_substitutes_next_sync` is no longer written. It stays registered so uninstall removes it and a rollback finds it.
5. **`includes/trait-features.php:157`**: when the Substitutes switch or its settings are saved, `delete_option('ews_substitutes_due_at')`. The next page then syncs, as it does within a minute today after switching on.
6. The cron, the shutdown sync, `create()` and the wp-admin page call are unchanged. `substitute_end` (`:267`) finishes without a sync and leaves an early stale value: one wasted sync, harmless.

### Why nothing changes
- The stored value can be early (harmless) but is late only by the race below.
- A boundary appears only through `create()` or the pending-to-scheduled step, and both are inside a sync that rewrites the value.
- Every other trigger of today's poll is covered by a sync that still runs:

| Trigger | Still covered by |
|---|---|
| Leave approved, rejected, cancelled or withdrawn | Shutdown sync (the four writers listed above). ASSUMED: there is no fifth leave-status writer; I grepped `includes/` and `src/`. |
| A level moved up by a deadline | Happens only in the hourly cron, and `substitutes_tick` runs right after it in the same event (`employee-schedule-manager.php:237`, then `:244`) |
| Manager changed | Routed at `ApprovalEngine.php:440` |
| Decision by a substitute or the manager | Shutdown sync; the count at `:82-84` is untouched |

### Declared differences
- **A start or end is handled at the first request after its time**, not at the first request after its time that is also 60 seconds or more after the previous sync. It is never later and up to 59 seconds earlier (notification, audit row, moved levels). See open question 1.
- **Cases only the minute poll catches today** wait for the next sync of any origin (a decision, a leave decision, a substitute change, the hourly cron). The one found: a substitute without a WordPress user, or inactive, who gets one later; `takeWaiting` returns 0 until then (`:125-126`). It can be made exact by deleting the option where an employee's user link or active flag is saved. See open question 2.
- **A race**: sync A reads the rows, `create()` B inserts and writes an earlier value, then A writes its later one (a window of milliseconds). B's start is then handled by the next sync of any origin, at worst the hourly cron. New requests are routed correctly throughout.

### Queries
- Ordinary page with the feature on: 0, as today inside the minute.
- The once-a-minute cost (1 options UPDATE plus 3 or more SELECTs) is gone.
- A syncing page: the same count as today (a sync plus one options write).

### Tests
- **Unit:**
  - `SubstitutesTest`: `dueAt` (none, scheduled, active, both, failed attempt, back-to-back rows).
  - `SubstituteServiceTest` with a fake port: set after a start, an end, a create, and a leave-linked row being scheduled; not set when `$now` is given; a failed transition gives `$now` plus 60 seconds.
- **e2e (`tests/e2e_substitutes.py`; add the new option to the cleanup at `:23`):**
  - HR sets a substitute starting in 2 minutes: the option equals `starts_at`. A page load before that makes no query on `ews_approval_substitutes`. Move the row's times into the past and reset the option to that time: the next ordinary page load starts it (status, moved level, notification, audit). Then the same for the end.
  - No rows: the option is `''` and pages never sync.
  - Features save deletes the option.
  - Update path: option absent and the old throttle in the future gives no sync; throttle past gives a sync.
  - A decision by the substitute still counts and syncs (existing `:197-200`).
  - Leave-linked: approve, reject, cancel and withdraw still follow (existing checks).
- **Golden:**
  - Pages: the same HTML and no more queries. The update-path rule keeps the first page equal even when the snapshot holds a live throttle.
  - Declare in `expected.json` the traces of `tests/golden/actions_approvals.php` whose SQL gains the option write (`sub2_sync_start`, `sub2_sync_leave_linked`, `sub2_create*`, and any deferred-sync trace the harness reports). Rows and returns must be identical.
  - `sub2_sync_end_*` pass a `$now` and must not change.

### Risks
- An undiscovered path that relied on the minute poll (bounded by the hourly cron and by every decision).
- A site with a dead WP-cron loses that bound, but time-driven starts and ends still work from page loads.

---

## Data model and upgrade path
- No table, column or index; `ews_schema_target()` does not move.
- One new option: `ews_substitutes_due_at` (autoloaded, internal, created on first sync).
- `ews_substitutes_next_sync` is kept, read once on the update path, and no longer written.
- Rollback to 3.31.150 works with no cleanup: the old code ignores the new option and resumes its minute throttle.
- Two filters for tests only: `ews_schema_lock_wait` (new), no setting behind it.

## A switch?
No switch is recommended. All three changes are internal, have no UI, and each falls back to today's behaviour by construction: no lock means unlocked; an action not in the set takes the old path; an absent option means the old throttle. A switch would double the test matrix for code that must be identical either way. The owner's rule is met by the golden harness and the declared differences above.

## Open questions for the owner
1. **Substitutes timing.** A start or end, with its notification, can happen up to 59 seconds earlier than today, never later. Accept?
2. **Substitutes safety net.** The rare cases only the minute poll catches today (for example a substitute who gets a WordPress account after being picked) would wait for the next decision or the hourly job instead of one minute. Options:
   - accept;
   - make that case exact with one more option delete on employee save;
   - keep a slower page poll (say every 10 minutes) on top.
3. **Schema wait.** Right after a plugin update, a request may wait up to 10 seconds for the upgrade another request is running, instead of running it too. Accept 10 seconds?
4. **Auto-attendance quirks found, not changed.** A one-time rule that never completes (a Sign Out with no Sign In, or an overnight rule on a day off) stays "enabled" on the rules page forever after its day. Leave it, or fix it in a later release? Fixing it changes what the page shows.
5. **Auto-attendance, day off.** The remaining per-tick read for a weekly rule on a day off (one query per rule per tick) could be batched later. Wanted?
6. **Release shape.** One release, 3.31.151, for the three, or three small ones so each can be rolled back alone?

## Owner's answers (2026-11-16)

1. Substitutes timing: accepted (a start or end up to 59 seconds earlier, never later).
2. Safety net: make it exact: saving an employee's WordPress user link or active flag deletes `ews_substitutes_due_at`, so the next page syncs.
3. Schema wait: 10 seconds, accepted.
4–5. Not asked now (later releases).
6. One release, 3.31.151.
