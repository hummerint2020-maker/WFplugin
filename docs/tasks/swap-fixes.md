# Swap fixes (before the L3a move)

## Request (owner, 2026-11-20)
The L3 design (`docs/tasks/schedule-rest.md` §5) found quirks SW-a…SW-n in the shift swaps. The owner chose to **fix them first**,
in their own release, before the L3a move (so the move carries the fixed code). Proposed numbers: fixes 3.31.156, L3a move 3.31.157.
The two race tests (double accept; two swaps on one day sharing a person) are added first, on the unchanged code.

## Owner decisions (2026-11-20)
| Quirk | Today | Decision |
|---|---|---|
| SW-a | an administrator who is also an employee may request a swap with anyone in any department | **Own department only**, like any employee (as the request sheet already offers) |
| SW-c | the administrator's direct approval (wp-admin) has no past-day check | **The administrator may still approve a past day** (corrections): unchanged |
| SW-f | Cancel on the Schedule page has no past-day check; a swap not found / not owned redirects as "cancelled" | **Cancel of a past day is refused** (as withdraw); a swap not found / not owned no longer says "cancelled" |
| SW-m | an administrator's decision sends no push | **Push like every other decision** |
| SW-e | an approver's / administrator's decision overwrites the colleague's `responded_by` / `responded_at`; swaps applied after approval end as `Accepted` | **Keep both**: the colleague's answer and the approver's decision are both recorded (a new column is acceptable); a swap approved by the approvers shows as approved |
| SW-b | several open swaps for the same day (different colleagues); a double click can create the same swap twice | **One open swap per requester and day**: a second request for the same day is refused until the first is closed or cancelled; a double click never creates two |
| SW-h | the Schedule page's "to approve" list ignores substitutes (capped at 50) | **Substitutes see the swaps waiting for them**, like the manager; the caps stay |
| SW-d | when the engine approves at once, APPROVED is committed before the exchange; a changed day leaves the swap stuck Awaiting | Fix (data integrity): the exchange and the approval succeed or fail together |
| SW-g | the approval status is compared case-sensitively in the app path, upper-cased in the Hub path | Fix (consistency) |
| SW-j, SW-l, SW-n, SW-i | achievements count approved swaps; an extra read; week-lock record inside the transaction; per-prefix join | Unchanged (not asked; harmless or correct) |

**Switch:** the owner chose **no switch: all fixes are on at once** (2026-11-20, "كله على طول"), an explicit exception to the
off-by-default rule for this fix release. The golden harness declares every page and trace these fixes change.

## Design (architect, read-only, on 3.31.155)

Abbreviations: `ts:` = `includes/trait-swap.php`, `tf:` = `trait-frontend.php`, `tma:` = `trait-my-approvals.php`,
`tsv:` = `trait-schedule-view.php`, `tpl:` = `templates/app/schedule.php`.

### 0. Findings that shape the design
- **SW-h is narrower than schedule-rest §5 says.** A substitute is already the level's approver: `ApprovalEngine.php:180` and
  `WordPressApprovalRequestStore::assignWaitingToSubstitute` (:236) write the substitute's user into `approver_wp_user_id`, so
  `ts:384` already lists the swap for the substitute. What is missing is the **manager who is away** and still decides when
  `manager_decides` is on (`tma:84-85`, `inboxRows` `WordPressApprovalRequestStore.php:196`); `approval_can_act` already lets them
  decide (`ApprovalEngine::mayDecide:293`). The fix makes the Schedule list match My approvals.
- **A swap is approved at once only when its workflow has levels but no active level rows** (`ApprovalEngine.php:105-116`). Tests
  reach it by turning the level rows off.
- **Swap errors are not shown on My requests today.** The sheet posts `ews_return=requests` (`tma:35`); the messages exist only for
  `ews_view=schedule` (`tf:255-262`); `requests_content` (`tma:294-301`) has no `swap_*` notice. §6.4 fixes this.
- `SwapInbox::state` already maps `'Approved'` (`src/Schedule/SwapInbox.php:18`); `swap_my_requests` already lists it (`ts:460`);
  CSS `.wfo-rq-state.is-approved` exists (`app-requests.css:45`).

