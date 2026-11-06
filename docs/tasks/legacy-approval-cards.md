# Design: legacy requests as cards in My approvals (QA 6, RC-C)

Status: design (architect, read-only, on main 570a442 / 3.31.141). Owner decision 2026-11-06
(`docs/tasks/qa-report-3.31.138.md` item 1): requests that never start an approval-engine instance (overtime with the
workflow "Not set yet", a leave cancellation when leave approvals are off) appear in My approvals as cards that decide
through their existing legacy path. No change to who approves or to permissions; the menu and Home counts include them.

## 1. Today
`my_approvals_items()` (`trait-my-approvals.php`) builds the inbox from `approvalReads()->inboxRows()` (engine steps
only) plus peer swap answers. Every count comes from this memoised list: menu badge `my_approvals_count()`
(`trait-app-layout.php:111`), Home `my_approvals_home()` (`trait-dashboard-view.php:33`), the "N waiting" link
`my_approvals_link()`, and `my_approvals_allowed()`. Legacy items added there are counted everywhere. With the feature
off it returns `[]` before any query.

| Kind | Legacy when | Handler / nonce / fields | Who may decide | Self rule | Note |
|---|---|---|---|---|---|
| Overtime | `overtime_enabled()` and `!Workflows::isLevels(overtime_workflow_mode())` ('' or active NONE) | `ews_overtime_request_respond` / `ews_overtime_respond` / `request_id`, `decision` (+`approval_step`) | `can('ews_manage_time')` | `approval_self_gate(uid, approval_self_rule('overtime')==='allow')`, admins always (audited) | ignored |
| Leave cancellation | `!leave_approval_active()`, `status='Approved' AND cancellation_status='Pending'` | `ews_leave_cancel_respond` / same / `request_id` | `can('ews_manage_settings')` | vacation self rule else 403 | ignored |
| Leave request | `!leave_approval_active()`, `status='Pending'` | `ews_vacation_request_respond` / `ews_vacation_respond` / `request_id` | `can('ews_manage_settings')` | vacation self rule else `leave_error=self` | ignored |
| Attendance correction | `cx_workflow_mode()===''`, `status='pending'` | `ews_correction_decide` / `ews_correction_decide_<id>` / `correction_id`, `request_stage=pending` | `cx_can_decide()` (never own; admin or `ews_manage_time` in department scope) | never own | required to reject |
| Early leave | always (`Workflows::NOT_IN_USE`) | `ews_early_leave_respond` / same / `request_id` | `can('ews_manage_time')` | `ews_early_leave_self`, admins always | ignored |

All five handlers return to My approvals with `ews_return=approvals`. Owner-named scope: overtime and leave cancellation;
the others match the definition (Q1–Q3). One provider per kind.

## 2. When a request is a legacy card (never twice)
All of: (1) its module is in legacy mode now (the handler's own predicate; with levels now the handler refuses or does
nothing → not shown, Q4); (2) its row is waiting; (3) no open engine level: `approval_shown_steps([...])[key]===0` (the
handler's guard); a request left open on the engine when its workflow was switched off shows once, as its engine card;
(4) the viewer passes the handler's check and self gate (§4). Cards post `approval_step=0` (an engine level opened
meanwhile → `moved`).

## 3. Reads and cost
Per provider, stop at the first "no": capability (memory) → module switch (autoloaded) → workflow mode (1 query) → one
list on the module's table (waiting, oldest first, `LIMIT 200`); corrections reuse `correctionQuery()->toDecideOpen()`;
early leave has no workflow read. One shared `approval_shown_steps()` for all keys (1–2 queries).
Viewers without a capability (golden `gdmgr`, `gdreq`): 0 extra. With a capability and levels: 1 per kind's workflow read.
Legacy mode: + 1 list per kind + 1–2 shared + card facts (overtime, early leave, corrections 0 — cards from the listed row,
`overtime_card_from_row()`; leave and cancellation ~2 per card, the same as engine leave cards; pass the row in).
Golden: the admin page `/app/?ews_view=approvals` (feature + substitutes on) gains the workflow reads → declare it; the
actions `inbox_items_mgr` / `inbox_allowed_mgr` if their manager has a capability.

## 4. Who sees each card = who can decide it today
One predicate per module, shared by the handler and the provider:
`overtime_legacy_may_decide($requester_uid)` (`ews_manage_time` && (not me || self rule allow || `manage_options`)),
`leave_legacy_may_decide()` (`ews_manage_settings`, vacation self rule), `cx_can_decide($c)` as is,
`early_leave_may_decide()` (`ews_manage_time`, `ews_early_leave_self`). The self test uses the listed row's `wp_user_id`.
Skip requesters the handler would refuse (overtime `people_employee(…,true)` missing, leave `leave_active_employee()`
missing). Known, unchanged: the Overtime page lists legacy requests to `ews_manage_settings` while the handler needs
`ews_manage_time` (Q10).

