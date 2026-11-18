# Manager office minimum

Owner request (2026-11-18): "عايزين نضيف فيتشر جديدة اسمها manager office minimum… مع الـ office minimum كمان وهي شغالة".

Owner answer: **a number of managers each day**, company-wide. That rules out "a manager per team every day" and "office days per manager".

## Proposal (Step 1: mockups, then STOP)

Mockups: `docs/mockups/manager-office-minimum/` (week, managers, phone, off, leave, settings; Arabic and English).

Everything works like the Office minimum (`docs/tasks/office-minimum.md`): a warning only, never blocking. It is a separate switch under the Office Minimum section and is off by default. Off, the Office minimum is exactly as today.

- **Settings** (Feature Configuration → Office Minimum → Managers in the office):
  - on / off;
  - managers each day (2 by default in the mockup);
  - an optional different number per weekday.
  - It uses the Office minimum's "in the office" statuses, working days and weekly reminder.
- **Who is a manager:** the team managers (the "Manager" badge the Attendance page already shows, from the Teams page). They also count in the people minimum.
- **Attendance page** (whoever sets the schedule):
  - **Counters:** a second counter under each day ("managers in the office / minimum"), in the table head and in the "Set…" row. It is red when short and updates live while editing, like the people counter.
  - **One warning card:** it lists each short day with what is short (people, managers or both) and a Teams and/or Managers button.
  - **The Managers sheet of a day:** each manager with their plan that day (Office, WFH, Leave, Trip); those working from home are marked "Could come in".
- **Approving leave / WFH / swaps:** when the person is a manager and approving would drop the day below the managers' minimum, a second note says so, with who could come in.
- **Weekly reminder:** next week's days short of managers are added to the same reminder.
- **Reports:** the "Days below minimum" list gets a managers column.

## Open questions for the owner

1. **Who counts as a manager?**
   - ★ The team managers (from Teams).
   - Or anyone with a manager role (approvers, department managers).
   - Or a list the administrator picks.
2. **Does the managers' minimum need the Office minimum on,** as in the mockup (a sub-section)? Or is it its own feature that can be on alone?
3. **A manager of two teams:** counted once (★).
4. **The reminder:** the same one as the Office minimum (★), or a separate one only to managers.

## Owner answers (2026-11-18)

1. **Who counts as a manager:** the team managers (from Teams). ★
2. **Where it lives:** inside the Office minimum, as a sub-section that needs the Office minimum on. It uses the same statuses and the same reminder. ★
3. **The reminder:** the same weekly reminder as the Office minimum. ★

Not asked; the recommended default applies: a manager of two teams counts once.
