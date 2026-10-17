# Task: one approval engine, new request types, leave rules

**Status: mockup reviewed, decisions taken (owner, 2026-10-14).** Mockup: `docs/mockups/approvals/`
(7 screens, AR/EN). Built: stage 1 (leave types, 3.31.99), stage 2 (the engine, 3.31.100), stage 3 (My approvals and My requests, 3.31.103) and stage 4 (substitutes, 3.31.107).

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

## Stage 4 decisions (owner, 2026-10-17; mockup `docs/mockups/substitutes/`)
1. **The manager can still decide while away**, whoever decides first. A setting.
2. **Requests already waiting** when the absence starts move to the substitute.
3. **When the manager is back**, what still waits goes back to them, with a summary of what was
   decided. A setting (or it stays with the substitute).
4. **Who can be a substitute: a setting.** Any active employee (the default), managers only, or the
   same department. The manager's manager and the department's managers are suggested first.
5. **The question in the leave request is optional.** A setting: optional, required, or not asked.
6. **"I'll be away" without a leave**, with a start and an end date and time. HR can always set one on
   wp-admin → Substitutes.

## Stage 5 decisions (owner, 2026-10-17; mockup `docs/mockups/new-types/`)
1. **Advance limit:** one month's salary, up to 12 instalments, one open advance at a time. All of it
   is settings.
2. **Paying an advance:** Finance records it as paid, with the date. Instalments start the month after.
3. **Leaving with an advance still open:** a warning when HR deactivates the employee; the rest comes
   off the last pay.
4. **Expenses** are refunded with the pay by default. A setting: or Finance pays them apart and records
   "paid".
5. **Receipt: a setting.** Always required by default, or only above an amount.
6. **The salary on a salary certificate:** the letter type decides (gross, basic, or none). The
   employee does not choose.
7. **A QR code on each letter.** It opens a page that shows only that the letter is genuine, its
   reference, date and the employee's name. No salary.
8. **One release per type:** the advance, then expenses, then letters.

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
