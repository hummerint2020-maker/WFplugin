# Design: moving the approvals engine to the layered shape (L2, move only)

**Status:** this is a design only. It was written by the read-only architect on top of 3.31.127 (`0d11ad3`). Nothing in it is decided for the owner; §8 lists the open questions. Line numbers refer to the current working tree.

**What is being moved.**
- The approvals engine (module `approvals`) moves to Controllers → Services → Stores.
- Behaviour, SQL text, SQL order, transactions, notifications, audit rows, hooks, options and schema all stay the same.
- The schedule change request (F1) is dropped. So the `Approvals\Engine` port and `LegacyApprovalEngine` from plan §3/§8.2 were never built (grep: no hits outside the plan).

---

## 0. What exists (measured)

**Engine traits (about 1,980 lines).**
- `trait-approvals.php`: 1,038 lines.
- `trait-approval-deadlines.php`: 85 lines.
- `trait-substitutes.php`: 399 lines.
- `trait-my-approvals.php`: 307 lines.
- `trait-admin-requests.php`: 153 lines.

**Pure rules that already exist** (unit-tested in `tests/unit/ApprovalsTest.php` and `LevelGuardTest.php`):
- `src/Approvals/{StateMachine,LevelGuard,Workflows,WorkingHours,Substitutes}.php`
- `Hooks.php` (three admin-post actions).

**Tables** (all `$wpdb->prefix.'ews_…'`, named through `approval_tables()` at `trait-approvals.php:13`):

| Table | Owner |
|---|---|
| `approval_workflows` | approvals |
| `approval_workflow_steps` | approvals |
| `approval_requests` | approvals |
| `approval_steps` (the "instances") | approvals |
| `approval_substitutes` (`substitutes_table()`, `trait-substitutes.php:31`) | approvals |
| `employee_relationships` | **people** (modules.json), but created and queried by `trait-approvals.php` |

**No `GET_LOCK` and no `SELECT … FOR UPDATE` in the engine.** Concurrency rests on three things:
- conditional UPDATEs (`WHERE id=… AND status='PENDING'|'WAITING'|<read status>`);
- InnoDB row locks held until the transaction ends;
- the UNIQUE keys `entity_workflow` and `request_step`.

The only GET_LOCKs on these paths are taken by callers before they start a request: `ews_with_lock('advance_emp_…')` (`trait-advances.php:163`) and the per-employee leave and overtime locks (`e2e_request_races`).

**The `$manage_transaction` contract** (`approval_start` :543, `approval_act` :687):
- **`true`:** the engine itself sends `START TRANSACTION … COMMIT`, and `ROLLBACK` on any failure. When a level waits for administrators they are told at once, after COMMIT (`approval_notify_administrators`).
- **`false`:** the caller owns the transaction. The engine never sends BEGIN, COMMIT or ROLLBACK (it only returns `WP_Error`, and the caller rolls back). Administrators are told at `shutdown` priority 5, and only if the level is still PENDING, rerouted and without an approver (`approval_notify_administrators_after` :287). So a rolled-back request tells nobody.
- **In both modes:** reads such as workflow, existing request, steps, approvers and the request/step rows of `act` happen **before** BEGIN when the engine owns the transaction. Conditional updates protect against what changed in between.
- `approval_close` and `approval_close_open` never begin or commit (the caller owns the transaction). The substitutes sync is always deferred to `shutdown` (`substitutes_sync_at_shutdown`, one flag per plugin object).

---

## 1. Inventory

Format: `function` (file:line) — callers. Tags: [SQL] runs SQL, [TX] transaction, [N] notifies, [A] audits.

### 1.1 Start a request
- **`approval_start($wk,$et,$eid,$requester,$ctx=[],$manage=true)`** (:543) [SQL TX N]
  - **Callers:**
    - leave :294 (false) and :413 cancellation (false), inside `leave_transaction`;
    - overtime :133 (false);
    - swap :281 (false);
    - corrections :301 (true);
    - advances :170 (false, under GET_LOCK);
    - face-reset :62 (true);
    - tests via Reflection (`e2e_query_batching`, `e2e_approval_engine` …).
  - **Reads in this order:**
    1. active workflow by key;
    2. existing request;
    3. active steps;
    4. `approval_self_rule` (options);
    5. `people_user_id(requester)`;
    6. for each step: `approval_resolve_approver` (:402; relationships, teams, departments, HR/Finance `get_user_by` + `people_employee_by_user`).
  - **Then:** `StateMachine::start`; BEGIN if `$manage`; INSERT request; for each step, `substitute_route` (a SELECT on substitutes **inside the transaction**) then INSERT step; COMMIT; notify administrators (now or later).
- **`approval_resolve_approver`** (:402): internal. **`approval_mode_family`** (:479): internal.
- **`approval_seed_workflows`** (:297) [SQL]: from `ensure_approval_schema`.

### 1.2 Act on a step
- **`approval_act($id,$decision,$comment='',$manage=true,$override=false,$expected=null)`** (:687) [SQL TX N A]
  - **Callers:**
    - leave :358, :454 (false), :508 Hub (false, override);
    - overtime :162 (false), :282 Hub (false, override);
    - swap :231 Hub (false, override), :330 app (**true**);
    - corrections :369 (true, override when Hub);
    - advances :214 (false);
    - face-reset :125 (true).
  - **Order:**
    1. SELECT request (`… WHERE id=%d LIMIT 1`);
    2. SELECT current PENDING step;
    3. `LevelGuard` check (before the approver check);
    4. login check;
    5. `approval_may_decide`;
    6. reject-note rule (`approval_workflow_key_by_id`, a query);
    7. `$is_self` (`people_user_id`);
    8. SELECT next WAITING step;
    9. BEGIN;
    10. UPDATE step `WHERE id AND status='PENDING'` (`!==1` → `approval_moved` / `approval_stale`);
    11. UPDATE next `WHERE status='WAITING'` (with `substitute_route`);
    12. UPDATE request (by id only);
    13. COMMIT;
    14. `substitute_after_decision` (UPDATE substitutes `decided+1`, then shutdown sync);
    15. audit `approval_self_decision` (a third workflow-key query);
    16. notify administrators.
  - **Lock order:** step row, then request row.

### 1.3 May decide and permissions
- **`approval_can_act($id,$order=null)`** (:650) [SQL]: `is_user_logged_in()` first, then a PENDING step (`… LIMIT 1`, no ORDER), then `approval_may_decide`.
  - **Callers:** leave, overtime, swap, corrections, advances, face-reset (7 calls).
