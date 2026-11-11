# Performance 1 (3.31.148): what every page and every five minutes pay

Owner request 2026-11-10 ("check the plugin for performance problems"), go-ahead 2026-11-11. Five read-only reviews (by area) found the costs below; the first four were verified in the code and are this release. Nothing changes for anyone: the same pages, the same numbers, the same reminders. Only fewer and cheaper queries.

Sizes assumed: 200 employees, two years of data (about 300k time-log rows, 30-60k approval steps).

## 1. The My approvals count builds the whole inbox on every app page

**Today.** The menu badge (`includes/trait-app-layout.php:111`) calls `my_approvals_count()` = `count($this->my_approvals_items())` (`includes/trait-my-approvals.php:179`), and `my_approvals_allowed()` (:43) calls `my_approvals_items()` too. `my_approvals_items()` builds a full card for every engine row (`my_approvals_card()`, :88): a leave card alone is 4-5 queries (the request, the balance, the department's members, who is away), plus `people_employee()` per row (:108). A manager with 40 waiting leaves pays about 200 queries on every page of the app to show a number.

**Design.** The function already has two depths for the legacy cards (`$this->my_approvals_full`, set by the My approvals page; `my_approvals_memo_full`). Extend that to the engine rows:

- `my_approvals_card($entity_type, $entity_id, $light = false)` and each module's `*_approval_card()` take `$light`. Light returns as soon as the card is known to exist (the same guards as today: the row is there, still pending, the feature is on), with only what the counts need: `tone`, `employee_id`, and `path_before` where a module sets it (it changes the path's steps, not the counts, so it may be `[]` only if nothing outside the My approvals page reads the steps: check). The detail queries (balance, who is away, instalments, names) run only for the full card.
- `my_approvals_items()` builds light cards unless `$this->my_approvals_full`; when it built any light card it sets `my_approvals_memo_full=false`, so the My approvals page rebuilds in full exactly as it does today for legacy cards.
- Off the My approvals page nothing reads `name`, `title`, `picture`, `admins_hint`, `for`, `with`: skip `people_employee()`, `employee_picture_url()`, `approval_admin_reason_hint()`, `people_name()`, `approval_user_name()` in light mode. **Verify every reader** of `my_approvals_items()` (`my_approvals_count`, `my_approvals_allowed`, `my_approvals_swap_count`, `my_approvals_home`, the Home card, the Schedule page, the API if any) and list what each reads; if a reader outside the page needs more, keep that field.
- In full mode replace the per-row `people_employee()` with one `people_by_ids()` before the loop (as `my_approvals_legacy_items()` does).

**Must hold.** The count, the overdue count, the swap count and `my_approvals_allowed()` return exactly what they return today for every user; the My approvals page is byte-identical. A card that is `null` today is `null` in light mode (same guards, same order).

## 2. `hasAnyStep()` scans the steps table on every page of an ordinary employee

**Today.** `SELECT id FROM ews_approval_steps WHERE approver_wp_user_id=%d OR acted_by_wp_user_id=%d LIMIT 1` (`src/Approvals/Data/WordPressApprovalRequestStore.php:202`). `acted_by_wp_user_id` has no index (`includes/trait-approvals.php:108-110`), so the `OR` scans the table; for an employee who never approved anything it never stops early. Reached from `my_approvals_allowed()` through the menu.

**Design.**
- New index `KEY acted_by (acted_by_wp_user_id, acted_at)` on `ews_approval_steps`: in the CREATE TABLE and added to existing sites (see 3 for the upgrade).
- The query as one statement of two indexed halves: `(SELECT id … WHERE approver_wp_user_id=%d LIMIT 1) UNION ALL (SELECT id … WHERE acted_by_wp_user_id=%d LIMIT 1) LIMIT 1`. One query, as today (the golden harness refuses a page that makes more queries than before).
- `decidedSince()` (same file) uses the new index as it is.

## 3. No index on time logs starts with the date

**Today.** `ews_time_logs` has `employee_date(employee_id,work_date)`, `employee_date_type_at(...)`, `event_type`, `event_at`, `corrects_id`. Queries by date alone scan the table: the Sign In / Out Report (count and page, `src/Attendance/Data/WordPressTimeReportQuery.php:64-72`), `openSignInsOn` (`WordPressTimeLogStore.php:94`), the missing-sign-out reminder (`WordPressCorrectionQuery.php:105`), Location Capacity (`includes/trait-reports.php:166`). `ews_signin_attempts` is the same.

