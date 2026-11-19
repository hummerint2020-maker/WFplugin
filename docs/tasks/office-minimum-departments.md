# Office minimum by department

Owner request (2026-11-18): "عايزين نخلي manager office minimum و office minimum مرتبطة بالإدارة عشان كل إدارة هيبقى ليها الطبيعة بتاعتها".

## Owner answers (2026-11-18)

1. **The company and departments.** The company's minimum stays as it is, so nothing changes for the current company. Any department may have its own minimum, for people and for managers, checked on its own. A department without a number has no check of its own.
2. **A department's managers** are the team managers in that department (as in 3.31.153).
3. **Who sees what.** Someone who sees only their department sees their department's counters and warning. The administrator sees the company and can pick a department to see its numbers.
4. **A separate release after 3.31.153** (PR #54 is merged as it is, when the owner says so).

## Step 1: mockups, then STOP

Mockups: `docs/mockups/office-minimum-departments/` (dept, phone, admin, company, settings; Arabic and English), built by `docs/mockups/_kit/screens_omdept.py`.

- **Feature Configuration → Office Minimum → "Each department's own minimum":** a table of departments with people, "in the office each day" and "managers each day". Empty means no minimum of its own.
- **Attendance page, a department manager** (Sales: 4 people and 1 manager):
  - A bar above the day counters says whose minimum the page shows.
  - The counters, the card and the sheets use the department's numbers and people.
- **Attendance page, the administrator:** the same bar, with "Minimum for: The whole company / Sales / …". The whole company shows exactly what it does today.

## Owner answers on the design (2026-11-18)

5. **A department's number is the same every working day** (★); no per-weekday number for departments for now.
6. **Teams' shares inside a department:** the department's number is shared among its teams by size, as the company's is. The administrator can also **fix a team's share inside its department** (a fixed number per team, per department), like the fixed team numbers of the company.
7. **The weekly reminder:** a department with its own number goes to the schedule managers of that department (★). The administrator keeps the company's reminder as today.
8. **A department manager whose department has its own number sees only their department's numbers** (★), not the company's warning.

## Correction (owner, 2026-11-18): a full copy of the settings per department

"انت فاهمني غلط، أنا عايز الموضوع زي ما هو شغال في الأصل، بس أضيف لكل إدارة طريقة شغلها وتنظيمها لجدولها."

This replaces answers 1, 5 and 6 above:

- **Each department can have its own full Office minimum settings,** exactly the form there is today:
  - on / off;
  - the minimum, and a number per weekday;
  - which statuses count as in the office;
  - the teams' shares, with fixed numbers for its own teams;
  - the managers' minimum;
  - the reminder.
- **Feature Configuration → Office Minimum** has a tab per department. A department tab has "<Department> has its own settings". Ticked, the form below is that department's: its people, its teams, its managers.
- **A department without its own settings works with the company's,** exactly as today. So nothing changes for the current company.
- **Answers 2, 3, 7 and 8 still hold:**
  - A department's managers are the team managers in it.
  - A department manager sees their department's numbers, and only those, when it has its own settings.
  - The administrator sees the company and can pick a department.
  - The reminder goes to that department's schedule managers.

Mockups: `settings` (the Sales tab, with its own settings) and `settings-company` (the company tab, as today), next to the Attendance screens.
