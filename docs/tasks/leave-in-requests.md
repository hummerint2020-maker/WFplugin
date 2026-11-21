# Leave in My requests (and the old Leave page behind a switch)

## Owner's request (2026-11-21)
"Cancelling an approved leave... I see the Leave page has no use any more; let's think about it together."

## What the Leave page does today, and where else it is (main @ 3.31.157)
| Leave page (?ews_view=vacation) | Elsewhere |
|---|---|
| Request leave | My requests → New request → Leave (same form, same handler) |
| Request early leave | New request → Early leave |
| My leave requests, their state | My requests (level by level; withdraw one still waiting) |
| A manager approves leave / its cancellation | My approvals (card with "Balance after") |
| Pending early leave for managers | My approvals |
| **Request cancellation of an approved leave** | **only here** |
| **Balance of every leave type** | only here (New request's line shows the first two) |
| **Full history ("View all leave requests")** | only here (My requests shows the last 20) |
| Office minimum warning while approving | to check in the My approvals card |
My requests / My approvals exist only while My approvals (ews_feature_my_approvals) is on.

## Owner's answers (2026-11-21)
1. My approvals is **on** at the current company.
2. Balances: **a strip at the top of My requests** (every leave type with its balance).
3. The old Leave page: **a switch, and it stays shown** until the owner turns it off.

## Scope (to design)
- My requests: "Request cancellation" on an approved leave that has not started (CancellationRules, same handler
  ews_leave_cancel, same approval path, ews_return=requests); its states (cancellation waiting / rejected / cancelled).
- My requests: the balances strip; a Leave filter with the whole history.
- My approvals: the office minimum warning on a leave card, if missing.
- A switch to hide the Leave page (menu item, its URL goes to My requests), on = shown (today's behaviour).
- Off by default where it is new: everything new only while My approvals is on; the Leave page unchanged until
  the switch is turned off.

## Design (architect, read-only, on Opus; 2026-11-21, main @ 3.31.157)
No schema change. Findings: the office-minimum warning is missing on the My approvals leave card (leave_approval_card never
calls om_leave_impact); notification links are saved when sent, so old ones point to ?ews_view=vacation and only a redirect
covers them; without a gate the My requests additions would show at the owner's company (My approvals is on there).

1. Settings: `ews_feature_leave_page` (default true = shown), in src/Settings/Options.php (features group, primed with the
   others). Effective only while My approvals is on. Maybe a second option for the My requests additions (open question 1).
2. Files:
   - Shared: pure src/Leave/MyRequestsLeave.php (state(), pageTarget()); trait-leave-view.php leave_page_shown(),
     leave_page_view($decision) (never returns vacation while hidden).
   - (a) Cancel in My requests: leave_my_requests() gets cancel / state labels / notes (+ $offset); templates/app/requests.php a form
     posting ews_leave_cancel with ews_return=requests; leave_cancel_request keeps its rules, but from My requests a refusal
     redirects with leave_cancel_error (pop-up in ux_notice_from_query) instead of wp_die; leave_redirect drops old keys.
   - (b) Balances strip: requests_content passes $new['types'] (leave_form_types, already read: no extra query); deducting types
     show available days, non-deducting "—"; CSS in app-approvals(.rtl).css.
   - (c) Leave filter: req_kind=leave reads leave_my_requests($emp,500), keeps the state chips; my_approvals_clean_url drops req_kind.
   - (d) Office-minimum note on the My approvals leave card: leave.php's markup moved to templates/app/om-leave-note.php (Leave
     page byte-identical), leave_approval_card adds om (full mode), approvals.php includes it; .wfo-om-note CSS copied.
   - (e) Switch: Features → My approvals section "Leave page in the app"; app_navigation hides the item and tiles;
     template_redirect (priority 5, after notification_mark_landed) sends ?ews_view=vacation to My requests / My approvals /
     dashboard keeping one-time keys, leave_history=all → req_kind=leave; render fallback; leave_redirect, my-profile urls and
     the smart nudge use leave_page_view().
3. Default (My approvals on, switch untouched): menu, page, redirects, handlers, writes unchanged. Would change unless gated:
   My requests (strip, button, notes, chip), the My approvals card note (only with Office minimum on), the Features page row.
4. Edge cases: pending / rejected / started / today; levels on and off (off: nobody is notified of a cancellation request, an
   existing gap); self-approval; week lock only records; deactivated type (history yes, strip no); next year's balance
   (strip shows this year, like the Leave page); double click (submit guard + conditional UPDATE); cancel vs approve race
   (decided); RTL; no employee record; administrators.
5. Tests: unit MyRequestsLeaveTest, OptionsTest; e2e_my_approvals (+legacy), e2e_leave/e2e_leave_view unchanged, a switch
   suite (menu, 302s, nid marked, keys kept), e2e_features, office minimum suites (card note), my_approvals_browser.js
   (Arabic phone, confirm, note); golden pages ews31-features, requests (+req_kind=leave), approvals with office minimum,
   vacation hidden (redirect) and shown (identical).
6. Risks: hiding the page while the additions are off would remove cancellation everywhere; redirect loops; om_leave_impact
   on many cards; keeping leave.php identical; golden query counts; a 500-item history.
7. Open questions: listed for the owner (gate for the additions; card note; early leave in the filter; history paging;
   balances detail; the confirm dialog; landing without My requests; notifying managers with approvals off; renaming
   "Leave" in Notification Settings / View Navigation).

## Owner's answers on the design (2026-11-21, after the mockups)
1. The My requests additions (balances strip, "Request cancellation", the cancellation's states, the Leave filter) are behind
   **their own switch, off by default** (`ews_feature_my_requests_leave`). The Leave page can be hidden only while that switch is
   on (so cancelling never disappears everywhere).
2. The office-minimum warning on the My approvals leave card: **yes, with the same switch**.
3. The Leave filter shows **leave and early leave**, the whole history in pages of 50.
4. With leave approvals off, a cancellation request **tells the administrators** (as a leave request does). Built behind the same
   switch so nothing changes at the current company until it is turned on.
Remaining open questions answered by the design's defaults: the existing confirm dialog is kept (Features → confirmations); the
strip shows this year's available days like the Leave page; someone without My requests or My approvals lands on the dashboard;
the "Leave" labels in Notification Settings / View Navigation stay.

## Owner's answers on the cancellation's edge cases (2026-11-21)
Reviewed together: asking (approved, before the start day, once), deciding (levels or the administrators), applying (days
back to the charged year's balance, the schedule snapshot restored, a manager's substitute ended, a locked week recorded).
- The Leave filter shows **20 a page** (50 was long on a phone).
- **A cancellation approved after the leave started cancels the days from today on only**: the days already taken stay leave
  and stay charged; a leave that has ended is not changed (the cancellation closes with nothing to give back).
- **A cancellation asked while leave approvals were off stays with the administrators** after approvals are turned on (it has
  no approval levels; until now it disappeared and could not be decided). The same for a leave request asked then.
- **New in this release** (all with Leave in My requests on): the employee can **withdraw a cancellation still waiting** (the
  leave stays as it is); **"I'm back early"** on a leave that has started: the days from tomorrow on are cancelled, with the
  same approval as a cancellation (mockup first).
- Not changed: a refused cancellation cannot be asked again; the schedule snapshot is restored as it was before the leave;
  an archived employee's cancellation (the decision page says "Employee not found").
These fixes act only while Leave in My requests is on, so nothing changes at the current company until it is turned on.

## Review 1 (read-only, on Opus): nothing serious; to fix
1. CONFIRMED (Leave page hidden): an approver's leave notification (?ews_view=vacation) lands on My requests instead of My approvals.
2. CONFIRMED: from My requests with leave approvals on, a transaction failure ('done') shows the leave request's pop-up.
3. CONFIRMED: request_withdraw keeps leave_cancel_error / leave_cancel_sent in the address, so the old pop-up comes back.
4. PLAUSIBLE: usort on 'when' has no tie-break (PHP 7.4 not stable): items could repeat or be skipped across pages.
5. CONFIRMED: the Newer/Older links drop req_state.
6. CONFIRMED: new methods inserted between leave_form_types' docblock and the function.

## As built (3.31.158)
- Options: ews_feature_my_requests_leave (off), ews_feature_leave_page (on); the page can be hidden only with the first on.
- Schema 3.31.158: ews_leave_requests.cancel_from DATE NULL ("I'm back early": the first day it cancels).
- CancellationRules: CLOSED (Withdrawn / Ended: a leave has one cancellation, its approval exists once), checkBackEarly()
  (NOT_STARTED, ENDING). Cancellation statuses added: Withdrawn, Ended; a shortened leave stays Approved with
  cancellation_status Approved and an earlier end_date.
- leave_apply_cancellation(): with the switch on, from = later of today and cancel_from; after the start day it calls
  leave_apply_cancellation_from() (only the snapshot days from then on are restored and given back).
- leave_without_levels(): with approvals on, a leave / cancellation without an approval request is decided as before by the
  administrators (respond handlers and leave_legacy_approvals).
- The generic "Cancel Leave Request" confirm is skipped for the back-early form (it has its own confirmation step).
- Differs from the mockup: the field notes are text only.