### 1. SW-e: the colleague's answer and the approvers' decision, both kept
- Today `swap_close` (`ts:47-51`) always writes `responded_by/at`; an approver's or administrator's decision (`ts:88`, `:238`, `:250`,
  `:349`) overwrites the colleague's answer written at `ts:279`; every exchange ends `Accepted`.
- **Data model:** two nullable columns on `ews_schedule_swaps` after `responded_at`: `decided_by BIGINT UNSIGNED NULL`,
  `decided_at DATETIME NULL` (in `ensure_swap_schema()`, `trait-schema.php:79-102`); a new status `Approved` (fits `VARCHAR(20)`).
  `responded_by/at` = only the colleague's answer (and the requester's cancel / withdraw while Pending); `decided_by/at` = an
  approver's or administrator's decision; `Accepted` = the colleague accepted and nobody else decided; `Approved` = exchanged on the
  approvers' or an administrator's decision (app last level `ts:346`, Hub last level `ts:237`, admin direct `ts:248`, approved at once);
  `Rejected` with `decided_by` = rejected by an approver or administrator. No schema-level key.
- **Code:** `swap_close($id,$status,$from='Pending',$by='responded')` (`'responded'|'decided'` picks the columns; formats unchanged);
  `swap_apply_rows($r,$approved=false)` claims `Approved`/`decided` or `Accepted`/`responded`; `swap_apply($r,$approved=false)` passes
  it on. `true`/`'decided'` at `ts:237`, `:238`, `:248`, `:250`, `:346`, `:349` and SW-d; unchanged at `ts:195`, `:179`, `:210`, `:543`.
  `swap_my_requests` (`ts:446-447`) shows "not approved" when `isset($engine[id]) || (int)$r->decided_by`.
- **Readers of `'Accepted'`:** `trait-achievements.php:211`, `:291` → `IN ('Accepted','Approved')` (SW-j kept); `:237` unchanged (peer
  path only); `tpl:53` `$swap_states` adds `'approved' => ['check', __('Approved')]` (existing msgid); `SwapInbox::state`, `ts:460`,
  `swap_state_for`, `trait-admin-requests.php:46`, `swap_pending_rows`, `swap_new_choices`, `swap_waiting_mine_count`,
  `trait-admin-dashboard.php:80`, `uninstall.php:62`: unchanged; reports, privacy, REST: do not read swaps.
- **Upgrade:** `ews_schema_target()` (`trait-schema.php:337`) `'3.31.148.1'` → `'3.31.156'`; dbDelta adds the columns; every write path
  calls `ensure_swap_schema()` before `START TRANSACTION` (`ts:42`, `:67`, `:141`, `:224`, `:277`, `:362`), so a site briefly on the old
  schema cannot fail on an unknown column. `src/Support/SchemaCheck.php:25` adds `'ews_schedule_swaps' => ['decided_by','decided_at']`.
  No backfill (§14 Q5): old approved-then-exchanged rows stay `Accepted` with the approver in `responded_by`.
- **Downgrade to 3.31.155:** dbDelta never drops columns, the old code ignores them; `Approved` rows show as approved on My requests,
  with the fallback chip `['alert','Approved']` on the Schedule page, and are left out of new milestone counts. Re-upgrading is idempotent.

### 2. SW-b: one open swap per requester and day
- Open = `Pending`, `Awaiting`. Refused when **this requester** already has an open swap they asked for on that day, or the reverse pair
  is open (as today). Past days are refused before (`ts:139`).
- **Race safety:** GET_LOCK through `ews_with_lock` (the house pattern: overtime `trait-overtime.php:92`, leave `trait-leave.php:303`,
  advances `trait-advances.php:163`); no transaction, no schema key; the INSERT is one autocommitted statement under the lock; dbDelta
  runs before the lock; SQLite runs unlocked; released in `finally`.
- **Code:** after `checkSwappable` (`ts:146-147`), the COUNT and INSERT (`ts:148-156`) move into `swap_create_locked(...)` called via
  `ews_with_lock('swap_emp_'.$requester->id, ..., ['error'=>'busy'])`. One query replaces the COUNT:
  `SELECT requester_employee_id,target_employee_id FROM {t} WHERE work_date=%s AND status IN ('Pending','Awaiting') AND (requester_employee_id=%d OR (requester_employee_id=%d AND target_employee_id=%d))`;
  pure `SwapRules::openConflict($rows,$requesterId,$targetId)`: same pair either way → `'pending'` (unchanged code and message); any
  other → `'one_per_day'`; then the unchanged INSERT.
