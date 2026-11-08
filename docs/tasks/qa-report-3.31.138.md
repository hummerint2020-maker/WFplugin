# QA report on 3.31.138: what was fixed, and the owner's decisions

The external QA report (ten issues and observations) was reviewed against the code by a read-only reviewer (root causes RC-A…RC-D).

## Fixed in 3.31.141 (PRs #30–#35)
Blank admin-post.php page after advance request / decision and report view save / delete (RC-A); Overtime page Reject without confirmation; the correction sheet opened by a refused reject (RC-D); CSV with BOM before a quoted header; XLSX with prefixed elements; People Clear and Notifications All (RC-B); Settings Overview grouped settings.

## Owner decisions (2026-11-06), for the next fix releases
1. **Legacy requests in My approvals (QA 6, RC-C):** requests that never start an approval-engine instance (overtime with the workflow "Not set yet", a leave cancellation when leave approvals are off) appear in My approvals as cards that decide through their **existing legacy path**. No change to who approves or to permissions; the counts in the menu and on Home include them.
2. **Early leave in My requests (QA 3):** shown with its status, and **withdrawable while it waits**, like leave and overtime.
3. **Administrator fallback wording (QA 8):** a **separate text per reason**: no direct manager (with a hint to set the organization chart) and "the approver made the request". The engine keeps the reason on the rerouted step (`skip_reason` or an equivalent); existing rows without a reason show a neutral text.
4. **Task column counts (observation):** the counts follow the filter and the search (they count what is shown).

Not decided here: Task Workload scope (stays organization-wide as today); the settings export of grouped values (stays as today). The attachment error inside the task dialog (QA 7) is a layout change: a mockup comes first.

Items 1 and 3 touch the approval engine and permissions: each gets a design (architect) before implementation.
