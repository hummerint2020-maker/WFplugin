# Requests Hub reports

**Owner request (2026-11-22):** "We want every request, even the approved ones, to show in the Requests Hub, under a new tab called Reports. Think about it with me."

**Status:** decisions taken (two rounds); the mockup matches them and is waiting for the owner's approval. Design next.

## Today

`wp-admin → Workforce One → Requests Hub` (`includes/trait-admin-requests.php`, `templates/admin/requests.php`, `src/Requests/Hub.php`):

- It lists only the requests that wait for a decision, oldest first.
- It covers nine types: leave, leave cancellation, overtime, early leave, shift swap, legacy vacation, attendance correction, HR letter and face reset.
- Once a request is decided, it leaves the page.
- The Report Center's "Leave & Balances" report counts leave per employee. It does not list the requests themselves.

## Owner's decisions (2026-11-22)

1. **Which statuses:** every status — waiting, approved, rejected, withdrawn and cancelled — with a filter to narrow it.
2. **Actions:** view only. Decisions stay on the first tab and in My approvals, so nothing changes in how a request is decided.
3. **Export:** CSV and Excel of the filtered list (every page), the same way the Report Center exports.
4. **History shown:** one line per request with the final decision, who took it, when, and a rejection's reason. Not the whole approval chain.

## Rules of the house that apply

- **Off by default, behind its own switch.** With the switch off, the Requests Hub is exactly as today: no tabs, same page, same queries.
- **Mockup before code:** `docs/mockups/hub-reports/` (built by `docs/mockups/_kit/screens_hubreports.py`).

## Mockup

- **Tab 1, "Waiting for a decision":** today's table, unchanged, with a count on the tab.
- **Tab 2, "Reports":**
  - **Filters:** From / To, Type, Status, Employee or department, Show.
  - **Export:** CSV and Excel.
  - **Totals by status.**
  - **Table:** Request, Employee, Details, Status, Requested, Decided (who · when), Note (rejection reason, withdrawn, issued, level).
  - **Paging:** 20 a page, newest first.
- **Filtered example:** Leave, Rejected, Sales, showing the reasons.
- **Empty period.**
- **Features:** the switch, off.

## Owner's answers (2026-11-22, second round)

5. **Default period:** the current month.
6. **The period filters on:** the date the request was made.
7. **A leave cancellation:** its own row, with its own decision.
8. **Who sees the tab:** administrators only, the same as the Requests Hub today.

## Known limits (to settle in the design)

- **Face resets:** these are kept in an option, one per employee, so older face resets may not all be in the history.
- **Who decided:** each type keeps this in its own way (approval steps, a `decided_by` column, or nothing for the older direct decisions). For the oldest rows the column may show "—".