**Design.**
- `KEY work_date_at (work_date, event_at)` on `ews_time_logs`; `KEY work_date (work_date)` on `ews_signin_attempts`. In the CREATE TABLEs (both places time logs is created: `employee-schedule-manager.php:302` and the fallback in `includes/trait-schema.php:74`) and added to existing sites the way `employee_date_type_at` is (`includes/trait-schema.php:57-58`: `SHOW INDEX`, `ADD KEY` if missing), each in the `ensure_*` routine that owns the table.
- `ews_schema_target()` moves to `3.31.148.1` so installed sites run the upgrade once (`includes/trait-schema.php:336`); `src/Support/SchemaCheck.php` and the schema e2e (`tests/e2e_schema_upgrade.py` or whatever CI calls "Schema upgrade") are updated if they list indexes.
- No query text changes: MySQL picks the index.

**Upgrade path.** `ALTER TABLE … ADD KEY` on a 300k-row table takes seconds and runs once, inside `maybe_upgrade_schema()` (failures are retried after 10 minutes and reported, 3.31.92). Idempotent: checked by name before adding.

## 4. Smart Nudges cron: 8-10 queries per employee every five minutes

**Today.** `smart_nudges_cron()` (`includes/trait-frontend.php:486`) loops over every active linked employee and calls `smart_nudge_for_employee()` (:422): user meta (dismissed, pushed), today's schedule, today's events, working hours, the sign-in window, a tasks query, a pending-leave query, tomorrow's schedule.

**Design (only what cannot change what is sent).**
- Before the loop: `update_meta_cache('user', $user_ids)` so the two `get_user_meta` reads per employee come from one query.
- Tomorrow's schedule and the pending leave requests for all the loop's employees in one query each (`employee_id IN (…)`), passed to `smart_nudge_for_employee()` as an optional prefetched array; without it (the app's own call for one person) the function reads as today. The leave rows keep `ORDER BY requested_at DESC` and the first three per employee.
- The tasks query, today's schedule, events and working hours stay as they are in this release (their conditions are per person and more involved; a later release if measured).
- **Not** in this release: running the cron only during working hours. That changes when a reminder can be sent: an owner decision (open question 1).

**Must hold.** The same nudges, in the same order, with the same ids, for every employee; the app's own nudges unchanged.

## Off by default / nothing changes

No setting. Every page and action trace must be identical (golden: no page or action declared, except SQL text where a trace records it: `hasAnyStep`'s statement and the cron's two prefetch queries, declared in `tests/golden/expected.json` with the reason if the harness records them).

## Tests

- Unit: where pure logic is added (for example taking three leave rows per employee from the grouped result).
- `tests/e2e_query_batching.py` / `tests/e2e_speed.py`: a new measurement: the query count of an app page for a manager with N waiting leave requests does not grow with N (for example 5 and 25 requests: the same count, or a small constant difference), and the menu count equals the number of cards on the My approvals page.
- Schema e2e: the three indexes exist after an upgrade from 3.31.147 and after a fresh install; running the upgrade twice adds nothing.
- Golden and every existing e2e suite unchanged.

## Risks

- A light card that exists where the full card would be `null` (or the reverse) changes a count. Mitigation: the light path is the same function returning earlier, after the same guards; the new e2e compares the count with the page.
- `UNION ALL … LIMIT 1` must return a row exactly when the `OR` did: yes (either half matching).
- An index added on a very large table during a page view: one-off, seconds; documented.

## Open questions for the owner

1. Should Smart Nudges stop checking outside working hours (say, from the latest shift end to the earliest shift start)? Today it checks every five minutes all day and night. Saves most of its remaining cost; changes nothing a person sees unless a reminder was meant to arrive at night.
2. The rest of the review (not in this release), in order of weight: the Sign In / Out CSV export and the Report Center load a whole date range into memory with no limit (a cap of about three months, or paging?); the auto-attendance cron re-checks finished rules every five minutes; the schema upgrade has no lock; the Substitutes sync runs inside any request; unbounded admin lists (leave, polls). Which next?
