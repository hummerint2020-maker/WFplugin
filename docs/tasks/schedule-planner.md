# Schedule planner

**Owner request (2026-11-28):** "I want to add AI that makes the schedule, but it must be free."

**Status:** decisions taken; the mockup comes next. No design or code yet.

## Owner decisions (2026-11-28)

1. **Engine:** a planner built into the plugin. It runs on the site's own server and costs nothing. No employee data leaves the site, and no external AI or API key is used. It follows the rules exactly instead of guessing.
2. **What it fills:** the empty days of a chosen week (next week by default). Anything already set, leave and company holidays stay as they are.
3. **Rules it follows (all four):**
   - **The office minimum** already set in Features (overall, per department, managers).
   - **Office days per person:** a company default (e.g. 3 a week), with a different number for some employees set by the admin on their profile.
   - **Fair rotation:** office and home days are spread evenly over the weeks, so nobody always gets the same days.
   - **Preferred days:** the employee marks the days they prefer the office or home, in the app (My Profile). The planner tries to follow them; the rules come first.
4. **After planning:** a draft on the Attendance grid. The administrator changes what they want, then presses Apply. Nothing is saved before Apply.
5. **Who runs it:** administrators only, for everyone.
6. **When the rules cannot all be met** (e.g. too many on leave to reach the minimum): the planner builds the closest week it can and lists each day and rule it could not meet in the draft, before Apply.

## House rules that apply

- **Off by default, behind its own switch.** With it off, nothing changes: no button, no profile fields, the same pages and queries.
- **HTML mockup before any code:** `docs/mockups/schedule-planner/`.
- **Design by the read-only architect** after the mockup is approved. It covers the algorithm, the draft's storage, the week lock, the schedule types that need sign-in, the audit trail and tests.

## Open points for the design (not product decisions)

- **What the planner fills an empty day with:** Office or WFH only, or every working schedule type?
- **Where the draft lives until Apply:** per administrator and week, and for how long.
- **A week locked by the week lock:** it cannot be planned.
- **Apply:** goes through the same save as the grid (audit, notifications as today). It is all-or-nothing, and refuses if someone changed a day since the draft.
