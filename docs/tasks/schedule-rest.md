# L3: the rest of the Schedule module behind Controllers → Services → Stores

Written by the read-only architect on `claude/schedule-l3` (= main db8d129, 3.31.155). Design only; nothing is decided for the
owner (§8 lists the open questions; §9 holds the owner's answers once given).

Abbreviations: `ts:` = `workforce-one/includes/trait-swap.php`, `tsh:` = `trait-shift-history.php`, `twt:` = `trait-work-time.php`,
`tc:` = `trait-core.php`. `src/` paths are under `workforce-one/src/`.

L3 is move only. Every slice keeps:
- the same SQL text and order, and the same transactions, locks and option reads at the same moments;
- the same audit rows, notifications, redirects and `wp_die` texts;
- the same hook, admin-post, REST and Reflection names.

There is no schema, option, cron or capability change (rule L6), so `tests/golden/expected.json` is emptied and stays empty.

---

## 0. Measured state

### 0.1 Per trait

Counts are `$wpdb` tokens, `global $wpdb` statements, and the L1 baseline (`docs/architecture/layer-baseline.json`).

| Trait | Lines | `$wpdb` / global | L1 now | Tables touched (lines) | Crosses into |
|---|---|---|---|---|---|
| `trait-swap.php` | 588 | 57 / 20 | 10 | `schedule_swaps` through `ensure_swap_schema()` ×15 and the literal at :49; `schedule` :93 (UPDATE), :482-483, :566, :575; `employees` :385, :399-400; `approval_requests`/`approval_steps` :381-384 | people (9 `people_*`/`team_*` calls), approvals (`approval_*` ×12), engagement (`achievement_evaluate_swap_acceptance` :197), week lock :99 |
| `trait-shift-history.php` | 421 | 42 / 11 | 0 (tables not enrolled) | `employee_shift_periods`, `shift_versions` through `shift_history_tables()` ×11; dbDelta :37, :49; 2 transactions :239, :324; GET_LOCK through `ews_with_lock` :226, :279, :317 | people (`people_set_shift_id` inside the transaction :269, :282; `people_all_shift_ids` :70); options `ews_shift_history_synced`, `ews_shift_history_checked` :290-303 |
| `trait-schedule-types.php` | 115 | 2 / 1 | 1 | `schedule` :84 (`schedule_leave_dates`); option `ews_schedule_types_config` written on an empty read :26 | — |
| `trait-schedule-config.php` | 166 | 9 / 3 | 4 | `schedule` :130 (DISTINCT status); `company_calendar` :147 SELECT, :148 INSERT, :160 DELETE; shift versions through `tsh` :71-72, :120 | options (hours, days, shifts, types) |
| `trait-schedule-view.php` | 143 | 8 / 3 | 3 | `schedule` :50; `teams`/`team_members` :22, :25, :27, :67 | people (teams) |
| `trait-schedule-api.php` | 46 | 2 / 1 | 1 | `schedule` :34 (`GET /me/schedule`, `src/Api/Routes.php:41`) | — |
| `trait-work-time.php` | 281 | 3 / 1 | 1 | `schedule_table_name()` :37 (counted, not SQL); `ews_overtime_requests` :21 (overtime's table) | overtime, attendance |
| `trait-core.php` (schedule part) | 261 | 0 | — | request statics `$ews_shifts_cache`, `$ews_shift_for_employee_cache`, `$ews_working_hours_cache`, `$ews_shift_periods_cache`, `$ews_shift_versions_cache`, `$ews_shift_periods_table`, `$ews_schedule_types_cache`, `$ews_schedule_type_by_name_cache`, `$ews_company_leave_cache` (:9-23); `invalidate_*` :49-73 | — |
| `trait-office-minimum.php` | 383 | 6 / 3 | 3 | `employees` :85, `teams` :89, `schedule` :111 | people |
| `trait-attendance-grid.php` | 174 | 0 / 0 | 0 | none (already only controllers) | — |
| `trait-attendance-cards.php` | 151 | 0 / 0 | 0 | none | — |

### 0.2 Already done
- `prime_shift_cache` (`twt:118-132`) and `shift_prime_people` (`tsh:197-202`) have no SQL of their own since L4a; their reads go through `PeopleDirectory::storedShiftIds` / `shiftIds`.
- The only SQL left in the primes is `shift_prime_periods` (`tsh:174-190`) and the statics in `trait-core`.

### 0.3 Other modules' SQL on schedule tables (stays as baseline debt)
- `schedule_swaps`: `trait-achievements.php:209, :235, :291`; `trait-admin-dashboard.php:80` (an audit label); `trait-schema.php:83`; `uninstall.php:62` (a bare name, not counted).
- `employee_shift_periods` and `shift_versions`: only `uninstall.php:55`.

### 0.4 Callers of swap functions from other files (names and signatures must not change)
- Hooks (`src/Schedule/Hooks.php:12-15`): `swap_request_create`, `swap_request_respond`, `swap_request_cancel`, `swap_manager_decide`.
- `trait-admin-requests.php`: `swap_pending_rows` :46, `swap_admin_decide` :116.
- `trait-schedule-view.php`: `swap_waiting_mine_count` :117, `swap_requests_for_user` :138, `swap_awaiting_my_approval` :139.
- `trait-my-approvals.php`: `swap_approval_card` :62, `swap_peer_cards` :99, `swap_state_for` :265, `swap_my_requests` :283, `swap_new_choices` :332, `swap_withdraw` :364.
- `schedule_for_employee_date` (moved in L1) is called by 9 places in leave, overtime, corrections, attendance, admin-requests and employee-profile.

### 0.5 Callers of shift and working-time functions (for L3c)
- `working_hours`: 15 traits, including `trait-payroll.php` (money), `trait-corrections.php`, `trait-attendance.php`, `trait-reports.php`, `trait-services.php` (×2 adapters).
- `shifts()`: 6 files. `shift_prime_people`: attendance-grid, attendance-insights, reports, time-report ×2.
- `shift_period_add`: `trait-services.php` (the People `ShiftHistory` port). `shift_history_view`: employee-profile. `shift_upcoming`: my-profile.
- Cron and admin-post from the main file: `shift_history_daily`, `shift_period_save`.

### 0.6 REST routes
- None for swaps. `GET /me/schedule` → `api_me_schedule` (L3b). `GET /attendance/today` reaches `working_hours` (L3c).

### 0.7 Names reached by Reflection, which must stay
- L3a: `swap_new_choices` (`tests/e2e_speed.py:122`, query count ≤ 12); `swap_withdraw` (`tests/e2e_swap_inbox.py:56`).
- L3b/L3c: `shift_for_employee`, `prime_shift_cache`, `shifts` (`e2e_reports.py:221-223`); `working_hours` (`e2e_schedule_config.py:85`); `shift_periods_of`, `shift_history_seed` (`e2e_shift_history.py:26, 192, 212, 220`); `week_dates_configured` (7 tests); `default_schedule_types_config` (5 tests); `statuses` (`e2e_office_minimum.py:132`); `working_days` (`e2e_api_app.py:137`); `save_att` (golden, notification_links).
- The race filter in `tests/e2e_swap_mu.php` matches the exact text `SELECT * FROM …ews_schedule WHERE employee_id=N AND work_date='D' LIMIT 1` (`ScheduleDayStore::find`, already moved); it must stay byte-identical.

### 0.8 Tests that reach the swap code
- e2e: `e2e_swap.py` (validation, peer, admin, levels, races through the mu filter, `break_writes` faults), `e2e_swap_inbox.py`, `e2e_schedule_view.py`, `e2e_level_guard.py`, `e2e_my_approvals.py`, `e2e_my_approvals_legacy.py`, `e2e_admin_requests.py`, `e2e_features.py`, `e2e_speed.py`, `e2e_dashboard_audit.py`, `e2e_approval_engine.py`, `e2e_approvals.py`, `e2e_notification_links.py`, `e2e_notifications_view.py`, `e2e_week_lock.py`, `e2e_big_lists.py`, `e2e_org_moves.py`.
- Golden: only `actions_approvals.php:160-167` (engine start and peer act for `shift_swap`). No golden seed has a swap row today, so the schedule, approvals and requests pages render empty swap panels.

### 0.9 Checks today
- `check_layers.py`: OK, 215 references in 48 files.
- `check_modules.py`: OK, with three pending "fewer" lines (attendance→people 14→10, locations→people 4→2, reports→people 6→5); write them with `--update` in this release.

---

## 1. Slicing

Each slice ships alone with zero visible change.

| Slice | What moves | Size | Risk | Main hazards |
|---|---|---|---|---|
| **L3a Swaps** (recommended first) | All SQL of `trait-swap.php` into `SwapStore` + a `SwapQuery` read model + three new `ScheduleDayStore` methods; the transactional core into `SwapService`; `schedule_swaps` enrolled; `trait-swap` clean | M | M | engine inside the caller's transaction, lock order swap → schedule rows, dbDelta never inside a transaction, option-read moments |
| **L3b Days, the rest** | The `schedule` and `company_calendar` SQL left in types :84, config :130/:147/:148/:160, view :50, api :34; the team reads of view :22-27/:67 through People; `attendance-grid` / `attendance-cards` listed clean | S | S | option writes in the config handlers stay; the `wp_die` text with the DB error (:149) |
| **L3c Working time and shift history** | `ShiftHistoryStore`, `ShiftHistoryService`, `WorkingTime` (`shifts`, `shift_for_employee(_on)`, `working_hours`, `shift_times_on`, the primes), schedule types config, a static `ScheduleMemo` owning the nine `trait-core` statics and `invalidate_*`; `schedule` fully enrolled | L | **L (money)** | `working_hours` feeds payroll, lateness, overtime and corrections; GET_LOCK + transaction + a People write inside it; process-wide statics used by ~15 traits; cron and option claims; dbDelta seed |
| **L3d Office minimum** | `om_people` :85/:89, `om_schedule_map` :111 | S | S | do it with the next office-minimum feature |

Not L3: `twt:21` reads `ews_overtime_requests` (the overtime move); `schedule_table_name()` (`twt:37`) stays as the container's wiring (Q7).

**Recommendation: L3a first.** It is the largest block of SQL in the module, one coherent unit on a table only Schedule writes,
the best-pinned behaviour in the suite (`e2e_swap.py` with its race mu-plugin and fault injection, `swap_inbox`, `level_guard`),
with no money, no GET_LOCK, no option writes and no cron; the engine it calls is already layered (L2). L3b is very low risk but small.
L3c goes last, on a harness extended by L3a and L3b, with a payroll trace.

---

## 2. L3a in detail: Swaps

### 2.1 Split of responsibilities
The transactional core goes into the Service; SQL goes into the Stores; the trait keeps controllers, presenters, audit and
notification text, Hub mapping and the `ensure_swap_schema()` calls at their current lines (as L5e1 / L5e2). Every option read
(`ews_schema_version`, `ews_feature_my_approvals`, the workflow, the week lock) stays at its current moment.

### 2.2 New files (namespace `WorkforceOne\Schedule`)
Every SQL method copies the text, the `wpdb` call, the formats and the raw return.

**`Data/SwapStore.php` / `Data/WordPressSwapStore.php`** (table `$db->prefix.'ews_schedule_swaps'`, in the constructor; the container is keyed by prefix):
- `pendingAsTarget(int $id, int $employeeId): ?object`, `pendingAsRequester(...)` (`ts:43`; text exactly `SELECT * FROM {t} WHERE id=%d AND status='Pending' AND target_employee_id=%d LIMIT 1`, and the requester variant).
- `close(int $id, string $status, int $by, string $at, string $from)` → `int|false` (`ts:50`; `update()` with `['%s','%d','%s']` and `['%d','%s']`).
- `markAwaiting(int $id, int $by, string $at)` → `int|false` (`ts:279`).
- `countOpenBetween(string $date, int $a, int $b): int` (`ts:148`).
- `insert(array $row): int` (`ts:150`; eight formats; 0 when refused; `insert_id` read at once).
- `openById(int $id): ?object` (`ts:225`); `awaitingById(int $id): ?object` (`ts:363`, identical `ts:412`).
- `open(): array` (`ts:269`); `mine(int $employeeId, int $limit): array` (`ts:430`); `waitingMineCount(int $employeeId, string $today): int` (`ts:526`); `stateRow(int $id, int $employeeId): ?object` (`ts:532`); `busyWith(array $dates, int $employeeId): array` (`ts:577`).

**`Data/SwapQuery.php` / `Data/WordPressSwapQuery.php`** (read model, listed under `reads`, writes nothing). Constructor `($db, string $schedule, string $employees)`: fixed schedule and employees names from `Services::$tables`, swaps and approvals tables from `$db->prefix` (quirk SW-i).
- `awaitingApprover(int $userId): array` (`ts:382-386`; `LIKE 'WAITING%%'`, `LIMIT 50`).
- `forEmployee(int $employeeId, ?string $from, ?string $to)` (`ts:389-404`; the **raw** `get_results` result).
- `peerWaiting(int $employeeId, string $today): array` (`ts:481-484`).

**`Data/ScheduleDayStore` additions:**
- `exchange(int $id, int $employeeId, string $date, string $was, string $status, int $by, string $at)` → `int|false` (`ts:93`).
- `daysBetween(int $employeeId, string $from, string $to): array` (`ts:566`, with its `ORDER BY work_date`).
- `statusesOf(array $ids, array $dates): array` (`ts:575`).

**`SwapApprovals.php`** (port; adapter in `trait-services.php` calling trait functions by name, as `LetterApprovals`):
- `start(int $swapId, int $requesterId): bool` → `approval_start('shift_swap','schedule_swap',id,req,[],false)`.
- `find(int $swapId): ?object` → `approval_find_request`.
- `act(int $arId, string $decision, string $note, $posted): string` → `''` or `approval_error_code(...)` of `approval_act(...,false,false,$posted)`.
- `actAsAdministrator(int $arId, string $decision, $posted): string` → `''` or `Hub::fromEngineError(code)` of `approval_act(...,'',false,true,$posted)`.
- `isOpen(?string $status): bool` → `Hub::isOpenApproval`; `outcome(?string $status): string` → `Hub::workflowOutcome`.

**`SwapSite.php`** (port): `ensureSchema(): void` → `ensure_swap_schema()`; `weekLockRecord(int $employeeId, string $date, string $ref): void` → `week_lock_record_request($eid,[$date],'swap',$ref)`.

**`SwapPorts.php`**: holder of `approvals` and `site`.

**`SwapService.php`** (no SQL, clock through `Clock`, actor as an argument). Constructor `(SwapStore, ScheduleDayStore, SwapPorts, Transaction, Clock)`; constants `APPROVED`, `REJECTED`, `ADVANCED`, `CHANGED`, `ERROR`, `MOVED` equal to `Hub`'s (unit-tested).
- `close(int $id, string $status, string $from, int $actor)` → `int|false` (`ts:47-51`).
- `apply(object $r, int $actor)` → `true|string` (`ts:66-73`); `applyRows(object $r, int $actor)` → `true|string` (`ts:79-101`).
- `create(int $requesterId, int $targetId, string $date, int $actor)` → `array{error?:string,id?:int,a?:object,b?:object}` (`ts:144-156`).
- `toApprovers(object $r, int $actor)` → `true|string` (`ts:277-282`).
- `managerDecision(object $r, object $ar, string $decision, string $note, $posted, int $actor)` → `string` (`ts:336-354`).
- `adminLevel(object $r, object $ar, bool $approve, $posted, int $actor)` → `array{outcome:string, fresh:?object}` (`ts:230-240`).

**`Platform/Services.php`**: `swapStore()`, `swapQuery()`, `swaps()`; constructor argument `?SwapPorts $swapPorts = null` at the end (as `letterPorts`); `trait-services.php` gains `swap_ports()`.

### 2.3 Trait functions after L3a (names and signatures unchanged)
- **Thin delegators:** `swap_close`, `swap_apply`, `swap_apply_rows`, `swap_manager_decision`; `swap_pending_row($id,$extra_where,...$args)` kept, mapping its two used forms to the two store methods after `ensure_swap_schema()`.
- **Controllers / orchestration, SQL gone:** `swap_request_create`, `swap_request_respond`, `swap_request_cancel`, `swap_withdraw`, `swap_to_approvers`, `swap_admin_decide`, `swap_manager_decide`; the reads `swap_pending_rows`, `swap_awaiting_my_approval`, `swap_requests_for_user`, `swap_approval_card`, `swap_my_requests`, `swap_peer_waiting`, `swap_waiting_mine_count`, `swap_state_for`, `swap_new_choices` call `ensure_swap_schema()` at their current line, then the store or query.
- **Unchanged (no SQL):** `swap_redirect`, `swap_manager_mode`, `swap_notify`, `swap_requester_url`, `swap_inbox_url`, `swap_audit`, `swap_admin_done`, `swap_notify_approver`, `swap_finish_approved`, `swap_approved_after`, `swap_rejected_after`, `swap_status_word`, `swap_peer_cards`, `swap_peer_card`, `swap_employee_user_id`. All `__()` strings stay.
- **Removed:** the dead `global $wpdb` at `ts:26` and `ts:297`.

### 2.4 Transactions, locks, money
- Every transaction is opened by the `Transaction` port with the same literal statements (`swap_apply`, `swap_to_approvers`, `swap_manager_decision`, the open-level branch of `swap_admin_decide`). A failed `START TRANSACTION` returns `'save'` (or `ERROR`) without a ROLLBACK, as today.
- `ensureSchema` (dbDelta) runs only before BEGIN (`ts:67`, `ts:277`); `close()` never ensures (`ts:48`). A unit-test journal asserts it.
- The engine is always called with `$manage_transaction=false` inside the swap's transaction; the admin path uses `override=true`.
- Lock order unchanged: peer path swap row (conditional UPDATE) → the two schedule rows by schedule id (`ts:91`); approver path engine steps → engine request → swap row → schedule rows. No GET_LOCK.
- Money: no payroll table is read or written. A swap exchanges two schedule statuses, read later by payroll and reports through `report_days`; the rows written and their moment do not change.

### 2.5 Caches, memos, multisite
No static memo in swaps. The workflow read, `PeopleMemo` and `ensure_swap_schema`'s option read are reached through the same
trait functions. One container per prefix: the swap store follows the prefix; the read model and `ScheduleDayStore` use the
plugin's fixed names, as today (SW-i); the store contract test pins both.

### 2.6 modules.json, baselines, check_layers
- `schedule.layered` gains slice `swaps`: `tables: ["schedule_swaps"]`, `store: src/Schedule/Data/WordPressSwapStore.php`, `services: [src/Schedule/SwapService.php]`, `reads: [{file: src/Schedule/Data/WordPressSwapQuery.php, tables: [schedule_swaps, schedule, employees, approval_requests, approval_steps]}]`, `moved` (the 21 functions above), `traits_clean: ["swap"]`.
- The `days` slice gains `traits_clean += ["attendance-grid", "attendance-cards"]`.
- `api` and `exports` unchanged. No new `HELPERS` entry for `ensure_swap_schema`.
- Baseline: one `--update --allow-increase` in the store commit (expected rises: `trait-swap` 10→11, `trait-achievements` 6→9, `trait-admin-dashboard` 20→21, `trait-schema` 25→26; real numbers in the PR); after the delegation `trait-swap` → 0 (`--update`); net about 215 → 210.
- `check_modules.py --update`: schedule→people and schedule→approvals go down; the three pending "fewer" lines are written.

---

## 3. Order of reads, writes and locks the golden traces must pin

- **create** (`ts:132-168`): nonce → `current_employee` → `checkRequest ?? checkNotPast(today)` → `ensure_swap_schema` → `people_employee(target,true)` → (if found) scope → `find(req)` → `find(target)` → `checkSwappable` → COUNT → INSERT → `insert_id` → audit `SCHEDULE_SWAP_REQUESTED` → `my_approvals_enabled` → (on) `swap_employee_user_id`, `swap_inbox_url`, or (off) the plain message → notify → redirect.
- **respond:** ensure → `pendingAsTarget`; `swap_employee_user_id(requester)` read before the branch (SW-l); reject: close Pending→Rejected → audit → notify → redirect; accept: `checkNotPast` → `swap_manager_mode`; with levels two `find` → `checkStillCurrent` → `swap_to_approvers`; without levels `apply` → `achievement_evaluate_swap_acceptance` → audit ACCEPTED → notify requester → notify self.
- **apply:** ensure → START (false → `'save'`, no ROLLBACK) → applyRows → ROLLBACK, or COMMIT (false → ROLLBACK, `'save'`).
- **applyRows:** `find(req)` → `find(target)` → `checkStillCurrent` → clock, actor → swap UPDATE (where status = `$r->status`; a second clock read inside `close`): 1 goes on, 0 → `'expired'`, false → `'save'` → schedule UPDATE ×2 in schedule-id order (false → `'save'`, not 1 → `'changed'`; no second UPDATE after a failure) → week lock record for the requester, then the target (each may INSERT an audit row inside the transaction).
- **toApprovers:** ensure → START → UPDATE Pending→Awaiting (not 1 → ROLLBACK, `'save'`/`'expired'`) → engine start with no transaction of its own (WP_Error → ROLLBACK, `'approval'`) → COMMIT → `$r->status='Awaiting'` → audit → find → APPROVED: `apply` in a second transaction, the approved audit and two notifications (SW-d); otherwise the current step → `people_name` ×2 → notify the approver → notify the requester.
- **managerDecide:** nonce → valid decision → ensure → `awaitingById` → find → posted step → `level_moved` → `can_act` → (approve) `checkNotPast` → START → `act` → find → APPROVED → applyRows; REJECTED → close Awaiting→Rejected (≠ 1 → `'save'`); otherwise true → ROLLBACK or COMMIT → after work; advanced → `find` again → notify the approver.
- **adminDecide:** ensure → `openById` → (Awaiting) find → open: START → `actAsAdministrator` → find (null → ROLLBACK, ERROR) → outcome → applyRows or close; `'changed'` → CHANGED, other → ERROR, with ROLLBACK; COMMIT → ADVANCED: `swap_notify_approver($fresh)`, otherwise `swap_admin_done`. Not open: posted step > 0 → MOVED; approve → `apply`; reject → close from the current status.
- **withdraw:** ensure → `pendingAsRequester` → `canWithdraw(today)` → close → `people_name` → audit → notify the colleague.
- **cancel:** ensure → `pendingAsRequester` → (found) close → audit; redirects as cancelled even when nothing was found (SW-f).
- **Reads:** `swap_my_requests`: ensure → today → `my_approvals_enabled` → `mine` → `people_brief_by_ids` → mode → `approval_find_requests`. `swap_new_choices`: ensure → `daysBetween` → `people_department_member_ids` → teams (`ensure_teams_schema` per call) → `statusesOf` → `busyWith` → `people_brief_by_ids`.

### 3.1 Golden harness
Traces run with `wp_cache_flush()` before each call, so option reads show as SQL and their order is pinned. Existing coverage
(`actions.php`, `actions_approvals.php:160-167`, the schedule, approvals and requests pages) has no swap row.

- **New `tests/golden/seed_swaps.php`:** one department with `swreq`, `swpeer`, `swpeer2`, `swother` (another department), approvers `swappr1`, `swappr2`; Office/WFH days D+1…D+6 and one Vacation; swaps in each state (Pending future and past, Awaiting with an open LEVEL_1 approval, Awaiting at level 1 of 2, Awaiting with an APPROVED approval, Accepted, Rejected by the peer, Rejected by the engine, Cancelled). `shift_swap` left at PEER; actions set the mode per case.
- **New `tests/golden/actions_swap.php`** (keys `swap:`; `evidence_lib.php` / `exit.php`; fault injection `[re, to, n]`): create (bad nonce, no employee, self, bad date, past, other department, same status, Vacation, duplicate, ok with My approvals off and on, INSERT fault); respond (reject ok / close fault; accept: past, PEER ok with the week lock on and a final week, day changed, faults on START, the swap UPDATE, the first and second schedule UPDATE, COMMIT; LEVEL_1: awaiting, day changed, engine-start fault, UPDATE 0, auto-approved with no approver, auto-approved with a changed day); cancel; managerDecide; adminDecide (through `ews31_admin_request_decision` and `$call`); withdraw; every read; at the end the dumped tables.
- **`run.py` sanity guard:** the trace really accepted, rejected, awaited, approved, advanced and refused `changed`; rolled back on every fault with equal START/COMMIT/ROLLBACK counts; wrote `schedule_locked_by_swap`; notified both sides; showed the schedule UPDATEs in id order.
- **`pages.json` additions:** `swreq`, `swappr1` schedule (My approvals off); `swpeer`, `swappr1` approvals (on); `swreq` requests and `requests&new=1` (on); admin `ews31-requests` and `ews31` now show swaps.
- **Mutation self-test in the PR:** swapping the two schedule UPDATEs, calling `ensure` inside the transaction, reading `swap_employee_user_id` after the branch, dropping `LIMIT 1` must each fail the trace.

---

## 4. Tests
- **Unit** `tests/unit/SwapServiceTest.php` with `Fakes/MemorySwapStore.php`, `MemoryScheduleDayStore` additions, `FakeSwapApprovals`, `FakeSwapSite` and a recording `FakeTransaction` on one journal: every branch of `apply`, `applyRows`, `create`, `toApprovers`, `managerDecision`, `adminLevel`; constants equal `Hub`'s. `SwapRulesTest`, `SwapInboxTest` unchanged.
- **Storage contract** `tests/e2e_swap_store.php` (wp eval-file, temporary tables; CI step): SQL text equals the old literal (query filter), formats, raw `int|false`, null rows, raw `forEmployee`, `LIMIT 50` / `LIMIT %d`, `WAITING%`, placeholders, prefix behaviour. `e2e_schedule_store.php` gains the three day methods.
- **e2e to run:** the swap suites (§0.8), `e2e_request_races.py`, `e2e_schedule_store.php`, `e2e_swap_store.php`; golden, unit, phpstan, phpcs, `check_layers`, `check_modules`, translations.
- **Gap (Q3):** no test for (a) two concurrent accepts of the same swap, (b) two Pending swaps on one day sharing the requester, both accepted (the second must be `changed`, no partial write). Add them to `e2e_swap.py` as commit 0, on the unchanged code.

---

## 5. Quirks preserved (SW-; for a later fix release)
- **SW-a** (`ts:143`, `trait-departments.php:76`): an administrator who is also an employee may request a swap with anyone in any department (scope check, not same department); the New request sheet offers only the department.
- **SW-b** (`ts:148-150`): no lock and no unique key: two creates at once for the same pair and day both insert; a requester may have Pending swaps for the same day with different colleagues; only one can apply.
- **SW-c** (`ts:223-253`): the administrator's direct approval has no past-day check (the peer path `ts:185` and the app approver path `ts:369` do).
- **SW-d** (`ts:286`, `ts:307-312`): when the engine approves at once, APPROVED is committed before the exchange runs in a second transaction; a changed day leaves the approval APPROVED and the swap Awaiting (only the Hub's direct path finishes it). On success the requester gets no "waiting" note.
- **SW-e** (`ts:47-51`): `close` overwrites `responded_by` / `responded_at` when an approver or administrator decides; swaps applied after approval end as `Accepted`, not `Approved`.
- **SW-f** (`ts:204-215`): Cancel on the Schedule page has no past-day check (withdraw has, `ts:542`); a swap not found or not owned redirects as "cancelled".
- **SW-g** (`ts:344` vs `ts:235`): the approval status is compared case-sensitively in the app path and upper-cased in the Hub path.
- **SW-h** (`ts:382-386`, `:397-403`, `:430`): the approver list ignores substitutes and is capped at 50; the requests list is capped at 50; My requests shows 10.
- **SW-i** (`ts:481-484`): the per-prefix swaps table is joined with the plugin's fixed schedule table (as AT-k).
- **SW-j** (`ts:197`, `trait-achievements.php:291`): achievements are evaluated only on a peer acceptance, but swaps applied after approval also end as `Accepted` and are counted in milestones.
- **SW-l** (`ts:177`): the requester's user id is read before the decision, even when refused.
- **SW-m** (`ts:262-263`): an administrator's decision sends no push; every other path pushes.
- **SW-n** (`ts:99`): the week lock record (option reads and an audit INSERT) runs inside the transaction.
- **SW-k** (`ts:26`, `:297`): dead `global $wpdb`: removed (no behaviour).

---

## 6. Risks and mitigations
- **Implicit commit:** `ensureSchema` only before begin; pinned by the journal unit test and the golden SQL order.
- **Option-read moments:** the ensure calls and option reads stay at their lines; golden flushes the cache before each call.
- **Concurrency:** double accept → the conditional claim gives 0 → `expired`; two swaps on one day → conditional schedule UPDATE 0 → `changed` → ROLLBACK; deadlocks avoided by id order; admin vs approver decided by the engine's step lock. Unchanged; covered by e2e plus the Q3 tests.
- **Engine inside the caller's transaction:** the adapter calls `approval_*` by name with the same flags.
- **Hub mapping** stays in the adapter and the trait; constants tested. **Mutated `$r->status`** before `swap_finish_approved` kept in the trait. **`insert_id`** read right after the insert. **Raw returns** (`forEmployee`).
- **Multisite prefixes** (SW-i) pinned by the contract test. **Reflection and hook names** unchanged (golden's hook inventory).
- **Query counts:** golden pages and `e2e_speed:122` (≤ 12).
- **Upgrade path:** none (L6). **Off by default:** not applicable; `expected.json` empty.

---

## 7. Commit order (green at each step)
0. (Q3) Race tests in `e2e_swap.py` on the unchanged code.
1. Harness on the unchanged code: `seed_swaps.php`, `actions_swap.php`, the pages, the `run.py` guard; `expected.json` emptied, release 3.31.156; base == head; the mutation self-test fails as it should.
2. Stores: `SwapStore` / `WordPressSwapStore`, `SwapQuery` / `WordPressSwapQuery`, the `ScheduleDayStore` additions; fakes; `e2e_swap_store.php` + CI step; `Services::swapStore()` / `swapQuery()`; `modules.json` slice `swaps` (store, reads, `services: []`); baseline enrolment with `--allow-increase`.
3. Read delegators (`swap_pending_row`, `swap_pending_rows`, `swap_awaiting_my_approval`, `swap_requests_for_user`, `swap_approval_card`, `swap_my_requests`, `swap_peer_waiting`, `swap_waiting_mine_count`, `swap_state_for`, `swap_new_choices`).
4. Ports, service and the first write delegators (`SwapApprovals`, `SwapSite`, `SwapPorts`, `SwapService`, adapters in `trait-services.php`, `Services::swaps()`, unit tests; `swap_close`, `swap_apply`, `swap_apply_rows`, the create core, the `swap_to_approvers` core).
5. `swap_manager_decision` / `swap_manager_decide`, and the open-level core of `swap_admin_decide`.
6. Finish: remove the dead globals; `traits_clean` swap / attendance-grid / attendance-cards; `moved` and `services` lists; `check_layers --update`, `check_modules --update`; `docs/architecture/modules.md`; the `layered-modules.md` L3 note; this file's as-built section; CHANGELOG, version 3.31.156.

### Later slices (summary)
- **L3b:** new `ScheduleDayStore` methods `statusesBetween` (api :34 and types :84 have identical text), `usedStatuses` (config :130), `weekStatuses` (view :50, no ORDER BY), `holidayOn` / `addHoliday` / `deleteHoliday` (config :147-160); team reads (view :22-27, :67) to new `TeamStore` methods with the same text, or left as baseline debt; golden actions `general_leave_save` / `delete`, `schedule_config_save`, `schedule_leave_dates`, `api_me_schedule`.
- **L3c:** `ShiftHistoryStore` (`tsh:69-306`; schema and seed stay); a `NamedLock` port with the exact name format of `ews_with_lock` (`trait-attendance.php:169-183`); `ScheduleMemo` static and process-wide like `PeopleMemo` (quirk: not keyed by prefix); `working_hours` and the shift reads behind `WorkingTime`; a payslip-amount golden trace and the payroll, reports, time report and insights pages; `HELPERS['shift_history_tables']='employee_shift_periods'` with a self-test.

---

## 8. Open questions for the owner (with recommended defaults)
1. **Which slice first:** L3a Swaps (recommended), or the smaller L3b first?
2. **Split:** L3a, L3b and L3c as three move-only releases, each in its own slot between features; L3d with the next office-minimum feature. *Default: yes.* L3a as 3.31.156?
3. **Race tests** (double accept; two swaps on one day sharing a person) as commit 0 on the unchanged code. *Default: yes.*
4. **Quirks SW-a…SW-n:** moved as they are and queued; proposed fix order SW-d, SW-b, SW-c, SW-e, SW-f, then the rest; the product direction of SW-a, SW-c, SW-f and SW-m asked when that release is designed.
5. **Audit and notification text stay in the trait** for swaps (Service = transactional core, Stores = SQL). *Default: keep them in the trait.*
6. **L3c money:** a payslip-amount trace in the golden job (about +1–2 min CI). *Default: yes, when L3c is designed.*
7. **`schedule_table_name()`** (`twt:37`) keeps one baseline reference. *Default: keep it counted.*

---

## 9. Owner's answers (2026-11-20)
1. **First slice:** L3a Swaps.
2. **Race tests:** yes, added first on the unchanged code (double accept; two swaps on one day sharing a person).
3. **Quirks SW-a…SW-n:** fix them **first**, in a fix release before the move (so the move carries the fixed code). The fixes
   are designed separately (`docs/tasks/swap-fixes.md`); the product direction of each is asked below.

## Notes after the swap fixes (3.31.156, before L3a)
The swap code changed before the move (`docs/tasks/swap-fixes.md`); update §2.2 when L3a is built:
- `countOpenBetween` → `openOnDay($date, $employeeId)` (`swap_open_on_day`), read under the person's lock (`swap_create_locked` becomes
  `SwapService::create`; accepting takes the same lock).
- `close(..., $by)`: `responded` or `decided` columns; `applyRows($r, $approved)` ends `Approved` on an approver's decision.
- `toApprovers` now holds `find` and, when approved at start, `applyRows` inside its transaction; `swap_finish_approved` is gone.
- `awaitingApprover($uid, $away)` (the manager away, `approval_inbox_away()`).
