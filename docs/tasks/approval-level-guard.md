# 3.31.122: a decision applies only to the approval level the person saw (approval engine, every type)

Design by the architect (read-only), saved before implementation. Owner decision (3.31.120): "the form posts the level it shows; a
decision on a level that moved on is refused", in the approval engine for every type, before the schedule change request.

## 0. Root cause
`approval_act()` (`includes/trait-approvals.php:626-680`) does not know which level the caller saw: it reads the first PENDING step
(`:635`), checks who may decide it (`:639`) and updates that step (`:650-658`, `WHERE id=<step read now> AND status='PENDING'`). A
second post after level 1 was decided (double click, resubmit, two administrators, a page loaded before an app approver acted)
finds level 2 PENDING and decides it. No form posts a level and every nonce is per request.

Only an approval decision moves a level forward. Skips and the self rule are fixed in `approval_start` (`:541-587`); escalation
(`trait-approval-deadlines.php:60`), substitutes (`trait-substitutes.php:160`) and org moves (`trait-approvals.php:328`) change
the approver of the same step row; a withdraw sets open steps CANCELLED (`:842`, `:862`). So the step id
(`ews_approval_steps.id`) identifies "the level I saw".

## 1. Decision surfaces
| # | Surface | Handler | Posts today | Stale post decides a later level? |
|---|---|---|---|---|
| 1 | wp-admin Requests Hub, all types | `admin_requests_decision` (`trait-admin-requests.php:100-117`) → leave, overtime, swap, corrections (`cx_hub_decide`), face reset | type, id, decision | **Yes**, every engine type (admin override) |
| 2 | App Leave: leave and cancellation | `leave_request_respond` (`trait-leave.php:328`), `leave_cancel_respond` (`:425`) | request_id, decision | Yes when the same user approves the next level too; else "Access denied" |
| 3 | App Overtime | `overtime_request_respond` (`trait-overtime.php:142`) | request_id, decision | Same as 2 |
| 4 | App Schedule: swap approver | `swap_manager_decide` (`trait-swap.php:288`) | swap_id, decision | Same as 2 |
| 5 | App and wp-admin Corrections | `correction_decide` (`trait-corrections.php:613`) → `cx_decide` | correction_id, decision, note | **Yes**, at engine levels and at the module's own HR stage (`pending` → `pending_hr`), even with the engine off |
| 6 | REST `POST /corrections/{id}/decision` | `api_corrections_decide` (`:1021`) | decision, note | Same as 5 |
| 7 | App My approvals (single and bulk) | the handlers above + `advance_respond` (`trait-advances.php:199`) | each card's fields | Same as 2-5 |
| 8 | wp-admin Face Reset Requests | `face_reset_admin_handle` (`trait-face-reset.php:189`) | employee_id | Same as 2 |
| 9 | Early leave | `early_leave_respond`, `early_leave_admin_decide` | request_id, decision | No levels; `WHERE status='Pending'` |
| 10 | Legacy vacation | `legacy_vacation_admin_decide` | — | No levels |

## 2. The fix, once, in the engine
- Forms post `approval_step` = the id of the PENDING step the page showed, or 0 when it showed no open level (Peer, Legacy /
  direct, already decided). Corrections also post `request_stage` (`pending` | `pending_hr`), the HR stage not being an engine level.
- Pure `src/Approvals/LevelGuard.php`: `posted($raw): ?int` (null = the form did not say; malformed = 0) and
  `check(?int $posted, int $current): ok|moved` (null or equal = ok).
- `approval_act(..., $expected_step=null)`: with a posted step, refuse with `WP_Error('approval_moved')` when the current PENDING
  step differs (checked before the approver check, so an old approver sees "moved"), and update `WHERE id=<posted> AND
  status='PENDING'`; 0 rows → `approval_moved`. One conditional statement: two posts at once cannot both win, and a stale post can
  only ever touch the step it posted.
- Helpers (module api): `approval_posted_step()`, `approval_level_moved($ar,$posted)` (the early check, and the check on paths that
  never reach `approval_act`), `approval_error_code($e)` (note / moved / approval; replaces 5 copies), `approval_shown_steps($keys)`.