## 5. Card
The module's card array plus `id=>0`, `item_key=>'legacy-<entity_type>-<id>'`, `workflow`, `entity_type`, `entity_id`,
`legacy=>true`, `admins=>false`, `for=>''`, `with=>''`, `fields+=['approval_step'=>0]`; a synthetic path
`my_approvals_legacy_path($since,$wk,$who)` (`levels=0` hides "Level x of y"; one "now" step; hours since; overdue per Q5).
Cancellation has no "asked" time: `wait=>false` (Q6). Note: the legacy paths store none → `note=>false` (Q7);
corrections keep it, required. Template: a pill "No approval workflow" when `legacy`; note block only when `note`;
wait pill only when `wait`. Bulk: selectable (Q8); add `early_error` to `errSel` if early leave is in. Filters work by
tone; "Overdue only" follows `path.overdue`. Merged before the existing `usort`.

## 6. Concurrency
Overtime: `WHERE status='Pending'`; the loser gets `overtime_error=done` (check the modal has a text); stale →
`overtime_done=1`. Leave stale → `leave_done=1`. Cancellation stale → `wp_die('Invalid cancellation.')` (Q9). Correction
`done`/`moved`. Early leave → `early_error=decided` (PR #37). Decided in the Requests Hub meanwhile: the same; the card is
gone on reload. Workflow switched to levels between load and post: overtime `overtime_error=approval`, leave silent
`leave_done=1`; an engine level that opened → `moved`.

## 7. Files and functions
- `trait-my-approvals.php`: `my_approvals_items()` merges new `my_approvals_legacy_items()`; `my_approvals_legacy_path()`; no SQL.
- `trait-overtime.php`: `overtime_legacy_approvals()` (api), `overtime_legacy_may_decide()` (used by the handler),
  `overtime_card_from_row()`; optional `early_leave_legacy_approvals()`, `early_leave_may_decide()`.
- `trait-leave.php`: `leave_legacy_approvals()` (api, both entity types, one query), `leave_legacy_may_decide()` (used by
  `leave_request_respond`, `leave_cancel_respond`), `leave_approval_card()` accepts a read row.
- `trait-corrections.php`: optional `cx_legacy_approvals()` (api).
- `templates/app/approvals.php` (§5), `assets/js/approvals.js` (`errSel`, early leave only).
- `docs/architecture/modules.json`: the providers in the api of overtime, leave, attendance; `approval_self_rule` in approvals.api.
- Translations; CHANGELOG; a line in `docs/tasks/approvals.md`; version.

## 8. Tests
e2e (new section or `tests/e2e_my_approvals_legacy.py`): feature off unchanged; overtime "Not set yet" → an
`ews_manage_time` user sees the card, count 1, Home 1, tone overtime, "No approval workflow", no level pill, no note;
approve back on approvals, Approved, `reviewed_by`, audit, employee told, card gone; reject; Mona (reports, no
capability) sees none and posting gives `overtime_error=access`; self rule cases (not allow → no card; allow → card;
admin own → audited); no double entry (started on LEVEL_1 then switched off → only the engine card; active NONE → legacy;
LEVEL_1 now with an old never-started one → none); leave cancellation (approve → Cancelled, balance back, schedule
restored; reject; no capability → nothing); filters; bulk (2 legacy + 1 engine → "3 requests approved."); two deciders
(stale → `done`, `reviewed_by` stays); Arabic strings.
Golden: `gdmgr`/`gdreq` unchanged with no more queries; declare the admin approvals page; new action traces in
`actions_approvals.php` (`my_approvals_items` as an administrator with overtime inactive, seeded legacy rows).
Unit: a pure self-test helper with a table test.

## 9. Risks
Holders of `ews_manage_settings` / `ews_manage_time` see an organisation-wide count (corrections are department-scoped;
overtime and leave have no scope) — the owner's rule, but visible. Leave cards ~2 queries each (cap 200). Card/handler
drift avoided by shared predicates. Bulk counts "already decided" as approved (existing). With vacation active NONE the
inbox becomes the only app place for those requests.

## 10. Open questions for the owner
1. Leave requests (not only cancellations) when leave approvals are off? Recommended: yes.
2. Attendance corrections with the workflow "Not set yet"? Recommended: yes. The HR stage (`pending_hr`)?
3. Early leave (never in the engine), tone Leave, label "Early leave"? Recommended: yes.
4. Old pending requests under a workflow that now has levels (never started): hide (recommended) or show to administrators with a link to the Requests Hub?
5. Overdue for legacy cards: the workflow's overdue hours, or never?
6. Cancellations have no "asked on" time: no waiting time (recommended), or a new column?
7. Note on legacy cards: hide (recommended) or keep (dropped)?
8. Bulk approve may select legacy cards? Recommended: yes.
9. A second decider on a cancellation gets a bare "Invalid cancellation." page: a redirect "already decided" instead?
10. The Overtime page lists legacy requests to "Manage Settings" while only "Manage Sign In / Out" decides: align? Not in this change.
11. Wording of the pill and the path step.
