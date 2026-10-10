# Task: organization chart

**Status: important, to build soon** (owner, 2026-10-10). Decisions taken; the mockups (version 3,
redesigned) are in `docs/mockups/org-chart/`. Before building: the owner's final look at the mockups.

Owner request (Oct 2026): an organization hierarchy for employees. Off by default (Feature
Configuration → Organization chart). Mockups: `docs/mockups/org-chart/` (5 screens, AR/EN).

## Owner decisions (2026-10-10)
1. **The chart is built on each employee's direct manager** (the "supervisor" relationship approvals
   already use), not on departments or teams. One source; nothing entered twice.
2. **Who sees the chart: a setting** — administrators only / administrators and managers (managers see
   their part in the app) / everyone in the app; and what an employee sees (their line and teammates,
   or the whole company).
3. **What a manager sees: a setting** — people who report directly, or everyone under them at every
   level, in Attendance, Reports, Requests and the Team Schedule.
4. **Approvals: settings** — a new level "Manager's manager", and per workflow "if nobody decides,
   move it to the next manager up after N working hours".

## Screens (mockup version 3)
Desktop: a top-down chart (cards coloured by department, a person panel on the side); phones and the
app: browse one person at a time (managers above, their people below, teammates). In the product's
own look (indigo hero, rounded cards, Alexandria).

1. wp-admin → People → Org chart: indented tree (opens collapsed, a branch loads when opened), search,
   counts (direct / all), export / import managers (Excel), "Needs attention" (no manager, wide span,
   archived manager), Move (new manager, from date, reason; the people under them move too; requests
   waiting on the old manager move; a loop is refused).
2. Feature Configuration → Organization chart: the settings above, wide-span limit, job title required.
3. Employee edit: job title (suggestions from titles in use), direct manager, the reporting line.
4. Approval workflows: level types incl. "Manager's manager", escalation after N working hours.
5. App → My team: my reporting line, reports to me, teammates.

## Database (planned)
- `ews_employees.job_title` (new column). The tree uses the existing approval relationships table
  (`relationship_type='supervisor'`); a move closes the old row and opens a new one with a from date.
