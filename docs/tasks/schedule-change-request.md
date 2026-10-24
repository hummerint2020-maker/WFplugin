# Design: week lock, release 2, "schedule change request" (owner answer 8)

**Status:** design only, read-only analysis of branch `claude/wordpress-plugin-improvement-jb0znc` at fefcda9 (3.31.121, PR #10 not merged). It is ready to save to `docs/tasks/schedule-change-request.md`. **No code or mockups yet.** Mockups come first (§10). The owner answers §12 before implementation starts.

---

## 0. What exists today

### The R1 lock
- `week_lock_refusal($date)` (`includes/trait-week-lock.php:44`) returns `''`, `'override'` or `'locked'`.
- The grid's concurrent path skips locked cells and counts them (`trait-attendance.php:317-319`). The no-JS path does the same (`:386-388`).
- The redirect sends `grid_locked=N` (`:410`). The message is built in `trait-frontend.php:180-193`: "N changes were not saved … To change a day of a final week, ask HR."
- `att_single` refuses with `wp_die` (`:414`).
- The CSV preview marks locked rows as errors (`parse_csv` `:453`), and they are re-checked at apply (`att_import` `:466`).
- **The grid for a final week is entirely read-only for non-override users** (`templates/app/attendance.php:82`, `$wl_readonly`; chips disabled at `:205`; save bar replaced at `:234-236`). So today a manager can only reach "N not saved" when the page was loaded before the cutoff. **R2 has to give a final week a "request mode"; a different message alone is not enough.**

### Schedule writes and their guards
- `save_att_if_unchanged` (`trait-core.php:233-362`) does a compare-and-update on the status. It notifies the employee ("Schedule Updated"), but outside any transaction.
- `branch_plan_day` (`trait-branches.php:150-160`) writes `ews_schedule.location_id`.

### The request types to copy
**Salary advance** (`trait-advances.php`) is the most modern engine-integrated type:
- `advance_workflow_ready()` (`:144-148`) configures a default workflow on first use, outside any transaction.
- The request is created inside one transaction with `approval_start(...,false)` (`:167-174`).
- A decision runs `START → approval_act(...,false) → entity update → COMMIT`, and notifies after the commit (`:211-227`).
- Withdraw (`:231-240`).

**Swap** (`trait-swap.php`) gives the apply guard and the Hub pattern:
- `swap_apply_rows` (`:79-101`) claims the swap first, then updates each day only if its status is still as read (`WHERE … status=old`), in a fixed id order so two swaps cannot deadlock.
- `swap_admin_decide` (`:196-224`) puts the engine decision and the apply in one transaction. A changed day returns `Hub::CHANGED` and the level stays open.

**The daily workers' "change request"** (`trait-daily-workers.php:779-862`, table `ews_dw_changes` `:188-208`) is the product precedent:
- A locked day plus a reason becomes a row holding the old and new values.
- One pending change per day (`:792`).
- A decision is claim-first, and the day is updated conditionally (`:839-843`).
- It is not on the approval engine. R2 is, because owner answer 8 says "a new approval type".

### The approval engine
- `Workflows::IN_USE` is at `src/Approvals/Workflows.php:26-35`.
- **`NONE_MEANS[$key]` is read without `??`** at `trait-approvals.php:888`, so a new key must be added there.
- Workflows are seeded at `:240-264`. Seeding only runs inside `ensure_approval_schema()`, so **the schema target must be bumped**.
- `approval_start` (`:486-591`) resolves approvers relative to `requester_employee_id`. Here the requester is the manager, so "Direct manager" means the manager's manager.
- The self rule is applied at start (`:552-555`) and when deciding (`approval_may_decide` `:611-623`).
- Substitutes are handled in `:580` and `:661`.
- `approval_act` (`:626-680`) does not know which level the form showed. That is the open "double post" item (`docs/tasks/swap-admin-levels.md:101-105`). **The owner asked for it to be fixed in the engine "before the week-lock feature". It is not done yet.**
- `approval_close_open` (`:852-866`) gives the lock order: engine rows first, then the module's row.
- Deadlines (`trait-approval-deadlines.php:15-45`) cover every `IN_USE` key, and labels come from `approval_kind_label` (`:80-84`).

### Hub, My approvals, My requests
- Hub: `Hub::TYPES` (`src/Requests/Hub.php:13`), rows (`trait-admin-requests.php:16-83`), decision switch (`:100-117`). It requires `manage_options`.
- My approvals: cards come from `my_approvals_card` (`trait-my-approvals.php:53-63`), filters from `my_approvals_kinds` (`:133`).
- My requests: built in `requests_content` (`:184-205`), withdraw in `request_withdraw` (`:244-262`).
- **Both need `ews_feature_my_approvals`** (`:25`, `:49`). An HR user who is not an administrator has nowhere else to decide.

### Money and attendance
- `report_days` (`trait-reports.php:274-333`) reads the planned status and its `attendance_rule`. This decides the day's result (Absent, Late, Leave, Business Trip).
- Payroll (`trait-payroll.php:115-159`, `src/Payroll/PayCalculator.php:18-28`): Absent days are deducted, late minutes are deducted, and **"leave without a request (planned by a manager) is paid"**.
- `statuses()` includes **"Absent"** (`trait-schedule-types.php:112-114`).
- So a schedule change moves money:
  - Office → Business Trip or Vacation on a past day removes an absence deduction.
  - → Absent adds one.
  - Leave balances only move through `ews_leave_requests` (`trait-leave.php:138-183`).
- Leave approval snapshots the days and writes Vacation (`:153-182`). **Leave cancellation restores the snapshot without any condition** (`:207-210`), so R2 must never write a day held by a leave.
- A correction in a closed payroll month carries the pay difference forward (`trait-corrections.php:485-529`).

### Platform and checks
- Schema: `ews_schema_target()` is `'3.31.121'` (`trait-schema.php:332`). Steps are listed at `:367-375`. SchemaSql format and `SchemaCheck` (`src/Support/SchemaCheck.php`) apply. `uninstall.php:56` lists the tables.
- Cron: `Support\Lifecycle::CRON_HOOKS` already has `ews_week_lock_tick` (5 minutes, `employee-schedule-manager.php:244,266`). R2 reuses it, so there is no new hook.
- `notify()` (`trait-notifications.php:138-163`) inserts a row at once and queues push for shutdown. **So notifications must be sent after COMMIT.**
- Module boundaries: the new trait goes in the `schedule` module (`docs/architecture/modules.json:96-146`), and its card, my-requests, withdraw and Hub functions go in that module's `api`.
- API: the only schedule route is the read-only `/me/schedule` (`trait-schedule-api.php:19-45`). No API route writes the schedule.

---

## 1. User journeys

### J1. Manager in the grid, a final week (one or many days and people)
1. The manager opens a final week with the feature on. The banner reads: "This week is final. Changes you make are sent to HR as a request, with a reason."
2. The cells are clickable ("request mode"). A day with an open request shows a clock badge, "Change requested: WFH (by Sara)". It cannot be requested again (one open request per person and day).
3. The manager changes one or more cells: several days, several people, the "Set…" fill and the branch too. The save bar reads "N changes to send to HR" with the button **"Send to HR…"**.
4. A sheet opens. It lists the changes (person · day · old → new · branch), has a **required reason** (minimum length from the settings) and shows the office-minimum effect per day (warning only).
5. Submit posts to the new handler `ews_schedule_change_request` with the same `att_changes_json` the grid uses (employee, day, status, original, branch) plus the reason.
6. The server checks each change (§4.2). Valid ones become **one request** with N days. Refused ones are listed in the result message with their reason (already waiting, a leave on that day, status not allowed, too far back, closed payroll month…).
7. The manager returns to the grid. The cells show "Change requested", and a notice says "Sent to HR: 3 days. 1 not sent: …".

An open week is saved directly as today. A page has one week, so a submission is never mixed.

### J1b. A page opened before the cutoff, saved after it (the R1 "not saved" case)
The changes are refused as in R1. With R2 on, they are also kept for 15 minutes in a draft (transient `ews_sc_draft_<uid>`). The R1 modal changes:
- from "N changes were not saved. This week is final now … ask HR."
- to the same text plus a button **"Send them to HR as a request"**.

The button reopens the grid in request mode with the draft loaded, and the manager only adds the reason. Conflicting cells stay reported as today.

### J2. CSV import
- The preview marks rows in a final week as **"Final week: will be sent to HR"** (not an error) when CSV requests are on (setting). The other errors are unchanged.
- The import button reads "Import N rows and send M to HR" and the form has a required reason.
- At apply, every row is checked again. Open-week rows are saved as today. Final-week rows become one request (source `import`) with the same reason, and the `max_days` limit applies.
- With the setting off, R1 behaviour stays (an error row).

### J3. HR decides
- **The app, My approvals.** The card is "Schedule change · Sara Ali (manager) · 3 days". It shows the reason, each day (person · day · old → new · branch), what each day is now if it changed (a "changed since the request" warning), the office minimum, and any closed payroll month. Approve, or reject with a reason.
- **wp-admin, the Requests Hub.** A row "Schedule change" with the days and reason. The administrator decides **the current level only** (as swaps since 3.31.120). The form posts the level it shows, and the handler refuses if the level moved on (§3.6).
- **The level's approver gets a notification** when the request reaches them: in-app plus push, linked to My approvals.

### J4. The requester follows and withdraws
- **My requests** shows "Schedule change · 3 days · week of 11 Oct", its path (the levels) and its state: waiting, approved, partly applied, rejected with the reason, withdrawn, expired, or closed.
- **Withdraw** is allowed while waiting (`kind=schedule_change`).
- The requester is told when it is decided. A partly applied request lists the days not applied and why.

### J5. The affected employee
- **On apply:** each employee gets one notification listing their days, "Your schedule for Tue 13 Oct is now WFH". This matches what a grid save sends today (`trait-core.php:350-358`).
- **On request:** told only if the setting is on (default off).
- **On reject or expiry:** nothing is sent, because nothing changed.
- The employee's schedule view shows nothing while the request waits (default).

### J6. Approve, last level
In one transaction:
- the engine decision;
- the claim on the request;
- for each day, a conditional write of the schedule row (status, and branch when requested) only if it is still the status read at request time.

Days that changed in between (leave approved, swap applied, override edit, another path) are handled by the "changed day" setting (§5): skip the day (default) or refuse the whole request. Audit rows and notifications follow the COMMIT.

### J7. Reject
The engine marks the request REJECTED, the request is claimed as rejected and its days are released (`open_key` cleared). After COMMIT the requester is told with the reason. The reason is required by default (seeded, §3.5).

### J8. Expiry
In the five-minute `ews_week_lock_tick`, a request still waiting is closed automatically ("Closed automatically") when:
- it is older than `expire_days` (default 14), or
- any of its days falls in a payroll month that has since been closed (when closed months are refused, the default).

The engine levels are cancelled with `skip_reason='closed'` and the requester is told. "The day has passed" is **not** a reason to expire, because past final days can be requested (within `past_days`).

### J9. The grid with a pending request
- The cell keeps the current planned status and adds a clock badge with the requested status in its title.
- It cannot be picked in request mode.
- Override holders and managers of open weeks can still edit it directly (the badge warns them). The request then becomes "changed" at apply.
- The day's result line is unchanged.

---

## 2. Data model

Two tables, created with `ensure_schedule_change_schema()` in the SchemaSql format (one column per line, `KEY name (cols)`, `PRIMARY KEY  (id)`, integer widths). Both are added to `maybe_upgrade_schema` (`trait-schema.php:367-375`) and `uninstall.php:56`, and the target is bumped (§2.3).

### 2.1 `ews_schedule_change_requests` (the header, one per submission)
| Column | Type | Note |
|---|---|---|
| id | BIGINT(20) UNSIGNED AI | the engine's entity_id |
| requester_employee_id | BIGINT(20) UNSIGNED NOT NULL | the manager (the approval requester) |
| requested_by | BIGINT(20) UNSIGNED NOT NULL | WP user |
| source | VARCHAR(10) NOT NULL DEFAULT 'grid' | grid / import / draft |
| reason | TEXT NOT NULL | |
| status | VARCHAR(20) NOT NULL DEFAULT 'pending' | pending, approved, partial, rejected, withdrawn, expired, closed |
| days | SMALLINT(5) UNSIGNED NOT NULL | number of day rows |
| first_date, last_date | DATE NOT NULL | for expiry and lists |
| requested_at | DATETIME NOT NULL | |
| decided_by | BIGINT(20) UNSIGNED NULL | |
| decided_at | DATETIME NULL | |
| decision_note | TEXT NULL | |
| applied | SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0 | days applied |

Keys: `status (status)`, `requester_status (requester_employee_id,status)`, `first_date (first_date)`.

### 2.2 `ews_schedule_change_days` (one row per employee and day)
| Column | Type | Note |
|---|---|---|
| id | BIGINT AI | |
| request_id | BIGINT NOT NULL | |
| employee_id | BIGINT NOT NULL | |
| work_date | DATE NOT NULL | |
| had_row | TINYINT(1) NOT NULL | whether a schedule row existed at request time |
| schedule_id | BIGINT NULL | that row |
| old_status | VARCHAR(40) NULL | **as stored**, untrimmed (the guard) |
| new_status | VARCHAR(40) NOT NULL | |
| branch_set | TINYINT(1) NOT NULL DEFAULT 0 | whether a branch was requested |
| old_location_id, new_location_id | BIGINT NULL | |
| result | VARCHAR(12) NULL | applied, already, changed, inactive, gone, branch, leave |
| now_status | VARCHAR(40) NULL | what the day was when it was skipped |
| open_key | VARCHAR(40) NULL | `employee_id|work_date` while the request is open, NULL after |

Keys: `request_id (request_id)`, `employee_date (employee_id,work_date)`, **`UNIQUE KEY open_key (open_key)`**. This enforces one open request per person and day; NULLs are allowed many times on MySQL and SQLite.

- **Why rows and not JSON:** the grid badge is an indexed lookup, the uniqueness is enforced by the database, and each day keeps its own result.
- **Approval link:** workflow `schedule_change`, entity_type `schedule_change`, entity_id = header id. One approval per request.

### 2.3 Upgrade
- Bump `ews_schema_target()`. It will be 3.31.123 if the engine double-post fix ships first as 3.31.122, as recommended.
- `approval_seed_workflows` adds `'schedule_change'=>['Schedule Change','LEVEL_1']`, inactive.
- `SchemaCheck::TABLES` is not changed: feature tables, such as advances, are checked by their own routine's errors.
- The switch is off, so nothing else changes.
- The second-upgrade "no ALTER" test covers the new tables (`tests/e2e_schema.py`).

---

## 3. Approval engine integration

### 3.1 Workflow key
`'schedule_change' => ['Schedule Change', ['LEVEL_1','LEVEL_2','LEVEL_3','LEVEL_4']]`.
- **No `NONE`:** "No approval" would turn the request into a direct edit and defeat the lock.
- **No `PEER`.**
- Add `NOT_SET_MEANS` ("The first request sets it to one level: HR") and **`NONE_MEANS` (`''`, required by `:888`)**.

### 3.2 Default levels and approver
`sc_workflow_ready()` is a copy of `advance_workflow_ready`. When the workflow was never saved it calls `approval_configure_workflow('schedule_change','LEVEL_1',[1=>['resolver_type'=>'HR']],…)`. **It runs before `START TRANSACTION`**, because it opens its own transaction.
- When HR is not set, the engine marks the level "missing" and routes it to the administrators (`:556-557`), who are notified.
- The Feature Configuration card warns "HR is not set (Approval Workflows → HR)".
- Configurable in the workflow editor like the other types (owner Q1).

### 3.3 Self rule (a manager who is HR)
`sc_workflow_ready()` also seeds `ews_approval_self_rule['schedule_change']='admins'`, only if no key exists. HR's own request then goes to the administrators instead of being approved by its own author. The rule is editable on the card.

### 3.4 Substitutes and deadlines
Both are inherited without new code (`:580`, `:661`; `approval_deadlines_tick` loops `IN_USE`). Defaults are 0 (never), as for every type. `approval_kind_label` gets `'schedule_change'=>__('A schedule change request')`.

### 3.5 Reject reason
`sc_workflow_ready()` seeds `ews_approval_reject_note['schedule_change']=1` when not set. It is editable on the card.

### 3.6 Double post
Until the engine fix lands:
- the card and Hub forms post `level=<step_order>`;
- `sc_decide()` reads `approval_current_step()` and returns `'moved'` ("It was already decided at this level") when the level differs.

Recommended sequencing: ship the owner-approved engine fix first (posted level checked in the engine for every Hub type), then R2 relies on it.

### 3.7 Hub, My approvals, My requests, withdraw
| Place | Change |
|---|---|
| `Hub::TYPES` | add `'schedule_change'` |
| `admin_request_rows` | a loop over `sc_hub_rows()`, ref `['schedule_change','schedule_change',id]` |
| `admin_requests_decision` | `case 'schedule_change': $outcome=$this->sc_hub_decide($id,$decision);` returning APPROVED / REJECTED / ADVANCED / CHANGED / ERROR |
| `Hub::hubNotice` | add `PARTIAL` ("Approved: some days had changed since the request and were not applied") |
| `my_approvals_card` | `case 'schedule_change': return $this->sc_enabled()?$this->sc_approval_card($id):null;` |
| `my_approvals_kinds` | `'schedule'=>__('Schedule changes')` |
| `requests_content` | merge `sc_my_requests($emp->id)` |
| `request_withdraw` | `case 'schedule_change': $r=$this->sc_enabled()?$this->sc_withdraw($id,$emp->id):'access';` |
| `templates/app/requests.php:55` | the withdraw text keeps "Nobody will need to decide it." |

---

## 4. Files and functions

### 4.1 New files
- `includes/trait-schedule-change.php`, `EWS_Schedule_Change_Trait`, schedule module.
- `src/Schedule/ScheduleChangeRules.php`: pure. `check(array $facts): string` returns '' or an error code. `outcome(old, now, new, facts): string` returns the per-day apply result. `summary()`.
- `src/Settings/ScheduleChangeSettings.php`: pure. `DEFAULTS`, `config($saved)`, `fromPost($post)`.
- Templates: `templates/app/schedule-change-sheet.php` (the send sheet), the card facts and the grid badge in `templates/app/attendance.php`, and the Hub row (generic).
- `assets/js/attendance-grid.js`: request mode (when `data-ews-sc-mode`, cells stay enabled, submit opens the sheet, the payload is unchanged, plus reason), loading the draft.
- CSS in `assets/css/app-attendance.css`.
- Tests and mockups (§9, §10).

### 4.2 Trait functions
- `sc_enabled()` = `option('ews_feature_schedule_change') && week_lock_enabled()`.
- `sc_settings()`, `sc_tables()`, `ensure_schedule_change_schema()`, `sc_workflow_ready()`.
- `sc_open_for_days(array $eids, array $dates)` for the grid badges (one query).
- `sc_facts($emp,$date,$status,$branch,$requester)` for the rules. It gathers:
  - locked (`week_lock_is_locked`);
  - the allowed statuses and whether the status is a leave type;
  - a leave on the day (`leave_away_between`, leave api);
  - a holiday (`company_leave_dates`);
  - recorded attendance (time logs);
  - days back (`past_days`);
  - a closed payroll month (`payroll_run`);
  - scope (`department_scope_allows_employee`);
  - attendance enabled;
  - an already open request (`open_key`).
- `sc_create($requester_emp, array $changes, $reason, $source)` returns `['id'=>…,'refused'=>[…]]`.
- `schedule_change_request()`: the admin-post handler for the grid and draft.
- `sc_import_requests()`: called from `att_import`.
- `sc_decide($id,$decision,$note,$level,$admin=false)`, plus `schedule_change_decide()` (admin-post for the app cards) and `sc_hub_decide()`.
- `sc_apply_days($r)`: inside the caller's transaction, never commits.
- `sc_withdraw($id,$employee_id)`, `sc_expire_tick()` (called from `week_lock_tick` before its own `enabled` check), `sc_close_all($why)` (switch turned off, §8.L).
- `sc_approval_card`, `sc_my_requests`, `sc_hub_rows`, `sc_notify_approver`, `sc_after_commit($r,$outcome,$applied)`.

### 4.3 Changes to existing files
- **`trait-attendance.php`**
  - `att_grid_save` stays as is. When `$locked>0 && sc_enabled()`, it stores the refused changes in the draft transient and adds `grid_draft=1` to the redirect.
  - `parse_csv`: when `sc_enabled() && settings import`, a final-week row is valid with `'request'=>true` instead of the error.
  - `att_import` calls `sc_import_requests($rows_for_request,$reason)`. With the switch off, the code path is identical to today.
- **`trait-attendance-grid.php:84`** passes `sc_mode`, `sc_open` (badges), `sc_settings` and the draft.
- **`trait-frontend.php:180-193`**: the modal gets the "Send them to HR" action when `grid_draft=1`. It also handles `sc_sent` / `sc_error` notices and adds them to the `remove_query_arg` list at `:615`.
- **`templates/app/attendance.php`**: `$wl_readonly` becomes false in request mode. Add the banner text, the badges, the save bar "Send to HR…" and the CSV note (`:284`).
- **Approvals:** `trait-approvals.php` `approval_seed_workflows`; `Workflows.php` (IN_USE, NOT_SET_MEANS, NONE_MEANS); `trait-approval-deadlines.php:80`.
- **Hub and inbox:** `trait-admin-requests.php`, `Hub.php`, `trait-my-approvals.php` (four places).
- **`trait-features.php`**: the card and saving `ews_feature_schedule_change` and `ews_schedule_change` (only for "Edit a locked week" holders, same as the week lock card, `:87`), the audit `schedule_change_settings`, and `sc_close_all()` when it goes off.
- **`templates/admin/features.php:166-180`**: a "Change requests to HR" block inside the Week lock card.
- **Schema and options:** `trait-schema.php` (step and target), `src/Settings/Options.php` (§5 keys), `uninstall.php`.
- **`employee-schedule-manager.php`**: `admin_post_ews_schedule_change_request`, `admin_post_ews_schedule_change_decide`.
- **Docs and translations:** `docs/architecture/modules.json` (trait in `schedule`, tables `schedule_change_requests` and `schedule_change_days`, api `sc_approval_card`, `sc_my_requests`, `sc_withdraw`, `sc_hub_rows`, `sc_hub_decide`, `sc_enabled`, `sc_import_requests`), `languages/*.po` (Arabic), `CHANGELOG.md`.

### 4.4 Create (pseudo-code)
```
sc_create(req_emp, changes, reason, source):
  if !sc_enabled()  -> error 'disabled'
  if !req_emp       -> error 'employee'          // the requester must be a linked employee
  ensure_schedule_change_schema()                // BEFORE any transaction (dbDelta commits implicitly)
  if !sc_workflow_ready() -> error 'approval'    // its own transaction, before ours
  reason length >= settings.reason_min, else error 'reason'
  for each change: facts = sc_facts(...); err = ScheduleChangeRules::check(facts)
      err ? refused[] : ok[]   (old_status = the stored status, had_row, schedule_id, old location)
  if count(ok) > max_days -> error 'too_many' (nothing sent)
  if !ok -> return refused
  START TRANSACTION
    insert header (status pending)                  -> false: ROLLBACK, 'save'
    for each ok (sorted employee_id, work_date):
      insert day with open_key = eid|date           -> false: ROLLBACK, 'pending'
        (never go on after a failed statement: SQLite has already rolled back the whole transaction)
    ar = approval_start('schedule_change','schedule_change',id,req_emp.id,[],false)  -> WP_Error: ROLLBACK, 'approval'
  COMMIT                                            -> false: ROLLBACK, 'save'
  after COMMIT: audit 'schedule_change_request' (one row; days listed); notify the current approver;
                administrators via approval_notify_administrators_after (already in the engine);
                if settings.notify_on_request: each employee told "a change of your day was requested"
```

### 4.5 Decide and apply (pseudo-code, the core)
```
sc_decide(id, decision, note, level, admin):
  r = sc_get(id)                                   // read through the schema (no ensure inside the transaction)
  if !r || r.status!='pending' -> 'done'
  ar = approval_find_request('schedule_change','schedule_change',id); step = approval_current_step(ar.id)
  if !step || step.step_order != level -> 'moved'  // double-post guard (§3.6)
  if !admin && !approval_can_act(ar.id) -> 'access'
  START TRANSACTION
    acted = approval_act(ar.id, decision, note, false, admin)        // locks the engine rows first
    if WP_Error: ROLLBACK; 'note' | 'approval'
    fresh = approval_find_request(...)
    if fresh.status starts with WAITING: COMMIT; after: notify the next approver; return 'advanced'
    if REJECTED:
       n = UPDATE requests SET status='rejected',decided_by,decided_at,decision_note WHERE id=? AND status='pending'
       if n!==1: ROLLBACK; 'done'
       UPDATE days SET open_key=NULL WHERE request_id=?   // check !==false only (MySQL counts changed rows)
       COMMIT; after: audit, notify the requester; return 'rejected'
    // APPROVED (the last level)
    n = UPDATE requests SET status='approved',decided_by,decided_at WHERE id=? AND status='pending'   // claim first
    if n!==1: ROLLBACK; 'done'
    res = sc_apply_days(r)      // per-day results or 'refuse' / 'save'
    if res=='save': ROLLBACK; 'save'
    if res=='refuse': ROLLBACK; return 'changed'    // setting changed_day=refuse: nothing changes, the level stays open (swap's Hub::CHANGED)
    UPDATE requests SET status = (applied==days ? 'approved' : 'partial'), applied=? WHERE id=?
  COMMIT                                            -> false: ROLLBACK, 'save'
  after COMMIT: substitute_after_decision is done by approval_act;
     audit 'schedule_change_applied' per applied day (entity employee:
        "date | change #id | old → new | approved by X (final week)") and 'schedule_change_skipped' per skipped day;
     notify each affected employee (one message per employee, their applied days);
     notify the requester (applied N of M, and why the rest);
     return applied==days ? 'approved' : 'partial'

sc_apply_days(r):      // inside the caller's transaction
  days = SELECT * FROM days WHERE request_id=? ORDER BY employee_id, work_date   // one order everywhere: no deadlock
  for d in days:
     emp = active and attendance-enabled?            else result 'inactive'
     new_status still an active type?                else 'gone'
     a leave now covers the day (pending/approved)?  else 'leave'
     branch requested and still the employee's and existing?  else 'branch'
     if d.had_row:
        n = UPDATE schedule SET status=new, [location_id=new_loc,] updated_by=uid, updated_at=now
            WHERE id=d.schedule_id AND employee_id=d.employee_id AND work_date=d.work_date AND status=d.old_status
        n===false -> return 'save'
        n===0: cur = SELECT status ...; result = (cur==new ? 'already' : 'changed'), now_status=cur
     else:
        exists = SELECT id ... (pre-check: never let an INSERT fail, SQLite would abort the transaction)
        exists: result 'already' / 'changed' as above
        else INSERT schedule row (employee, date, new, note '', updated_by, updated_at [, location_id])
             false -> return 'save'   // a racing insert on MySQL: the whole decision rolls back, try again
     non-applied && settings.changed_day=='refuse' -> return 'refuse'
     UPDATE days SET result=?, now_status=?, open_key=NULL WHERE id=?
  return results
```
- **Lock order** (the same in create, decide, withdraw and expire): engine instances and requests, then the module's header, then its day rows, then `ews_schedule` rows in (employee_id, work_date) order.
- **No nested transactions:** only `sc_decide`, `sc_create`, `sc_withdraw` and `sc_expire_one` start one. The engine is always called with `manage_transaction=false`. `sc_hub_decide` does not wrap `sc_decide`.

### 4.6 Withdraw and expire
- **Withdraw:** START; `approval_close_open('schedule_change','schedule_change',id)` ('moved' gives ROLLBACK, 'done'; false gives 'save'); claim `UPDATE requests SET status='withdrawn' WHERE id AND requester_employee_id AND status='pending'` (must be 1); clear `open_key`; COMMIT; then audit.
- **Expire:** the same with `closed=true` and status `expired`. After it, tell the requester.

---

## 5. Settings, the switch and off behaviour

**Recommended:** an own switch `ews_feature_schedule_change`, default off, effective only while the week lock is on. It is shown inside the Week lock card and greyed when the lock is off.

**Off means R1 exactly:**
- the grid read-only;
- the same "not saved" modal;
- CSV error rows;
- no query on any page (the `sc_enabled()` checks come first, like `week_lock_enabled()`);
- no Hub or inbox type rows (functions return `[]` or `null` without touching the tables);
- no API change.

The tables are created by the upgrade, as for every feature. They are not created when the switch is turned on.

### Settings table
All in Options.php. The grouped option `ews_schedule_change` is sanitised by `ScheduleChangeSettings`, edited on the Feature Configuration → Week lock card, and only "Edit a locked week" holders may change it.

| Key (option · field) | Default | Allowed | Where |
|---|---|---|---|
| `ews_feature_schedule_change` (bool) | 0 (off) | 0/1 | Week lock card |
| `ews_schedule_change` · `statuses` | all active types whose rule is attendance or business_trip, plus Absent | any subset of active types plus Absent | Week lock card |
| · `allow_leave` | 0 (leave types must go through leave requests) | 0/1 | Week lock card |
| · `reason_min` | 10 characters (a reason is always required, owner answer 8) | 1–200 | Week lock card |
| · `max_days` | 25 day rows per request | 1–200 | Week lock card |
| · `past_days` | 14 days back (0 = today and later only) | 0–90 | Week lock card |
| · `closed_month` | `refuse` | `refuse` (the only value in R2; `carry` needs a later release) | not shown in R2 |
| · `expire_days` | 14 (0 = never) | 0–90 | Week lock card |
| · `notify_on_request` | 0 | 0/1 | Week lock card |
| · `changed_day` | `skip` (apply the others) | `skip` / `refuse` | Week lock card |
| · `import` | 1 (final-week CSV rows can be sent to HR) | 0/1 | Week lock card |
| · `close_on_off` | fixed behaviour, see Q8 | — | not a setting |
| Approval levels, approvers, amounts | LEVEL_1 HR (seeded on first use) | LEVEL_1–4, every resolver | Approval Workflows card "Schedule Change" |
| `ews_approval_self_rule[schedule_change]` | `admins` (seeded) | allow / next / admins | Approval Workflows card |
| `ews_approval_reject_note[schedule_change]` | 1 (seeded) | 0/1 | Approval Workflows card |
| `ews_approval_deadlines` / `_overdue` [schedule_change] | 0 / 24 | engine ranges | Approval Workflows card |
| `ews_sc_draft_<uid>` (transient) | — | internal, 15 minutes | — |

### Not worth making configurable
- **Requests for open weeks.** The manager edits them directly. A request there adds work and nothing else.
- **Days held by a leave.** They must be changed through the leave, because a cancellation restores its snapshot and would silently undo the change.
- **Holidays and non-working days.** The planned status has no effect there (`report_days` uses Holiday or Off Day).
- **"Ask the requester again" when a day changed.** It is a new approval loop for a rare case; the requester can send a new request.
- **"Apply anyway" when a day changed.** It would overwrite a leave, swap or override edit. That is unsafe, and the owner's "requests always apply" answer is about leave and swaps, not this.
- **A separate "may request" permission.** Requesters are the people who already manage attendance (`ews_manage_attendance`) in their department.
- **Employee notification on apply.** It always happens, as every grid save notifies today. Turning it off would hide a real schedule change.
- **The office minimum.** It is a warning only and follows its own feature.

---

## 6. Interaction with R1
- **Override holders ("Edit a locked week"):** unchanged. They edit directly, the edit is audited, and they see the badges.
- **Managers without override:** a final week is in request mode (J1). `att_single` still refuses.
- **The "N changes were not saved" message** gains "Send them to HR as a request" (J1b).
- **Requests (leave, cancellations, swaps):** unchanged and still recorded by `week_lock_record_request`. They can make an open change request "changed".
- **Only final days can be requested.** An open week is saved directly. A day that became open again (cutoff moved) can still be decided, and the guard decides.

---

## 7. Mobile API
**Recommended: no write endpoint in R2**, because the API has no schedule-editing route for managers today.

Optional, read-only and only when the switch is on: `/me/schedule` days get `final` (the R1-deferred flag) and nothing about pending requests. That means `ScheduleDays::build` plus `docs/api/openapi.yaml` plus `check_openapi.py`. This is Q9, with "none in R2" as an alternative.

---

## 8. Edge cases
Each case: the scenario → what happens → recommended behaviour → whether a setting controls it.

### A. Concurrency
1. **Two managers request the same day at once.** → The UNIQUE `open_key` lets one insert through. → The second request is rolled back whole and reported as "already waiting". The per-day pre-check gives the friendly message; the database decides the race. Setting: no.
2. **Two days of one request, one of them already open elsewhere.** → The pre-check refuses that day, and the rest is sent. If the race happens between the check and the insert, the whole submission rolls back with "try again". Setting: no.
3. **HR approves while the grid saves the same cell (override holder).** → The conditional UPDATE matches 0 rows. → The day becomes `changed` (skip or refuse per the setting). Never overwritten. Setting: `changed_day`.
4. **HR approves while a leave is approved for that day.** → Both are transactions. The schedule row lock serialises them: the second sees the new status, so the day is changed (leave) or the leave snapshot holds the requested status. → If the leave wins, the day is skipped. If R2 wins first, the leave snapshots the new status and its cancellation restores it, which is correct. Setting: no.
5. **Two administrators, or a double click, on the Hub.** → Without a guard, level 2 would be decided too. → The posted level is checked and a mismatch returns `moved` (§3.6). Setting: no.
6. **Approve and withdraw at the same moment.** → Both touch engine rows first: `approval_act`'s `WHERE status='PENDING'`, and `approval_close_open`'s 'moved'. → One wins, the other gets "already decided". Setting: no.
7. **Expiry tick and an approval at the same moment.** → The same as 6. Two overlapping ticks: the claim UPDATE lets only one through. Setting: no.
8. **A row inserted by someone else between the pre-check and the INSERT (MySQL).** → The INSERT fails. → The whole decision rolls back, the level stays open and HR sees "try again". On SQLite the writer lock makes this impossible. Setting: no.
9. **The cutoff passes between page load and submit.** → R1 draft flow (J1b). Setting: no.

### B. Data changed before the decision
10. **The status changed to something else** (leave, swap, override edit, another approved request). → `changed`, `now_status` recorded, shown on the card before deciding. → Skip (default) or refuse the whole. Setting: `changed_day`.
11. **The status is already the requested one.** → `already`, counted as done, no write. Setting: no.
12. **The day changed and changed back (ABA).** → The guard sees the old status and applies. Acceptable: the day is as it was when requested. Setting: no.
13. **The schedule row was deleted (leave cancellation of a day that had no row).** → It is treated as "no row": insert if it had none, changed if it had one. Setting: no.
14. **A leave (pending or approved) now covers the day.** → `leave`, skipped. Never written under a leave. Setting: no.
15. **The requested branch was removed from the employee, or the location deleted.** → `branch`, skipped. Setting: no.

### C. Permissions and scope
16. **Requester scope.** Only employees `department_scope_allows_employee` allows, with attendance enabled, at request time. Not re-checked at apply (HR approved it). Setting: no.
17. **The requester has no linked employee** (a WP user with Manage Attendance). → Refused: "Link your account to an employee". Administrators have the override anyway. Setting: no.
18. **A manager requesting their own day.** → Allowed (they are in their department). HR still decides. The self rule applies when the approver is the requester. Setting: self rule.
19. **The requester is HR (the LEVEL_1 approver).** → Self rule `admins`, seeded: the level goes to the administrators. Setting: yes, the self rule.
20. **Who sees a request.** The requester (My requests), the level approvers (My approvals and the path), administrators (Hub). Affected employees see nothing until it is applied. Other managers see only the grid badge with the requester's name. Setting: no.
21. **HR is not an administrator and My approvals is off.** → HR would have nowhere to decide. → The switch cannot be turned on while My approvals is off (the card says why). Setting: no (Q7 on the dependency).
22. **The requester loses Manage Attendance, or leaves.** → The request stays decidable. Withdraw needs the requester's own My requests. Setting: no.

### D. Money, payroll and lateness
23. **A past day, Office → Business Trip, Vacation (if allowed) or WFH, in an open month.** → Payroll recomputes live: the absence or lateness deduction disappears (`PayCalculator:18-24`). This is the point of the request. The card shows "Pay effect: removes an absence on this day" when the day is now Absent. Setting: `statuses`, `past_days`.
24. **→ Absent on a past day.** → Adds an absence deduction. Allowed by default (the grid allows it today). HR approves it. Setting: `statuses`.
25. **A day in a closed payroll month.** → The payslip is frozen, and a change would leave the payslip and the schedule disagreeing. → Refused at request time, and a pending request expires when its month closes (J8). A carry like corrections is a later release. Setting: `closed_month` (refuse only in R2).
26. **A day with recorded attendance changed to a leave type.** → The day would hold both. → Refused (as corrections, `cx_should_close`). Setting: no.
27. **A change of a future day.** → No money effect until the day comes. Setting: no.

### E. Leave balance
28. **Vacation or another leave-rule type requested.** → It would be paid leave with no balance deduction. → Refused by default with "Use a leave request (HR can record one for the employee)". If `allow_leave=1`, allowed, and the card warns "No balance is deducted". Setting: `allow_leave`.
29. **A leave-rule day (from a grid Vacation, no leave request) changed back to Office.** → No balance involved. Allowed. Setting: no.

### F. Office minimum
30. **A request takes a day below the minimum.** → The send sheet and the HR card show "Wed: 8/10 in the office after this change" (`om_days` with an override map). Warning only. Setting: no (it follows the Office Minimum feature).

### G. Branches
31. **Branches "By the schedule".** The grid sends `branch`, which is stored as `branch_set`/`new_location_id` and validated against the requester's `branch_choices()` at request and the employee's branches at apply. Other branch modes ignore it. Setting: no.
32. **A branch-only change (same status).** → It is a request too: `new_status` = the current status and `branch_set=1`. Setting: no.

### H. Holidays and days off
33. **A company holiday.** → Refused: "a company holiday". Setting: no.
34. **A non-working day.** → It is not in the grid. CSV rows for it are refused as today. Setting: no.
35. **The working days change after the request** (the week anchor moves). → The request keeps its dates. A day that is no longer working is still applied if its status is unchanged (harmless). Setting: no.

### I. Multi-day and batch requests
36. **More than `max_days`.** → Nothing is sent, with "Send at most N days at once". Setting: `max_days`.
37. **Partial apply.** → Header status `partial`, per-day results. The requester and HR are told which days and why. Setting: `changed_day`.
38. **Several people in one request.** → One approval. The approver is resolved from the requester. The card lists people. Setting: no.
39. **One reason for a whole CSV import.** → Yes, required on the import form. Setting: `import`.
40. **Partial approval by HR (approve some days).** → Not in R2. HR rejects and the manager sends a smaller request (Q3).

### J. Expiry
41. **Undecided after `expire_days`.** → Closed automatically, engine levels cancelled with `skip_reason='closed'` ("Closed automatically" on the path), the requester told. Setting: `expire_days`.
42. **Its month is closed in Payroll.** → Closed the same way ("the month was closed"). Setting: no.
43. **The day passed undecided.** → It stays open (past days are valid within `past_days`). Setting: no.
44. **Deadlines (reminders, escalation) before expiry.** → Engine behaviour, workflow card. Setting: yes, on the workflow.

### K. Withdraw
45. **Withdraw while a level is being decided.** → Case 6.
46. **Withdraw after partial approval.** → Not possible (it is decided).
47. **Withdraw one day of a request.** → Not in R2: withdraw it all and send again.

### L. The switch turned off with requests open
48. **Recommended:** turning off `ews_feature_schedule_change` closes every open request (`sc_close_all('switched_off')`). The card warns "3 requests are waiting and will be closed". Requesters are told. Otherwise they would hide from the inbox while their engine steps still get deadline reminders. Setting: no (Q8: close or keep).

### M. The week lock switched off, or its cutoff moved, with requests open
49. **The week lock is switched off.** → `sc_enabled()` becomes false, so the same close as 48 ("the week lock was switched off"). Setting: no.
50. **The cutoff moved so a requested week is open again.** → The request stays decidable, and the manager may also edit directly (then the day becomes `changed`). The card notes "This week is no longer final". Setting: no.

### N. Employees deactivated or moved to another manager
51. **The affected employee is deactivated or has attendance turned off.** → `inactive`, skipped. Setting: no.
52. **The requester is moved under another manager.** → `approval_reassign_supervisor` moves their SUPERVISOR steps (requests where they are the requester), which includes these. Setting: no.
53. **The affected employee is moved to another department.** → Applied (HR approved). The badge shows on the new manager's grid. Setting: no.
54. **The approver (HR user) is changed in Approval Workflows.** → Engine behaviour: already resolved steps keep their approver. Escalation and administrators can still decide. Setting: no.

### O. Deleted schedule types
55. **The requested type was deactivated or renamed before the decision.** → `gone`, skipped (or refuses the whole when `changed_day=refuse`). Setting: `changed_day`.
56. **The old status's type was deleted.** → No effect: the guard compares stored strings.

### P. Multisite
57. Each subsite has its own tables, options, cron and request ids through `$wpdb->prefix`. There are no network tables. Named locks are not used, so nothing else is needed. Setting: no.

### Q. Upgrade
58. **Upgrading with the switch off.** → Tables and the workflow row are created and nothing else changes. Setting: no.
59. **A failed CREATE.** → The schema is not marked done and the update is retried every 10 minutes (`trait-schema.php:383-388`). While a table is missing, `sc_enabled()`-guarded readers run no query when off. When on, `sc_*` reads check the table once (like `cx_hub_rows`, `trait-corrections.php:961`). Setting: no.
60. **Downgrade to R1 with open requests.** → The R1 grid stays locked. The engine rows of the open requests have no card: an administrator rejects or cancels them on the Hub or Approval Workflows audit. Documented in the CHANGELOG. Setting: no.

---

## 9. Tests

### Unit
- `tests/unit/ScheduleChangeRulesTest.php`: every `check` code (not_locked, status, leave_status, same, leave_day, holiday, past, closed_month, pending, attendance, scope, inactive, too_many, reason) and every `outcome` (applied, already, changed, inactive, gone, branch, leave; skip vs refuse).
- `ScheduleChangeSettingsTest.php`: defaults, ranges, fromPost.
- `OptionsTest`: the new keys and defaults read through `option()`.
- `WorkflowsTest`: the `schedule_change` modes have no NONE or PEER, and `NONE_MEANS` has the key.
- `HubTest`: `TYPES` and the `PARTIAL` notice.
- `LifecycleTest`: unchanged (no new hook).

### e2e: `tests/e2e_schedule_change.py`
Following `e2e_week_lock.py` and `e2e_swap.py`, with cleanup in `finally`.

Off:
- the grid, CSV, modal, Hub, My approvals and My requests are byte-identical to R1;
- the `num_queries` of the grid page equals the switch off;
- no API field.

On, create:
- one day, several days and people, a branch;
- each refusal code with its message;
- `max_days`;
- the reason minimum;
- a non-linked requester;
- out of department;
- the CSV preview "sent to HR", and apply re-checks the lock;
- the draft from a refused grid save (J1b).

On, decide:
- My approvals approve (the employee notified once with their days);
- reject with a required reason;
- a Hub decision on level 1 of LEVEL_2 is ADVANCED, and a stale posted level is `moved`;
- the self rule `admins` (HR requesting);
- HR not set, so administrators.

Changed days:
- set the day via an override edit, a leave approval or a swap before approving: skip gives `partial`, refuse gives CHANGED and the level stays open.

Race (the `stale` read helper, as `e2e_swap_mu.php`):
- the guard reads the day as it was a moment before the change, and the conditional UPDATE refuses.

`break_writes`:
- `break_writes('schedule','UPDATE')` during apply: everything rolls back (engine level still PENDING, request pending, open_key kept);
- `break_writes('schedule_change_days','INSERT')` during create: no header row, no approval request;
- `break_writes('approval_steps')` during decide.

Withdraw, expire and switches:
- withdraw while pending; withdraw after a decision gives `done`;
- expiry by days and by a closed payroll month; a second tick does nothing;
- switching off with requests open closes them; week lock off does the same;
- the cutoff moved: still decidable;
- deactivated employee, deleted type and removed branch each skip with the reason;
- the leave-type status refused and allowed (`allow_leave`);
- holiday refused; past_days boundary; closed month refused.

Payroll:
- an open-month Office → Business Trip removes the absence in `payroll_month`.

### Existing suites, run with the switch off and on
`e2e_week_lock`, `e2e_attendance_grid`, `e2e_admin_requests`, `e2e_my_approvals`, `e2e_approval_engine`, `e2e_self_approval`, `e2e_request_races`, `e2e_schema` (second upgrade with no ALTER), `e2e_lifecycle`, `e2e_substitutes`.

### Static checks
phpstan, phpcs, `check_modules.py` (the baseline must not grow), `check_translations.py`, `check_openapi.py` if Q9 adds a field, and the browser smoke test for the grid's request mode.

---

## 10. Mockups
Add `docs/mockups/_kit/screens_schedulechange.py`, starting from `snap/attendance.*.html` as `screens_weeklock.py` does, AR and EN, under `docs/mockups/schedule-change/`:
1. The grid, final week in request mode: new banner, enabled cells, a pending badge, the save bar "3 changes to send to HR".
2. The send sheet: the list of changes, the reason, office-minimum warnings, refused days.
3. The result notice: sent N, not sent M with reasons.
4. The R1 modal with "Send them to HR as a request".
5. CSV preview with "Final week: will be sent to HR" rows and the reason field.
6. My approvals card (HR): reason, days old → new, a "changed since" warning, office minimum, pay note, approve or reject.
7. The Hub row and the notices (advanced, partial, changed, moved).
8. My requests: waiting with its path, partial with skipped days, rejected with reason, expired; withdraw.
9. Employee notification and push: "Your schedule for Tue 13 Oct is now WFH".
10. Feature Configuration: the Week lock card with the "Change requests to HR" block (switch, settings, the My approvals dependency, "N open requests will be closed").
11. Approval Workflows: the "Schedule Change" card (LEVEL_1 HR, self rule admins, reason on reject).
12. Audit Log rows.

---

## 11. Risks and release split

### Risks
- **Pay changes after the fact.** A past-day status change moves absence and lateness pay. It is mitigated by the HR approval, `past_days`, refusing closed months, and the card's pay note.
- **The approval UI depends on My approvals.** HR who is not an administrator needs it (case 21).
- **The double post** until the engine fix (§3.6).
- **Batch semantics.** Partial apply may surprise people. It needs clear messages (Q3/Q5).
- **Grid JS complexity.** Request mode reuses the payload but changes the read-only logic.
- **Audit volume.** One row per applied day, as override edits.
- **Downgrade with open requests** (case 60).
- **The leave interaction** (cases 4, 14, 28) is the most delicate. It must be covered by e2e.

### Release split (recommended)
- **3.31.122:** the engine fix for the repeated Requests page post. The owner already approved it, before the week lock.
- **R2a, 3.31.124:**
  - tables, settings, switch;
  - grid request mode and the R1 draft conversion;
  - create, decide and apply with the guard;
  - HR in My approvals and the Hub; My requests and withdraw;
  - expiry; closing on switch off;
  - audit, notifications, translations, tests.
- **R2b (if R2a is too big):** CSV import requests (the R1 error-row behaviour stays meanwhile), the read-only API `final` flag, and the closed-month carry instead of the refusal.

---

## 12. Questions for the owner (recommended option first)
1. **Who approves a schedule change?**
   - (a) HR by default, and the levels can be changed in Approval Workflows like any other request (for example the manager's manager, then HR).
   - (b) Always HR only, fixed.
   - (c) Administrators only.
2. **Which day types can a manager ask for?**
   - (a) Any type except leave types (Vacation and other leaves go through a leave request, so the balance is counted). A setting can allow leave types.
   - (b) Any type, Vacation included (paid, no balance deducted).
   - (c) A fixed short list chosen by HR in the settings.
3. **One day or many in one request?**
   - (a) Many days and people in one request (up to a set number, default 25). HR approves or rejects it as a whole.
   - (b) One request per person and day.
   - (c) Many in one request, but HR can approve some days and reject others (larger change).
4. **Should the employee be told?**
   - (a) Only when the change is applied (as a normal schedule change today). A setting can also tell them when it is requested.
   - (b) Both when requested and when decided, always.
   - (c) Only when applied, with no setting.
5. **A day changed between the request and the decision** (for example a leave was approved):
   - (a) The changed day is left as it is and the other days are applied; everyone sees which day was skipped and why. A setting can choose (b).
   - (b) Nothing is applied: HR must reject it and the manager sends a new one.
   - (c) It is applied anyway (not recommended: it would overwrite a leave or a swap).
6. **When does a waiting request end by itself?**
   - (a) After 14 days without a decision (setting, 0 = never), and when its month is closed in Payroll.
   - (b) Only when its month is closed in Payroll.
   - (c) Never; someone must decide or withdraw it.
7. **Only final weeks, and where HR decides:**
   - (a) Only days of final weeks can be requested (other weeks are edited directly). HR decides in the app's My approvals and on the wp-admin Requests page, so My approvals must be on to use this feature.
   - (b) Any week can be requested.
   - (c) Final weeks only, with a separate "Change requests" page for HR that does not need My approvals (larger change).
8. **The switch, and switching it off:**
   - (a) A separate switch, off by default, that only works while the week lock is on. Turning it off, or turning the lock off, closes the waiting requests automatically and tells the managers.
   - (b) One switch with the week lock (the lock always comes with requests).
   - (c) A separate switch, and waiting requests are kept, hidden, until it is turned on again.
9. **The phone app:**
   - (a) Nothing new in the app's interface this release. Managers request from the web grid, and HR decides in the web app or wp-admin.
   - (b) Add only a read-only "Final" mark on each day of the employee's schedule in the app (postponed from release 1).
   - (c) Also let managers send and HR decide from the phone (new endpoints, a larger release).
10. **Past days:**
    - (a) Allowed up to 14 days back (setting), never in a month already closed in Payroll.
    - (b) Only today and future days.
    - (c) Any past day of an open payroll month.
11. **The CSV import:**
    - (a) Rows in a final week are sent to HR as one request with one reason (setting, on by default once the feature is on), in R2a or R2b depending on size.
    - (b) Rows in a final week stay errors (R1 behaviour); requests only from the grid.

### Critical Files for Implementation
- /home/user/WFplugin/workforce-one/includes/trait-attendance.php
- /home/user/WFplugin/workforce-one/includes/trait-approvals.php
- /home/user/WFplugin/workforce-one/includes/trait-swap.php
- /home/user/WFplugin/workforce-one/includes/trait-advances.php
- /home/user/WFplugin/workforce-one/templates/app/attendance.php
- Also: /home/user/WFplugin/workforce-one/includes/trait-week-lock.php, /home/user/WFplugin/workforce-one/includes/trait-admin-requests.php, /home/user/WFplugin/workforce-one/includes/trait-my-approvals.php, /home/user/WFplugin/workforce-one/src/Approvals/Workflows.php, /home/user/WFplugin/workforce-one/includes/trait-schema.php, /home/user/WFplugin/workforce-one/src/Settings/Options.php, /home/user/WFplugin/docs/architecture/modules.json
## Owner's answers (2026-10-22)
All as recommended:
0. Order: first 3.31.122, the approval-engine fix for a repeated Requests page post (all types); then this feature on top of it.
1. Approver: HR by default; the levels are configurable in Approval Workflows like any other type.
2. Statuses: every type except leave types (leave goes through a leave request so the balance is counted); `allow_leave`
   can allow them.
3. One request holds many days and people (up to `max_days`, default 25); HR approves or rejects it as a whole.
4. The employee is told when the change is applied; `notify_on_request` can also tell them at request time.
5. A day changed before the decision is skipped and the others are applied, with the reason shown; `changed_day=refuse`
   can refuse the whole request instead.
6. Expiry: after 14 days without a decision (`expire_days`, 0 = never), and when its month is closed in Payroll.
7. Only days of final weeks can be requested; HR decides in My approvals and on the Requests page, so the feature needs My
   approvals on.
8. Its own switch, off by default, working only while the week lock is on; switching it or the week lock off closes the
   waiting requests and tells the managers.
9. Mobile app: nothing new in this release.
10. Past days: up to 14 days back (`past_days`), never in a month closed in Payroll.
11. CSV import: final-week rows can be sent to HR as one request with one reason (`import`, on by default once the feature
    is on); in R2a or R2b depending on size.
12. Banner text (2026-10-24, asked while reviewing the R1 banner): the text of the final-week banner on the Attendance page is a
    setting on the Week lock card (title and text, Arabic and English; `{since}` is the lock time; empty keeps the default text).
    Part of 3.31.123.

Note (2026-10-24): 3.31.123 became a swap fix release (owner report); this feature is now 3.31.125 (3.31.124: swaps decided in My approvals, owner decision).
