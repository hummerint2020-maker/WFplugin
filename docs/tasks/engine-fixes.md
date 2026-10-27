# Design: approvals engine fixes (3.31.129+): Q1, Q2, Q4, Q5

**Status:** design only, written by the read-only architect on main `4fb9290` (after 3.31.128). Owner decisions of 2026-10-27: fix Q1 (withdraw locks the steps first), Q2 (decision and the module's own write atomic, each module on its own), Q4 (an administrator may decide their own request from the Hub), Q5 (an org move also moves the steps a substitute holds). Left as they were: Q3, Q6–Q10 (design `approvals-engine-move.md` §8).

**Three findings change the shape of the ask:**
- Q1 alone does not remove every deadlock. It removes the cycle on steps and request. A last-level decision against a withdraw still has a cycle on the module's own row in leave, overtime and advances (OQ2).
- Q2 for corrections would create a new deadlock unless `cx_close` and `cx_withdraw` call the engine first. That reorder is part of the design.
- Q4 reverses a rule written in the 3.31.75 changelog ("the requester cannot decide it, administrators included"). `tests/e2e_self_approval.py:135-141` checks that rule.

## 1. Data model and upgrade path
None: no schema change, no version bump, no option. What changes: the order of two statements (Q1), one condition in `mayDecide` (Q4), three SQL texts and one new UPDATE in the request Store (Q5), and where three modules open and commit their transaction (Q2). Existing rows left stuck by the old behaviour are not repaired (approval APPROVED with the swap still Awaiting; steps already sent back to an old manager); admins can still finish them through the Hub's non-workflow path (OQ9).

## 2. Files and functions

### 2.1 Q1: withdraw locks the steps first
`src/Approvals/ApprovalEngine.php` `closeWithdrawn()`:
```php
// before
if ($this->requests->cancelRequestIf(id, status, now) !== 1) return false;
return $this->requests->cancelOpenSteps(id, false);
// after (same SQL texts, order swapped)
if (!$this->requests->cancelOpenSteps(id, false)) return false;
return $this->requests->cancelRequestIf(id, status, now) === 1;
```
Docblocks: the lock-order paragraph (deciding, withdrawing and closing all lock levels, then the request); `closeWithdrawn` and the trait wrapper say "on false the caller must roll back: the levels may already be marked". Callers already roll back on false (`trait-leave.php`, `trait-overtime.php`, `trait-advances.php`). Not the last level: the withdraw waits on the step, the decision commits, the withdraw's request update finds the status changed (0 rows) and returns false; the caller rolls back and the employee sees 'save' (Q9, unchanged). Last level: still a cycle on the module row (decision holds steps and request, wants the module row; withdraw holds the module row and balance, wants the steps). InnoDB aborts one, data stays consistent (OQ2).

### 2.2 Q2: decision and module write in one transaction
Shape of the Hub (`swap_admin_decide`): BEGIN; `approval_act(..., false, ...)`; find the request; the module's rows (each write checked); COMMIT; audit and notifications after the COMMIT. On any failure ROLLBACK and the level stays open. Rules: nothing that can commit implicitly inside (`ensure_*_schema`, `dbDelta`, ALTER; so the corrections payroll read stays before BEGIN); every write checked and the code stops at the first failure (a deadlock victim is rolled back whole, later statements would autocommit). Side effects (wanted): the self-decision audit and the substitutes `decided+1` roll back with a failed module step; administrators of a rerouted next level are told at the end of the page after a re-check.

**Swap, app (`trait-swap.php`):** split `swap_finish_approved` into `swap_apply` (unchanged) and new `swap_approved_after($r)` (audit + notifications). Move the body of `swap_manager_decide` after its checks into `swap_manager_decision($r,$ar,$decision,$note,$posted)` returning approved|rejected|advanced or an error code (so races can call it from the CLI). Body: BEGIN; act with `false`; fresh read; APPROVED → `swap_apply_rows`; REJECTED → `swap_close(...)===1 ? true : 'save'`; on failure ROLLBACK and return the code; COMMIT; then the after-work. Locks: steps, request, substitutes row, swap row, schedule rows in id order (the Hub's order). Who notices: an approver whose last-level approval finds the schedule changed sees `swap_error=changed` and the level stays open.

**Face reset (`trait-face-reset.php`):** split `face_reset_finish` into `face_reset_finish_rows` (checked `face_delete_template` + option update) and `face_reset_finish_after` (audit + notification); the direct paths keep rows then after. Workflow branch: BEGIN; act `false`; fresh read; ADVANCED → COMMIT then notify; final → rows, COMMIT, after; on rows/COMMIT failure ROLLBACK, `wp_cache_delete('ews_face_reset_requests','options')`, return ERROR (the option update had already filled the object cache). Covers the Face Reset page and the Hub (`$as_admin`). Who notices: the approver, only when the face cannot be deleted.

**Corrections (`trait-corrections.php`):** split `cx_finalize` into `cx_finalize_prepare` (reads), `cx_finalize_rows` (conditional UPDATE, time_logs INSERTs, `applied_ids` now checked) and `cx_finalize_after` (payroll carry, audit, notify); `cx_finalize` keeps prepare, own transaction, rows, after (HR stage, direct path). `cx_after_manager` gets a rows part with `manager_by` checked and the pending_hr UPDATE `===1`. New open branch of `cx_decide`: prepare before BEGIN (payroll schema); BEGIN; act `false`; fresh read; REJECTED → `cx_mark_rejected` must return 1; APPROVED with needs_hr → rows; otherwise close/stale/employee → ROLLBACK and return it (close → `cx_close`), else `cx_finalize_rows`; COMMIT; after-work. Lock order required: the decision is now steps, request, correction row, time_logs. Today `cx_close` and `cx_withdraw` take the correction row first, so they must call `approval_close_open` BEFORE the module UPDATE (return codes unchanged: close false→'save', 'moved'→'approval', mark_rejected false→'save', 0→'done'; withdraw 'moved'→'done', false→'save', UPDATE 0→'done'). Who notices: an approver whose last-level approval finds the day changed; the level now stays open.

### 2.3 Q4: an administrator may decide their own request from the Hub
`ApprovalEngine::mayDecide`:
```php
$isSelf = $userId && $userId === $this->requesterUserId($request);
if ($isSelf && selfRule(key) !== 'allow') {
    $adminLevel = !empty($step->rerouted) && !(int) $step->approver_wp_user_id;   // the level waits for administrators
    if (!($adminOverride && $adminLevel && $this->permissions->isAdmin($userId))) return error('approval_self_not_allowed', ...);
}
```
Read order unchanged; `isAdmin` runs only for a self-decision under next/admins with an override, so no other call's SQL changes. `act()` unchanged: `step.self_decision=1`, the `approval_self_decision` audit and the self-decisions list stay. Only the four-condition case changes: requester, rule next or admins, the Hub override (`admin_requests_decision`, already `manage_options`), a level waiting for administrators. Reaches leave balance and overtime hours (payroll) on such a level; swap schedules and face reset; salary advances are NOT reachable (no advance type in the Hub, `advance_respond` never overrides). Corrections' HR wp-admin override without `manage_options` is still refused. Rule 'allow' and the no-workflow `approval_self_gate` already let an administrator decide their own request, audited, so this is not new power; it mostly matters on a site with one administrator. The narrow `$adminLevel` rule is the architect's proposal (OQ3).

### 2.4 Q5: an org move also moves the steps a substitute holds
A level waits for manager M when `COALESCE(for_employee_id, approver_employee_id) = M`. `WordPressApprovalRequestStore`: `supervisorStepIds` uses the COALESCE; `reassignSteps` also sets `for_employee_id=NULL, substitute_id=NULL`; new `routeStepsToSubstitute(ids, subEid, subUid, managerEid, rowId)` (same SET as `assignWaitingToSubstitute`, `WHERE id IN (...) AND status='PENDING'`), added to the interface; `waitingOnSupervisors` selects and groups by the COALESCE (so the Org chart preview counts what the move moves). `ApprovalEngine::reassignSupervisor` after `reassignSteps`: `$route = $this->substitutes->route($to->id, $employeeId)`; if present, `routeStepsToSubstitute(...)` (false on error). `$pending` still counts all. Locks: only the org-move transaction plus one SELECT on substitutes. Who notices: an org move of an employee whose old manager is away with a cover.

## 3. Off by default
No switch; each fix changes behaviour from the upgrade on. Q1: statement order only. Q2: failure handling (approvers get an error and the level stays open; administrators of a rerouted next level are told at the end of the page). Q4: permission (CHANGELOG must say it reverses 3.31.75). Q5: routing of org moves while the old manager has an active cover.

## 4. Edge cases
Q1: `closeWithdrawn` outside a transaction would leave steps cancelled (no such caller; docblock + unit test). Q2: the same "auto-approved at start, module applied afterwards" shape exists in `swap_to_approvers`, `face_request_reset`, `cx_create` (only when every level is skipped; not in scope, OQ10); the face reset option is one map, two decisions for different employees can lose an update (exists today). Corrections: HR stage never touches approval rows; prepare says 'close' but act advanced → ROLLBACK then `cx_close` closes the open level. Q4: rule next with a later level: that level is someone else's, the administrator still cannot decide it; no override in the app inbox: refused; multisite super admin passes `isAdmin`. Q5: the new manager's cover is the requester → stays with the new manager; the new manager is the old cover → goes to them; WAITING levels are not routed (routed when they open); a step held by the old manager as a cover for X (approver=old, for=X) is X's level and is no longer moved.

## 5. Tests
**Unit** (`ApprovalEngineTest`, fakes): fakes get COALESCE in `supervisorStepIds`, `for/substitute_id` cleared in `reassignSteps`, `routeStepsToSubstitute`, a real `waitingOnSupervisors`, `isAdmin` call count. Q1: log order `cancelSteps` then `cancelRequest`; status changed after the read → false with steps already marked. Q4: matrix (self + next/admins + override + isAdmin + administrators' level ok; same on another's level refused; without isAdmin refused; without override refused; 'allow' unchanged; `act()` writes `self_decision=1`, the audit, accepts a reject without a note; `isAdmin` not called otherwise). Q5: substituted step moves with for/sub cleared; with the new manager's cover → cover is approver, for = new manager; cover is the requester → not routed; WAITING not routed; approver=old/for=X not moved; counts; second call 0; `waitingOnSupervisors` by covered manager.
**Golden:** harness first (commit 0): `expected.json` gains `"actions": {"approvals:<key>": "<reason>"}`; `run.py` prints EXPECTED for listed keys instead of failing; `_rows` split into one key per table. Intended changes: Q1 `withdraw_close` (SQL order only); Q4 new calls on new entity ids with the Hub override (base refuses, head allows), plus the other-level and no-override controls (equal); Q5 `reassign_*` and `_rows:steps` plus a scenario with both managers covered. Q2: no golden entry (the golden does not drive those module functions).
**e2e:** fault injection (`break_writes`) for the swap (UPDATE broken on schedules / swaps → `swap_error`, swap Awaiting, step PENDING), face reset (DELETE broken on face_profiles → error, option still pending, template present), corrections (INSERT broken on time_logs, a stale day → approval open, correction pending, no time logs); flip `e2e_self_approval.py:135-141` (admin's own request under 'admins' is approved with `self_decision=1` and one audit row; another level still refused); `e2e_org_moves.py` (active cover, move, step goes to the new manager, ending the cover does not send it back, dialog count includes it). Two-process races in a new `tests/e2e_approval_races.py` (MySQL/MariaDB only; mu-plugin `tests/e2e_pause.php` pauses one process before a matching query): R1 decision at level 1 of 2 vs leave withdraw (no deadlock, A advanced, B 'save'); R2 last level (consistency only); R3 swap last level vs the requester's schedule change ('changed', step PENDING, swap Awaiting); R4 vs Hub `swap_admin_decide` (exchanged once); R5 corrections decision vs `cx_withdraw` (no deadlock, time logs once); R6 withdraw first vs decision ('moved'); R7 two face-reset decisions (one audit row, one notification).
Regressions: unit, phpstan, phpcs, check_modules, check_layers, check_translations, golden, and the 3.31.128 safety-net suites.

## 6. Risks
1. An implicit commit inside the new transactions (payroll prep and `ensure_swap_schema` run before BEGIN; the reviewer checks every call inside each for `ensure_*`/`dbDelta`). 2. Statements after a deadlock autocommit (every write checked, including two unchecked today). 3. Stale object cache after ROLLBACK (face reset option; `wp_cache_delete`). 4. Lock-order inversions (corrections removed by the reorder; the last-level cycle in leave/overtime/advances remains, OQ2). 5. Wider permission (Q4; narrow rule; `isAdmin` = `manage_options`). 6. Locks held a few milliseconds longer. 7. Q5 adds a substitutes SELECT inside the org-move transaction and changes the Org chart count. 8. The expected-changes harness could hide a regression (one key per table, a reason per entry, the diff pasted in the PR). 9. SQLite: no `FOR UPDATE`; races skipped there.

## 7. Open questions for the owner (architect's recommendation first)
1. **How to release.** Recommended: 3.31.129 engine fixes (Q1, Q4, Q5), then 3.31.130 swap, 3.31.131 face reset, 3.31.132 corrections (touches payroll carry).
2. **The last-level deadlock left after Q1** (leave, overtime, advances withdraw). Recommended: not now; do it with Q9/Q10. Corrections gets its reorder now.
3. **How far Q4 reaches.** (A) only a level waiting for administrators (recommended), for rules next and admins alike; (B) any open level of their own request through the Hub.
4. Q4 with more than one administrator: allow (recommended).
5. Q4 audit text: no change (recommended).
6. Q4 reverses 3.31.75: confirm the CHANGELOG wording and the flipped e2e check.
7. **Q5 where substituted levels go.** (A) to the new manager or their active cover, old cover's link cleared (recommended); (B) stay with the old cover until it ends.
8. Q5 details: reset `reminded_at` on levels sent to the new cover; the new manager's "waiting for you" count includes them; no new notification to the old substitute (all recommended yes/no as stated).
9. Repair of existing stuck rows: no automatic repair (recommended).
10. The three "auto-approved at start" sites: not in this release, record them (recommended).
11. Q2 swap reject when `swap_close` returns 0: keep 'save' (recommended).

## 8. Commits and releases
0. Harness (expected "actions" map, `run.py`, `_rows` per table, `e2e_pause.php`, race skeleton), base == head. 1. Q1 + R1/R2. 2. Q4 + e2e flip + CHANGELOG note. 3. Q5 + org-moves e2e. (3.31.129 ends here if split.) 4. Q2 swap + R3/R4 (3.31.130). 5. Q2 face reset + R7 (3.31.131). 6. Q2 corrections incl. `cx_close`/`cx_withdraw` reorder + R5/R6 (3.31.132). 7. Docs, CHANGELOG, version. Each PR says where the code differs from this design. One release instead: commits 0–7 under 3.31.129.
