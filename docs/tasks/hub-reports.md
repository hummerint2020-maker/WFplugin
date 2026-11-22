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

---

## Design (architect, 2026-11-22)


Branch `claude/hub-reports`, tree clean. I edited nothing. The owner's 8 decisions in `docs/tasks/hub-reports.md` are taken as given. Everything that is a product choice is in section 8.

**Summary**
- **No schema change.** One new option, off by default, primed with the other options, so it costs no query.
- **One new read model** in the approvals module, `WorkforceOne\Requests\Data\WordPressHubReportQuery`. It runs `UNION ALL` queries over 8 tables and returns only integers and dates.
- **Face resets** come from the option and are merged in PHP.
- **Only the 20 rows of the page are filled in**, with batched reads that already exist.
- **The export** walks the same keys in keyset batches into `RowSpool`, then goes through two functions extracted, byte for byte, from the Report Center code.
- **With the switch off**, the page takes the existing code path untouched. The template gets one line that prints nothing.

---

### 1. Data model per type (what exists today)

Report status codes: waiting=1, approved=2, rejected=3, withdrawn=4, cancelled=5. Type codes follow the order of `Hub::TYPES`.

| Type | Requested | Status column → report status | Decided by / at | Reason / note source | Approval key |
|---|---|---|---|---|---|
| **leave** (`ews_leave_requests`, every row) | `requested_at`, filled by the database clock because `leave_insert_request` never sets it | Pending→waiting; Approved→approved (also when shortened, or its cancellation was refused, withdrawn or ended); Rejected→rejected; Cancelled with `cancellation_status='Approved'`→cancelled; other Cancelled→withdrawn (`leave_withdraw`); anything else→cancelled | `reviewed_by/reviewed_at`; withdrawn: `cancelled_by/cancelled_at` | Last REJECTED step `comment` (engine only; direct decisions keep no reason) | `vacation/leave/id` |
| **leave_cancellation** (same table, rows with `cancellation_status IN ('Pending','Approved','Rejected','Withdrawn','Ended')`; `NULL` / `'Not Requested'` makes no row) | **No column.** Use the approval request's `created_at` (`entity_type='leave_cancellation'`). With leave approvals off, nothing records it (open question 2) | Pending→waiting; Approved→approved; Ended→approved, with a note (open question 3); Rejected→rejected; Withdrawn→withdrawn | Approved/Ended: `cancelled_by/cancelled_at`. Rejected: engine step only, because `leave_reject_cancellation` writes no decider. Withdrawn: the approval request's `completed_at` | Step comment. Shortened: `cancel_from` | `vacation/leave_cancellation/id` |
| **overtime** (`ews_overtime_requests`, may not exist) | `requested_at` (site clock) | Pending/Approved/Rejected; Cancelled→withdrawn (`overtime_withdraw`) | `reviewed_by/at`; withdrawn = the employee | Step comment | `overtime/overtime_request/id` |
| **early_leave** (`ews_early_leave_requests`) | `requested_at` | Same as overtime | `reviewed_by/at` | None (no approval engine) | none |
| **shift_swap** (`ews_schedule_swaps`) | `created_at` | Pending and `work_date >= today`→waiting (waiting for the colleague); Pending and the day passed→**expired** (open question 4); Awaiting→waiting (levels); Accepted/Approved→approved; Rejected/Declined→rejected; Cancelled→withdrawn | Colleague's answer: `responded_by/at`. Approver or administrator: `decided_by/at`. Approved at start: no one. A colleague's decline = Rejected with `decided_by` NULL and no approval request (rule of `swap_my_requests`) | Step comment; peer decline: "Declined by the colleague" | `shift_swap/schedule_swap/id` |
| **legacy_vacation** (`ews_vacation_requests`, may not exist) | `requested_at` | Pending/Approved/Rejected; anything else→cancelled | `reviewed_by/at`, often NULL on old rows → "—" | None | none |
| **correction** (`ews_attendance_corrections`) | `requested_at` | pending and pending_hr→waiting; approved; rejected (also closed by the system); cancelled→**withdrawn** (`CorrectionStore::withdraw`) | `decided_by/at` (HR or the last manager); manager stage: `manager_by/at`; withdrawn: the employee; closed by cron: `decided_by` may be 0 | `decision_note` (also the text of an automatic close), else the step comment; pending_hr: "Second level (HR)" | `attendance_correction/attendance_correction/id` |
| **hr_letter** (`ews_hr_letters`, from `LetterSchema`) | `requested_at` | pending→waiting; approved, issued and ready→approved, with a note "Approved, not issued yet" / "Issued" / "Ready to collect"; rejected; withdrawn; cancelled (HR cancelled it after approval) | **No `decided_by` column.** The decider comes from the engine's final step, which is always there (`LetterDecisions::decide` requires the approval). `decided_at`; cancelled: `issued_by/issued_at` | `decision_note`. The hub's own rejection stores "Rejected on the Requests page." | `hr_letter/hr_letter/id` |
| **face_reset** (option `ews_face_reset_requests`, one entry per employee) | `requested_at` (site clock) | pending/approved/rejected | `handled_by/handled_at` | Step comment, and only when that approval belongs to this request (below) | `face_reset/face_reset_request/employee_id` |

