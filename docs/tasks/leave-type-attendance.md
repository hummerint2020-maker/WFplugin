# Leave type on the Attendance page

Owner request 2026-11-18: "بدل ما يظهر Leave بس يظهر نوع الـ leave نفسها سواء عادية، عارضة أو مرضي، أو لو فيها حاجة تانية في الكونفجريشن اتضافت".

## Owner's decisions (2026-11-18)

1. **Where:** the Attendance page only (app → Attendance, the manager's grid). Reports, exports and Attendance Insights do not change.
2. **Summary:** one card, Leave days, with the count by type under it ("Annual 5 · Sick 2 · Casual 1"). The page has no Leave card today (Employees, Present, Late, Absent, Attendance rate), so the card is new and appears only with the feature on; six cards in a row on a wide screen.
3. **Colour:** the same as Leave today for every type; only the word changes.
4. **Without an approved leave request:** as today. A day set by hand to a leave status in the schedule shows that status as now; a company holiday shows "Leave" with the holiday's name, as now.
5. **Off by default** (project convention): a switch in Features. Off, the page is exactly as today.

## Where the type comes from (first reading of the code, to be confirmed by the design)

- Approving a leave sets the day's schedule to `Vacation` with the note `Approved leave #<id>` (`includes/trait-leave.php:184-188`), and the request carries `leave_type_id` → `ews_leave_types.name`, the types configured on the Leave Types page (Annual Leave, Sick Leave, Casual Leave, and any added).
- The grid's badge comes from `Insights::actual()` → `GridRules::badge()` (`src/Attendance/GridRules.php:27`), built in `includes/trait-attendance-grid.php:84`.

## Mockups

`docs/mockups/leave-type/` (`on`, `off`; Arabic and English; `index.html`).