- Hub: `Hub::MOVED` "This request moved to another level since you opened the page; nothing was changed." (an error notice);
  `fromEngineError()`; the Face Reset page notice too.
- App errors: `leave_error`, `overtime_error`, `swap_error`, `cx_error`, `adv_error` get `moved` with a translated message. The My
  approvals bulk JS counts `adv_error` as failed (it counted a refused advance as approved).
- REST corrections: optional `step` and `stage` body params; a refused decision is `VALIDATION_FAILED` with reason `moved`;
  `/corrections/pending` adds `step`. OpenAPI additive.
- Backward compatible: a missing field means today's behaviour (cached forms, the PWA, API clients, existing tests). Nonces stay per
  request; the posted step is not a privilege (tampering can only target the current level, which the person may decide anyway).

## 3. Switch
None: a bug fix to the documented rule, already decided by the owner. No schema change.

## 4. Files
`src/Approvals/LevelGuard.php` (new); `includes/trait-approvals.php`; `src/Requests/Hub.php`; `includes/trait-admin-requests.php`
and `templates/admin/requests.php` (rows carry `step` and `stage`); each module's Hub and app handler (leave, leave cancellation,
overtime, swap, corrections incl. `cx_finalize($from)` and `cx_mark_rejected`, advances, face reset); the forms that must post the
field (`trait-leave-view.php` rows + `templates/app/leave.php`, overtime, `swap_awaiting_my_approval` + `templates/app/schedule.php`,
corrections app and admin, face reset admin, My approvals items + `templates/app/approvals.php`, `cx_approval_card`);
`docs/architecture/modules.json`; translations; OpenAPI; version 3.31.122 + CHANGELOG.

## 5. Tests
- Unit: `LevelGuard` (posted / check matrix); `RequestsHubTest` (MOVED notice, error notice, `fromEngineError`).
- e2e on a 2-level workflow: the Hub repeat for leave, overtime, swap, corrections (`moved`, level 2 still PENDING, module row still
  pending, no completion note or audit) and a fresh page approves level 2; without the field the repeat behaves as today (pins
  compatibility); an app approver acted first then the Hub post is `moved`; the same person at both levels double-posting in the
  app; swap Peer→Awaiting and the Q4 state; corrections engine + HR stage, engine off with a manager who is HR, REST with a stale
  step; advances in My approvals and the bulk count; face reset page and Hub; a forced race (`approval_act` with a step marked
  APPROVED just before the UPDATE) → moved, nothing written; single level unchanged; withdrawn; early leave and legacy vacation
  unchanged.
- Suites: admin_requests, approval_engine, approvals, my_approvals (+ browser bulk), request_races, swap (+ mu), corrections (+ mu),
  advances, leave, leave_admin, leave_view, leave_types, overtime, presence_face, self_approval, substitutes, api_app; phpunit,
  phpstan, phpcs, modules, translations, openapi.

## 6. Edge cases
- Escalation, substitutes, org moves between load and post: same step id → not refused; an administrator's Hub decision goes
  through, an old app approver gets `access` as today.
- Withdrawn: the module row is closed first (ERROR / done as today); a race past it finds no PENDING step → moved.
- Engine APPROVED/REJECTED with the module row still pending (swap Q4 state, face reset, a stale correction finalize): a post with a
  level → moved; a fresh page posts 0 and the override behaves as today.
- Single-level workflows: the first decision is identical; a repeat already fails on the closed module row.
- Types without the engine: unchanged.
- The workflow switched on/off while the page was open: posted level and none open → moved; 0 posted and a level open → moved.

## 7. Risks
Forms missed keep the bug silently (missing = legacy): the table in §1 is the checklist and every e2e scrapes the field from the
real page. Messages change from "could not be processed" / "access denied" to "moved" in these cases (intended). `cx_decide` and
`cx_finalize` gain a parameter. The advances bulk count fix is a pre-existing bug.

## 8. Open question for the owner
1. A decision posted without a level (a page opened before the update, an API client without `step`): (a) recommended: decide the
   current level as today; (b) refuse it on the Requests Hub only; (c) refuse it everywhere (breaks older app versions on
   corrections).

## Owner's answer (2026-10-22)
1. (a) A decision posted without a level decides the current level, as today.