**Gaps (all stay as they are; the report shows "—")**
- **Face resets:** only the latest one per employee exists. `face_reset_clear_finished_approval` also deletes the previous finished approval request. A leftover approval request (workflow later turned off) can be older than the option's entry. Use the approval only when it fits: the option is pending and the approval is open, or the option is decided and the final step's `acted_by_wp_user_id` equals `handled_by`.
- **Leave cancellations decided directly** (approvals off): no decider when rejected, and no time of request.
- **Direct rejections** of leave, overtime and early leave keep no reason.
- **Shortened leave:** after a "from a day" cancellation is approved, `end_date` and `requested_days` are overwritten. The leave row shows the shortened leave; the original dates are only in the Audit Log (`leave_cancelled_from`).
- **Clocks differ:** `ews_leave_requests.requested_at` and `ews_approval_requests.created_at` come from the database clock (`DEFAULT CURRENT_TIMESTAMP`). Every other time comes from `current_time('mysql')`. On a database running UTC with a Cairo site, a leave asked at 00:30 on the 1st falls into the previous month (open question 17).

**Schema:** none needed. Optional later change: indexes on the request dates (section 2). Optional, only if the owner wants exact cancellation dates with approvals off: a `cancel_requested_at` column written in `leave_mark_cancellation_pending`. That touches the request path, so I do not recommend it for this release. `ews_schema_target()` stays at 3.31.158.

### 2. Query strategy

**Why a read model.** `check_modules.py` counts raw SQL in `trait-admin-requests.php` against other modules' tables as crossings. `check_layers.py` L1 counts references to layered tables (`employees`, `approval_*`, `attendance_corrections`, `hr_letters`). Either check fails if the trait gains SQL. The house pattern for a page that joins several modules' tables is a read model under `"reads"` (precedents: `WordPressCorrectionQuery`, `WordPressLetterQuery`). Rule L6 keeps it to reads only.

**The new slice in `modules.json`, approvals module, next to "engine" and "substitutes":**
```json
{"slice":"hub","namespace":"Requests","tables":[],"store":[],
 "services":["workforce-one/src/Requests/HubReports.php"],
 "reads":[{"file":"workforce-one/src/Requests/Data/WordPressHubReportQuery.php",
   "tables":["leave_requests","leave_types","overtime_requests","early_leave_requests","schedule_swaps","vacation_requests",
             "attendance_corrections","hr_letters","approval_requests","approval_workflows","employees"]}],
 "moved":[],"traits_clean":[]}
```
`check_layers.py` accepts an empty store list. Run it to confirm.

**`HubReportQuery` (interface) and `WordPressHubReportQuery` (only SELECTs)**
- `present(): array<string,bool>`
  - One `SHOW TABLES LIKE '{prefix}ews\_%'`. Parts whose table is missing are left out (overtime and legacy vacation, as `admin_requests_table_exists` handles them today).
- `counts(HubReportFilter $f): array<int,array<int,int>>` (type → status → n)
  - One query: a `UNION ALL` of one aggregate per part, `SELECT <t> t, <CASE> st, COUNT(*) n FROM … WHERE <date/employee/department> GROUP BY st`.
  - The status filter is not applied here; the page applies it afterwards, as the Corrections list does (`CorrectionListFilter->status`).
  - The page's total is the sum of the counts with the status filter applied, plus the face resets. No separate COUNT.
