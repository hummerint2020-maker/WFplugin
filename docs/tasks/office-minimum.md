# Task: office minimum (الحد الأدنى في المكتب)

Owner request (2026-10-09). Small and mid-size companies want a fixed number of people in the office
every working day. When a day drops below it, whoever sets the schedule is warned and sees which
teams are under their share (teams are not the same size). Switchable in Feature Configuration, off
by default.

Owner decisions: a **fixed number** (not a percentage); **warning only** (never blocks saving);
shares by **Teams**; business trips and training do **not** count.

## Step 1 — HTML mockups, then STOP

`docs/mockups/office-minimum/` (the Attendance screens start from snapshots of the real page). Wait
for approval before Step 2.

## Step 2 — build

- **Settings** (Feature Configuration → Office Minimum): on / off; the minimum (people); an optional
  different number per weekday; which schedule statuses count (Office by default); a fixed number per
  team (else automatic); the weekly reminder (on, day and time). Working days only; company holidays
  and days off are skipped.
- **Share per team** = minimum × team size ÷ active employees, rounded by largest remainder so the
  shares add up to the minimum; a team's fixed number replaces its share; people in no team share one
  line ("No team"). A person in several teams counts in their primary team (as the Attendance page
  shows it).
- **App → Attendance (whoever sets the schedule)**: under each day "in the office / minimum" (red when
  short) in the table head and the "Set… for the whole day" row; a warning card listing the short days
  with a "Teams" button; the teams sheet: each team's share, in the office, short / above, and who
  could come in (working from home that day, not on leave). The counters update live while editing;
  saving a change that leaves a day short asks "Save anyway?" (never blocks).
- **Approving leave / WFH / swaps**: the approver sees what approving does to the day and the team.
- **Weekly reminder** (notification + push) to schedule managers: next week's days below the minimum.
- **Reports**: the Location Capacity day view shows the minimum line; a "Days below minimum" list
  with Excel export.
- Pure rules in `src/Schedule/OfficeMinimum.php` (counts, shares, gaps) with unit tests;
  `tests/e2e_office_minimum.py` in CI; translations; CHANGELOG; no schema change expected (settings
  in options).
