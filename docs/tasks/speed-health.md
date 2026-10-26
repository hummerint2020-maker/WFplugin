# 3.31.126 Speed, and what System Health shows

## Why
System Health on the owner's live site (screenshot, 2026-10-25) and a review of it by Codex reported:

| Warning | Where it came from |
|---|---|
| App: Attendance runs the same query 67 times in one load | `people_shift_id()` (and, with it, a `SHOW TABLES` and the shift history per person) |
| App: Attendance Insights, 45 times | the same |
| Sign In / Out (wp-admin report), 42 times, 1 in 20 loads over 3 s | the same, plus each record's Work Location (2 queries per person) |
| App: My requests, 34 times | `people_name()` for each colleague in the New request → Shift swap choices |
| Job `ews_push_deliver`, 30 times | `push_jobs_for_user()`: one devices query per person in the batch |
| Job `ews_health_rollup`, 115 times | `health_build_daily()`: the multi-row INSERT is refused by that database and the rows go in one by one |
| Slow (1 in 20) above Worst (3 s vs 2.7 s; 60 s vs 31 s) | `Stats::percentile()` returns the upper edge of a time band |
| `app:default` 9.8 s, `rest:other` 4.4 s | names nobody can read |
| `ews_profile_photo_save` typical 7.5 s, worst 31 s (3 runs) | a phone photo of several MB is received and resized on the server |

## Owner decisions
1. One release for all of it (3.31.126), before the schedule change request (now 3.31.127).
2. No switch: it is a speed fix with no visible change.

## What changes
- **Shifts for everyone at once.** `people_shift_ids()` (people module) reads `employees.default_shift_id` for many people in
  one query, or uses the rows already loaded, into the request memo `people_shift_id()` uses. `shift_prime_people()` (schedule
  module) adds today's shift (`prime_shift_cache()`) and the shift history (`shift_prime_periods()`). Called from
  `attendance_walk()` (Attendance and its cards' lists), `insights_compute()` (both Attendance Insights pages), the Sign In / Out
  report and its CSV, and the reports engine (`trait-reports.php`).
- **`shift_prime_periods()`** looks for its table once a request once it is found (it was once per person through
  `shift_periods_of()`).
- **Work Locations for everyone at once.** `ews_v321_employee_locations_prime($ids)`: one join of the assignments with the
  active locations, and the default location once, into a request cache that `ews_v321_employee_location()` reads first. The
  cache is cleared by `ews_v321_ensure_locations_table()` / `ews_v321_ensure_employee_map()`, which every location or assignment
  writer calls, and by `invalidate_employee_runtime_cache()`. Primed in the Sign In / Out report (page and CSV), the Attendance
  branch filter and branch section, and the cards' Absent list.
- **Swap colleagues' names:** `people_brief_by_ids()` once in `swap_new_choices()`.
- **Push batch:** `push_devices_by_user()` reads every user's devices in one query; `push_jobs_for_user()` takes them as an
  optional argument (other callers are unchanged).
- **System Health:**
  - `Stats::percentile()` never returns more than the worst seen, when it is known.
  - `health_route_label()` names `app:default` (App: Dashboard), `rest:other`, `post:other`, `cron:other`, `other:admin`,
    `other:front`.
  - `health_build_daily()` keeps the database's answer when the multi-row INSERT is refused (`ews_health_insert_error`,
    internal, in the options registry) and the Scheduled jobs tab shows it; the next rollup that writes the rows together
    clears it. The cause on the live site is not known yet: this makes it visible.
- **Profile photo:** `my-profile.js` draws a chosen photo larger than 512 px onto a canvas at 512 px at most (same type: JPEG,
  PNG or WebP) and sends that, when it is smaller. The server keeps all its checks and still resizes.

## Off changes nothing
There is no switch. The pages' HTML is the same: 13 pages (Dashboard, Attendance plain / branch filter / search, Attendance
Insights app and wp-admin, Sign In / Out report over a month and one day with branches, Daily Log, My requests, My approvals,
Sign In) were recorded on 3.31.125 and compared byte for byte (nonces and `ver=` normalised).

## Tests
- `tests/e2e_speed.py` (new, in CI): each page costs the same queries for 8 people as for 78 (it fails on 3.31.125: Attendance
  59 → 269, Insights 53 → 263, Daily Log 49 → 119, Sign In / Out 63 → 253); Work Locations read in one go equal those read one at
  a time (assigned, archived → default, none → default) and a change clears them; the branch filter; the swap colleagues'
  names; a push batch's devices in the same order.
- `tests/profile_photo_browser.js` (new, in CI): a 3000×2000 JPEG is sent smaller and saved 512×341; a PNG stays a PNG; a small
  photo is sent as chosen.
- `tests/e2e_system_health.py`: the refused INSERT's answer is kept, shown and cleared; the route names.
- `tests/unit/HealthStatsTest.php`: the percentile is capped at the worst.

## Risks
- A request that changes a shift, a location or an assignment and then reads them again: the caches are cleared by the same
  functions that already cleared `people_shift_id()`'s memo, and by the location writers (checked in e2e_speed).
- The push devices for one user, when two have the same `updated_at`, may come in another order (no tie-break before either);
  only the sending order changes.
- The browser resize changes what the server receives: a photo over 2 MB that was refused before may now be accepted (made
  smaller first). EXIF orientation is applied by the browser when it draws.

## Where the implementation differs (after the review)
- The refused INSERT's answer is kept or cleared once per rollup run, after every day's transaction (`health_rollup()`), not
  inside `health_build_daily()`: a ROLLBACK cannot undo it, and a later day written together cannot hide an earlier refusal.
  It is cut with `mb_substr()` (a localised MySQL message stays valid UTF-8) and only escaped, not stripped.
- The Work Locations cache is cleared by `ews_v321_locations_changed()` right after each write (location save, default
  change, archive, assignment save or clear), not only before it.
- The shift periods table flag is kept by table name (another site of a network is looked at again).
- The photo script keeps only the latest choice (a second photo chosen while the first is made smaller wins), catches any
  error while drawing, and never holds the button for more than 10 s.
- **The photo change is visible**, unlike the rest: a photo over 512 px is re-encoded in the browser (quality 0.9), so its
  EXIF data (GPS included) is no longer stored, the browser's orientation is used, and a photo over 2 MB that was refused
  before can now be accepted once made smaller. A browser too old to apply EXIF orientation when drawing (iOS before 13.4)
  could save a turned photo sideways.