- **`approval_may_decide($step,$uid,$override)`** (:668) [SQL]:
  1. SELECT request `WHERE id=%d` (a different text from `act`'s);
  2. self rule (workflow-key query; the requester's user);
  3. override;
  4. approver;
  5. `substitute_manager_may_decide`;
  6. rerouted + `user_can(manage_options)`.
- **`approval_self_gate`** (:145) [A]: no SQL. Used by leave, overtime and daily_workers outside a workflow. Stays in the trait.
- **Option readers** (no SQL beyond `get_option`, stay in the trait as the policy source):
  - `approval_self_rule` (:158), `approval_self_allowed` (:135);
  - `approval_reject_note_required` (:167), `approval_deadlines` (:242);
  - `approval_overdue_hours` (:174), `approval_settings` (:252).
- **`approval_requester_user_id`** (:261), **`approval_workflow_key_by_id`** (:267) [SQL]: internal.

### 1.4 Level routing, "moved up", and lists
- **`approval_level_moved`** (:196), **`approval_level_moved_for`** (:206) [SQL through find / current step].
  - **Callers:** leave, overtime, swap, corrections, advances, face-reset (13 calls).
- **`approval_posted_step`, `approval_posted_note`, `approval_error_code`, `approval_moved_error`**: superglobals and WP_Error; stay in the trait as controllers.
- **`approval_find_request`** (:513, joins workflows): 34 calls in 7 modules.
- **`approval_find_requests`** (:781): swap, admin-requests, approvals.
- **`approval_current_step`** (:519): 14 calls.
- **`approval_current_steps`** (:531): Hub.
- **`approval_shown_steps`** (:229): corrections, leave-view.
- **`approval_request_key`** (:770): pure (`sanitize_key`).

### 1.5 Substitutes (`trait-substitutes.php`)
- **Engine hooks:**
  - `substitute_route` (:87), from `approval_start` / `approval_act`, through `substitute_active_for` (:76) [SQL];
  - `substitute_manager_may_decide` (:96);
  - `substitute_after_decision` (:102) [SQL];
  - `substitutes_sync_at_shutdown` (:110), from leave, approvals and substitutes.
- **Sync and lifecycle:**
  - `substitutes_sync` (:134) [SQL N A]. Reads `leave_status_of` from **leave's table**. Called from the tick, `maybe_sync` and `create`.
  - `substitute_take_waiting` (:156), which UPDATEs `approval_steps`.
  - `substitute_begin` (:167), `substitute_finish` (:180), which UPDATEs `approval_steps`.
  - `substitute_tell_picked` (:204).
- **Validation and creation:**
  - `substitute_check` (:224) [SQL, plus `leave_away_between` from leave];
  - `substitute_create` (:249) [SQL A N, `created_by = get_current_user_id()`].
- **Controllers:** `substitute_save` (:342), `substitute_end` (:363, SQL find), `admin_substitutes` (:389, SQL list).
- **View data:** `substitute_app_data` (:317) [SQL], `substitute_choices` (:268) (people).
- **Throttle:** `substitutes_maybe_sync` (:120) on `init` priority 30, keyed on an option.

### 1.6 Deadlines and reminders cron
- **`approval_deadlines_tick($now=null)`** (deadlines :15) [SQL N], on `ews_approvals_tick` (hourly). There is **no current user**.
  1. UPDATE backfilling `pending_since`;
  2. for each workflow with deadlines: SELECT up to 500 PENDING steps;
  3. remind: conditional UPDATE `reminded_at`, then notify;
  4. or `approval_escalate` (:51): conditional UPDATE `… AND approver_wp_user_id=%d`, then audit `approval_escalated`, then notify the old approver, then notify the new one or the administrators.
- **`approval_kind_label`** (:80): text, stays.

### 1.7 Close, cancel, withdraw
- **`approval_close($wk,$et,$eid)`** (:898) [SQL], for a withdraw by the employee.
  - **Callers:** leave :596, overtime :360, advances :238, each inside the caller's transaction **after** its own row was updated.
  - **Order:** find; shutdown sync; UPDATE **request** `WHERE id,status=<read>`; then UPDATE steps.
- **`approval_close_open($wk,$et,$eid,$closed=false)`** (:915) [SQL], from corrections :412 and :1096.
  - **Order:** clear `last_error`; find; sync; UPDATE **steps first**, then the request → `true` | `'moved'` | `false`.
- **`face_reset_clear_finished_approval`** (face-reset :86) [SQL]: DELETE steps and request directly on approvals tables.

### 1.8 Path and steps views
- **`approval_paths($keys,$now=null)`** (:822) [SQL]. Callers: my-approvals and approvals; Reflection in `e2e_query_batching` and `e2e_my_approvals`.
  - SQL: `find_requests` + steps IN (…).
  - People reads: `people_names_by_user`, `cache_users`, `people_brief_by_ids`.
  - **Then text:** `__()`, `date_i18n`, `number_format_i18n`, WorkingHours.
- **`approval_path`** (:810): used by advances.
- **`approval_user_name`** (:763): my-approvals; people reads.
- **`approval_resolver_label`**: text.
- **My approvals:**
  - `my_approvals_items` (my-approvals :69) [SQL inbox query, memo `$my_approvals_memo`];
  - `my_approvals_allowed` (:45) [SQL];
  - `approvals_content` (:164) [SQL, the week count];
  - `requests_content`: no SQL of its own.
- **Requests Hub:** `admin_request_rows` (admin-requests :16) reads **other modules'** tables (leave, overtime, early leave, legacy vacation) and calls `approval_find_requests` / `approval_current_steps`.

### 1.9 Reassign on an org move
- **`approval_reassign_supervisor($eid,$from,$to,&$pending)`** (:375) [SQL]. Called from `org_supervisor_change` (org-moves :123) **inside its transaction**. Also runs from cron for scheduled moves (no user).
- **`approval_reassign_notify`** (:391) [N]: called after the caller's COMMIT (:135).
- **`approval_waiting_on_supervisors`** (:359) [SQL]: org-chart :83.

### 1.10 Workflows admin
- **`approval_workflow`** (:473) [SQL]: 9 calls in 7 modules.
- **`approval_configure_workflow`** (:488) [SQL TX]: advances and the admin page; Reflection in e2e.
- **Controllers** (stay in the trait): `admin_approval_workflows` (:932), `approval_workflow_save` (:971), `approval_settings_save` (:1006).
  - `admin_approval_workflows` has 3 SQL reads, one of which **joins `employees`** (`self_decisions`, :961).

### 1.11 People-table code that lives in this trait
- `approval_related_employee` (:323), `approval_supervisor_ids` (:340), `approval_set_relationship` (:446), `approval_relationship_save` (:1020).
- They query `employee_relationships` plus `employees` (both owned by people).
- Callers: employee-admin, people-view, my-profile, employee-profile, substitutes, deadlines.
- **They do not move with the engine** (L3 forbids it). They move with L4 People.

### 1.12 Other modules that query engine tables directly
- **Single-table reads/writes:**
  - `leave_notify_pending_approvers` (leave :235): `SELECT * … steps WHERE request AND PENDING`;
  - `face_reset_clear_finished_approval` (face-reset :86).
- **Joins with their own tables (read models):**
  - `cx_to_decide` (corrections :745);
  - `leave_view_manager_rows` (leave-view :33);
  - the overtime manager rows (leave-view :150);
  - `swap_awaiting_my_approval` (swap :351).
- **Helper misuse:** `employee_admin_set_supervisor` (employee-admin :86) uses `approval_tables()['relationships']`.
- **Test fixture:** `tests/fixtures/approval_path_3_31_111.php` uses `approval_tables()` and must keep working.

---

## 2. Target shape

### 2.1 Services (`src/Approvals/`)
The engine is split by responsibility. Each part is about 100–350 lines and is unit-testable alone.

| Service | Owns | Why separate |
|---|---|---|
| `ApprovalEngine` | `start`, `act`, `mayDecide`, `canAct`, `levelMoved`, `closeWithdrawn`, `closeOpen`, `purge`, `reassignSupervisor`, `waitingOnSupervisors`, `stillWithAdministrators` | Every write to requests and steps, and every transaction, is in one class. The lock order lives in its docblock. |
| `ApprovalReads` | `findRequest`, `findRequests`, `currentStep`, `currentSteps`, `pendingSteps`, `stepsOf`, `inboxRows`, `hasAnyStep`, `decidedSince` | Read-only list queries for pages (no transaction). It keeps `ApprovalEngine` small, and pages can be tested on fakes. |
| `WorkflowService` | `workflow`, `activeWorkflow`, `keyById`, `steps`, `configure` (its own transaction), `seedDefaults` | A different aggregate (the definitions). |
| `DeadlineService` | `tick` (+ private `escalate`) | The cron path with no user. Its clock and its list come in as arguments. |
| `SubstituteService` | `activeFor`, `route`, `managerMayDecide`, `afterDecision`, `sync`, `finish`, `check`, `create`, `ownRows`, `forRows`, `adminRows`, `find` | Its own table and lifecycle. It changes steps only through the request Store. |

**Results.**
- `src/Approvals/ApprovalResult.php`: `final class ApprovalResult { bool $ok; mixed $value; string $code; string $message; static ok($v); static error($code,$msg) }`.
- The trait turns it back into exactly today's return (`int` / `true` / `new WP_Error($code,$message)`). Services never build a `WP_Error`, and messages stay the same English strings.

### 2.2 Signatures (actor and time are always arguments; names and values come already sanitised by the trait)

```php
ApprovalEngine::start(string $wk, string $et, int $entityId, int $requesterEid, array $ctx, bool $manageTransaction): ApprovalResult  // value: int id
ApprovalEngine::act(int $requestId, string $decision, string $comment, bool $manageTransaction, bool $adminOverride,
                    ?int $expectedStep, int $actorUid, bool $loggedIn): ApprovalResult                                      // value: true
ApprovalEngine::mayDecide(object $step, int $uid, bool $adminOverride): ApprovalResult
ApprovalEngine::canAct(int $requestId, ?int $order, int $uid): bool          // the trait checks is_user_logged_in() first, as now
ApprovalEngine::levelMoved(int $requestId, ?int $posted): bool
ApprovalEngine::closeWithdrawn(string $wk, string $et, int $eid): bool       // approval_close
ApprovalEngine::closeOpen(string $wk, string $et, int $eid, bool $closed)    // true|'moved'|false
ApprovalEngine::purge(int $requestId): void                                  // face reset's DELETE steps, then request
ApprovalEngine::reassignSupervisor(int $eid, int $from, int $to, int &$pending)  // int|false
ApprovalEngine::stillWithAdministrators(int $requestId, int $order): bool
DeadlineService::tick(array $deadlinesByKey, string $now): array             // {reminded,moved}
SubstituteService::sync(string $now): array; create(int $mgr,int $sub,string $starts,string $ends,string $source,string $reason,int $leaveId,string $status,int $actorUid)  // int|string
WorkflowService::configure(string $wk, string $mode, array $configs, ?array $supported): ApprovalResult
```

### 2.3 Stores (`src/Approvals/Data/`): one per aggregate; SQL copied verbatim, the same text and the same `$wpdb` format arrays

- **`ApprovalRequestStore` + `WordPressApprovalRequestStore`.**
  - **Tables:** `approval_requests` and `approval_steps`. Its finders join `approval_workflows` read-only, because the request is found by workflow key and splitting that join would change the SQL. The join is allowed because both stores are in the same slice (§4).
  - **Several methods per family, because the SQL text differs and the trace compares text:**
    - request reads: `requestRow(int)` (`LIMIT 1`, from act) and `requestForCheck(int)` (no LIMIT, from mayDecide);
    - step reads: `currentStep(int)` (ORDER BY step_order LIMIT 1), `pendingStepAt(int,?int)` (can_act form), `pendingSteps(int)` (leave form), `nextWaiting(int)`;
    - request lookups: `existingId`, `findByKey`, `findByKeyGroups(array $groups)` (builds the same OR'ed prepared WHERE, chunks of 500);
    - batch step reads: `currentSteps(array)`, `stepsOf(array)`.
  - **Writes and other reads:**
    - start and act: `insertRequest(array $row,array $fmt): int`, `insertStep(array,array): bool`, `decideStep(int,array): int|false`, `openNext(int,array): int|false`, `updateRequest(int,array,array): int|false`;
    - close: `cancelRequestIf(int,string $status,string $now)`, `cancelOpenSteps(int,bool $closed)`;
    - face reset: `deleteRequest(int)`;
    - after-commit check: `stillWithAdministrators(int,int)`;
    - org moves: `waitingOnSupervisors()`, `supervisorStepIds(int,int)`, `reassignSteps(array,int,int)`, `countPending(array)`;
    - deadlines: `backfillPendingSince`, `overdueCandidates(string $wk)`, `markReminded`, `escalate(int $stepId,?array $up,int $fromUid,string $now)` (the SET fragment is prepared inside, with the same text);
    - substitutes: `assignWaitingToSubstitute(...)`, `returnToManager(...)`, `pendingForSubstitute(int)`;
    - My approvals: `inboxRows(int $uid,int $admin,int $away)`, `hasAnyStep(int)`, `decidedSince(int,string)`;
    - `lastError()`.
- **`WorkflowStore` + `WordPressWorkflowStore`:** `approval_workflows` and `approval_workflow_steps`.
  - `byKey`, `activeByKey`, `keyById`, `activeSteps`, `allSteps`;
  - `idByKey` + `insertWorkflow` (seed), `setModeActive`, `deleteSteps`, `insertStep(row,fmt)`;
  - `lastError`.
- **`SubstituteStore` + `WordPressSubstituteStore`:** `approval_substitutes`.
  - reads: `activeFor`, `linkedToLeave`, `current`, `active`, `find`, `forSubstitute`, `ownOpen`, `adminList`;
  - writes: `transition(int $id,string $from,array $set)`, `incrementDecided`, `decided`, `insert`;
  - overlap checks: `overlapForManager` and `overlapForCandidate` (two texts).
- Each Store builds its table names from `$db->prefix` when it is built. The container is keyed by prefix, so this gives the same tables as today's per-call `approval_tables()`.

### 2.4 Ports
New in `src/Platform/`:
- **`Transaction`:** `begin(): bool`, `commit(): bool`, `rollback(): void`. They send exactly `START TRANSACTION`, `COMMIT` and `ROLLBACK`.
  - Not nesting-aware: the `$manage_transaction` flag stays the contract, and the Service calls `begin`/`commit` only when it is true.
  - Stores never begin or commit.
- **`Permissions`:** `isAdmin(int $uid): bool` (`user_can(...,'manage_options')`). It is called lazily, at the same point as today.
- **`Org`:** `supervisorOf(int)` (→ `approval_related_employee(…,'supervisor')`), `departmentManager`, `teamManager`, `hasReports`.
- **`People`, extended:** `userId(int $eid)`, `employee(int,bool $activeOnly)`, `employeeByUser(int)`, `name(int)`, `userExists(int)`.
  - `namesByUser` and `briefsByIds` stay in the trait's path presenter.

New in `src/Approvals/`:
- **`ApprovalPolicy`:** `selfRule`, `rejectNoteRequired`, `deadlines`, `settings`, `workingDays`, `substitutesEnabled`, `substituteSettings`.
  - Its adapter calls today's trait readers. **It is lazy**: options that are not autoloaded query the database on their first read, so they must be read at the same point.
- **`ApprovalNotifier`:**
  - `administratorsNeeded(int $req,int $order)`;
  - `reminded(int $uid,string $et,int $requesterEid,float $hours,int $req)`;
  - `movedUp(int $fromUid,?object $up,string $et,int $requesterEid,int $req)`;
  - `nowWaitingForYou(int $toUid,int $eid)`;
  - `substituteStarted(object $row,int $moved)`, `substituteEnded(int $muid,object $row,int $decided,int $back,int $still)`, `substitutePicked(object $row)`.
  - Words, links, `get_users` and `notify()` stay in the adapter, byte for byte, as `ScheduleNotifier`.
- **`AfterRequest`:**
  - `syncSubstitutes()` → `$this->substitutes_sync_at_shutdown()` (it keeps its flag and its default priority);
  - `administratorsIfStillWaiting(int,int)` → `add_action('shutdown', …, 5)`, whose closure calls `$this->services()` **at shutdown** (the prefix current then, as today) and then `stillWithAdministrators`.
- **`LeaveFacts`:** `statusOf(int $leaveId)`, `awayBetween(int,string,string)`. The adapter calls the leave module's functions.
- **Existing:** `Clock` and `AuditLog`.

**Wiring.**
- `Services` gains `approvalRequestStore()`, `workflowStore()`, `substituteStore()`, `approvals()`, `approvalReads()`, `workflows()`, `approvalDeadlines()` and `substitutes()`.
- Its constructor takes a new `Approvals\ApprovalPorts` holder (policy, notifier, afterRequest, leaveFacts) plus `Transaction`, `Permissions` and `Org`.
- The adapters are anonymous classes in `includes/trait-services.php`, as in 3.31.127.

### 2.5 Interface for other modules
**No `Approvals\Engine` port now.** The engine itself moves, so `LegacyApprovalEngine` would wrap the very code being replaced.
- Callers keep calling the same trait names, whose bodies delegate. That gives zero edits in leave, overtime, swap, corrections, advances, face reset, org moves and daily workers.
- `modules.json` `exports` lists `WorkforceOne\Approvals\ApprovalEngine` and `ApprovalReads` for future layered callers (the mobile API, L6).
- An interface is extracted when the first layered caller needs to fake it.
- Plan §3 (F1 row) and §8.2 get a one-line note.

**Kept as delegators** (names and signatures unchanged; §1.3 of the plan):
- request engine: `approval_start`, `approval_act`, `approval_can_act`, `approval_may_decide`, `approval_close`, `approval_close_open`;
- lookups and level checks: `approval_find_request(s)`, `approval_current_step(s)`, `approval_path(s)`, `approval_shown_steps`, `approval_level_moved(_for)`;
- workflows: `approval_workflow`, `approval_workflow_key_by_id`, `approval_configure_workflow`, `approval_seed_workflows`;
- org moves: `approval_reassign_supervisor`, `approval_reassign_notify`, `approval_waiting_on_supervisors`;
- admin notices and internals: `approval_notify_administrators_after`, `approval_requester_user_id`;
- crons: `approval_deadlines_tick`;
- substitutes: `substitutes_sync`, `substitute_route`, `substitute_manager_may_decide`, `substitute_after_decision`, `substitute_check`, `substitute_create`, `substitute_finish`, `substitute_app_data`;
- `my_approvals_items` and `my_approvals_allowed` lose their SQL.

**Deleted** (internal only; grep `tests/` first): `approval_resolve_approver`, `approval_mode_family`, `approval_escalate`, `substitute_active_for`, `substitute_take_waiting`, `substitute_begin`.

**Stays in the traits as controllers or presenters:**
- nonces, `can()`, redirects, templates and superglobals: `approval_posted_*`, `approval_error_code`, `approval_moved_error`, all admin-post handlers, `request_withdraw`, the `*_content` views;
- the option readers;
- text: `approval_kind_label`, `approval_resolver_label`;
- **the string-building loop of `approval_paths`** (it gets its rows from `ApprovalReads::stepsOf`), `approval_request_key`;
- schema: `ensure_approval_schema`, `ensure_substitutes_schema`;
- `approval_tables()` and `substitutes_table()` (for the schema, for other modules' joins and for the fixture);
- §1.11 people code;
- the `admin_approval_workflows` self-decisions query (it joins `employees`);
- the throttle option of `substitutes_maybe_sync`;
- `$my_approvals_memo` (a page memo of cards from 6 modules), `$substitute_leave_pick`.

Optional, in its own commit (open question 3): `leave_notify_pending_approvers` → `approvalReads()->pendingSteps()`, and `face_reset_clear_finished_approval` → `approvals()->purge()`. Same SQL text. Each lowers the baseline by 1.

---

## 3. Equivalence strategy

1. **Verbatim SQL and order.** Every statement is copied with the same text, format arrays, `LIMIT`, `ORDER BY` and chunking, and runs at the same point relative to the port calls. Examples:
   - `substitute_route` stays inside the start transaction;
   - `people_user_id` stays after the self rule;
   - the workflow key is still read three times per `act`;
   - `last_error` is still read **after** ROLLBACK, as today (see Q3).

   No new caches and no merged reads in this release.
2. **Golden approval trace** in a new `tests/golden/actions_approvals.php`. `run.py` runs every `actions*.php`. The trace is compared base vs head from the same snapshot.
   - **It records:**
     - each call's return (WP_Error → `[code,message]`) and the redirect `Location`;
     - **the exact SQL sequence per call**, captured with `add_filter('query')`, times and `NOW` literals made placeholders, `START TRANSACTION` / `COMMIT` / `ROLLBACK` included (this proves the lock order);
     - `$wpdb->num_queries` per call (head ≤ base);
     - all `ews_*` rows, notifications, audit and options at the end, dumped from a `shutdown` callback at `PHP_INT_MAX`, so the deferred notifications at priority 5 and the substitutes sync are included.
   - **Running handlers that `exit`** (leave approve, advance respond, swap decide, withdraw, substitute save/end, workflow save): in process, with `wp_redirect` and `wp_die_handler` filters that throw a `GoldenExit($location)`, and `$_POST`/`$_REQUEST['_wpnonce']=wp_create_nonce(...)` for the user set with `wp_set_current_user`.
   - **Matrix**, run for each type (leave, leave cancellation, overtime, correction including `pending_hr`, swap with levels, face reset, salary advance; daily workers only for the self gate):
     - start: NONE (auto-approved), LEVEL_1, LEVEL_2 with `min_amount` skipped, a missing approver (skip / administrators);
     - approve L1→L2, final approve, reject with and without the required note, Hub override;
     - double post (the second call → `approval_stale` or `moved`), a stale `approval_step` (level moved);
     - self rule `allow` / `next` / `admins` (with the audit row);
     - substitute: routed at start, routed at the next level, waiting steps taken at begin, `manager_decides`, finish `back` / `stay`, the `decided` count;
     - deadlines: tick at `now+N days` → remind, then escalate (to the manager's manager, and to the administrators when there is none);
     - withdraw for leave, overtime, advance, correction and swap; `cx_close` (`closed=true`);
     - an org move through `org_supervisor_change` with reassign and notify;
     - face reset asked twice (purge);
     - `approval_configure_workflow` error and success;
     - `approval_paths`, `my_approvals_items` and `approval_waiting_on_supervisors` as data.
   - **Seed:** `tests/golden/seed_approvals.php` runs once before the snapshot, **through the base side**. It configures all workflows and supervisors (mona → big), HR (hana), a substitute (sami), and open, escalated, rerouted, rejected and withdrawn requests. New logins: mona, big, hana, sami.
3. **Pages added to `pages.json`:**

   | Page | Who | Switches / variant |
   |---|---|---|
   | `ews31-approvals` | admin | plain; `&s=…&paged=2`; `&approval_error=1&approval_error_message=x` |
   | `ews31-requests` (with levels) | admin | — |
   | `ews31-substitutes` | admin | substitutes off and on |
   | `/app/?ews_view=approvals` | mona, big, hana, sami, admin | `ews_feature_my_approvals=1` and `ews_feature_substitutes=1`; `&apv_kind=leave`; `&apv_kind=late` |
   | `/app/?ews_view=requests` (+`&new=1`) | emp1 | My approvals on |
   | `/app/` home card | mona | My approvals on |
   | manager pages: vacation, overtime, corrections, schedule (swap awaiting) | mona | — |
   | Org chart; employee profile | admin | — |
   | Advances admin | admin | — |

   New normalisation: `waiting [\d.,]+ working hours` and the overdue flag (re-fetched if they differ, as for clock boundaries).
4. **Hook inventory** unchanged (`shutdown` priorities 5 and 10, `init` 30, `ews_approvals_tick` callback order). `expected.json` stays empty.
5. **Safety net (all must stay green):**
   - approvals: `e2e_approvals`, `e2e_approval_engine`, `e2e_my_approvals` (+ `my_approvals_browser.js`), `e2e_self_approval`, `e2e_level_guard`, `e2e_request_races`, `e2e_substitutes`, `e2e_admin_requests`;
   - leave and overtime: `e2e_leave`, `e2e_leave_view`, `e2e_leave_admin`, `e2e_overtime`;
   - swaps and corrections: `e2e_swap`, `e2e_swap_inbox`, `e2e_swap_mu.php`, `e2e_corrections`, `e2e_corrections_mu.php`;
   - advances, face reset and daily workers: `e2e_advances`, `e2e_presence_face`, `e2e_daily_workers`;
   - organisation and profiles: `e2e_org_chart`, `e2e_org_moves`, `e2e_organization`, `e2e_my_profile`, `e2e_employee_profile`;
   - shared: `e2e_query_batching` (with its fixture), `e2e_speed`, `e2e_notification_inbox`, `e2e_notification_links`;
   - checks and tools: golden, unit, phpstan, phpcs, `check_modules`, `check_layers`, `check_translations`.

---

## 4. `check_layers.py` and `modules.json`

**`check_layers.py`:**
- `store` may be a string or a list. L3 is checked for each store against its slice's tables. Self-test: a list with one bad store fails.
- L1 also counts the table helpers: `HELPER_TABLES = {'approval_tables': 'approval_steps', 'substitutes_table': 'approval_substitutes'}`. Each `$this->approval_tables()` or `$this->substitutes_table()` call counts as one reference. Self-test included.
- Today, uses through `{$t['instances']}` are invisible, so without this the ratchet would see almost nothing.
- **Known false positive:** employee-admin :86 uses the helper for `relationships`. Counting it is acceptable, or the line can be changed to the literal (owner, Q3).

**`modules.json` → `approvals`:**
```json
"layered": [
 {"slice": "engine", "tables": ["approval_workflows","approval_workflow_steps","approval_requests","approval_steps"],
  "store": ["workforce-one/src/Approvals/Data/WordPressApprovalRequestStore.php","workforce-one/src/Approvals/Data/WordPressWorkflowStore.php"],
  "services": ["workforce-one/src/Approvals/ApprovalEngine.php","workforce-one/src/Approvals/ApprovalReads.php",
               "workforce-one/src/Approvals/WorkflowService.php","workforce-one/src/Approvals/DeadlineService.php"],
  "moved": ["approval_start","approval_act","approval_can_act","approval_may_decide","approval_close","approval_close_open",
            "approval_find_request","approval_find_requests","approval_current_step","approval_current_steps","approval_paths",
            "approval_shown_steps","approval_level_moved","approval_level_moved_for","approval_workflow","approval_workflow_key_by_id",
            "approval_configure_workflow","approval_seed_workflows","approval_reassign_supervisor","approval_waiting_on_supervisors",
            "approval_notify_administrators_after","approval_deadlines_tick","my_approvals_items","my_approvals_allowed","approvals_content"],
  "traits_clean": ["approval-deadlines","my-approvals"]},
 {"slice": "substitutes", "tables": ["approval_substitutes"],
  "store": "workforce-one/src/Approvals/Data/WordPressSubstituteStore.php",
  "services": ["workforce-one/src/Approvals/SubstituteService.php"],
  "moved": ["substitutes_sync","substitute_route","substitute_after_decision","substitute_check","substitute_create",
            "substitute_finish","substitute_app_data","admin_substitutes","substitute_end"]}],
"exports": ["WorkforceOne\\Approvals\\ApprovalEngine","WorkforceOne\\Approvals\\ApprovalReads"]
```

**Not clean:**
- `trait-approvals` (schema, people code, the self-decisions join);
- `trait-substitutes` (dbDelta);
- `trait-admin-requests` (other modules' tables).

**Baseline effect** (`layer-baseline.json`).
- This is the first time these tables are enrolled, so **new rows appear**:
  - in the approvals module: `trait-approvals` (the `approval_tables()` literals + schema + admin page, about 8), `trait-substitutes` (about 2);
  - in other modules: corrections 1, leave-view 2, swap 1, leave 1→0 and face-reset 1→0 (optional), employee-admin 1;
  - in shared files: `uninstall.php` 5, `src/Support/SchemaCheck.php` 3.
- `--update` refuses new rows, so the first enrolment needs `--allow-increase` once (Q7).
- After that it only goes down.
- `check_modules` is unchanged: cross-module calls keep their names, and moved SQL leaves the traits.

---

## 5. Slicing

**Recommended: one release.** Start and act share the step and request rows with substitutes, deadlines and close, and every request type reaches all of them. Moving the writes in two halves leaves two implementations of the same conditional updates and lock order (a half-moved engine).

**A safe two-release split exists if the owner wants it (Q1):**
- **L2a**, read-only: `ApprovalReads`, `WorkflowService`, the Stores' reads and the golden pages. Low risk, HTML proof.
- **L2b**, all writes: start, act, close, reassign, deadlines, substitutes, with the trace.

L2a enrols the tables. Their writes stay in the trait under the L1 ratchet, which the plan allows.

**Size (one release):**
- **New `src/` code (about 1,900 lines, 16 files):** 5 services, `ApprovalResult`, 4 ports, 3 Store interfaces plus 3 implementations, and the `Services`/`Platform` port changes.
- **Changed `includes/` code (about 900 lines of bodies, 8 files):** `trait-approvals`, `trait-approval-deadlines`, `trait-substitutes`, `trait-my-approvals`, `trait-services`, and optionally `trait-leave` / `trait-face-reset`.
- **Tests (about 1,600 lines):** fakes, unit tests, store e2e, golden actions and seed.

**Commit order (green at each step):**
1. Harness only, against unchanged code:
   - `actions_approvals.php`, `seed_approvals.php`, new pages and logins, the SQL-sequence capture, `GoldenExit`;
   - base == head;
   - deliberately reordering two statements makes it fail (self-test).
2. `check_layers.py`: list stores and helper counting, with self-tests. No new slice yet.
3. Ports and container adapters (no caller yet).
4. Stores, fakes, `e2e_approval_store.php`.
5. Reads: find, current, steps, the paths presenter, inbox, counts.
6. `WorkflowService`.
7. `ApprovalEngine` start, act, mayDecide, canAct, levelMoved (one commit).
8. Close, closeOpen, purge, reassign, waiting.
9. `SubstituteService`.
10. `DeadlineService`.
11. Optional cross-module replacements.
12. `modules.json`, baseline, docs.

Each commit runs unit, phpstan, phpcs, golden and the §3.5 suites touched.

---

## 6. Risks and mitigations

| Risk | Mitigation |
|---|---|
| **Double decision / money paths** (advances amount, leave balance, overtime hours) | The step UPDATE `WHERE status='PENDING'` and the `!==1` → `approval_moved` / `approval_stale` stay verbatim. The trace covers double posts for each type. A two-process store e2e: A holds the transaction after deciding, B decides and blocks, then gets stale. `$ctx['amount']` is passed through unchanged (floats). |
| **Lock order.** Decisions lock step → request → module row; withdraws lock module row → request → steps (`approval_close`); swap's fixed id order is in `swap_apply` (not moved) | Written in the `ApprovalEngine` docblock. `closeWithdrawn` keeps request-first and `closeOpen` keeps steps-first (a faithful move; see Q1 below). The SQL-sequence trace makes any reordering fail. |
| **`$manage_transaction`** | Same flag and same branches. The `Transaction` port only sends the three strings. Unit tests run with a fake that records begin/commit/rollback and can fail each one. |
| **Notify after commit** | `administratorsNeeded` runs after COMMIT when the engine owns the transaction. Otherwise `AfterRequest::administratorsIfStillWaiting` (shutdown priority 5, re-checked). Reassign notify stays the caller's step after its COMMIT. Deadlines and substitutes notify after their autocommit UPDATEs, as now. |
| **Static and request caches** | No new cache. `$my_approvals_memo` and `$substitutes_shutdown_sync` stay on the plugin object (same semantics). People memos from 3.31.126 are reached through the same functions by the adapters. `e2e_speed` and golden query counts pin this. |
| **Option reads change the query order** (options saved with autoload off) | The policy port is lazy and called at the same points. The SQL-sequence trace catches drift. |
| **Cron with no current user** (deadlines tick, substitutes tick, `maybe_sync` on `init` for visitors, scheduled org moves) | The actor is an argument (0). `get_current_user_id()` / `current_time()` are banned in services (L2). The trace runs the tick logged out. |
| **Permissions** | `is_user_logged_in()` is still checked first in `approval_can_act` (trait). `isAdmin` is lazy and called at the same branch. The override and login order of `act` is kept. Unit tests cover every `mayDecide` branch (self not allowed before override; substitute's manager; rerouted + admin). |
| **Multisite prefix** | Container per prefix, Stores built from `$db->prefix`. The shutdown closures call `services()` at shutdown, as `approval_tables()` did. Store e2e: `switch_to_blog` writes blog 2's tables, and switching back reuses blog 1's Store. `e2e_swap_mu` and `e2e_corrections_mu` stay. |
| **Hooks and names others rely on** | All callers, Reflection names (`approval_start`, `approval_paths`, `approval_configure_workflow`, `approval_set_relationship`, `my_approvals_items`, `requests_content`) and the fixture's `approval_tables()` are kept. The harness inventory diff covers hooks. |
| **Schema** | Not touched (`ensure_*_schema` stay). `ews_schema_target()` is equal on both sides (harness assert). |
| **SQLite** | The same strings reach wpdb. The golden harness runs on SQLite too. |

---

## 7. Tests

**Unit (no WordPress; `tests/unit/Fakes/`):**
- **Fakes:** `MemoryApprovalRequestStore`, `MemoryWorkflowStore`, `MemorySubstituteStore`, `FakeTransaction`, `FakeApprovalNotifier`, `FakePolicy`, `FakePeople`, `FakeOrg`, `FakePermissions`, `FakeAfterRequest`.
- **`ApprovalEngineStartTest`:**
  - NONE, PEER without target, missing steps, invalid mode, already started;
  - the condition skip; the self rule allow / next / admins (by employee id and by user);
  - missing approver → skip; last level → administrators (`administratorsNeeded` versus later, depending on the flag);
  - a substitute route stored "for" the manager; HR without an employee;
  - insert failure → rollback only when managed.
- **`ApprovalEngineActTest`:**
  - invalid decision; not found;
  - level moved before not-pending; not logged in; every `mayDecide` branch;
  - note required, but not with override;
  - stale conditional update → `approval_moved` (expected given) or `approval_stale`;
  - next-level open with a substitute; reject and final approve (request columns);
  - commit failure → rollback;
  - `decided+1` only when the substitute decided; self audit text; administrators told for a rerouted next level.
- **`CloseTest`:**
  - `close` on decided → false, on no request → true;
  - `closeOpen` order (steps then request), `'moved'` when the request changed, `skip_reason` closed, a read error → false.
- **`DeadlineServiceTest`:**
  - backfill; remind once; skip administrators; escalate up, or to administrators when up is the requester, the same person or has no user;
  - a substitute's base is the manager; audit text.
- **`SubstituteServiceTest`:** leave-linked transitions, begin moves waiting steps, finish back / stay, check codes, create audit and picked notification.
- **`ReassignTest`**, **`WorkflowServiceTest`** (plan errors, transaction rollback per write, `min_amount` format).

**Store e2e on a real database** (`tests/e2e_approval_store.php`, `wp eval-file`, prefix-isolated, as `e2e_schedule_store.php`):
- conditional update affected counts (0 / 1 / false);
- UNIQUE `entity_workflow` on a concurrent start;
- `findByKeyGroups` gives the same rows as N `findByKey` (chunk boundary at 500);
- `escalate` WHERE `approver_wp_user_id`;
- `assignWaitingToSubstitute` subquery;
- the prefix switch.
- **Races** (two `wp eval` processes, `innodb_lock_wait_timeout=3`): decide vs decide → one stale; decide vs `closeOpen` → `'moved'` or stale, never both applied; **decide vs withdraw** → documents today's outcome (one fails cleanly, rows consistent).

**Golden:** §3.2 and §3.3.

**Regressions:** §3.5, plus a "write then read in one request" case: act then `currentStep` and `approval_paths` in the same request.

---

## 8. Open questions for the owner

1. **One release or two?** One L2 (recommended; about 1,900 new lines in `src/` and 900 changed in traits), or L2a reads and workflows then L2b writes?
2. **Cadence.** 3.31.127 was move-only, and the feature that was to follow it (the schedule change request) is dropped. Decision 4 allows at most one move-only release between two features, so L2 now would make two moves in a row. Is an exception agreed, or does a feature go first?
3. **Other modules' engine SQL:**
   - Replace `leave_notify_pending_approvers` and the face-reset purge with same-SQL Store calls in this release?
   - Keep the four joins (corrections, leave-view ×2, swap) as baseline debt, or turn them into `Approvals\Data\*Query` read models listed under `reads`?
   - Change employee-admin's `approval_tables()['relationships']` to the literal?
4. **Supervisor relationships** (people's table, §1.11): stay in `trait-approvals` until L4 People (recommended), or move now?
5. **Schema:** keep `ensure_approval_schema` / `ensure_substitutes_schema` in the traits (recommended), or move them to byte-identical `ApprovalSchema.php` / `SubstituteSchema.php` now?
6. **Strict trace:** may the golden approval trace compare the exact SQL sequence (stricter than 3.31.127's pages; about +1–2 min CI)?
7. **Baseline:** the first enrolment of the approvals tables needs `check_layers.py --update --allow-increase` once. Agreed?
8. **Quirks a faithful move preserves.** Fix each one separately (its own release), or leave it?
   - **Q1, CONFIRMED (order), PLAUSIBLE (deadlock):** `approval_close` (withdraw) locks request → steps. `approval_act` and `approval_close_open` lock steps → request. A withdraw at the same moment as a decision can deadlock; InnoDB aborts one, and the employee sees "could not be withdrawn". Fix: steps first, as `close_open` (:925 comment).
   - **Q2, PLAUSIBLE:** the decision is committed before the module's own row in:
     - swap in the app (`trait-swap.php:330`, `$manage=true`, then `swap_finish_approved` outside it);
     - face reset (:125);
     - corrections (:369).

     If the module step fails (for example the swap's schedule "changed"), the approval is APPROVED while the swap stays Awaiting, decidable only through the Hub's non-workflow path. The Hub path (3.31.120) does both in one transaction.
   - **Q3, CONFIRMED:** when the engine owns the transaction, the `approval_db_error` message is empty, because `ROLLBACK` runs before `$wpdb->last_error` is read and wpdb's `flush()` clears it.
   - **Q4, oddity:** `approval_may_decide` checks "self not allowed" before the administrator override. An administrator who made the request cannot decide it on the Hub, and rule `next` counts as "not allowed". Is that intended?
   - **Q5, PLAUSIBLE:** `approval_reassign_supervisor` ignores steps held by the old manager's substitute (`approver_employee_id` = the substitute). After the move they stay with the substitute and, on `on_return='back'`, go back to the **old** manager.
   - **Q6, CONFIRMED:** the duplicate-start check is not atomic. A concurrent second start fails on UNIQUE as `approval_db_error`, not `approval_already_started`. Harmless: callers roll back.
   - **Q7, PLAUSIBLE, minor:** the substitutes shutdown flag, the administrators-later closure and `$my_approvals_memo` are per plugin object, not per site (`switch_to_blog` in one request).
   - **Q8, efficiency:** one `act` reads the workflow key up to 3 times and the requester's user twice. A later "fewer queries" commit would show in the trace (`expected.json`).
   - **Q9, minor:** a withdraw racing a level advance returns "could not be withdrawn, try again" instead of "already decided".
   - **Q10, module-side, PLAUSIBLE:** `advance_respond` ignores a 0-row update of the advance (withdrawn meanwhile) while committing the approval. Its withdraw and respond lock the advance and the approval rows in opposite orders.

---

## 9. Owner decisions (2026-10-27)
1. **One release** (3.31.128), not two.
2. **A faithful move:** the quirks in §8 question 8 stay as they are and are fixed in a separate release afterwards (each
   one decided on its own). Cadence (question 2): the schedule change request was dropped, so this move follows 3.31.127
   directly, by the owner's choice.
3. **Strict trace:** the golden approval trace compares the exact SQL sequence per call (about +1–2 min of CI).
4. **PR #16 (3.31.127) merged first** (it carries the container, check_layers and the harness this builds on).
5. Taken as the recommended defaults (not asked; the owner can change them): the two optional cross-module replacements
   (leave_notify_pending_approvers, face-reset purge) are **not** done in this release (their direct SQL stays as baseline
   debt); the supervisor-relationship code stays in trait-approvals until People moves (§1.11); the schema functions stay in
   the traits; the first enrolment of the approvals tables raises the layer baseline once (`--allow-increase`).
6. **Where the harness differs from §3.3 (step 1):** the approval trace is `tests/golden/actions_approvals.php`, run in
   process through the engine's own functions (`ReflectionMethod`), not through the module admin-post handlers (no
   `GoldenExit` handler runs); the module handlers stay covered by the existing e2e suites. `run.py` runs every
   `actions*.php` and prefixes the keys (`approvals:<call>`). Each call records its return, the exact SQL in order
   (timestamps as `T`) and the query count; the end state records every approval, substitute and workflow row, the
   notifications and the audit log. Checked: equal on unchanged code (3 runs), and it fails when one extra query is added
   to `approval_current_step()`.
7. **Where the implementation differs from the design (3.31.128):**
   - **People and Org ports are one:** `Approvals\ApprovalDirectory` (user of an employee, an employee by id or by user, name,
     user exists, has reports, supervisor / team manager / department manager) instead of extending `Platform\People` and a
     separate `Org`. `Platform\People` is the Schedule's port and is left as it was. `Platform\Transaction` and
     `Platform\Permissions` are as designed.
   - **Ports are held together** in `ApprovalPorts` (policy, notifier, after-the-page, directory, deadline notifier) and
     `SubstitutePorts` (policy, leave facts, notifier), so the container takes two arguments, not ten.
   - **`ApprovalPolicy`** has `selfRule`, `rejectNoteRequired`, `settings` and `workingDays`; it is lazy, so options are read
     at the same points as before. The deadline hours are still read by the trait (`approval_deadlines()`), which decides
     whether the tick has anything to do before a Service is built.
   - **`waitingOnSupervisors`** is in `ApprovalReads` (it only reads). `purge` is not built: the two optional cross-module
     replacements (§9.5) were left out, so `face_reset_clear_finished_approval` and `leave_notify_pending_approvers` keep their
     SQL and stay in the baseline.
   - **`SubstituteService implements SubstituteRouting`**, the small port the engine uses, so the engine never sees the
     substitutes' Store. `substitute_app_data` passes a presenter to `decidingFor()` so the page's own reads (names) happen
     between the Service's reads in the old order.
   - **Deleted** (nobody else called them): `approval_resolve_approver`, `approval_mode_family`,
     `approval_requester_user_id`, `approval_notify_administrators_after` (now the `AfterRequest` adapter),
     `approval_escalate`, `substitute_active_for`, `substitute_route`, `substitute_manager_may_decide`,
     `substitute_after_decision`, `substitute_take_waiting`, `substitute_begin`. `substitute_route`,
     `substitute_manager_may_decide` and `substitute_after_decision` also left the module's `api` list in `modules.json`;
     `approval_notify_administrators` joined it (the container's adapter calls it).
   - **`approval_invalid_mode` cannot happen:** `Workflows::modeFamily()` maps any unknown mode to NONE, so the check in
     `start()` is dead code. It was moved as it was (a faithful move) and is a candidate for the quirks release.
   - **Stays in the traits as baseline debt** (design §2.5): `admin_approval_workflows` (three reads, one joining
     employees), the schema functions, the relationship code (People's, not moved), and the helpers `approval_tables()` /
     `substitutes_table()`. The first enrolment of these tables raised the layer baseline once
     (`check_layers.py --update --allow-increase`): `trait-approvals` 10, `trait-substitutes` 2, `trait-swap` +1,
     `trait-leave` +1, `trait-leave-view` 2, `trait-corrections` 1, `trait-employee-admin` 1, `trait-face-reset` 1.
   - **Golden:** 91 engine calls and the rows they leave (`tests/golden/actions_approvals.php`), and 9 pages with
     `tests/golden/seed_approvals.php` (users gdmgr and gdreq). `run.py` runs every `actions*.php`.
8. **Review (3.31.128), what was done:** the request key is built by one function (`ApprovalReads::requestKey()`) on both
   sides; the unit fakes clear the database message on ROLLBACK like wpdb; the substitutes' writes were added to the golden
   trace. **Not done, recorded:** the two-process race tests of §7 (decide vs decide, vs closeOpen, vs withdraw): the lock
   order is unchanged and checked by the SQL trace, `e2e_request_races.py` (kept green) covers the races, and the Store e2e is
   single-process. `substitute_create` tells the substitute only when the new row reads back (the old code warned and told user 0).
