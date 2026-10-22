# 3.31.120: the administrator decides the current swap level (owner Q4 of 3.31.119), plus 3 inbox-review fixes

Design by the architect (read-only), saved before implementation. Owner's answers are added at the end.

## 0. Root cause
`swap_admin_decide` (`includes/trait-swap.php:179-196`) calls `approval_act(...,'approve','',true,true)`, which manages and commits its own
transaction. On a workflow with 2+ levels that only moves the request to `WAITING_FOR_LEVEL_2`; the code then calls `swap_apply($r)`
anyway: the days are exchanged and the swap becomes Accepted while the engine is still open at level 2. `trait-admin-requests.php:109`
maps `===true` to `Hub::outcome()`, so ADVANCED is never shown.

`swap_apply` opens its own transaction (`:71`, `:84`), so it cannot run inside one that holds `approval_act(...,false,true)`: on MySQL the
inner START commits the approval decision implicitly; on SQLite a nested BEGIN fails. It has to be split.

Today `swap_manager_decide` (app approver) commits the approval first, then `swap_finish_approved` applies in a second transaction; a
failed apply leaves the engine APPROVED and the swap Awaiting (3.31.117 Q4, documented). The admin single-level path has the same order.

## 1. Data model and upgrade
No schema change. Swaps applied too early by the bug before 3.31.120 (swap Accepted, engine still waiting) keep an orphaned open engine
request; not repaired (owner Q6), CHANGELOG explains. Version 3.31.120.

## 2. Changes
### 2.1 `swap_apply` split
- `swap_apply_rows($r)` (new): today's body (read, `checkStillCurrent`, claim via `swap_close`, two conditional UPDATEs in id order);
  returns `true|'changed'|'expired'|'save'`; never START/COMMIT/ROLLBACK. The caller owns the transaction.
- `swap_apply($r)`: wrapper, same contract as today: START (false→'save'); rows; not true → ROLLBACK, return; COMMIT (false→ROLLBACK,'save').
  Used by `swap_request_respond`, `swap_finish_approved` and the fallback path below.