- **Messages:** `one_per_day` "You already have a swap waiting for this day. You can ask again once it is answered or withdrawn.";
  `busy` (existing) "Another request of yours is being saved. Try again in a moment."; a double click → the second waits, then `pending`;
  exactly one row.
- **Choice lists (§14 Q1):** the New request sheet drops days where `a == me` from `$out['days']` (`ts:577-578`, no new query); the
  legacy Schedule form (`tsv:110-113`) leaves out dates with an outgoing open row (build `swap_requests` `tsv:138` first). Test flip:
  `e2e_my_approvals.py:321`.
- **Existing duplicates:** left (§14 Q8); when one applies, the other can only be declined or cancelled; the requester cannot ask again
  for that day while one stays open.

### 3. SW-a: own department only
- Today `ts:143` uses `department_scope_allows_employee` (`trait-departments.php:72-80`), true for `manage_options`.
- **Create:** after `if(!$target_emp)` → `employee`, the pure `SwapRules::checkSameDepartment(requesterDept, targetDept)` →
  `'department'` (fails when the requester has no department or they differ); for non-administrators the same rule as before, one query
  fewer. Code `employee` → `department` (`e2e_swap.py:85` flips). Message "You can swap only with a colleague in your own department."
- **Choices:** the sheet (`ts:563-569`) already uses the department; the legacy form (`tsv:98`) filters `$swap_people` to the requester's
  department. The admin pages' department scope is untouched. `check_modules --update` (schedule→people goes down).

### 4. SW-d (+ SW-g): approval and exchange in one transaction
- Today `swap_to_approvers` (`ts:276-292`): START → Pending→Awaiting → `approval_start(...,false)` → COMMIT → audit → `find` → on
  `=== 'APPROVED'` (SW-g) `swap_finish_approved` → `swap_apply` in a second transaction; a changed day leaves APPROVED + Awaiting.
- **Change:** START; UPDATE Pending→Awaiting; `approval_start(...,false)`; `approval_find_request(...)` **inside** the transaction (same
  connection; `findByKey` has no memo, `WordPressApprovalRequestStore.php:72-77`); if `Hub::workflowOutcome($ar->status)===Hub::APPROVED`:
  `$r->status='Awaiting'`, `$done=swap_apply_rows($r,true)`; on failure ROLLBACK everything, `$r->status='Pending'`, return `$done`;
  COMMIT (false → ROLLBACK, `save`); audit ACCEPTED as today; if applied `swap_approved_after($r)` and return `'approved'`, else notify the
  approver and the requester and return true. `swap_finish_approved` (`ts:306-312`) is removed.
- **Changed day:** everything rolls back: swap Pending, no approval request; the colleague gets `swap_error=changed` (as the peer path).
- **Redirect:** `$out==='approved'` → `swap_done=approved` (existing "The swap was approved and both schedules were updated.",
  `tf:261`); the same msgid added to `tma:246-247`.
- **Lock order:** swap row → new engine rows → the two schedule rows by id; no deadlock with the Hub's order.
- **SW-g:** `swap_manager_decision` (`ts:343-350`) branches on `Hub::workflowOutcome((string)$fresh->status)`, as `ts:235`.

### 5. SW-h: the "to approve" list matches My approvals
- New api helper in `trait-my-approvals.php` (listed in the approvals `api`):
  `approval_inbox_away()` = `current_employee()->id` when substitutes are on and `manager_decides`, else 0; `my_approvals_items`
  (`tma:84-85`) uses it with the same reads in the same order.
- `swap_awaiting_my_approval` (`ts:379-387`): `... st.status='PENDING' AND (st.approver_wp_user_id=%d OR (%d>0 AND st.for_employee_id=%d))`
  (uid, away, away); `LIMIT 50` stays. Deciding unchanged (`approval_can_act` → `managerMayDecide`). Caps stay. Administrators'
  rerouted levels not added (§14 Q6).

### 6. SW-f: cancel on the Schedule page
- `ts:204-215`: not found → `swap_error=not_found` (existing message, as `ts:176`); `SwapInbox::canWithdraw('Pending', work_date, today)`
  false → `swap_error=past_date` (existing message); then close, audit, `cancelled` as today.
