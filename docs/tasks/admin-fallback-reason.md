# Design: why a level went to administrators (QA 8)

Status: design (architect, read-only, on main 570a442 / 3.31.141). Owner decision 2026-11-06
(`docs/tasks/qa-report-3.31.138.md` item 3): a separate text per reason; for "no direct manager" a hint to set the
organization chart; "the approver made the request" as its own text; the engine keeps the reason on the rerouted step;
existing rows without a reason show a neutral text.

## 1. Today

### 1.1 Ways a level ends with administrators
A level waits for administrators when `rerouted=1 AND approver_wp_user_id IS NULL` (`ApprovalEngine::mayDecide`,
`WordPressApprovalRequestStore::inboxRows` / `stillWithAdministrators`).

| # | Path | Code | Stored today |
|---|---|---|---|
| A | approver is the requester, rule "It goes to administrators" (`ews_approval_self_rule=admins`) | `ApprovalEngine::start()` ~143 | `rerouted=1`, `skip_reason` NULL |
| B | approver is the requester, rule "next level", no later level acts | `StateMachine::start()` :43 turns `skip='self'` into rerouted | `rerouted=1`, `skip_reason` NULL (`self` lost) |
| C | nobody to decide (no direct manager / manager's manager / team or department manager, HR or Finance not set, specific employee archived, specific user deleted), or the person found has no WordPress user (start() :138) | `StateMachine::start()` :43, `missing` → rerouted | `rerouted=1`, `skip_reason` NULL (`missing` lost) |
| D | moved up past the top (hourly deadline job, nobody above) | `DeadlineService::escalate()` → store `escalate()` with `$up=null` | `rerouted=1`, approver NULL, `escalations+1`, `escalated_from_wp_user_id` |

B and C can hit a level still WAITING that opens later in `act()`: the reason is written when the step is inserted.
Levels skipped earlier keep their `skip_reason` (`condition` / `self` / `missing`).

### 1.2 Where the wording shows
1. Notification to every `manage_options` user: `approval_notify_administrators()` (`trait-approvals.php:253`) always says
   "its approver is the person who made the request" — wrong for C and D (the QA 8 bug). Callers:
   `ApprovalNotifier::administratorsNeeded` (`ApprovalEngine.php:207, 361`), `AfterRequest::administratorsIfStillWaiting`
   (`trait-services.php:213`), `approval_tell_moved_up()` (`trait-approval-deadlines.php:44`). No e-mail.
2. Requests Hub: `Hub::approvalLabel()` (`src/Requests/Hub.php:62`) "For an administrator (the approver made the request)"
   for every rerouted level (wrong for C, D). Called from `trait-admin-requests.php:78` (`$step` from `currentSteps`, all columns).
3. The path in My approvals and My requests: `approval_paths()` (`trait-approvals.php:455-476`), `who`="Administrators",
   no reason. Templates `app/approvals.php:91`, `app/requests.php:44`.
4. My approvals pill "For administrators" (`app/approvals.php:82`), driven by `$mine` in `trait-my-approvals.php:105`
   comparing `$st['who']===__('Administrators')` — `who` must not change.
5. Static help texts (admin approvals, org chart) are right; unchanged.

## 2. Reasons and texts

### 2.1 Storage: reuse `skip_reason` (VARCHAR(20)) on the rerouted step
No schema change, no `ews_schema_target()` bump. Only readers today: `approval_paths()` and e2e; PENDING/WAITING
branches never read it; CANCELLED tests only `closed`; `cancelOpenSteps(…, true)` overwrites with `closed` (right).
Codes written by `start()`: `self` (A, B), `missing` (C, resolver found nobody), `no_login` (C, no WordPress user).
D needs no write: derived from `escalations>0`, approver NULL, `escalated_from_wp_user_id` (covers old rows).
A new column would need a schema bump and break the golden base run (snapshot carries HEAD's schema version): not recommended.

### 2.2 Display codes: `AdminReason::of($step)` (new pure class `src/Approvals/AdminReason.php`)
`''` when not with administrators; else in order: `escalations>0 && escalated_from` → `moved_up`; `self`; `no_login`;
`missing` by `resolver_type` (SUPERVISOR `no_manager`, MANAGERS_MANAGER `no_managers_manager`, TEAM_MANAGER
`no_team_manager`, DEPARTMENT_MANAGER `no_department_manager`, HR `no_hr`, FINANCE `no_finance`,
SPECIFIC_EMPLOYEE/USER `approver_gone`); anything else incl. NULL → `unknown` (neutral).

### 2.3 Texts (`__()`; the Hub stays English like the rest of `Hub.php`)
| Code | Path line (`why`) | Hub suffix | Admin notification (title "Approval needed") |
|---|---|---|---|
| self | Administrators decide it: the approver made the request | (the approver made the request) — existing | existing string, translation kept |
| no_manager | …: no direct manager is set | (no direct manager) | …waits for an administrator: %3$s has no direct manager. Decide it in wp-admin → Requests Hub, and set their manager in the organization chart so their next requests go to them. |
| no_managers_manager | …: no manager's manager is set | (no manager's manager) | …: %3$s's manager has no manager. … |
| no_team_manager | …: no team manager is set | (no team manager) | …: %3$s's team has no manager. … |
| no_department_manager | …: no department manager is set | (no department manager) | …: %3$s's department has no manager. … |
| no_hr / no_finance | …: HR is not set / Finance is not set | (HR not set) / (Finance not set) | …: HR (Finance) is not set in Approval Workflows. … |
| approver_gone | …: the chosen approver is no longer active | (approver no longer active) | …: the approver chosen for this level is no longer active. … |
| no_login | …: the approver has no user account | (approver has no user account) | …: its approver has no WordPress account. … |
| moved_up | …: not decided in time, and nobody is above %s | (moved up, nobody above) | …: it was not decided in time and there is nobody above %3$s to move it to. … |
| unknown | Administrators decide it | (neutral) "For an administrator" | Approval #%1$d, level %2$d, waits for an administrator. Decide it in wp-admin → Requests Hub. |

%3$s: the requester's name (`people_name`), for `moved_up` the approver it moved up from.

- Path: new step key `why` (`''` otherwise), one more `<small class="wfo-apv-why">` under `sub`; `who`/`sub` unchanged.
- Hint: only on the My approvals card, only for `no_manager` / `no_managers_manager` / `no_login`, only with
  `ews_manage_employees`: "Set their manager so their next requests go to them." → `ews31-org-chart` when `org_enabled()`,
  else `ews31-employees&s=<name>`. Other hints: OQ1. "next requests": setting a manager does not move this request (OQ7).
- My requests (requester): the `why` line, never a hint.

## 3. Changes
Writes (one place): `StateMachine::start()` adds `'why'=>$skip` on steps it reroutes; `ApprovalEngine::start()` records
`self` (rule admins) / `no_login` in the plan and sets `$row['skip_reason']` on rerouted steps in the same INSERT (same
transaction, no new statement).
Notification: `string $reason='', string $name=''` on `ApprovalNotifier::administratorsNeeded`,
`AfterRequest::administratorsIfStillWaiting`, their adapters (`trait-services.php` 213/227/233) and
`approval_notify_administrators($id,$order,$reason='',$name='')`; the engine passes `AdminReason::of(...)`;
`approval_tell_moved_up()` passes `moved_up` and the from-name; one `people_name` per batch.
Reads: `approval_paths()` adds `why` (no new query); new helpers `approval_admin_reason_text($code,$from_name)`,
`approval_admin_reason_hint($code,$employee_id)` (in `modules.json`); `my_approvals_items()` adds `admins_why`,
`admins_hint` (the `$mine` check unchanged); templates `app/approvals.php`, `app/requests.php`;
`Hub::approvalLabel(..., string $reason='')` with `trait-admin-requests.php:78` passing `AdminReason::of($step)`;
CHANGELOG, `.pot`, ar `.po`/`.mo`/`.l10n.php`.

## 4. Switch
Recommended: none — it corrects a wrong text and adds a reason line; routing, permissions and timing are unchanged.
The only new UI is the hint link (OQ4 mockup, OQ9 switch).

## 5. Upgrade and concurrency
Old rows: NULL → neutral text (old escalated rows → `moved_up`, derived). No backfill. A downgrade is safe (old code reads
`skip_reason` only for SKIPPED/CANCELLED). No new statements in `act()`, `escalate()` or closing; the deferred shutdown
notification still re-checks `stillWithAdministrators`. `cancelOpenSteps(closed)` overwriting the reason is intended.

## 6. Golden (`expected.json`, release 3.31.142)
Run and declare exactly what it reports. Expected: approvals actions whose INSERT now carries `skip_reason`
(`self_admins_start`, `selfadmin_next_start`, `selfadmin_admins_start`, `selfadmin_other_level_start`,
`dl_start_overtime_no_mgr`, `tx_start_committed`, maybe `correction_start_hr`); notification text and the name read
(`shutdown_deferred`, `dl_tick_escalate_admins`); returns with `why` (`paths`, `path_one`, `inbox_items_mgr`, maybe
`leave_shown_steps`); rows `approvals:_rows:steps`, `_rows:notifications`, and the strict `_rows_before:*`; pages
`ews31-requests` (both: the seeded HR row with NULL becomes the neutral text — proves the old-row case), and the app
approvals/requests pages if they render it. Seed: add a rerouted `missing` SUPERVISOR row and a `self` row in
`seed_approvals.php`. `hooks_added`: none.

## 7. Tests
Unit: `ApprovalsTest` (StateMachine `why`), new `AdminReasonTest` (every code, `moved_up` first, NULL → unknown, not
with admins → ''), `ApprovalEngineTest` (`skip_reason` for admins/next-at-last/missing/no_login; others unchanged;
the notifier gets the code from `start` and `act`), `RequestsHubTest` (suffixes, neutral, self unchanged).
e2e: `e2e_approval_engine.py` (:171 Finance `missing` + message, :185 no manager + "has no direct manager", :203
correction `missing`, :221 Mona `self`, :258 "nobody above"); `e2e_self_approval.py` :118-123 `self`, Hub text
unchanged; `e2e_my_approvals.py` :181 (Big Boss card reason + hint link org chart on/off, no link without the
capability, old NULL row neutral, My requests reason without link); `my_approvals_browser.js` (phone, RTL).
Checks: unit, phpstan, phpcs, modules, translations, golden.

## 8. Risks
- `$mine` compares the translated "Administrators": keep `who`, add `why`.
- `skip_reason` now has two meanings: document it in `ensure_approval_schema` and `AdminReason`; a future filter must check `status`.
- Interface signatures change (`ApprovalNotifier`, `AfterRequest`): update test doubles; defaults keep old callers valid.
- The hint must not suggest this request moves ("next requests").
- The strict golden `_rows_before` changes: declare precisely.

## 9. Open questions for the owner
1. Hints for the other reasons (HR/Finance → Approval Workflows; team/department manager → Teams/Departments; approver
   gone → Approval Workflows), administrators only? Recommended: yes.
2. Reason on the My approvals card as a visible line under "For administrators" (recommended), or only in Details → The path?
3. Requests Hub: reason text only (recommended), or also the hint link?
4. Mockup first (CLAUDE.md), or is a one-line reason + link small enough?
5. My requests: the reason line without the hint? Recommended: yes.
6. Skipped levels with the finer text too ("Skipped: no direct manager is set")? Recommended: not in this release.
7. Setting the manager later: also move the requests already waiting for administrators? Today not (separate engine change).
8. The requester's name in the admin notification? Recommended: yes.
9. Confirm no switch is needed (wording fix, no routing or permission change).
