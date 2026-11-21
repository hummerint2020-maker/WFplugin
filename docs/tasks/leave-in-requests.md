# Leave in My requests (and the old Leave page behind a switch)

## Owner's request (2026-11-21)
"Cancelling an approved leave... I see the Leave page has no use any more; let's think about it together."

## What the Leave page does today, and where else it is (main @ 3.31.157)
| Leave page (?ews_view=vacation) | Elsewhere |
|---|---|
| Request leave | My requests → New request → Leave (same form, same handler) |
| Request early leave | New request → Early leave |
| My leave requests, their state | My requests (level by level; withdraw one still waiting) |
| A manager approves leave / its cancellation | My approvals (card with "Balance after") |
| Pending early leave for managers | My approvals |
| **Request cancellation of an approved leave** | **only here** |
| **Balance of every leave type** | only here (New request's line shows the first two) |
| **Full history ("View all leave requests")** | only here (My requests shows the last 20) |
| Office minimum warning while approving | to check in the My approvals card |
My requests / My approvals exist only while My approvals (ews_feature_my_approvals) is on.

## Owner's answers (2026-11-21)
1. My approvals is **on** at the current company.
2. Balances: **a strip at the top of My requests** (every leave type with its balance).
3. The old Leave page: **a switch, and it stays shown** until the owner turns it off.

## Scope (to design)
- My requests: "Request cancellation" on an approved leave that has not started (CancellationRules, same handler
  ews_leave_cancel, same approval path, ews_return=requests); its states (cancellation waiting / rejected / cancelled).
- My requests: the balances strip; a Leave filter with the whole history.
- My approvals: the office minimum warning on a leave card, if missing.
- A switch to hide the Leave page (menu item, its URL goes to My requests), on = shown (today's behaviour).
- Off by default where it is new: everything new only while My approvals is on; the Leave page unchanged until
  the switch is turned off.
