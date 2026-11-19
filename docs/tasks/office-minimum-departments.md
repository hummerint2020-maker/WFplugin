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
