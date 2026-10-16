# Task: one approval engine, new request types, leave rules

**Status: mockup reviewed, decisions taken (owner, 2026-10-14).** Mockup: `docs/mockups/approvals/`
(7 screens, AR/EN). Built: stage 1 (leave types, 3.31.99), stage 2 (the engine, 3.31.100) and stage 3 (My approvals and My requests, 3.31.103).

## Owner decisions (2026-10-14)
1. **One engine for every request type.** Up to 3 levels by default, a 4th at most.
2. **Who approves each type, the limits (e.g. salary advance) and the deadlines (remind / move up
   after N working hours) are settings per type**, not fixed numbers.
3. **A substitute** approves while a manager is away; set by the manager or by HR.
4. **New types first:** salary advance, expense claim, HR letter. Later: data change, business trip,
   resignation.
5. **HR letters:** four ready types (salary certificate, to whom it may concern, experience,
   embassy), and the company can add its own.
6. **Today's requests** (leave, overtime, attendance corrections, shift swaps, face reset) move to
   the new engine with their current setup.
7. **Leave types:**
   - Planned leave must be asked before the day starts; unplanned leave can be asked on its own, on
     the day or after it. The latest it can be asked after the day is a setting per type.
   - Unplanned leave uses its own balance, not the annual one.
   - Each leave type has a balance renewed every year; what is left does not carry over. Renewal
     (1 January or the hiring anniversary) and carry-over (none / all / up to N days) are settings
     per type. Today's behaviour (before the day; same balance each year, nothing carried) is the
     default, so nothing changes until a company changes it.

## Stage 3 decisions (owner, 2026-10-16; mockup `docs/mockups/my-approvals/`)
1. **Off by default**, turned on in settings. Once on: "My approvals" in the app menu and a home card
   with the count.
2. **The approval lists inside each page stay**, with a link "All your approvals in one place" above them.
3. **Approve several at once:** every type, up to 20 at a time; rejecting stays one at a time.
4. **A reason to reject: a setting per request type** (today only corrections require one).
5. **My requests:** the employee can cancel any request still waiting (leave, overtime, correction);
   an approved leave still goes through a cancellation request.
6. **"Overdue": a setting** (working hours); by default the type's reminder time, or 24 working hours
   when no reminder is set.

## Plan (one release each, every new thing off until turned on)
1. **Leave types:** planned / unplanned, the window after the day, renewal and carry-over per type.
2. **The engine:** types, up to 4 levels, approvers (direct manager, manager's manager, department
   manager, team manager, HR, Finance, a specific person), a condition per level (e.g. an amount),
   remind / move up after working hours, the requester-is-approver rule; today's types moved over.
3. **My approvals:** one inbox in the app for every type, levels shown, overdue marked, approve
   several small ones at once; the employee's "My requests" with the path.
4. **Substitutes:** set by the manager (asked when they request leave) or by HR; "for" on decisions.
5. **New types:** salary advance (limits, a monthly deduction in Payroll), expense claim (receipt,
   paid in Payroll), HR letter (types, generated PDF).
