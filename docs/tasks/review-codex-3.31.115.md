# External review of 3.31.115 (Codex, 2026-10-21): verification and plan

Each finding was checked against `main` (a3c6c60) by an independent read-only review.

| # | Verdict | Severity | Plan |
|---|---|---|---|
| F11 working hours saved before the date is checked; grace/overnight change rewrites past days | Confirmed, worse than reported | P1 (lateness, payroll) | PR A (payroll integrity) |
| F02 payroll reopen not atomic | Confirmed | P1 | PR A |
| F01 instalment taking counts 0 rows as success; payroll_month before the transaction | Confirmed (needs two closes at once) | P2 | PR A |
| F06 approved overtime made a personal leave day correctable | Confirmed (code wider than the owner's decision) | P2 | PR B; owner: **the leave wins** |
| F07 cx_close ignores approval_close(), no transaction, pending_hr engine already APPROVED | Partly (the correction status stays right) | P3 | PR B |
| F05 swap reads before the transaction, UPDATE by id only | Confirmed (millisecond window) | P3 | PR B |
| F04 offline attempt note in localStorage has no account; logout does not clear it | Confirmed (evidence log only) | P3 | PR C |
| F08 DB failures in auth: access revoke unchecked, login inserts unchecked, revoke_device false→0 | Confirmed (DB errors only) | P3 (P2 on password change) | PR C |
| F12 shift period / org move partial saves | Confirmed (failure path) | P3 | PR D |
| F13 org move stuck in 'applying'; tasks claim-first is deliberate (at most once) | Partly | P3 | PR D (org moves only) |
| F14 automatic attendance without the per-employee lock | Confirmed (small window) | P3 | PR D |
| F15 deactivate/uninstall cleanup (break events with args, ews_push_deliver, ews_foreman) | Confirmed (minor) | P3 | PR D |
| F09 old health key never removed | Refuted: the old key never shipped; SCHEMA never bumped is hygiene | — | note only |
| F10 daily rollup delete/insert gap | Partly: diagnostic data, the next rollup rebuilds it; the empty version cannot occur | P3 | PR D if cheap |
| F03 week lock "the owner asked for" | No such decision on record; the guard is missing | — | owner: **a new feature behind a switch**, designed separately |
| S01 face check has no liveness proof | Confirmed design limit (no liveness is claimed) | owner decision | later |
| S02 private files rely on .htaccess unless WFO_PRIVATE_DIR | Confirmed, documented, with a weekly check | — | — |
| S03 no multi-tenancy | By design (one site per company) | — | — |
| No privacy exporters/erasers; no readme.txt; PHP 7.4 / WP 6.0 never run in CI | Confirmed | P2/P3 | distribution PR later |
| No native attendance write routes | Confirmed | — | mobile PR 2 |

Order (owner, 2026-10-21): PR A payroll integrity (F11, F02, F01) first, then B, C, D, then the week lock feature and the mobile work.