- **Template** `tpl:252`: an outgoing row with `work_date < $today` renders without a Cancel button.
- **6.4 Swap results on My requests:** the `swap_error` / `swap_done` maps (`tf:256`, `:261`) move to `swap_notice_text($kind,$code)` in
  `trait-swap` (adding `department`, `one_per_day`); `tf:255-262` calls it; `requests_content` (`tma:296-301`) adds `swap_sent`
  (existing text) and `swap_error`.

### 7. SW-m
`swap_admin_done` (`ts:262-263`) passes `$push=true` (the `swap_notify` default); same audience; still subject to
`notification_push_allowed('swap')` (`trait-notifications.php:156`).

### 8–9. SW-g: §4. SW-c: nothing to do (`swap_admin_decide` keeps no past-day check).

### 10. Golden
- New `tests/golden/actions_swap.php` (keys `swap:*`; `evidence_lib.php`, `exit.php`, `$post`, `$faulty`); seeds its own rows after
  `restore()` (one department: `swreq`, `swpeer`, `swpeer2`, approver `swappr`, a manager away with a substitute; `swother` in another
  department; the administrator linked to an Ops employee); records the push queue size and the swap row's `responded_*`/`decided_*`
  as set or NULL. `expected.json` reset to release 3.31.156.
- **Declared traces:** `create_ok`, `create_not_swappable`, `create_insert_fault` (SW-a, SW-b: inDepartment read gone; GET_LOCK, the
  open-swaps SELECT, RELEASE_LOCK new); `create_other_department` (`department`); `create_admin_other_department` (was `swap_sent`);
  `create_pair_duplicate`, `create_reverse_pair` (SQL; still `pending`); `create_second_colleague` (`one_per_day`); `create_busy`;
  `respond_accept_levels` (find before COMMIT); `respond_accept_auto_approved`; `respond_accept_auto_changed` (ROLLBACK, Pending, no
  request); `decide_last_approve`, `decide_reject`, `decide_last_changed_stale` (SW-e); `hub_level_approve_last`, `hub_level_reject`,
  `hub_direct_approve`, `hub_direct_reject` (SW-e, SW-m: push 0 → 2); `cancel_not_found`; `cancel_past`; `awaiting_list_approver`
  (new SQL, same rows); `awaiting_list_away_manager` (now lists it); `new_choices_open_day`; `accept_peer_achievements`.
- **Identical:** `create_self`, `create_past`, `create_bad_nonce`, `respond_reject`, `respond_accept_peer`,
  `respond_accept_peer_changed`, `respond_levels_engine_fault`, `decide_advance`, `hub_level_advance`, `hub_moved`, `cancel_ok`,
  `withdraw_*`, `my_requests`, `state_for`, `pending_rows`, and all existing traces (including `approvals:swap_*`).
- **Pages:** declare the admin Schedule page (SW-a legacy form); the rest identical, query counts unchanged or lower.
- **`run.py` guard:** created, refused (`department`, `one_per_day`, `pending`, `busy`), approved at once, rolled back on `changed`
  (START = ROLLBACK), exchanged in schedule-id order, wrote `decided_*`.

### 11. Tests
- **Unit** `SwapRulesTest`: `checkSameDepartment` (same → null; different / 0 → `department`); `openConflict` (none, pair, reverse,
  another colleague → `one_per_day`, pair + another → `pending`); constants `DEPARTMENT`, `ONE_PER_DAY`. `SwapInboxTest`: `Approved`.
- **Commit 0 race tests** (`e2e_swap.py`, on 3.31.155; `e2e_swap_mu.php` accepts several `stale <eid> <day> <status>` lines):
  (a) two accepts of the same swap → the second `swap_apply($r_before)` by Reflection returns `'expired'`, days exchanged once, one
  ACCEPTED audit, one notification pair; (b) two Pending swaps A→B, A→C on one day **seeded with `$wpdb->insert`**, C's schedule row id
  lower than A's; accept A→B; A stale; accept A→C → `changed`, A→C Pending, C's day not written (rolled back), A→B intact.
