# Design: layered modules ("move the module you touch")

**Status:** design only, written by the read-only architect on 3.31.126 (68e829f; PR #15 has since been merged). It is meant to be saved as `docs/tasks/layered-modules.md`. Nothing here is decided for the owner. §7 lists the open questions.

---

## 0. What exists today (measured)

- **One object.** `EWS_Manager_V31_1` (`employee-schedule-manager.php:102-103`) uses 76 traits. Its constructor (`:108` onwards) registers every hook as `[$this,'method']`. Table names are properties on the manager (`$this->schedule`, `$this->employees`, … `:105`).
- **SQL everywhere.** About 555 `global $wpdb` across `includes/`. 22 trait files query `ews_schedule` directly through `$this->schedule`. The heaviest are `trait-core.php` (7), `trait-swap.php` (6), `trait-leave.php` (4) and `trait-branches.php` (4).
- **Request caches are static properties on the manager** (`trait-core.php:8-24`), for example `$ews_people_shift_memo`, `$ews_shift_for_employee_cache`, `$ews_employee_location_cache` (3.31.126) and `$ews_shift_periods_table` (3.31.126). They are cleared by `invalidate_*_runtime_cache()` (`:26-50`).
- **Two precedents to copy:**
  - **Notifications inbox.** `src/Notifications/Inbox.php` (pure use cases) → `InboxStore.php` (interface) → `WordPressInboxStore.php` (the only `$wpdb`). There is one instance per table prefix, held on the manager (`trait-notifications.php:19-22`). It is guarded by `tests/check_notification_inbox_boundary.py` and `tests/e2e_notification_inbox.php`.
  - **Attendance.** `src/Attendance/AttendanceService.php` takes an `AttendanceContext`, which is a bag of closures built in `trait-attendance.php:84-92`. Parity was proven with `tests/e2e_attendance_parity.py`, a normalised trace of the rows each action writes.
- **Templates already never query.** `grep '$wpdb|get_results' templates/` finds nothing. They do still call `$this->…`.
- **Tooling.** Composer is dev-only (phpstan level 2, phpunit 11, phpcs PHP 7.4). The runtime uses `src/autoload.php` (PSR-4, no Composer). There is no golden harness in CI. 3.31.126 compared 13 pages by hand.

---

## 1. Target shape for one module

### 1.1 Folders and namespaces (example: Schedule)

```
workforce-one/src/Schedule/
  WeekLock.php, ScheduleDays.php, SwapRules.php …   Rules (exist today: pure, static, unit-tested)
  ScheduleDayService.php, WeekLockService.php        Services (use cases)
  Data/
    ScheduleDayStore.php                              Store interface (what the service needs)
    WordPressScheduleDayStore.php                     the only class with SQL for these tables
    ScheduleSchema.php                                (later) the module's CREATE TABLE, as InboxSchema
  Hooks.php                                           (exists) hook registration
includes/trait-schedule-*.php, trait-week-lock.php   Controllers: thin, same method names as today
src/Platform/                                         shared kernel ports + the container
  Services.php, Clock.php, AuditLog.php, Notifier.php, Transaction.php, Actor.php
  WordPress/ (WordPressClock, LegacyAuditLog, LegacyNotifier, WordPressTransaction)
```

**Naming:**
- `<Thing>Store` is the interface and `WordPress<Thing>Store` the implementation. Keep the inbox's word "Store", not "Repository", so the codebase uses one word.
- `<Thing>Service` is a use case.
- `<Thing>Rules` (or the existing names) are pure rules.
- Result objects are `<Thing>Result` where one is needed (as `AttendanceResult`).

### 1.2 What each layer may and may not do

| Layer | May | May not |
|---|---|---|
| **Controller** (trait method, later `src/<M>/Web/*Controller.php`) | nonce, `can()`, read and sanitise `$_POST`/`$_GET`, call one service, translate result codes to text, `wp_safe_redirect`, `wp_die`, `render_template` | `$wpdb`, table names, business decisions, loops that query |
| **Service** | call its module's Rules, its own Stores, Platform ports, and other modules' *public* services or interfaces; return arrays or result objects with codes | `global`, `$wpdb`, superglobals, `echo`/`print`/`exit`/`wp_die`/redirects/headers, `__()` text for the screen, `add_action`, `EWS_Manager_V31_1`, `get_current_user_id()` or `current_time()` (the actor and the clock come in as arguments or ports) |
| **Store** (WordPress implementation) | `$this->db` (`\wpdb`) on **its module's tables only**; request-local caches of what it reads; `prime(ids)` batch reads | another module's tables, `START TRANSACTION`/`COMMIT` (the Transaction port owns those), notifications, audit, `EWS_*`, `use WorkforceOne\<OtherModule>` |
| **Rules** | pure PHP | anything WordPress (today's convention, unchanged) |
| **Templates** | print the variables they are given; formatting helpers | queries, options, services. A ratchet counts `$this->` calls in templates, which may only go down. |

**Cross-module reads.** When a service needs another module's data, it gets it through that module's service or interface listed in `modules.json` (`exports`), never through that module's store. Reports and shell, which own no tables, get **read models**: `src/Reports/Data/*Query.php` classes that are allowed to join several tables. Each join is listed in `modules.json` under `reads` and counted by the check, so the list can only shrink.

### 1.3 How a trait shrinks

Every public hook callback, REST handler (`src/Api/Routes.php` names methods on the manager), `modules.json` `api` function, and private method that e2e tests invoke through `ReflectionMethod` (for example `e2e_speed.py:83,123,143`) **keeps its name and signature**. Its body becomes a delegation:

```php
// trait-core.php (unchanged name and return shape; the body moved)
private function save_att_if_unchanged($eid,$date,$status,$expected_status){
    return $this->services()->scheduleDays()->saveIfUnchanged((int)$eid,(string)$date,(string)$status,
        $expected_status===null?null:(string)$expected_status, get_current_user_id());
}
```

**Two stages:**
1. **Stage 1, every move.** The trait becomes controllers plus one-line delegators.
2. **Stage 2, optional, once a module is fully layered.** The controllers move to `src/<M>/Web/`. They are registered from `src/<M>/Hooks.php`, which already exists for Schedule, Attendance, Approvals and Leave. The trait keeps only the forwarding methods that other modules and tests still call, and they are deleted as their callers move.

### 1.4 Wiring: a small explicit container (PHP 7.4, no Composer)

`src/Platform/Services.php`:
- It has one explicit, lazily-built method per service. There is no reflection autowiring and no runtime dependency.
- The legacy kernel (`notify`, `audit`, `can`, the people api) reaches services only as **closures wrapped in port objects**, as `AttendanceContext` already does. A service therefore never sees the manager.

```php
final class Services {
    private $db; private $legacy; private $made = [];
    public function __construct(\wpdb $db, LegacyPorts $legacy) { $this->db = $db; $this->legacy = $legacy; }
    public function scheduleDays(): ScheduleDayService {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new ScheduleDayService(
            new WordPressScheduleDayStore($this->db), $this->legacy->notifier(), $this->legacy->audit(), $this->legacy->people());
        return $this->made[__FUNCTION__];
    }
}
```

The manager holds one container per table prefix, exactly as the inbox does:

```php
private $ews_services = [];
private function services(): \WorkforceOne\Platform\Services {
    global $wpdb; $k = $wpdb->prefix;
    return $this->ews_services[$k] ?? ($this->ews_services[$k] = new Services($wpdb, new LegacyPorts($this)));
}
```

- **Prefix-keyed.** `switch_to_blog()` gets its own stores and caches, and switching back reuses them, the same as `e2e_notification_inbox.php` proves for the inbox.
- **Inside the plugin only.** `LegacyPorts` is the only class besides the manager that holds `$this`. It lives in `includes/` (not `src/`), so check rule L4 keeps `src/` free of the manager.
- **PHP 7.4 limits.** No constructor promotion, `match`, `readonly`, enums, union types, `mixed`, nullsafe operator or named arguments. phpcs already enforces this.
- **API and cron.** The mobile API (`src/Api`) gets the same container through `Api\Hooks::register($this)`. Cron callbacks stay `[$this,'week_lock_tick']` and delegate.

---

## 2. Enforcement in CI

### 2.1 `tests/check_layers.py` (new; generalises `check_notification_inbox_boundary.py`)

**Driven by `modules.json`.** Each module gets an optional `"layered"` block listing the **slices** moved so far. A module moves in slices, as the inbox did:

```json
"schedule": { …, "layered": [
  {"slice": "days", "tables": ["schedule", "company_calendar"],
   "store": "src/Schedule/Data/WordPressScheduleDayStore.php",
   "services": ["src/Schedule/ScheduleDayService.php", "src/Schedule/WeekLockService.php"],
   "moved": ["save_att_if_unchanged", "save_att", "today_schedule_for_employee", "schedule_for_employee_date",
             "company_calendar_events", "week_lock_tick", "week_lock_notify"],
   "traits_clean": ["week-lock"]}],
  "exports": ["WorkforceOne\\Schedule\\ScheduleDayService"] }
```

**Rules.** Each rule has a built-in self-test (as the inbox check has), so a loosened regex cannot pass silently.

- **L1: a layered table is queried only by its store.** Count, per file, the SQL references to a layered table (the same detection as `check_modules.py`: `PROPS`, `HELPERS`, `LITERAL`, extended to `src/`, `templates/` and the `{$this->table}` form) everywhere except its `store` and `*Schema.php`.
  - These counts go into `docs/architecture/layer-baseline.json`. They **may only go down**, and a new file is a failure.
  - On day one `$this->schedule` appears in 22 trait files, so this is a ratchet, not a ban. Each touched module's release lowers it.
- **L2: services and rules are pure of transport and DB.** In the listed services and every non-`Data/` file of a layered module, fail on: `global`, `wpdb`, `$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)`, `echo`, `print`, `exit`, `die`, `wp_die`, `wp_redirect`, `wp_safe_redirect`, `header(`, `setcookie`, `check_admin_referer`, `wp_verify_nonce`, `add_action`, `add_filter` (except in `Hooks.php`), `EWS_`, `include`, `require`, `get_current_user_id`, `current_time`.
- **L3: stores touch only their own module.** A store has no `use WorkforceOne\<Other>`, no `EWS_`, and no table of another module.
- **L4: dependency direction.**
  - `src/<A>/` may `use` `WorkforceOne\Platform\*`, `WorkforceOne\Support\*`, its own namespace, and only those classes of `<B>` that are listed in B's `exports`.
  - Nothing in `src/` may reference the manager or `LegacyPorts`.
- **L5: moved functions are thin.** In `includes/`, a function named in `moved` contains no `$wpdb` and no table reference. A trait listed in `traits_clean` has no `global $wpdb` at all.
- **L6: off is off.** In a layered slice's release, `src/Settings/Options.php` and `ews_schema_target()` are unchanged, unless the release notes name a schema change. A move release never has one.

**Existing checks.** `check_modules.py` is unchanged and still runs. Its baseline must not rise. The inbox check becomes the first enrolled slice (`notifications.inbox`), and the old script is kept as a thin wrapper until the rule set covers it.

**Optional:** a second PHPStan config at level 6 limited to the enrolled `src/<M>/` folders. These pure classes can take a strict level, which is not possible for the traits.

### 2.2 Golden byte-for-byte harness: feasible. `tests/golden/run.py`

The trick is to render **base and head in the same CI job, against the same database snapshot, with the same login cookie**. Then ids are identical (same seed), nonces are identical (same user, session token and tick), and dates are identical (same day). Very little needs normalising.

**Steps:**
1. `git worktree add $BASE origin/<PR base>` (read-only checkout).
2. Seed once with `tests/e2e_setup.php` plus `tests/fixtures/golden_seed.php` (fixed people, weeks, requests, switches on and off). Then `wp db export golden.sql`.
3. Mint one auth cookie per role with `wp eval` (`wp_generate_auth_cookie` with a fixed expiry). The session token goes into `session_tokens`, so it survives the `db import`.
4. For each side (base, head): swap the plugin directory (symlink), `wp db import golden.sql`, flush the object cache, then GET every page in `tests/golden/pages.json` (URL, role, switch set).
   - The `EWSQ` mu-plugin from `e2e_speed.py:25` records `num_queries`.
   - `SAVEQUERIES` records the query shapes, normalised with the existing `src/Health/QueryShape.php`.
5. **Pages.** Compare the HTML after normalising: the plugin version / `ver=` (as 3.31.126 did), the `EWSQ` comment, and a short per-page list of "now" patterns (clock minutes, "x minutes ago"). If base and head straddle midnight, the job reruns.
6. **Actions.** Run scripted POSTs and cron runs (`wp cron event run`) on both sides from the same snapshot. Compare a normalised trace, as `e2e_attendance_parity.py` does:
   - the redirect `Location`;
   - new or changed rows in every `ews_*` table, with times turned into placeholders and ids kept;
   - notifications, audit rows and scheduled cron events.
7. **Pass when:**
   - the HTML is identical;
   - the traces are identical;
   - per page, the head query count is at most the base count;
   - any new query shape is printed for review.
   - In addition, the inventory of hook and action names (`add_action`/`add_filter`/`add_shortcode`/`do_action`/`apply_filters` strings, REST route table, admin-post actions) is identical. This is a static diff of the two trees.

**Limits:**
- It only covers pages and actions in `pages.json`. The list grows with each move: each release adds the pages and actions of the slice it moves.
- A release that changes the schema needs both sides upgraded. Move releases never change the schema, so the harness first asserts that `ews_schema_target()` is equal on both sides.
- **Cost:** about 30–40 pages × 2 sides plus traces, a few minutes, in the existing "WordPress + MySQL e2e" job (or a sibling job using the same WordPress setup).
- **Random output:** any `uniqid()` or `wp_generate_password()` in HTML is found on the first run. It is either normalised or fixed at source.

---

## 3. Order and phasing

**Principles:**
- The first move is the slice the next feature needs.
- New code is born layered.
- High-risk engines (approvals, payroll) move in their own release with a trace, never inside a feature release.
- The heavy modules' **Stores** go first, because that is where the 3.31.126 speed work lives.
- `shell` and `platform` go last.

| # | Release (proposed number) | What | Size | What it proves |
|---|---|---|---|---|
| L0 | after PR #15 merges (3.31.127, or folded into L1: §7 Q1) | `src/Platform/Services.php`, ports, `LegacyPorts`; the inbox moves into the container (same per-prefix behaviour); `check_layers.py` with the inbox slice; the golden harness in CI on 15 pages | S | container + harness work; harness catches a deliberate one-byte change (a self-test PR) |
| L1 | **pilot** (with or just before the schedule change request) | Schedule `days` slice + Week lock service (§4) | M | all four layers incl. a guarded write, a cron path, notify-after-write, a multisite prefix switch; zero visible change |
| F1 | schedule change request (owner's 3.31.127 design) | the feature built layered from birth: `src/Schedule/Data/ScheduleChangeStore` + `WordPressScheduleChangeStore`, `ScheduleChangeService` (create / decide / apply / withdraw / expire), `ScheduleChangeRules` (already designed as pure); the trait `trait-schedule-change.php` is controllers only. It reaches the engine through a new **port** `WorkforceOne\Approvals\Engine` (start / act / closeOpen / currentStep) whose first implementation `LegacyApprovalEngine` delegates to `approval_start`, `approval_act` and `approval_close_open` with `$manage_transaction=false` unchanged | L (feature) | a new request type with no SQL in a trait; the Engine port ready for L2 |
| L2 | approvals engine move (own release; high risk) | `src/Approvals/Data/ApprovalStore` (requests, steps, workflows, substitutes), `ApprovalEngine` service implementing the port (start, act, may-decide, level-moved, close-open, deadlines); `LegacyApprovalEngine` deleted. Parity: a new `e2e_approval_trace.py` (every type × approve / reject / self / substitute / double post / deadline) on base vs head | L | the engine every request type uses is in one tested place; money paths unchanged |
| L3 | rest of schedule (with the next schedule-touching release) | `SwapStore` (`trait-swap.php`, 20 `$wpdb`), shift history (`trait-shift-history.php`, 11), schedule types / config, `prime_shift_cache` / `shift_prime_people` into the store; `schedule` fully enrolled | M | 3.31.126's shift primes in one class; `schedule` L1 count to zero inside its own module |
| L4 | People Store (proactive, heavy; **L4a reads done in 3.31.133, L4b org writes done in 3.31.134, L4c employee writes done in 3.31.135**: `docs/tasks/people-store.md`) | `src/Organization/Data/PeopleStore` (or `People/`): `people_employee*`, `people_names_by_user`, `people_brief_by_ids`, `people_shift_ids` with its memo, active lists, the `emps()` cache; **the shared "names by ids" read** the table-crossing step 1 needs (`modules.md` "Next steps" 1) | L | most remaining `employees` crossings go; one memo owner; the speed fixes in one place |
| L5 | Attendance Stores (proactive, heavy; **L5a reads done in 3.31.136, L5b writes done in 3.31.137, L5c auto + manual records done in 3.31.138**; split into L5a–L5e: `docs/tasks/attendance-store.md`) | a: `TimeLogStore`, `BreakStore`; `AttendanceContext` closures that query are replaced by stores; b: corrections, sign-in attempts, the time report read model | L+M | the API's sign-in uses the same Service+Store as the web; `e2e_attendance_parity` green |
| L6 | Mobile API PR 2 (feature) | new routes call Services from the container, never trait methods | — | API reuse |
| — | as touched | leave, overtime (money), locations (the v321 location prime from 3.31.126), tasks, engagement, daily_workers, app, privacy exporters (§7 Q6), reports (read models) | S–M each | one module per release |
| last | payroll (own release with a payslip-amount trace), then shell, platform (schema per module, settings) | | L | real module switches (a module's hooks and tables are its own) |

---

## 4. The pilot (L1): Schedule "days" slice + Week lock

### 4.1 Why this slice
- The schedule change request (F1) writes and reads exactly these rows and the week lock.
- It has the hardest pattern, a guarded compare-and-update with a race on the UNIQUE key (`save_att_if_unchanged`, `trait-core.php:237-366`). If the layering works here, it works elsewhere.
- It has a cron path (`week_lock_tick`) and a notify step.
- It is small: about 350 lines moved.
- It does not touch the approval engine or payroll, except through callers that keep their names.

### 4.2 Files and functions

**New:**
- `src/Schedule/Data/ScheduleDayStore.php` (interface):
  - `find(int $eid, string $date): ?object`: `SELECT *`, the same as `today_schedule_for_employee`.
  - `findStatusNote(int $eid, string $date): ?object`: `id,status,note`.
  - `statusById(int $id): ?string`
  - `insert(array $row): int`: 0 on failure.
  - `lastError(): string`
  - `updateStatusIf(int $id, string $expected, string $status, string $note, int $by, string $at)`: returns the affected count, or `false`.
  - `rowsOnDates(array $dates): array`: for `week_lock_notify`.
  - `calendarEvents(string $start, string $end): array`
  - `saveFree(...)`: what `save_att` does today, with the same 3 reads at first. Collapsing them to 1 is allowed ("fewer"), but in its own commit so the trace diff is readable.
- `src/Schedule/Data/WordPressScheduleDayStore.php`: SQL copied **verbatim** from the current functions (same text, same order).
- `src/Schedule/ScheduleDayService.php`:
  - `saveIfUnchanged(int $eid, string $date, string $status, ?string $expected, int $actor): array` returns exactly today's shape: `ok`, `conflict`, `id`, `changed`, `old_status`.
  - `saveFree(...)`
  - `todayRow(int $eid, string $day)`
  - Ports used: `AuditLog` (the `schedule_insert_failed` and `schedule_update_failed` rows), `People` (the active employee's name and user, today's `SELECT name,wp_user_id … active=1` through the people api), `Notifier`.
- `src/Schedule/WeekLockService.php`:
  - `refusal(string $date, bool $canOverride): string`, `view(...)`, `next(...)`
  - `tick(string $now, array $cfg, array $workingDays): void`, which uses a `OnceClaim` port for the conditional UPDATE of `ews_week_lock_notified`. The options table is platform's, so the claim is a platform port (`WordPressOnceClaim`), not schedule SQL.
  - `notifyWeek(...)`
  - It calls the existing pure `WeekLock::*`.
- `src/Platform/{Services,Clock,AuditLog,Notifier,People(interface),OnceClaim,Transaction}.php` and their `WordPress*` / `Legacy*` implementations.
- `includes/class-ews-legacy-ports.php` (`LegacyPorts`).

**Changed (bodies only; names, signatures and return shapes kept):**
- `trait-core.php`: `save_att_if_unchanged`, `save_att`, and the new `services()`.
- `trait-work-time.php`: `today_schedule_for_employee` (`:57`), `company_calendar_events` (`:296`).
- `trait-swap.php`: `schedule_for_employee_date` (`:21`) only. The rest of swap is L3.
- `trait-week-lock.php`: every function becomes a delegator. `week_lock_tick` keeps `public` and its hook name.
- `docs/architecture/modules.json`: the `layered` slice and `exports`.
- `employee-schedule-manager.php`: the `require_once` of `LegacyPorts` only.

**Text stays in the controller.** The notification title and message ("Schedule Updated", "Your schedule for … is now …", "Next week's schedule is final") are built in a `LegacyNotifier` callback that receives codes and values. The service never builds HTML or calls `__()`, so the bytes stay identical.

### 4.3 Tests to add
- **Unit** (fast, with an in-memory `tests/unit/Fakes/MemoryScheduleDayStore.php`):
  - `ScheduleDayServiceTest`: empty cell inserted; insert race → conflict with the winner's id; insert failure with no winner → audit plus `ok=false`; expected mismatch → conflict; update that changed 0 rows to the same value → `ok`, `changed=false`; update failure → audit; note kept; notify only when changed, active and with a user; actor 0 (cron) passes through.
  - `WeekLockServiceTest`: refusal codes; tick claims once (a second tick is a no-op); a cutoff older than a day is not sent; notify groups days per person.
- **e2e, real MySQL:** `tests/e2e_schedule_store.php` (`wp eval-file`, temporary tables, the same pattern as `e2e_notification_inbox.php`):
  - UNIQUE race;
  - the conditional UPDATE's affected counts;
  - `find()` columns equal to `SELECT *`;
  - prefix switch reuses the store.
- **Golden** (`pages.json` + actions):
  - **Pages:** Attendance grid (open week, final week read-only, override user), Team schedule, My profile, Dashboard, Feature settings Week lock card, Requests Hub with a swap.
  - **Actions:** grid save (including a stale page → conflict, and locked count), `att_single`, CSV preview / import, swap approve (writes days), leave approve and cancel, correction apply, overtime, and the `ews_week_lock_tick` cron.
- **Existing suites, all green:** `e2e_attendance_grid`, `e2e_week_lock`, `e2e_swap`, `e2e_swap_inbox`, `e2e_swap_mu.php`, `e2e_leave`, `e2e_corrections`, `e2e_overtime`, `e2e_schedule_view`, `e2e_office_minimum`, `e2e_shift_history`, `e2e_speed` (query counts pinned), `e2e_request_races`, plus unit, phpstan, phpcs, `check_modules`, `check_translations`.

### 4.4 How "nothing visible changes" is proven
1. Golden HTML is identical on every listed page, with switches both off and on.
2. Action traces are identical.
3. Query counts are the same or lower per page and action, and query shapes are the same.
4. The hook, route and admin-post inventory is identical.
5. `Options.php` and the schema target are unchanged (rule L6).
6. The `check_modules` baseline is not raised, and the `layer-baseline` for `schedule` and `company_calendar` drops by the moved references.
7. No new option, switch, table or string. Translations are unchanged.

---

## 5. Risks and mitigations

| Risk | Mitigation |
|---|---|
| **Static caches and memos split in two** (a legacy static plus a new store cache), so a read after a write in the same request is stale | Each cache moves **with** the query it caches, in the same release. The legacy static is deleted. `invalidate_*_runtime_cache()` calls `$this->services()->…->forget()` for that slice. An e2e "write then read in one request" case goes in the store test. |
| **3.31.126 request-level caches and primes** (`people_shift_ids`, `shift_prime_people`, `shift_prime_periods` table memo, `ews_v321_employee_locations_prime`) | No move starts before PR #15 merges. Primes become store methods (`prime(array $ids)`) in the module that owns the table (L3 schedule, L4 people, locations as touched). `e2e_speed.py` pins the counts (8 vs 78 people), and the golden query rule catches any N+1 regression. |
| **Multisite / `switch_to_blog`** | The container is per prefix (the inbox precedent). `e2e_swap_mu.php`, `e2e_corrections_mu.php` and `e2e_payroll_mu.php` stay in the suite set. |
| **Transactions and lock order** (approvals then the module's row; swap's fixed id order; `$manage_transaction` flags) | Stores never begin or commit. One `Transaction` port (`run(callable)`, nesting-aware, `GET_LOCK` helper) owns them. In L2 the engine keeps the `$manage_transaction=false` contract exactly. Lock order is written in each service's docblock and covered by `e2e_request_races`. |
| **Notify inside a transaction** (`notify()` inserts at once) | Services return "after-commit effects", or use a `Notifier` that queues until `Transaction` commits. This is the existing rule ("notifications after COMMIT") made structural. |
| **Cron paths have no current user** | The actor is always an argument. `get_current_user_id()` is banned in services (rule L2). Cron runs are in the golden actions. |
| **Names others rely on**: hook callbacks, the `Api\Routes` handler table, shortcodes, `wfo/*` actions, private methods invoked by tests via Reflection, `$this->schedule` used by 22 traits | Names and signatures are kept (§1.3). The inventory diff in the harness checks this. Table properties stay until the L1 ratchet reaches zero. |
| **Byte drift** from moving code that builds HTML or strings | Strings and HTML stay in controllers or legacy adapters. Copy SQL and string-building verbatim, then refactor in a later commit. The golden diff is authoritative. |
| **Performance**: autoloading about 5–10 more small files per request; object overhead | This is negligible with opcache. System Health measures it. The real risk is per-row `find()` in loops, which the query-count rule catches. |
| **Approval engine, payroll, money** | Each moves in its own release with a parity trace (L2; payroll last). They are never moved inside a feature release. The reviewer brief always includes money, permissions and concurrency. |
| **Merge conflicts** with open branches (`claude/mobile-api-1`, PR #15, F1) | One move at a time. Merge first. Never force-push. A move release does not reformat untouched lines. |
| **The harness becomes a burden** (flaky time strings) | Per-page normalisation lists are kept small and reviewed. If base and head straddle midnight, the job reruns. A page that cannot be stable is moved to the trace mode (rows, not HTML). |

---

## 6. What we gain per phase

- **L0:** Every later move is checked by a machine, not by hand. Golden comparison replaces manual snapshots, and a regression in queries per page fails the PR.
- **L1:**
  - Schedule writes have one implementation with fast unit tests. The guard logic is testable without MySQL.
  - F1 and the API can call `ScheduleDayService` directly.
- **F1:** The new request type has no SQL in a trait from day one. The `Approvals\Engine` port exists, so L2 can swap the implementation without touching any request type.
- **L2:**
  - The approval engine is one tested service. Every request type, the web, and the future API decisions go through it.
  - Double-post and level guards live in one place.
- **L3/L4/L5:**
  - The 3.31.126 speed fixes (shift, people and location primes) live in the stores that own the data, so the next speed fix is a one-class change.
  - Most cross-module table uses disappear, which is the `modules.md` step 1 goal.
  - Unit tests run against fakes, in milliseconds.
- **L6 and the as-touched releases:** The mobile API reuses the same Services as the web, so behaviour cannot drift between web and app.
- **Last (payroll, shell, platform):** Modules own their schema and hooks, so real module switches become possible: a module that is off registers nothing and creates nothing.

---

## 7. Open questions for the owner

1. **Numbering and bundling.** Should L0 (container + harness) and L1 (pilot) be:
   - a separate small release before the schedule change request, which then becomes 3.31.128; or
   - bundled into the schedule change request release?

   The working method allows both ("in the same release or a separate small release just before it").
2. **Pause for the pilot.** Should feature work pause until L1 merges, or can F1's implementation run in parallel and be rebased on L1?
3. **Does F1 "touch" approvals?** F1 registers a new type in the engine (`Workflows::IN_USE`, Hub, My approvals) but does not change the engine. Is that enough for the "move the module you touch" rule? Or should the engine move (L2) come **before** F1, which would delay F1 by one L release?
4. **Proactive order for the heavy modules:** after L1, people (L4) or attendance (L5) first? Schedule is already in progress.
5. **Release cadence.** How many pure "move" releases are acceptable between feature releases (for example, at most one in a row)? Does each one need the owner's approval like a feature?
6. **Privacy exporters.** Should they:
   - wait for each module's store (each module exports its own personal data); or
   - ship sooner through a read-only `Platform\Data\PrivacyQuery` listed as an allowed cross-module read and removed later?
7. **The harness in CI.** May the golden job add about 5 minutes to every PR? Should it run on every PR or only on PRs marked as moves?
8. **Stage 2 controllers.** Should controllers stay in the traits for good (simpler, names kept), or move to `src/<M>/Web/` once a module is fully layered?
9. **Naming.** Should the code keep "Store" (as the inbox uses), or use "Repository" as in the owner's layer diagram? It should be one word everywhere.

---

## 8. Owner decisions (2026-10-26)

1. **Bundling:** L0 (container, layer check, golden harness) and L1 (pilot: Schedule days + Week lock) are one release of
   their own, **3.31.127**, with no visible change. The schedule change request becomes **3.31.128** and is built on them
   (so feature work waits for L0+L1; question 2 is settled by this).
2. **Approvals:** the engine moves **after** the schedule change request. F1 reaches it through the `Approvals\Engine` port
   (`LegacyApprovalEngine`); L2 follows in its own release with a full approval trace.
3. **First heavy module after schedule:** **People** (L4) before Attendance (L5).
4. **Cadence:** at most **one move-only release between two feature releases**; each needs the owner's approval like any
   release.
5. **Golden harness:** runs on **every PR**. A feature PR names what it changes on purpose in `tests/golden/expected.json`
   (pages that may differ, hook names it adds; no removal allowed); everything else must stay the same; the file is
   emptied again by the next release (owner decision 2026-10-27).
6. **Naming:** **Store** (`<Thing>Store` / `WordPress<Thing>Store`), as the inbox.
7. **Privacy exporters:** **now**, through a read-only `Platform\Data\PrivacyQuery` listed as an allowed cross-module read,
   removed as each module gets its Store.
8. **Controllers:** **stay in the traits** (thin, same names). Moving them to `src/<M>/Web/` is decided once a module is
   fully layered.

### Resulting order
3.31.127 L0+L1 (move only) → 3.31.128 schedule change request (built layered, Engine port) → L2 approvals engine (move)
→ a feature → L4 People (move) → a feature → L3 rest of schedule / L5 Attendance … (one move between features).
Privacy exporters can ship in any feature slot.

---

## 9. 3.31.127 (L0 + L1): where the implementation differs from the design
- **Adapters:** the ports' WordPress side is a set of small anonymous classes built in `includes/trait-services.php`
  (`EWS_Services_Trait::services_build()`), each wrapping a closure made inside the plugin object, instead of a separate
  `class-ews-legacy-ports.php`. Same rule: nothing in `src/` sees the plugin object.
- **Ports added now:** `Platform\AuditLog`, `Clock`, `People`, `OnceClaim` and `Schedule\ScheduleNotifier` (the words and
  links of the two notifications stay in the adapter). `Transaction` waits for the first slice that needs one (none in
  this slice runs a transaction).
- **The inbox stays where it is** (its own per-prefix holder in `trait-notifications.php`); moving it into the container
  is left for the next move release, so this one moves only Schedule.
- **`save()` keeps its three reads** (id, status, note) exactly as `save_att()` had them; collapsing them is a later,
  separate commit.
- **People:** `people_active_brief()` (people module) keeps the exact query the grid's save ran on `ews_employees`.
- **Golden harness** (`tests/golden/run.py`):
  - the database is saved and put back by `tests/golden/snapshot.php` (every `ews_*` table with `TRUNCATE` so the ids
    match, and the plugin's options), not `wp db export`, so it runs on CI's MySQL and on SQLite;
  - each side gets its own PHP server (no file or realpath cache of the other side) and one login for all sides;
  - the base is rendered twice: a page that differs on the old code is reported as not stable instead of compared;
  - pages are compared on HTML and query count (not query shapes yet); the actions run in PHP through the same
    functions (`actions.php`), not through HTTP posts; the hook inventory is a text scan of both trees;
  - normalised: the version, WordPress's `userSettings.time` in wp-admin and "N minutes ago".
  - Proven to fail: one extra space in the Attendance template fails 4 pages; one extra query on the Dashboard fails 3.
- **check_layers.py:** rules L1–L5 as designed, with self-tests; L6 (options and schema unchanged) is not automated yet:
  the harness's equal pages and writes cover it for this release.

### After the review (3.31.127)
- The golden harness runs in **its own CI job** (its own WordPress and MySQL), so its seed (a manager capability,
  departments, teams, a holiday, a night shift) never reaches the other suites.
- It creates the mu-plugins folder (a fresh WordPress has none), checks that it starts its own server on a free port,
  refuses to run outside CI (or `GOLDEN_OK=1`) or on a site that is not 127.0.0.1, checks every page answers 200, and
  fetches a page that differs once more on both sides (a clock boundary between two sides is not a difference).
- The actions now notify someone (omar gets a WordPress user; days are planned for the coming weeks), keep the raw
  return types of `save_att()`, and must have saved, notified and claimed the week, else the run fails.
- Hooks: none may be removed; added ones must be named in `expected.json`.
- The Store keeps the plugin's schedule table name (`$this->schedule`, as the old code), so a `switch_to_blog()` reads
  the same table as before; the calendar uses the current prefix, as before.
- `save()` takes a null note (stored as NULL, as before).
- `check_layers.py` also bans hooks, options, `time()`/`date()`, `$GLOBALS` and output functions in Services, sees
  `$GLOBALS['wpdb']`, scans the main plugin file and `uninstall.php` (the baseline rose once for that wider scan), and
  `--update` refuses an increase unless `--allow-increase`.
- The unit fake clears its error on reads, as wpdb does, so the insert-failure audit is the one production writes
  ("Database insert failed.").
