# Corrections balance in My requests (3.31.163)

## Owner's request (2026-11-24)
"In page My requests need to add correction balance." Asked what it means, the owner chose **the attendance corrections
balance** (not a request to correct a leave balance): show how many attendance corrections are left this month.

## What exists (main @ 3.31.161)
- Attendance corrections have a monthly limit (`ews_correction_settings.monthly_limit`, built in 3, 0 = no limit) and a rule
  for the ones above it (`above_limit`: `hr` = allowed, sent to HR; `refuse`). The count is `CorrectionStore::monthCount`:
  the employee's own requests (source employee) waiting or approved, for days of the month.
- My requests shows that number only inside the New request sheet ("2 left this month" on the correction card).
- "Your balance" on top of My requests lists leave balances, only with Leave in My requests on (3.31.158).

## Design
- A switch `ews_feature_my_requests_cx_balance`, **off by default** (Features → My approvals and My requests). Effective only
  while Attendance corrections and My approvals are on. Off: nothing printed, no extra work.
- Rule (pure): `src/Attendance/CorrectionBalance::card(settings, used)` → `{left, limit, used, after}` or null without a
  monthly limit; `after` is `''` while some are left, else `hr` or `refuse`.
- Controller: `cx_new_request_sheet()` already counts this month's corrections for the New request sheet; it hands the card
  (only while the switch is on) to `my_requests_new()`, and `requests_content()` passes it to `templates/app/requests.php`.
  **No new query, no new SQL**: the Store method and its count are the existing ones. No Service was added, because there
  is no use case beyond the pure rule applied to a number already read (layered-modules.md: the controller calls the rule).
- Template: the strip shows when there are leave balances **or** the card. The card comes last; "This year · after requests
  waiting" only with leave balances. States: some left ("2 of 3 this month"), none left + HR (amber), none left + refuse (red).
- Mockup: docs/mockups/corrections-balance/index.html.

## Edge cases
- No employee record: no card (as the strip). Corrections off: no card. Limit 0: no card.
- More approved than the limit (HR approved above it): 0 left, never negative.
- Rejected / withdrawn / HR's own corrections do not count (the limit's own rule).
- Requests for last month's days (inside the deadline) count towards last month, as the limit does.

## Tests
- Unit: tests/unit/CorrectionBalanceTest.php.
- e2e: tests/e2e_corrections_balance.py (off as before; on alone and with leave; waiting counts; HR / refuse; no limit;
  corrections off; switch saved and audited; off again).
- Golden: only the Features page changes (a new switch).