- `keys(HubReportFilter $f, int $limit, int $offset, ?array $before = null): list<object{t,id,at,emp}>`
  - `SELECT t,id,at,emp FROM (part1 UNION ALL part2 …) x [WHERE x.st=%d] [AND keyset] ORDER BY at DESC, t DESC, id DESC LIMIT %d OFFSET %d`.
  - The keyset condition (for the export) is `(at<%s OR (at=%s AND (t<%d OR (t=%d AND id<%d))))`.
- Detail reads by id list (≤ 500 per chunk, the store's habit): `leaves(ids)` (joins `leave_types` for the name; cancellations use it too), `overtime(ids)`, `earlyLeave(ids)`, `swaps(ids)`, `legacyVacation(ids)`, `corrections(ids)`, `letters(ids)`.

**Rules for the UNION**
- **Only integers, dates and ids leave the UNION.** Type and status are numbers and the CASE yields numbers, so string literals from tables with different collations never meet ("Illegal mix of collations"). The `hr_letters` table comes from `LetterSchema` with its own collation.
- **The CASE expressions are generated from one map,** `HubReport::STATUS_MAP` (pure, unit-tested). The same map gives the face resets' status in PHP, so the SQL and PHP cannot drift.
- **"Today" for expired swaps** is passed in as a parameter (`current_time('Y-m-d')`), never `CURDATE()`.
- **The cancellation part** reads `LEFT JOIN approval_requests ar ON ar.entity_type='leave_cancellation' AND ar.entity_id=r.id AND ar.workflow_id=(SELECT id FROM approval_workflows WHERE workflow_key='vacation')`, using the existing `KEY entity`. Its `at` is the fallback chain chosen in open question 2. Until the owner answers: `COALESCE(ar.created_at, r.cancelled_at, r.requested_at)`, shown with a "not recorded" hint when `ar` is missing.

**Filters**
- **Period:** `at BETWEEN 'from 00:00:00' AND 'to 23:59:59'`, on each part's request-date column (decision 6). Default: `current_time('Y-m-01')` to `current_time('Y-m-t')`.
- **Employee:** `x.employee_id=%d`; for swaps `requester_employee_id` (open question 8).
- **Department:** `JOIN employees e ON e.id=<employee column> AND e.department_id=%d` in each part. This is the employee's department now, archived employees included (open question 9).
- **Face resets** are filtered in PHP. For the department filter, one `people_by_ids()` call over the face-reset employees.

**Paging (20 a page, newest first): `HubReports::page()`**
- With no face resets in scope (type filter, or none in the period), the query uses `keys(LIMIT 20 OFFSET (p-1)*20)` directly.
- Otherwise it fetches `keys(LIMIT p*20)`, merges with the sorted face list `F` (at most one per employee) and slices. Page 500 means 10k short key rows: bounded and acceptable.

**Queries on one Reports page:**

| Step | Queries |
|---|---|
| Prime options (`prime_options`, already done) | 0 extra |
| `present` | 1 |
| `counts` | 1 |
| `keys` | 1 |
| Detail reads, only the types present on the page | ≤ 7 |
| `approval_find_requests` + `services()->approvalReads()->stepsOf()` | 2 |
| `people_by_ids` (requesters, swap colleagues, substitutes) | 1 |
| `people_names_by_user` (deciders) + `cache_users` for users with no employee | ≤ 2 |
| `people_department_names` | 1 |
| `people_active_list` for the picker | 1 |
| The waiting tab's count (below) | the hub's own reads |

The cost does not grow with the number of rows (pin it in the e2e with `EWSQ`).

**Large companies.** None of the request-date columns is indexed: leave, overtime, early leave and vacation `requested_at`, swaps `created_at`, corrections `requested_at`. `hr_letters` has `(status, requested_at)`. Each page view therefore scans each table about 2 times (counts and keys). Measured at roughly 375k leave rows (5k employees × 5 years), that should stay under about 1 s.
- Recommendation: no index in this release. The e2e seeds about 50k rows and records the time.
- Upgrade path if it is needed (next release, technical, owner informed): in the existing `ensure_*_schema()` functions (and `ensure_swap_schema`), `ALTER TABLE … ADD KEY requested_at (requested_at)` guarded by `SHOW INDEX` (the pattern of the 3.31.148 `acted_by` key in `ensure_approval_schema`), plus a bump of `ews_schema_target()`. InnoDB adds such an index online.

**The waiting tab's count:** `count($this->admin_request_rows())`, exactly the table of tab 1. On the Reports tab this costs the hub's own reads (about 12). The cheaper alternative, the report's own "waiting" count, is a different set: tab 1 lists expired swaps and hides corrections and letters when their feature is off.

### 3. Files and functions

**New pure code (`src/Requests/`, namespace `Requests`)**
- **`HubReportFilter.php`** (value object): `from`, `to`, `type` (0 = all), `status` (0 = all), `employeeId`, `departmentId`, `today`, `types` (present), `withFaces`.
- **`HubReport.php`** (pure, no WordPress):
  - `STATUS_MAP` per type, and `caseSql(string $type, array $columns): string` used by the read model.
  - `statusOfFace(string)`.
  - `filter(array $get, string $today): HubReportFilter`: Y-m-d check, a From/To given the wrong way round is swapped, type and status whitelists, page ≥ 1.
  - `parseWho(string): array{0:string,1:int}`: one "Employee or department" field (`dept:12` / `emp:34`; employees use the `Support\Picker` label "Name · #34").
  - `outcome(list<object> $steps): array{levels:int,level:int,decision:string,by:int,for:int,at:string,comment:string}`: positions counted without SKIPPED steps, as in `approval_paths`; the REJECTED step wins; otherwise the last APPROVED; CANCELLED steps mean withdrawn.
  - `decided(...)`: "who · when", with " (level n)" when levels > 1, "(HR)" for the corrections HR stage, "X for Y" for a substitute.
  - `note(...)` (the table under Edge cases), `details(...)` (from parts already formatted), `tiles(counts, status)`, `exportHeaders()`, `exportRow(...)`.
  - `mergeFaces(keys, faces, offset, per)`.
- **`HubReports.php`** (service, clean under L2): `__construct(HubReportQuery $q)`; `page(HubReportFilter, list $faces, int $page, int $per=20): array{counts,total,keys}`; `batches(HubReportFilter, list $faces, int $size): \Generator<list>` (keyset over `keys()`, merged with the faces).
- **`Data/HubReportQuery.php`** + **`Data/WordPressHubReportQuery.php`** (the read model described in section 2).
- **`src/Platform/Services.php`**: `hubReportQuery()` and `hubReports()` factories, built with `$wpdb` and the employees table name as `letterQuery()` is.

**Approvals module: `includes/trait-admin-requests.php`**
- `hub_reports_enabled(): bool`, which is `(bool)$this->option('ews_feature_hub_reports')`. Add it to the approvals `api`.
- `admin_requests()`:
  - First line after the permission check: `if(!$this->hub_reports_enabled()){ …today's body, unchanged… }`.
  - When on: `$tab = sanitize_key(wp_unslash($_GET['tab']??''))==='reports'?'reports':'pending'`.
  - Pending: today's variables plus `'tabs'=>$this->admin_requests_tabs('pending',count($rows))`.
  - Reports: `render_template('admin/requests-reports', $this->admin_requests_report_data())`.
- `admin_requests_tabs($current,$waiting)`: labels and URLs.
- `admin_requests_report_data()`: filter → `services()->hubReports()->page()` → `admin_requests_report_rows($keys)`; plus tiles, pager (`Ui\ListPage` if it fits, else prev/next), `export_urls` (`wp_nonce_url(add_query_arg([...filters,'action'=>'ews31_requests_export','format'=>…],admin_url('admin-post.php')),'ews31_requests_export')`, as `report_export_urls` does), departments, picker list.
- `admin_requests_report_rows(array $keys, bool $forExport=false)`:
  - Detail reads per type; approval keys built from the table in section 1; `approval_find_requests` + `services()->approvalReads()->stepsOf`; people and decider names.
  - Then `HubReport::row()`, with dates through `date_i18n('j M H:i')` on screen and raw `Y-m-d H:i:s` in the export.
- `admin_requests_faces()`: normalizes `face_reset_requests()`. Add `face_reset_requests`, `cx_type_label` and `cx_change_text` to the attendance `api`, plus `vacation_working_days_count` to the leave `api` if the "N days back" detail is kept. Without that, the new calls raise `approvals->attendance` above the baseline. With it, the counts go down; run `check_modules.py --update`.
- `admin_requests_export()`:
  - Checks: `manage_options`, then `check_admin_referer('ews31_requests_export')`, then `wp_die` when the switch is off.
  - For each batch from `batches(…, (int)apply_filters('ews_hub_export_batch', 2000))`: fill the rows, `RowSpool::add()`, `ExportTime::extend()` between batches.
  - Then `report_csv_stream()` or `report_xlsx_workbook()`.
  - Audit: `audit('requests_report_export','report',0,"CSV|Excel · from..to · type · status · who · rows")`.
  - Send: CSV with a BOM and `fpassthru`, as `report_download` does; Excel through `Support\Download::send`.
- **`src/Requests/Hooks.php`**: `add_action('admin_post_ews31_requests_export',[$plugin,'admin_requests_export'])`. Always registered; listed in `tests/golden/expected.json` → `hooks_added`.

**Export reuse (`includes/trait-reports.php`).** Pure extract-method; the Report Center bytes do not change (protected by `tests/golden/actions_reports.php`). Add both functions to the reports `api`.
- `report_csv_stream(array $headers, iterable $rows)`: the body of `report_detailed_csv` after `report_export_table`.
- `report_xlsx_workbook(array $table, string $title, string $subtitle, array $widths, string $start, string $end, bool $definitions)`: the body of `report_xlsx_build_parts` after the table is read, wrapped with the ZipArchive check, temporary files and `finally` of `report_xlsx_build`. The hub passes its totals as `kpis` (6 fit the KPI styles), `definitions=false` and its own subtitle. The plain-sheet alternative uses `report_xlsx_table_sheet` (open question 13).

**Templates and assets**
- `templates/admin/requests.php`: after the `<p …>` intro line, add exactly one line with no indent: `<?php if (!empty($tabs)): include __DIR__ . '/requests-tabs.php'; endif; /* Reports tab (off: nothing printed) */ ?>`. PHP drops the newline after `?>`, so the output is byte for byte the same when off. Add `@var array|null $tabs`.
- `templates/admin/requests-tabs.php` (new): `nav-tab-wrapper`, with the count on tab 1.
- `templates/admin/requests-reports.php` (new):
  - The same `wrap`, h1 and intro as today, then the tabs.
  - A GET form (`page`, `tab`, `from`, `to`, `type`, `status`, `who` + datalist, "Show"); the export buttons; the tiles; the table (Request, Employee, Details, Status pill, Requested, Decided, Note); the pager; the empty state; the "View only" description.
  - No form posts anywhere.
- `assets/css/admin-requests.css` + `-rtl.css`, from `docs/mockups/hub-reports/hub-reports*.css`. Enqueue them in `includes/trait-admin.php:enqueue_admin_ui_foundation` only when the hook suffix ends with `ews31-requests` and `hub_reports_enabled()`. Off, the page `<head>` is unchanged.

**The switch**
- `src/Settings/Options.php`: `'ews_feature_hub_reports' => ['group'=>'features','label'=>'Requests Hub reports (a Reports tab: every request in every status, view only, CSV and Excel)','kind'=>'bool','default'=>false,'page'=>'ews31-features']`. It is primed by `prime_options()`.
- `includes/trait-features.php:admin_features`: add `'hub_reports'=>$this->hub_reports_enabled()` to `on`.
- `includes/trait-features.php:features_save_handler`: `if(isset($post['hub_reports_present']) && $this->hub_reports_enabled()!==$flag('hub_reports_enabled')){ update_option('ews_feature_hub_reports',$flag(...)?1:0,false); $this->audit('hub_reports_feature_update','settings',0,$flag(...)?'enabled':'disabled'); }`. It writes and audits only on a change (the 3.31.152 / 158 pattern).
- `templates/admin/features.php`: a section `id="ews-hub-reports"` with the title, the description and the "off, exactly as today" note from the mockup, the hidden `hub_reports_present` field and `$switch('hub_reports_enabled', $on['hub_reports'])`.

**Translations.** Every new string goes through `__()` / `esc_html_e(..., 'workforce-one')`:
- tab labels, filters, status names, notes, tiles, export headers;
- the Features texts;
- the Audit Log label, if the dashboard maps audit actions.

Regenerate `languages/workforce-one.pot`, `-ar.po`, `.mo` and `.l10n.php` (no JS, so no JSON). The existing hub text stays English as today.

**Docs and housekeeping**
- `docs/tasks/hub-reports.md`: add this design.
- `docs/architecture/modules.json`: the slice and the `api` additions; baselines via `--update`, only lower.
- `docs/architecture/modules.md`: one line.
- `CHANGELOG.md`, version 3.31.159.
- `.github/workflows/ci.yml`: add `tests/e2e_hub_reports.py`.

### 4. Off by default and byte-identical when off

- The default `false` is in `Options.php`. Nothing is written on upgrade; there is no schema change.
- With it off, `admin_requests()` runs today's code in the same order: the same queries, and one primed option read.
- The `?tab=…` and filter parameters are not read.
- No `tabs` variable is passed, and the one new template line prints nothing.
- No CSS is enqueued.
- The export handler `wp_die`s. Its hook is new and declared in `expected.json`.
- Golden: keep the three existing `ews31-requests` pages. Add `…&tab=reports` and `…&tab=reports&type=leave&status=3&from=2026-01-01` with the switch off; they must be identical and use no more queries. In `expected.json`, list only the two Features pages (they change on purpose) and the new admin-post hook. Unchanged `actions_reports` proves the Report Center exports are untouched.

### 5. Edge cases (rules the code follows)

**Dates and timezones**
- From and To are inclusive whole days on the site's clock.
- The default is the current month by `current_time` (February and leap years are unit-tested).
- From > To is swapped; an invalid date falls back to the default.
- Leave and approval timestamps are on the database clock (open question 17); shown as stored, as the hub does today.

**Approval levels**
- Multi-level: "Level n of N" (non-skipped positions) on a waiting row.
- On a decided multi-level row, the decider shows "(level n)".
- Rejected at level 1 of 2: status Rejected, decided by the level 1 person "(level 1)", note = that step's comment.
- Administrator override: `acted_by` is the administrator, whose name is shown.
- Substitute: "Sami for Mona".
- Engine approved at start (every level skipped): decider "—" or "Automatically" (open question 12).

**Swaps**
- Declined by the colleague: Rejected, decided by the colleague (`responded_by/at`), note "Declined by the colleague".
- Rejected by an approver: `decided_by/at` plus the step comment.
- Accepted with no levels: Approved by the colleague.
- Withdrawn: the requester.
- Expired: open question 4. Tab 1 still lists it as waiting today; that stays unchanged.

**HR letters**
- Issued, ready and cancelled are events after the decision. "Decided" shows the approval; the event goes in the Note: "Issued 4 Nov", "Ready to collect", "Cancelled by HR: <reason>".
- Status is Approved for issued and ready, Cancelled for cancelled (open question 5).

**Corrections**
- pending_hr is waiting, with note "Second level (HR)".
- Approved by HR: "Name (HR)".
- Closed by the system: Rejected with the stored note, decider "—" when `decided_by=0`.
- Withdrawn by the employee: stored as `cancelled`, shown as Withdrawn.

**Leave**
- Cancelled after approval: the leave row is Cancelled, with the leave's own decision and the note "Cancelled at the employee's request". The cancellation row is Approved with its own decider (`cancelled_by/at`).
- Shortened: the leave row stays Approved, note "Shortened: now ends on X", details show today's dates. The cancellation row reads "From X · N days back".
- Cancellation Ended: approved, with note "Decided after the leave had ended: nothing changed" (open question 3).
- Cancellation withdrawn: the leave row is Approved, the cancellation row Withdrawn.
- Recorded by an administrator on the Leaves page: an Approved row, decided by that administrator.

**Employees and users**
- Archived employees keep their names (`people_by_ids` includes inactive ones). A missing employee row shows "Employee #id", as the hub does today. Under a department filter, rows of a missing employee drop out.
- A decider with no employee falls back to `display_name`; a deleted user shows "—".

**Features and tables**
- Tables that do not exist are left out of the report.
- Corrections and HR letters while their feature is off: open question 6.

**Large exports**
- Keyset batches of 2000 (filter `ews_hub_export_batch`), held in `RowSpool` so memory stays flat.
- `ExportTime::extend()` per batch.
- `Csv::row` protects against formula injection; the Excel cells are inline strings.
- A failed batch sends no file: the same rule as 3.31.149, wrapped in `RuntimeException`.

**Ordering**
- A stable order on equal timestamps (`at DESC, t DESC, id DESC`). The face-reset merge uses the same comparison.

### 6. Tests

**Unit: `tests/unit/HubReportTest.php`**
- The status map, per type and per stored value, table-driven:
  - It agrees with `MyRequestsLeave::itemState` except for the intended split (Approved with cancellation Pending: the leave row stays approved), with `SwapInbox::state`, and with the corrections state map at `trait-corrections.php:1109`.
  - `caseSql()` output for every map.
- `filter()`: default month, swapped dates, bad input, whitelists. `parseWho()` with the Picker labels.
- `outcome()`: one level; 2 levels rejected at 1; skipped levels; administrator override; substitute; cancelled steps.
- `note()` / `decided()` for every row of the table in section 1.
- `mergeFaces()` at page boundaries; the tiles with and without a status filter; `exportRow()` and the headers.
- `OptionsTest` covers the new option automatically.
- `HubReports` with a fake `HubReportQuery`: paging and keyset batches give every key once, in order.

**e2e: `tests/e2e_hub_reports.py`** (new; seeds each type and status by SQL, as `e2e_admin_requests.py` does)
1. **Off:** no `nav-tab-wrapper`; `?tab=reports` shows today's page; the export is refused; the Features switch is shown off; no option row is stored.
2. **Switch:** turning it on writes one `hub_reports_feature_update` audit row; saving again unchanged writes none; turning it off audits "disabled".
3. **Tab 1, on:** the `<table>` is byte-equal to the off page; the tab count equals the number of rows; decisions still work and redirect to tab 1.
4. **Reports:** the default period is this month; boundary rows at 00:00:00 on the 1st and 23:59:59 on the last day are in, the previous month is out; the totals equal the seed.
5. **Filters:** type, status (one tile), employee, department (an archived employee included); the mockup example Leave + Rejected + Sales shows the reasons.
6. **Order and paging:** 45 rows including face resets → 3 pages, no duplicate and none missing.
7. **Decided and Note per source:** engine, override, direct, peer, face; "—" for legacy rows and for a cancellation rejected directly; rejected at level 1 of 2; pending_hr; an HR letter issued or cancelled; a leave shortened or ended; an expired swap.
8. **View only:** no `ews31_requests_decision` form on the Reports tab, and the rows of every request table are unchanged after browsing.
9. **Permissions:** emp1, and a user with `ews_manage_settings` but without `manage_options`, get access denied on the page and on the export; the export without a nonce is refused.
10. **Export CSV and Excel:** BOM; the CSV row count equals the screen total across every page, in the same order; a `=cmd` value is neutralized; Arabic text survives; the xlsx opens and its KPIs equal the tiles; one audit row with the filters.
11. **Large export:** 5k seeded rows with `ews_hub_export_batch` at 300 give the same file as with 100000; peak memory recorded.
12. **A missing overtime or legacy table:** the page and the export still work.
13. **Query count** of the Reports page through the `EWSQ` mu-plugin: the same with 20 or 2000 rows.
14. **Cairo site** (as in `e2e_timezone`): which month a request falls into follows the stored time.
15. **Arabic locale:** labels translated, the RTL CSS loaded.

Also run, unchanged: `e2e_admin_requests.py`, `e2e_features.py`, `e2e_reports*.py`, `e2e_export_batches.py`, `e2e_leave_requests.py`, `e2e_swap*.py`, `e2e_corrections.py`, `e2e_hr_letters*.py`.

**Golden:** the pages and `expected.json` entries from section 4; `actions_reports` unchanged.

**Static checks:** `check_layers.py` (new slice: L2, L3, L4, L6), `check_modules.py` (no pair worse), `check_translations.py`, PHPStan (template `@var`s, the new classes), phpcs.

### 7. Risks

1. **SQL and domain rules drifting apart.** Mitigated by generating the CASE from `STATUS_MAP` and by the unit tests that check it against `itemState` and `SwapInbox::state`.
2. **Slow pages on large, old sites** (no date indexes). Measure in the e2e; the index upgrade path is ready (section 2).
3. **The Report Center refactor** (two extracted functions) changing export bytes. Guarded by golden `actions_reports`.
4. **Collation errors in the UNION** if any string column is added to it. The rule is integers and dates only, plus an e2e on a site where `hr_letters` uses a different collation.
5. **Module and layer checks:** new non-api calls (`face_reset_requests`, `cx_*`) raise the baseline. Extend the `api` lists first.
6. **Data exposure:** the export holds reasons and letter details (no salary amounts). It is limited to `manage_options`, behind a nonce, and audited.
7. **Approximate data shown as fact:** cancellation request dates with approvals off, deciders on old rows, face-reset approvals that belong to an older request. Label these clearly ("—", "not recorded").
8. **Tab 1 and Reports disagreeing** (expired swaps, features that are off). Document it, or settle it through open questions 4 and 6.
9. **The waiting count** costs the whole tab 1 read on the Reports tab.
10. **Export time** on shared hosting: per-batch extension, and nothing is sent until the file is complete.

### 8. Open questions for the owner

1. **A leave cancelled after its approval:** should its own row show the original approval as "Decided" (proposed) or the cancellation's decider? And should a shortened leave stay Approved with a "Shortened" note?
2. **Requested date of a leave cancellation made while leave approvals were off:** nothing records it. Show a fallback date marked "not recorded", leave such cancellations out of date filtering, or add a column from now on (a schema change)?
3. **A cancellation approved after the leave had ended** (nothing changed): Approved with a note, or another status?
4. **A swap whose day passed before the colleague answered:** which of the five statuses? (Proposed: Cancelled, "The day passed".) Tab 1 lists these as waiting today.
5. **HR letters:** approved, issued and ready all shown as Approved with a note? A letter HR cancelled after approval as Cancelled, with HR's reason in the note and the approval in "Decided"?
6. **Corrections and HR letters while their feature is off:** show their history in Reports, or hide them as the hub does?
7. **Legacy vacation:** add it to the Type filter (the mockup does not have it), or fold it into Leave?
8. **Employee filter on a swap:** the requester only, or the colleague as well?
9. **Department filter:** the employee's department now, archived employees included? The department at the time of the request is not stored.
10. **Archived employees:** mark them "(archived)"? Offer them in the employee picker?
11. **Face resets:** accept only the latest per employee, or rebuild older ones from the Audit Log?
12. **Decisions nobody took** (every level skipped, corrections closed automatically): show "Automatically" or "—"?
13. **Export columns:** add Department, the reference ids and plain date-time columns? Excel in the branded Report Center layout with the totals as cards (proposed), or a plain sheet?
14. **Totals:** keep "withdrawn or cancelled" as one tile, as in the mockup, while the filter has them apart?
15. **Indexes:** may a later release add indexes on the request dates (a small schema change) if large sites are slow?
16. **Intro sentence:** keep today's "pending requests" wording on both tabs? The mockup drops "pending".
17. **Leave and approval times** are recorded on the database clock, which can differ from the site's time zone: show them as stored, or correct them?

#### Critical Files for Implementation
- /home/user/WFplugin/workforce-one/includes/trait-admin-requests.php
- /home/user/WFplugin/workforce-one/src/Requests/Data/WordPressHubReportQuery.php (new) with /home/user/WFplugin/workforce-one/src/Requests/HubReport.php (new) and /home/user/WFplugin/workforce-one/src/Requests/HubReports.php (new)
- /home/user/WFplugin/workforce-one/templates/admin/requests.php (one line) with /home/user/WFplugin/workforce-one/templates/admin/requests-reports.php (new)
- /home/user/WFplugin/workforce-one/includes/trait-reports.php (the extracted `report_csv_stream` / `report_xlsx_workbook`)
- /home/user/WFplugin/docs/architecture/modules.json (the "hub" slice and the `api` additions), with /home/user/WFplugin/workforce-one/src/Settings/Options.php, /home/user/WFplugin/workforce-one/includes/trait-features.php and /home/user/WFplugin/tests/golden/expected.json