### 2.2 `swap_admin_decide($id,$decision)` returns a Hub outcome (pattern: `overtime_admin_decide`)
- Schema ensured before any transaction. Row Pending/Awaiting, else ERROR.
- Awaiting with an open engine request (`Hub::isOpenApproval`): one transaction holding `approval_act(...,false,true)`, a re-read, and
  - APPROVED → `swap_apply_rows` must be true;
  - REJECTED → `swap_close($id,'Rejected','Awaiting')===1`;
  - ADVANCED → nothing else;
  anything failing → ROLLBACK, ERROR. After COMMIT: ADVANCED → `swap_notify_approver` (the next level), no audit or employee notes;
  APPROVED/REJECTED → `swap_admin_done` (today's audit + notes).
- Otherwise (Pending, Awaiting without an engine request, or one already decided): unchanged (apply / close, then `swap_admin_done`).

### 2.3 `trait-admin-requests.php:109`
`$outcome=$this->swap_admin_decide($id,$decision)`. The Hub already shows ADVANCED ("Approval recorded and moved to the next level.").

### 2.4 Notifications
ADVANCED: the level-2 approver ("Shift swap to approve"); a level with no approver is told to administrators by `approval_act`'s deferred
notice (re-checked after commit). No Completed/Rejected notes on ADVANCED. Final decisions: unchanged.

### 2.5 Inbox-review fixes (Codex PR #7)
1. `tests/check_notification_inbox_boundary.py`: the call regex also catches `\get_option(` (leading backslash stripped), with a self-test.
2. Same script: the legacy rule matches `ews_notifications(?!\w)`, so `ews_notifications_cleanup` no longer fails it; self-test.
3. `notification_insert`: `absint($user_id)`, return 0 early when 0 (no misleading `notification_insert_failed` audit row). Defensive.

## 3. Switch
None: a bug fix restoring documented behaviour ("the days are exchanged only when the last one approves") and the owner's Q4 answer.

## 4. Edge cases
- Concurrency with an app approver: both hit `approval_act`'s conditional UPDATE on the PENDING instance; the loser gets `approval_stale`
  → ROLLBACK → ERROR / 'approval'. Lock order instance → next → request → swap → schedules in both paths; no cycle.
- Engine APPROVED, swap Awaiting (the Q4 `changed` state): plain path; Approve retries, the claim settles a race.
- Engine REJECTED, swap Awaiting: owner Q4.
- Final level by the admin, apply fails: everything rolls back (owner Q1): level stays PENDING, swap Awaiting, schedules untouched.
- Reject at any level: engine REJECTED and swap Rejected in one transaction.
- Single level: same success outcome as today, now atomic.
- Pending swap: unchanged (admin overrides colleague and approvers).

## 5. Tests
- Unit: no new pure logic (`RequestsHubTest` covers `workflowOutcome`); the boundary script self-test runs in CI.
- `tests/e2e_swap.py` with a 2-level workflow (level 2 a specific employee): admin advance (advanced notice, swap Awaiting, days unchanged,
  level 2 notified, no completion notes/audit); second admin approve completes; admin level 1 + app level 2 completes; old app form of
  level 1 refused; reject at level 1 and level 2; final level with a stale schedule → error and everything still open, then a clean reject;
  broken writes (schedule, approval requests, swaps); LEVEL_1 stale end state; engine forced APPROVED; a Pending swap with levels.
- `tests/e2e_notification_inbox.php`: `notification_insert(0,…)` returns 0 and writes no failure audit row.
- phpstan, phpcs, modules, translations; e2e approval_engine, request_races, my_approvals, admin_requests.

## 6. Risks
MyISAM (no rollback, existing); the Hub decides the level current at posting time (as leave/overtime); ERROR hides the reason (as today);
the `swap_apply` split touches the colleague-accept path (covered by the 3.31.117 race/broken-write tests); old orphaned engine requests.

## 7. Open questions for the owner (recommended first)
1. Admin's final level, apply fails: roll everything back / commit the approval and leave the swap Awaiting (as the app path).
2. App approver path: leave its 3.31.117 order this release / make it atomic now too.
3. Past day: no `checkNotPast` on admin decisions (override) / refuse an admin approval of a past day.
4. Engine REJECTED, swap Awaiting: admin may still approve (override) / reject only.
5. Hub message for a refused apply: the generic error / a new "schedule changed since the request" notice.
6. Swaps applied too early before 3.31.120: no repair, CHANGELOG explains / an upgrade routine cancels their open engine requests.

## Owner's answers (2026-10-22)
Q1 roll everything back; Q2 leave the app approver path as it is; Q3 no past-day check; Q4 the administrator may still approve;
Q5 **a special notice** ("a schedule day of this swap changed since the request"); Q6 no repair.

## Implementation notes and review (3.31.120)
Where the code differs from the design:
- Q5: `Hub::CHANGED` ('changed') with `Hub::isErrorNotice()`; the text is English like the other Requests page notices (that page
  is not translated). A refused exchange on the direct path (Pending, or an approval already decided) shows it too.
- The Requests page did not show the level of an Awaiting swap ("Peer"): the row now carries its approval request, so it shows
  "Approval #n · Level n" (the design assumed it already did).
- `swap_close` uses the table name directly instead of `ensure_swap_schema()`: it runs inside the transaction, where a dbDelta on
  an out-of-date schema would commit the approval decision early (review 5). Every caller reads the swap through the schema first.

Independent review: no CONFIRMED code bug. Fixed: the CHANGELOG (an orphaned approval from before can still send deadline
reminders or escalations when Shift Swap deadlines are set; the Approval column line), the missing tests (one level with a
changed schedule; an approval REJECTED while the swap waits), a test that could pass on another error notice, and `swap_close`
inside the transaction.
Not fixed (owner question): a repeated post (double click, two administrators) decides the next level too, since the form does not
say which level it decides. It is the same today for leave, overtime and every Requests page type; the fix (post the level, refuse
when it moved on) belongs in the approval engine for all types, in its own release.
