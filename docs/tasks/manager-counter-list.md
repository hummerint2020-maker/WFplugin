# The managers' counter opens the day's managers (3.31.155)

## Request (owner, 2026-11-19)
A phone screenshot of the Attendance page with the managers' counter (2 / 1) under each day:
"عايز الجزء بتاع المديرين اقدر ادوس عليه و اعرف مين اللي نازل اللي مكتوب في 2/1"
(I want to tap the managers' part and see who is coming in, the ones counted in 2/1.)

## Mockup
docs/mockups/manager-counter-list/ (built by docs/mockups/_kit/screens_mgrlist.py): the counter as a button with a
small arrow; Sunday tapped (enough managers, green); Thursday tapped (short, as today from the warning); a phone.

## Owner answers (2026-11-19)
1. Only the managers' counter opens a list; the people's counter (10 / 10) stays as it is.
2. No separate switch: it comes with the managers' minimum, so a site without it sees no change.

## Design (a small change to 3.31.153; no architect: no data, money, permission or schema change)
- templates/app/attendance.php `$om_counter`: the managers' counter is a `<button type="button" data-wfo-sheet="wfo-mom-<date>">`
  with the same data-mom-* attributes (the live counter keeps working) and a chevron.
- templates/app/office-minimum-managers.php: the warning card only when a day is short (as before); a Managers sheet for
  every checked day with the managers' minimum (not only the short ones). The rows are grouped under headings with their
  count: In the office, Working from home (Could come in), Not in the office. A day with enough managers: the summary is
  green, "Enough: %1$d in the office, the minimum is %2$d".
- "Not in the office", not "Away", for the third heading: a manager outside a department-scoped viewer's department is
  masked to in / not in the office (3.31.153), so the heading must not say why.
- Nothing new is read: om_days() already works out every day's managers for the counter.
- Styles appended to assets/css/app-attendance.css and its -rtl copy.

## Tests
- tests/e2e_manager_office_minimum.py: the counter is a button opening that day's sheet (10 buttons: the head and the
  "Set…" row), every checked day has a sheet; Tuesday (enough) has its sheet, green, with each manager's plan; Thursday's
  headings and counts; the card still offers Managers only for a short day.
- Golden: a new page, the Attendance page with the managers' minimum on (min 3), declared in expected.json; every other
  page identical.

## Review (read-only, on Opus): nothing serious
Checked clean: the department masking (headings, counts and order leak neither why nor working from home), byte-identical
with the managers' minimum or the Office minimum off, the card and teams sheets unchanged, skipped days (holiday, minimum 0),
a day with no managers, ids, the live counter, escaping, translations, tests.

Fixed
1. CONFIRMED (cosmetic). On a day with enough managers, the "/ 2" inside the summary pill was red under a green line
   (`.wfo-om-sheet-sum span` matched it). The pill now keeps its own colour.
2. CONFIRMED (accessibility). The button's name was only "2 / 1": it now has aria-haspopup="dialog" and an aria-label
   "Managers in the office on Sunday 15 November: 2 of 1".

Not changed
3. PLAUSIBLE (UX, an owner call). The counter follows unsaved changes but the sheet shows the saved schedule, as the teams
   sheets always have. Asked the owner.
4. Nit. The headings are list items, not headings, for screen readers. Left as they are.
