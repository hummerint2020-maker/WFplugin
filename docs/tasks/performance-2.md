# Performance 2 (3.31.149): exports in batches

Owner request 2026-11-15 (the next item of `docs/tasks/performance-1.md`, open question 2). The exports that load a whole date range into memory must stop doing so, so a year for 200 employees (about 300k time-log rows, 50-70k employee-days) cannot exhaust PHP memory. Binding rule: the company running today is not affected: same files, same bytes, same pages. No cap on the date range (anything that looks like a cap is an open question below).

Read-only design; nothing was run. Claims are marked VERIFIED (read in the code) or ASSUMED (sizes, MySQL and PHP internals, harness behaviour not read to the end).

## 0. Findings that shape the design

1. **The Report Center screen has the same problem as its exports.** VERIFIED: every builder calls `report_attendance_data()` for the whole range before it shows the export links (`includes/trait-reports.php:75`, `:83`, `:388`, `:409`; the summary also loads the previous period, `:87`). A year on screen costs what the export costs. The brief covers exports only; step B1 below also relieves the screen's largest transient without changing it. The rest of the screen is open question 3.
2. **The Sign In / Out CSV order is not total and cannot be proved to tie-break by id.** VERIFIED: `ORDER BY l.work_date ASC,e.name ASC,l.event_at ASC` (`src/Attendance/Data/WordPressTimeReportQuery.php:116`). Rows tie when day, name (under the column's collation, so "Ali" = "ali") and second are equal: two employees with the same name, or one employee with two records in the same second. ASSUMED: MySQL's order for ties depends on the plan and the sort algorithm, and MariaDB differs. Performance 1 already met this when an index reordered the report page (`docs/tasks/performance-1.md`, "Item 3"). So keyset pagination with `l.id` as a tiebreak is rejected: it would define an order that today's file does not have. The design keeps the statement text unchanged and cuts by day instead (section 1).
3. **Hidden per-row cost: `working_hours()` memoises per employee and day and never evicts.** VERIFIED: `self::$ews_working_hours_cache[$employee_id.'|'.$date]` (`includes/trait-work-time.php:147-168`), reached per sign-in row by `sign_in_classification()` (`:201-206`) and per employee-day by `report_days()` (`trait-reports.php:305`). A year for 200 people is 50-70k entries of eight keys (ASSUMED 30-40 MB).
4. **PHP 7.4 is supported** (`employee-schedule-manager.php:10`), where `uasort` is not stable. Any batching of the per-employee reports must hand the final sort the same array, in the same order, as today (section 2).
5. **An `.xlsx` is never byte-identical between two runs today.** VERIFIED: the sheet carries `Generated: <minute>` (`trait-reports.php:564`, `:590`) and the zip entries carry the time. "Same bytes" for Excel therefore means the same parts, in the same order, each with the same content once the generated time is normalised.

## 1. Sign In / Out CSV (`admin_time_csv`)

**Today (VERIFIED).** `includes/trait-time-report.php:176-209`:
- `:182` loads every row of the range through `TimeReportQuery::csv()` (`WordPressTimeReportQuery.php:113-120`): `SELECT l.*`, the employee, and a correlated `MIN(correction_id)` per row (it uses `KEY corrects_id`, so it is fine).
- `:191-192` primes shifts and locations for every employee id in the rows.
- `:193-207` writes with `fputcsv` to `php://output`.
- There is no `set_time_limit` anywhere in the plugin (grep), no audit entry, and no error check: a failed query gives `(array)null`, which is a header-only file with status 200.

Where memory goes (ASSUMED sizes): 300k row objects of about 27 properties (400-600 MB), a second transient copy in mysqlnd's buffered result, and the working-hours cache (finding 3). The other caches are bounded by the number of employees: `$radius` (`:190`), locations (`trait-locations.php:102-121`), shift periods (`trait-shift-history.php:175-190`), `PeopleDirectory::shiftIds` memo (`src/People/PeopleDirectory.php:140-152`). All three primes skip ids they already hold, so they can be called per batch.

**Design: day windows, the same statement.**

- New `TimeReportQuery::csvDayCounts(string $start, string $end, ?int $scope): array` returns `[work_date => n]`, ascending. SQL in `WordPressTimeReportQuery`: `SELECT l.work_date d,COUNT(*) n FROM logs l [LEFT JOIN emps e ON e.id=l.employee_id] WHERE l.work_date BETWEEN %s AND %s{scope} GROUP BY l.work_date ORDER BY l.work_date ASC`. The join is added only when `$scope!==null`. For administrators it is an index-only read of `work_date_at` (3.31.148).
- New pure class `src/Attendance/TimeCsvWindows.php`: `pack(array $dayCounts, string $start, string $end, int $budget): array` returns a list of `[from, to]`.
  - It adds days greedily until the next day would pass the budget. A single day over the budget is its own window.
  - Windows are contiguous and cover `[$start, $end]` exactly: the first starts at `$start`, each next starts the day after the previous end, the last ends at `$end`. A row inserted during the export on a day that had none is still in exactly one window, and never twice.
  - No rows at all gives one window `[$start, $end]`.
- `csv()` keeps its signature and text. The trait calls it once per window. Because `work_date` is the first sort key, the concatenation of the windows is the whole-range order. When the range fits the budget there is one window with the original bounds, so the statement is character for character today's.
- Budget: 5,000 rows a window (ASSUMED about 10 MB of row objects), read through `apply_filters('ews_export_batch_rows', 5000)` so the tests can force many windows on small data.
- `admin_time_csv()` after the change, in this order:
  1. Capability, nonce and date checks, as today.
  2. Day counts, then windows.
  3. Write to a spool, `fopen('php://temp/maxmemory:2097152','w+')` (spills to a temp file): the BOM, the header, and per window: `csv()`, check `$wpdb->last_error`, prime shifts and locations for the window's ids, the row loop exactly as `:193-207`, then drop the working-hours cache and call `@set_time_limit(120)`.
  4. Only when every window succeeded: the same three header lines as `:184-186`, verbatim, then `rewind`, `fpassthru`, `exit`.
- The row loop may memoise `date_i18n('l', …)` per `work_date` inside a window (same string, about 300k fewer calls). Nothing else in the row changes.
- New one-line helper next to the cache: `private function working_hours_forget(){ self::$ews_working_hours_cache=[]; }` in `includes/trait-work-time.php`. The cache is already dropped on writes (`trait-shift-history.php:99`, `:117`, `:271`, `:284`), so dropping it is safe: the values are recomputed from bounded caches.
- Errors: a window whose query fails (`$wpdb->last_error!==''`) or a short `fwrite` ends in `wp_die('The export could not be completed. Please try again.')` before any byte is sent. Today the same failure gives a header-only file with status 200. This is the only behaviour difference and it occurs only on a database error.

**Why spool then send, not write straight to the browser.** Headers go out only after success, as today (the query runs before the headers at `:182-184`); there is never a half file; golden's capture is unaffected. The cost is temp disk (ASSUMED about 60 MB for 300k rows) and no earlier first byte than today. Streaming each window directly is the alternative if a host's idle timeout bites; it would need an "export incomplete" last line on failure. Not proposed now.

**Left as it is:** output buffers are not ended and no Content-Length is added (the headers stay identical). `$wpdb->queries` under SAVEQUERIES grows by one entry per window, which is small.

## 2. Report Center exports

**Today (VERIFIED).**
- `report_download()` (`trait-reports.php:709-717`) calls `report_detailed_csv()` (`:521-527`: php://temp, then one string, then `echo BOM.$csv`).
- `report_xlsx()` (`:700-707`) calls `report_xlsx_build()` (`:558-693`), which sends through `Download::send()`.
- Both go through `report_export_table()` (`:444-519`), then `report_attendance_data()` (`:346-357`), then `report_days()` (`:274-332`).
- `report_email()` (`:719-739`) is not a range export: it sends each employee their own schedule, one small query each. Out of scope.

Where memory goes for a year and 200 people (ASSUMED sizes, VERIFIED locations), largest first:

| | What | Where |
|---|---|---|
| a | Every time log of the range in one result (300k objects), though only the first and last per day are kept | `report_days()` `:285-289` |
| b | The employee-day matrix twice: `$rows` of `report_days()` (about 30 keys each), and the copy `report_attendance_data()` makes with `teams`, `flags`, `note` | `:327`, `:354` |
| c | The working-hours cache (finding 3) | `:305` |
| d | Sheet XML as PHP strings, about 1.2 KB a row, held up to three times: `$sheet` or `$xml`, the `'<?xml…'.$xml` concatenation, and ZipArchive's copy until `close()`. Also `array_merge([$headers],$rows)` | `:540-556`, `:606-619`, `:686`, `:546` |
| e | `$out` arrays (the detail rows, and "Daily Details" for the summary and the timesheet) | `:453-457`, `:514-516` |
| f | The CSV in up to three string copies | `:526`, `:716` |

The zip itself is small once compressed (ASSUMED 3-6 MB), so `file_get_contents($tmp)` and `Download::send()` stay.

### Step B1: `report_days()` reads in employee batches (fixes a and c, for the screen too)

- Split `report_days()`:
  - `report_days_context($s,$e,$off_days)`, computed **once**: `$dates`, `$working`, `$holidays`, `$today`, `$now`, `$allowance`, `$ot_on` (today at `:276-277`, `:294-301`). `$now` must not be taken per batch: `DayMetrics` uses it for today's row.
  - `report_days_batch(array $emps, array $ctx)`: today's body from `:279` on, for a subset of employees. The SQL moves with it, unchanged, inside the same trait.
  - `report_days_each($emps,$s,$e,$off_days)`: a generator over `array_chunk($emps,$size)` that calls `working_hours_forget()` between batches.
  - `report_days()` itself is the concatenation of the batches: same return value, so every caller is untouched.
- Batch size: `max(1, intdiv($budget, $calendar_days))` with `apply_filters('ews_export_batch_days', 10000)` employee-days. 200 people for a month is 6,200, so **one batch: exactly today's statements**. A year gives 27 employees a batch, 8 batches.
- Why the result is identical: every query in `report_days()` is `employee_id IN (…)` and every figure is per employee and day (VERIFIED `:284-296`, `:303-329`). Batches keep the employees' order, so the rows come out in the same order.

### Step B2: the exports stop holding the matrix (fixes b, e, f)

- New pure class `src/Support/RowSpool.php` (IteratorAggregate, Countable): `add(array $row)` writes `serialize($row)` length-prefixed to `php://temp/maxmemory:…`; iterating rewinds and yields the rows with their exact types. Types matter: the sheet writers test `is_int` and `is_numeric` (`:550`, `:609`).
- `report_attendance_data()` keeps its signature for the screen. A new generator `report_attendance_each(...)` yields each batch's rows after the same loop as `:349-355`.
- `report_export_table()` (`:444-519`) uses the generator for the four types built on the matrix:
  - **attendance** (`:513-518`): the rows go to a `RowSpool`; the summary accumulates with `Summary::add` exactly as today (before the status filter, `:352-353`).
  - **summary** (`:504-511`) and **timesheet** (`:492-503`): `EmployeeSummary` and `Timesheet` get an accumulating form: `start()`, `accumulate(array $state, array $days)`, `finish(array $state)`. `byEmployee()` becomes `finish(accumulate(start(), $days))`, so the screen is unchanged. `finish()` runs the same `uasort` **once** over the same array in the same insertion order (employees in scope order). That is the only way to stay identical on PHP 7.4 (finding 4): do not sort per batch and merge. `EmployeeSummary::totals()` gets the same accumulate and finish (its `add` is private today, `src/Reports/EmployeeSummary.php`). "Daily Details" rows go to a `RowSpool`.
  - **workforce** (`:446-452`): `Workforce::byDay()` per batch, summed per date and column, then `ksort`. Integer sums, so identical.
  - `'rows'` and `sheets[n]['rows']` become `iterable`: an array for the small tables, a `RowSpool` for the large ones.
- `report_download()`: the spool pattern of section 1. `fputcsv` each row to `php://temp/maxmemory`, then the audit, then the same headers, then `echo "\xEF\xBB\xBF"`, then `fpassthru`. Same bytes, same order (table, audit, send), no string copies.
- **Overtime and leave** (`:106-113`, `:126-137`) pass the whole matrix to `OvertimeReport::byEmployee($rows,$requests)` and `LeaveReport::byEmployee($people,$rows,…)`. They gain a and c from B1 for free. Their own accumulating form is the same pattern and can be a later step: their output is one row per employee and they need care with employees who have requests but no days. **Capacity** (`:151-181`) reads by date, not through `report_days()`; one row per schedule entry of the range. Left for later.

### Step B3: sheets written to temp files (fixes d)

- `report_xlsx_table_sheet()` (`:540-556`) and the main sheet in `report_xlsx_build()` (`:575-619`) write with `fwrite` to a `wp_tempnam()` file, row by row from the iterable, instead of building `$xml` or `$sheet`. The file starts with the `<?xml …?>` declaration that `:686` prepends today.
- The main sheet needs the KPI cells (rows 6-7, `:593-598`) before the data, and the last row number after it (`:621`). The KPIs are known when `report_export_table()` returns, so the order of writing is unchanged. The table sheet's `autoFilter` uses `count($all)`: 1 + `count($rows)`, hence Countable.
- `$zip->addFile($tmpSheet,'xl/worksheets/sheet'.$n.'.xml')` replaces `addFromString` for the sheets. The parts are added in the same order (`:672`, `:680-691`). The temp files are unlinked after `$zip->close()`, in a `finally`.
- `Xlsx::write()` (`src/Support/Xlsx.php:24`) and `payroll_xlsx()` (`trait-payroll.php:529`) are not touched: their callers are bounded (section 3).

**Order of work and what can wait.** A (section 1) can ship alone. B1 is small and gives the most per line changed. B2 and B3 are both needed before a year of "attendance", "summary" or "timesheet" in Excel is safe: after B2 alone the XML strings are still about 60 MB, times three. Overtime and leave accumulation, capacity and the screen can wait. Proposal: 3.31.149 = A + B1 + B2 + B3, in that order, as separate commits.

## 3. Other exports

| Export | Range | Verdict |
|---|---|---|
| Corrections CSV, `includes/trait-corrections.php:948-965` | One month (`CorrectionRules::monthBounds`, `:954`) | Bounded. Out. |
| Poll CSV, `includes/trait-polls.php:288-297` | One poll | Bounded. Out. |
| Employee details and Org export, `trait-employee-details.php:38-63`, `trait-org-import.php:49-55` | Every employee, no dates | Bounded by headcount. Out. |
| Payroll Excel and PDF, `trait-payroll.php:495-521` | One month | Bounded. Out. |
| Schedule PDF, `trait-schedule-view.php:91` | One week | Bounded. Out. |
| **Daily workers export**, `trait-daily-workers.php:1588-1628` | Free `from` / `to` | **Unbounded.** The "Days" sheet is one row per worker-day (`:1618`), all in memory, then `payroll_xlsx()` strings. The insurance CSV is grouped per month and worker: small. Same cure as B3. Not in this release (open question 4). |

## 4. Data model and upgrade

None. No table, column, index or option; `ews_schema_target()` does not move. Two filters (`ews_export_batch_rows`, `ews_export_batch_days`) exist for the tests, with no setting behind them.

## 5. Files

| File | Change |
|---|---|
| `src/Attendance/Data/TimeReportQuery.php`, `WordPressTimeReportQuery.php` | `csvDayCounts()` (the only new SQL). `csv()` untouched. |
| `src/Attendance/TimeCsvWindows.php` (new, pure) | `pack()` |
| `src/Support/RowSpool.php` (new, pure) | rows on a temp stream |
| `src/Reports/EmployeeSummary.php`, `Timesheet.php` | `start` / `accumulate` / `finish`; `byEmployee()` and `totals()` are defined through them |
| `includes/trait-time-report.php` | `admin_time_csv()` |
| `includes/trait-work-time.php` | `working_hours_forget()` |
| `includes/trait-reports.php` | `report_days_context` / `_batch` / `_each`, `report_attendance_each`, `report_export_table`, `report_download`, `report_xlsx_table_sheet`, `report_xlsx_build`. `report_detailed_csv()` goes if nothing else calls it. |
| `tests/e2e_attendance_store.php` | the contract for `csvDayCounts()` (next to the `csv` checks at `:294`, `:325`) |
| `docs/architecture/modules.json` / `modules.md` | the new query method if the map lists methods (the entry for `WordPressTimeReportQuery.php` is at `modules.json:480`) |

Module boundaries: the only new SQL sits in `src/Attendance/Data`. `report_days_batch()` moves existing SQL within the same trait. ASSUMED: `tests/check_modules.py` counts crossings per file, so the baseline should not move. Run it before anything else, and mind the comment-stripper bug recorded in Performance 1.

## 6. How nothing changes, and how it is proved

- **Sign In / Out CSV.**
  - `tests/golden/actions_timecsv.php` and `actions_timecsv_mgr.php` record the file and the exact SQL of a three-day export (VERIFIED). Expected: `return` (the file) is identical; `sql` gains one statement (`csvDayCounts`) before the unchanged `csv` statement; `queries` is +1. Declare that in `tests/golden/expected.json` with the reason.
  - New e2e: the same range exported with the budget filter at its default and at 3 rows gives equal bytes. With one window the statement is the old one, so this is old against new.
- **Report Center.** Not traced by golden today (VERIFIED: only the screen, `tests/golden/pages.json:40`).
  - Add `tests/golden/actions_reportcsv.php`: the CSV of each type, and for the xlsx each part's content with `Generated:` normalised.
  - ASSUMED: `run.py` runs the head's action scripts against both the base and the head code. If it does not, the comparison is the e2e with the filter at 1 employee-day against the default (one batch is today's path).
- **Pages.** No page declared: `report_days()` runs one batch for golden's ranges, so the same statements and the same count (the harness refuses more queries, `tests/golden/run.py:281`).

## 7. Edge cases

- **Empty range or no rows:** one window, header-only file as today; the xlsx keeps "No records found" (`:617`).
- **One day:** one window, today's statement.
- **A day larger than the budget:** its own window. Memory is bounded by the busiest day, not the range.
- **A manager's scope:** `scope()` (`WordPressTimeReportQuery.php:30-34`) is used in both the count and the rows. Scope 0 gives `AND 1=0`: no counts, one window, no rows.
- **A deleted employee** (LEFT JOIN, name NULL): unchanged. No comparison on the name is added.
- **Renamed during the export:** today one statement sees one name; with windows, earlier windows keep the old name. The same holds for rows edited or deleted between windows. Rows inserted are read once. A consistent-snapshot transaction would remove this, but it holds a long read transaction on the live database: not proposed.
- **Ties** (finding 2): inside one window, whatever MySQL does for that statement, as today. The windows cannot reorder across days.
- **Time zone:** `date_i18n` on stored local times, per row, untouched. `$today` and `$now` are taken once for all batches (B1).
- **BOM:** written first to the spool, the same three bytes, once.
- **`report_days()` picks the first sign-in by `ORDER BY event_at`** (`:285`): a sign-in and a legacy `late_sign_in` of one employee in the same second have no defined order today, and a batch could in theory pick the other. Listed as a risk, not handled.
- **Excel's row limit** (1,048,576): the detail sheets pass it for about 3,000 people over a year. ASSUMED: Excel then refuses the file. Today the request dies of memory first. Open question 5.
- **Temp directory full or not writable:** `fwrite` returns short, so `wp_die` before any byte; temp files are unlinked.

## 8. Tests

**Unit (`tests/unit`):**
- `TimeCsvWindows::pack`: empty counts; one day; a day over the budget; exact cover of the range with no gap and no overlap; the last window ends at `$end`.
- `RowSpool`: int, string, float, empty-string and non-UTF-8 values round-trip; count; iterated twice.
- `EmployeeSummary` and `Timesheet`: accumulate over any split of the days equals `byEmployee()` and `totals()` over the whole, including two employees with the same name (order kept).
- `Workforce`: the sum of the batches equals `byDay()`.

**Storage contract:** `csvDayCounts` for all, a department, scope 0 and an empty range, in `tests/e2e_attendance_store.php`.

**e2e, new `tests/e2e_export_batches.py`** (added to `.github/workflows/ci.yml` next to "Reports … and exports", `:190`):
- Sign In / Out CSV: default against 3-row windows, byte-equal, for the administrator and a manager; a range with rows on one day only; an empty range.
- Each report type, CSV and xlsx parts: default against a budget of 1 employee-day, equal; with a status filter; with `employee_status=all`.
- A failing window (query filter in a mu-plugin, as the `_mu.php` tests do): an error page, no partial file, no temp files left.
- Memory: seed two sizes by SQL (for example 25k and 100k time logs); a test mu-plugin records `memory_get_peak_usage(true)` at shutdown; the peak of the larger export is within a few MB of the smaller, for the time CSV and for the attendance xlsx. This is the `tests/e2e_speed.py` idea: it does not grow.

**Existing:** `tests/e2e_time_report.py`, `tests/e2e_reports.py`, `tests/e2e_reports_more.py`, `tests/e2e_speed.py`, `tests/e2e_query_batching.py` and golden stay green unchanged, apart from the one declared trace. Then phpstan, phpcs, PHPCompatibility 7.4 (generators and `yield` are fine), modules, layers.

## 9. Risks

- **Two paths for one figure** (screen: whole array; export: accumulated). Mitigation: `byEmployee()` and `totals()` are defined through the accumulating form, so there is one implementation; the unit tests cover the split.
- **More statements for long ranges** (one per window or batch). System Health always samples the report exports (`src/Health/Routes.php:22`) and may list them as repeated queries. Expected; say so in the changelog.
- **Time is not solved by this.** CPU per row is unchanged (two `date_i18n` calls a row). `set_time_limit` only moves PHP's own limit; a host's proxy or FPM limit still cuts a very long export. Open question 2.
- **Temp disk** instead of memory: about 60 MB for a year's CSV, a few hundred MB of sheet XML for very large Excel files, freed at the end of the request.
- **Ties** (finding 2 and section 7): not made worse and not fixed.
- **`addFile` against `addFromString`:** the same part contents; zip metadata (times, attributes) differs, as it already does between two runs.
- **The upload of a broken budget filter** (0 or negative): clamp to at least 1.

## 10. Open questions for the owner

1. **Records that tie exactly** (same day, same name, same second) have no fixed order in the Sign In / Out CSV today; the database decides. This release leaves that as it is. Should a later release fix the order (by record number)? It could move such rows once.
2. **Very long exports and the host's time limit.** After this release memory no longer grows with the range, but a year still takes time and a host can cut the request. If that happens: leave it, add a cap, or prepare the file in the background and email a link? Suggest measuring on the live site first.
3. **The Report Center screen** loads the whole range as well, before the export links are shown. This release lightens its largest read but does not rebuild the page. Do that next?
4. **The Daily workers export** takes any from / to and is built in memory too. Include it in a later release?
5. **Excel's limit of 1,048,576 rows a sheet**: reached by the detail sheets at about 3,000 people for a year. When a sheet would pass it: refuse with a message, split into several sheets, or offer CSV only?