- **Per fix:** SW-a (administrator linked to an Ops employee asks a Sales employee → `department`; Ops colleague works; legacy form);
  SW-b (second colleague → `one_per_day`; Awaiting still refused; after cancel it works; reverse pair → `pending`; two
  `swap_create_locked` calls → one row; the sheet drops the day; My requests shows the refusal); SW-d (level rows `active=0`: accept →
  `approved`, `Approved`, exchanged, requester notified once; with the requester stale → `changed`, Pending, no approval request);
  SW-e (`responded_by` colleague, `decided_by` approver; reject with `decided_by`; admin direct on Pending → `responded_*` NULL; flips
  `Accepted` → `Approved` at `e2e_swap.py:138, :176, :190, :292, :299, :344, :350, :369`, `e2e_level_guard.py:263`; peer path stays
  `Accepted`); SW-f (past → `past_date`, still Pending, no Cancel button; forged id → `not_found`); SW-h (manager away with
  `manager_decides` on sees and approves the swap; the substitute too; off → not shown); SW-m (golden push queue); **upgrade** (drop
  the columns, version `'3.31.148.1'`, legacy rows; `maybe_upgrade_schema` → columns, version `3.31.156`, no `ews_schema_failed`, rows
  unchanged; a decision before the upgrade still works); **downgrade** (old CREATE TABLE through dbDelta keeps the columns).
- **Suites:** unit, phpstan, phpcs, `check_layers`, `check_modules --update`, translations; `e2e_swap`, `e2e_swap_inbox`,
  `e2e_schedule_view`, `e2e_level_guard`, `e2e_my_approvals`, `e2e_my_approvals_legacy`, `e2e_admin_requests`, `e2e_approval_engine`,
  `e2e_approvals`, `e2e_request_races`, `e2e_speed` (`swap_new_choices` ≤ 12), `e2e_week_lock`, `e2e_notification_links`; golden.

### 12. Risks
- **Money: none** (no payroll table; the schedule rows written by an exchange are unchanged; SW-d only removes "APPROVED but not exchanged").
- **Permissions:** SW-a narrows an administrator-employee's app swaps (grid and SW-c kept); SW-h shows the away manager only what
  `approval_can_act` already allows, behind the My approvals setting; no capability change.
- **Concurrency:** SW-b per-requester GET_LOCK (10 s, then `busy`); SW-d one transaction (§4 lock order); double accept → `expired`;
  shared person → `changed` → ROLLBACK; dbDelta never inside a transaction or the lock.
- **Upgrade:** nullable columns, no backfill, `ensure` covers half-upgraded sites; downgrade shows `Approved` with the fallback chip.
- **L3a:** update schedule-rest §2.2 in the last commit (`countOpenBetween` → `openOnDay`; `close(...,$by)`; `toApprovers` includes
  `find` + `applyRows`; `awaitingApprover($uid,$away)`; `swap_create_locked` → `SwapService::create`; `swap_finish_approved` removed).

### 13. Commit order (green at each step)
0. Race tests + multi-line mu-plugin, on 3.31.155.
1. Golden `actions_swap.php` on the unchanged code (base = head); `expected.json` release 3.31.156, empty; `run.py` guard; version 3.31.156.
2. `SwapRules` additions + unit tests.
3. SW-e (schema, `ews_schema_target`, `SchemaCheck`, close/apply flags, achievements, chip, My requests, e2e flips, upgrade test, golden).
4. SW-d + SW-g.
5. SW-b (lock, codes, messages, the day lists, swap notices on My requests, translations).
6. SW-a (+ `check_modules --update`).
7. SW-f.  8. SW-m.  9. SW-h (`approval_inbox_away`, modules.json api, list SQL).
10. CHANGELOG, as-built section, schedule-rest §2.2 notes, final `expected.json`.

New strings: "You can swap only with a colleague in your own department."; "You already have a swap waiting for this day. You can ask
again once it is answered or withdrawn." All other texts are existing msgids.

### 14. Open questions for the owner (recommended defaults)
1. A day the person already asked a swap on: removed from the New request sheet and the legacy form (default), or kept with a note (mockup).
2. Show swap results on My requests (sent, refusals): yes (default).
3. Does "one open swap per requester and day" also count swaps they were asked in? Default no.
4. Wording of the two new messages: as written (default).
5. Backfill old rows: no (default); a partial reconstruction is possible for engine-approved rows only.
6. SW-h scope: parity with My approvals (the away manager); administrators' rerouted levels not added to the Schedule list (default).
7. Approved at once: the colleague sees "approved"; the audit keeps "accepted, waiting" then APPROVED (default yes).
8. Existing duplicate open swaps: left; no automatic cancel (default).
