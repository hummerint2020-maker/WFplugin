# Attendance cards you can open (3.31.125): design

The read-only architect wrote this design before implementation. Sources: the owner's request of 2026-10-25, the five fixed decisions, the approved mockups (`docs/mockups/attendance-cards/`, generator `docs/mockups/_kit/screens_attcards.py`) and the code at `709a57b`. Product questions are in §13, and none of them are settled here. Where a default is needed so implementation can start, it is labelled **default, pending Qn**.

---

## 0. What exists today (facts the design depends on)

- **The page.** `attendance_content()` is at `workforce-one/includes/trait-attendance-grid.php:15-100`.
  1. Employees: `department_scoped_employees()` → `attendance_enabled` → `schedule_employees_ordered()` (TeamOrder, the viewer first) (`:18`).
  2. Dates: `week_dates()` → `week_dates_configured($_GET['week'])` (`trait-work-time.php:277`). With 0 working days it returns `[]` and the page uses `[$today]` (`:21`).
  3. Holidays: `company_leave_dates()` (`:23`).
  4. Branch filter: `$_GET['branch']`, only when there is more than one branch and the mode is not `single`. It keeps people whose **main or other** branches include it (`:28-36`).
  5. `Ui\ListPage::apply($emps, $_GET)` (`:37`). **Up to 60 people the server ignores `q` / `team`.** In that case the browser hides rows only (`attendance-grid.js:205-224`), and the cards count everyone. Above 60, `q` / `team` / `pg` run on the server.
  6. `insights_load()` on the matched list (`:40`): the schedule, the first non-sign-out event and the last `sign_out` per employee and day (`trait-attendance-insights.php:14-30`).
  7. A loop over `matched × dates` calls `insights_day()` and fills `$summary[Present|Late|Absent|Leave]`, `$day_summary` and the page's `$days` (`:43-56`).
  8. Cards (`templates/app/attendance.php:105-111`): Employees = `$list['total']`. Present / Late / Absent = `$summary[...]`. Rate = `Insights::rate(P,L,A)`.
- **Day result.** `insights_day()` (`trait-attendance-insights.php:33-50`) feeds `Insights::actual()` (`src/Attendance/Insights.php:33-41`):
  - **Leave**: a company holiday, a leave rule, or the status `Leave`.
  - A business-trip type returns its own name.
  - **Not Scheduled**: the type does not require sign-in.
  - **Late**: there is a sign-in, and either the first event is a legacy `late_sign_in` or `sign_in_classification()` = `Late Arrival`.
  - **Present**: any other sign-in.
  - **Absent**: no sign-in on a past day, or today after the cutoff.
  - **Pending**: anything else.
- **Late rule.** `sign_in_classification($event_at,$eid)` (`trait-work-time.php:231-236`):
  - It uses `working_hours($eid, substr($event_at,0,10))`. That is the shift, or the company hours, of **the calendar day of the sign-in**, with the shift history (3.31.108).
  - It then calls `Lateness::classify()` (`src/Attendance/Lateness.php`): `dayOf()` moves a sign-in at or before the shift end on an overnight shift to the day before, and Late = `ts > shiftStart + grace*60`.
- **Late minutes elsewhere.** `Reports\DayMetrics` counts late minutes **from the shift start** (`intdiv(in − start, 60)`, `DayMetrics.php:66`). The approved mockup does the same: start 09:00, grace to 09:10, 09:23 shows "23 min late" and 10:02 shows "62".
- **Absent-after cutoff for today.** `normal_until` = shift start + `ews_absent_after_minutes` (default 120), capped at a non-overnight end (`trait-work-time.php:181-187`). Company hours use `ews_working_hours.normal_until`.
- **Lazy loading.** The plugin has no admin-ajax actions. Logged-in app features use `admin-post.php` actions with a nonce. JSON precedents: `push_subscribe` (`trait-notifications.php:486`, `wp_send_json_success`) and the GET + nonce PDF `schedule_pdf` (`trait-schedule-view.php:75,131`). The REST table (`src/Api/Routes.php`) is for Face and native-token routes and has an OpenAPI check. A web-cookie read route there would be a new kind. **Use admin-post.**
- **`app_view_url()` is built from the current request URL** (`trait-frontend.php:138`). From admin-post it would produce an `admin-post.php` link. So every app link the sheet needs (Reports, profile) is built on the page and passed in markup.
- **The sheet.** `assets/js/sheet.js` handles `dialog.wfo-sheet`: `wfoSheet.open(dialog, opener)`, Esc, the close button, tap outside, and focus back to the opener. Styles are in `assets/css/app-requests.css:65-96,285-290`: a centred dialog on a computer, a bottom sheet at ≤700px. Office minimum enqueues `workforce-one-requests` + `workforce-one-sheet` on this page only when it is on (`trait-office-minimum.php:100-106`). That is the precedent.
- **Shift-period priming.** `attendance_content()` does not call `shift_prime_periods()`. On past weeks, every employee with a sign-in costs one `shift_periods_of()` query. Reports do prime (`trait-reports.php:303-304`). This is an existing cost; see §11.

---

## 1. Data flow: the sheet always counts what the card counts

### 1.1 One scope, one loop (refactor, behaviour-preserving)
Split `attendance_content()` into two shared private functions in `trait-attendance-grid.php` (schedule module), and call both from the page and the endpoint.

1. **`attendance_scope(array $get)`** holds everything from `:17` to `:39`, reading only `$get`, never `$_GET`. The page passes `wp_unslash($_GET)` and the endpoint passes its own GET.
   - It returns: `current_emp_id`, `all_emps`, `matched` (= `$list['matched']`), `list`, `dates`, `sun`, `today`, `week_end`, `holidays`, `branch_filter`, `branch_filter_names`.
   - Week: `week_dates_configured(sanitize_text_field($get['week'] ?? today))`, exactly what `week_dates()` does today, including the `[$today]` fallback.
   - The branch rule, `ListPage::apply($emps, $get)` and its LIMIT/q/team behaviour stay unchanged.
2. **`attendance_walk(array $emps, array $dates, $today, $holidays, callable $each)`** loads `insights_load()` once, then for each employee (in grid order, with its index `$pos`) and each date calls `insights_day()` and `$each($pos, $e, $d, $planned, $day, $ev)`. It returns `[$schedule, $events]`.
   - The page's closure fills `$summary`, `$day_summary` and `$days` exactly as `:46-56` does.
   - The endpoint's closure collects rows for one kind.

So the card and the sheet come from the same employees, the same dates, the same holiday map, the same `insights_day()` and the same `actual` values. A test asserts sheet total = card number for every kind (§12).

### 1.2 What each kind collects (`$day['actual']` from `Insights::actual`)

| Kind | Card | Rows | Count |
|---|---|---|---|
| `people` | `$list['total']` | one per matched employee: counts of Present, Late, Absent, Leave over the week | `count(matched)` |
| `present` | `$summary['Present']` | person-days with `actual === 'Present'` (on time only) | rows |
| `late` | `$summary['Late']` | person-days with `actual === 'Late'` | rows |
| `absent` | `$summary['Absent']` | person-days with `actual === 'Absent'` | rows |
| `rate` | `Insights::rate(P,L,A)` | per date `Insights::rate(Pd,Ld,Ad)`; per person `Insights::rate(p,l,a)` | the week's rate (no paging) |

Pending, Not Scheduled and trip results appear in no list, just as in no card. Leave is not a card. It appears only as the 4th mini-count in `people`, and it includes company holidays because `insights_day()` returns Leave for a holiday (see Q5).

### 1.3 Lazy, light, paged
- **Page load.** Nothing extra is computed. With the switch on, the page adds only markup, one nonce, and the already-registered `workforce-one-requests` / `workforce-one-sheet` assets plus the new script. There are no extra queries (§12, SAVEQUERIES).
- **Opening a card.** One GET request. The server runs the scope and walk for the whole matched list, because counts need everyone: 3000 × 7 = 21,000 `insights_day()` calls. The page already does exactly this on every load.
  - The walk closure keeps only **light keys** for the requested kind: `[pos, date, eid]`, or per-person counters for `people` and `rate`.
  - It sorts with `CardLists::order()`, slices `offset … offset+20`, and only then **enriches those 20 rows**: name, team, picture, times, minutes late, planned status and branch.
- **Bulk priming before the walk.** The endpoint, not the page, calls `prime_shift_cache($matched)` (already primed via `emps()`, but harmless) and `shift_prime_periods(ids)`. The endpoint's query count is then constant in N, roughly 12-15 queries. The page's per-employee `shift_periods_of()` cost is not changed in this release (off must change nothing; see §11 R6).
- **"Show more".** The same GET with `offset += 20`, appended to the list. It is recomputed each time, with no cache.
  - Risk: a sign-in between clicks can shift rows by one (§11 R3).
  - Optional mitigation, if review asks for it: a 2-minute per-user transient holding the ordered key list for the `kind+order+filters` hash. Not in the first cut.
- **Ordering** (`CardLists::order`):
  - **By day** (default): dates ascending, then grid position (TeamOrder, the same order as the page; the mockup's rows are not time-sorted).
  - **By employee**: grid position, then date ascending.
  - `people`: grid order.
  - `rate` "lowest": rate ascending, then more absent days first, then grid position; at most 10, only `required > 0 && rate < 100`.
  - Paging counts rows, not groups. A group header continues across "Show more": JS appends to the last group when the date (or employee) is the same.
  - Within-day and lowest-list ordering is **default, pending Q8**.

---

## 2. "Minutes late": the exact definition

For a row with `actual === 'Late'` and `$in = $events[eid][d]['sign_in']`:

```
$ts  = strtotime($in->event_at);
$cfg = working_hours($eid, substr($in->event_at, 0, 10));   // the same call sign_in_classification() makes
$min = Lateness::minutesLate($ts, $cfg['start'], $cfg['end'], !empty($cfg['overnight']));
```

New pure function in `src/Attendance/Lateness.php`:

```php
/** Whole minutes after the shift start of the work day this Sign In counts against (0 when not after it). */
public static function minutesLate(int $ts, string $start, string $end, bool $overnight): int
{
    return max(0, intdiv($ts - self::shiftStart(self::dayOf($ts, $start, $end, $overnight), $start), 60));
}
```

- **Counted from the shift start, not from the end of grace.** This is the DayMetrics and Reports convention and the approved mockup: 09:23 is "23 min late" with start 09:00 and grace 10. Grace only decides *whether* the day is Late. `normal_until` (absent-after) plays **no part** in minutes late. It only decides when today's no-show turns from Pending to Absent.
- **Which shift.** The shift the employee was on on the **calendar day of the sign-in**, with that day's times from the shift history (`shift_for_employee_on` → `shift_times_on`). Today uses the current shift. With no shift, the company working hours of that day apply: `shift_times_on(0, date)`, else `ews_working_hours`, grace `ews_grace_period`.
  - This copies `sign_in_classification()` exactly, so a row is never "Late, 0 min" because of a different anchor. The one exception is the legacy case below.
- **Overnight (22:00-06:00).**
  - A sign-in at 22:35 on D gives 35 min.
  - A sign-in at 00:15 on D+1 (work_date D) is moved by `dayOf()` to D. The start is D 22:00, giving 135 min.
  - The row shows the time "00:15" with a small "next day" mark when `date(event_at) ≠ work_date` (**default, pending Q10**).
  - Existing behaviour kept: for that row, `working_hours()` is read for D+1, not D. If the employee's shift changed exactly on D+1, the D+1 shift is used (§11 R4).
- **Legacy `late_sign_in` events.** These are Late whatever the time. `minutesLate` can be 0 (or the sign-in can be before the start). The response then sends `late_min: null` and the row shows a plain "Late" pill without minutes (**default, pending Q9**).
- **Missing times.**
  - No sign-in: never Present or Late.
  - No sign-out: `out: null` and a "No sign-out" pill (see §6 and Q7 for today).
  - Events come only from live rows (`tl_live()`), so a corrected event shows the corrected time, as the grid does.
  - A sign-out earlier than the sign-in is shown as-is, the same as `insights_load`. It is not hidden like in DayMetrics, so the sheet matches the grid's data.

The header note under the Late title reads: "Start 9:00, late counts after 9:10 (each person's own shift)".
- It is used when all rows on the current page share one start and grace.
- Otherwise the generic text is "Late counts from each person's shift start, after its grace period".
- The server sends `note: {start, late_after} | null`.

---

## 3. The endpoint

- **Action:** `admin_post_ews_att_cards`, registered next to the other `ews31_att_*` actions (`employee-schedule-manager.php:165-169`). It is logged-in only; there is no `nopriv`.
- **Method:** GET (read only), from `fetch(url, {credentials:'same-origin', cache:'no-store'})`.
- **Parameters:**
  - `action=ews_att_cards`
  - `_wpnonce` (action `ews_att_cards`)
  - `kind` ∈ `people|present|late|absent|rate`
  - `order` ∈ `day|employee` (ignored for people and rate)
  - `offset` (absint, a multiple of 20; clamped to total)
  - `week`, `q`, `team`, `branch`: copied from what the page applied. Only the server-applied values are sent: `q` and `team` only when `$list['paged']`, `branch` only when `$branch_filter`.
  - The page size is a server constant (`CardLists::PER = 20`) and is not accepted from the client.
- **Handler `att_cards_data()`.** Order of checks:
  1. `if(!$this->attendance_cards_enabled())` → `wp_send_json_error(['reason'=>'off'],404)`.
  2. `wp_verify_nonce($_GET['_wpnonce'],'ews_att_cards')` fails → `wp_send_json_error(['reason'=>'nonce'],403)`. Do not use `check_admin_referer`, which prints an HTML die page.
  3. `!$this->can('ews_manage_attendance')` → `403 ['reason'=>'access']`.
  4. Validate `kind`/`order`. Then `nocache_headers()`.
  5. `$s = $this->attendance_scope($get)`. **Department scope comes from `department_scoped_employees()` inside the scope**: admins see all, a manager sees their department, a manager without a department sees no one. It is the same call the page makes.
  6. Prime shifts, walk, order, slice, enrich, `wp_send_json_success($data)`.
  7. Nothing is written and nothing is audited.
- **Response** (`data`). Plain data only: no HTML, no URLs except picture URLs (`esc_url_raw`).

```json
{ "kind":"late", "order":"day", "total":14, "offset":0, "next":20, "card":14,
  "note":{"start":"09:00","late_after":"09:10"},
  "rows":[{"employee_id":12,"name":"Ahmed Samir","sub":"Sales","initials":"AS","tone":2,"picture":"",
           "date":"2026-10-18","in":"09:23","out":"17:31","missing_out":false,"next_day":false,
           "late_min":23,                        // late only (null = legacy, no minutes)
           "plan":"Office","plan_class":"office","branch":"Maadi"}] }   // absent only (branch only in "By the schedule" mode)
```

  - `people` rows: `{employee_id,name,sub,initials,tone,picture,present,late,absent,leave}`.
  - `rate`: `{"rate":97,"days":[{"date":"2026-10-18","rate":97|null,"attended":38,"required":39}],"lowest":[{…person…,"rate":60,"attended":3,"required":5}]}`. A date with `required = 0` sends `rate: null` and is shown as "—".
  - `next` is `null` when no more rows remain. `card` repeats the number the card shows, recomputed now (for the stale-page note, Q6).
  - `sub` = `_schedule_primary_team`, else `domain_name`. `tone` = `id % 5` and `initials` = `ProfileSummary::initials()`, as the grid does.
- **Escaping.**
  - JSON: `wp_send_json` (`wp_json_encode`).
  - JS builds every node with `createElement` and `textContent`, and never `innerHTML` with data. `img.src` is set only from the server's `esc_url_raw` value.
  - Icons come from `<template>` elements rendered by PHP (`Icons::svg`) and are cloned.
- **Locale.** No translated text is in the response. Day labels, pills and notes come from the page's markup (§5), so admin-post's user-locale context does not matter.
- **Errors in the sheet:**
  - `nonce`: "This page has expired. Reload it."
  - Network or 5xx: "Could not load the list." with a Retry button.
  - `off` or `access`: "Not available."

---

## 4. Files and functions

### PHP
- **`includes/trait-attendance-grid.php`** (schedule module):
  - Extract `attendance_scope(array $get)` and `attendance_walk(...)`, and rewrite `attendance_content()` on top of them. The output must be byte-identical.
  - When `attendance_cards_enabled()`, `attendance_content()` passes extra template vars (`cards` below) and enqueues `workforce-one-requests`, `workforce-one-sheet` and `workforce-one-attendance-cards`. When off, it passes `cards => null`.
- **New `includes/trait-attendance-cards.php`** (`EWS_Attendance_Cards_Trait`, schedule module, next to the grid):
  - `attendance_cards_enabled()` = `(bool)$this->option('ews_feature_attendance_cards')`.
  - `attendance_cards_view(array $s)`: the page config described in §5.
  - `att_cards_data()`: the handler.
  - Private enrich helpers: `att_cards_row($e,$d,$day,$ev,$kind)` and `att_cards_late($eid,$in)`, which uses `working_hours` and `Lateness::minutesLate`.
  - Absent rows in "By the schedule" mode use `branch_planned_bulk($ids20,[$d…])` for the 20 rows only.
- **`employee-schedule-manager.php`:** `require_once` the new trait, add it to the `use` list, and add `add_action('admin_post_ews_att_cards',[$this,'att_cards_data'])`. Bump Version / `EWS_VERSION` to 3.31.125.
- **`includes/trait-frontend.php:~281`:** register `workforce-one-attendance-cards` (`assets/js/attendance-cards.js`, deps `['workforce-one-sheet']`). Register only; do not enqueue.
- **`includes/trait-features.php`:**
  - `admin_features()`: `'attendance_cards'=>$this->attendance_cards_enabled()` in `on`.
  - `features_save_handler()`: `if(isset($post['attendance_cards_present']))update_option('ews_feature_attendance_cards',$flag('attendance_cards_enabled')?1:0,false);`
  - Add `'attendance_cards='.$state(...)` to the `feature_update` audit line.
- **`templates/admin/features.php`:** add the hidden `attendance_cards_present` input and a section `id="ews-attendance-cards"`. Title "Attendance card details". The description and the note follow `settings.en.html`. Use `$switch('attendance_cards_enabled', $on['attendance_cards'])` and `esc_html_e` strings.
- **`src/Settings/Options.php`** (next to `:119-120`): `'ews_feature_attendance_cards' => ['group'=>'features','label'=>'Attendance card details (the Attendance page cards open who and when)','kind'=>'bool','default'=>false,'page'=>'ews31-features']`.

### New pure classes (no WordPress), with unit tests
- **`src/Attendance/Lateness.php`:** `minutesLate()` (§2).
- **`src/Attendance/CardLists.php`:**
  - `KINDS`, `PER = 20`.
  - `accepts(string $kind, string $actual): bool`.
  - `order(array $keys, string $kind, string $order): array`: keys `[pos,date,eid]`, stable.
  - `page(array $sorted, int $offset): array{rows, total, offset, next}`.
  - `personCounts(array $counts)`: Present / Late / Absent / Leave per employee from walked `actual`s.
  - `rateByDay(array $dayCounts): list<{date,rate|null,attended,required}>`: uses `Insights::rate`, and null when required = 0.
  - `lowest(array $perPerson, int $limit = 10)`: only `required>0 && rate<100`, sorted rate ↑, then absent ↓, then pos ↑.
  - `missingOut(bool $hasIn, bool $hasOut, string $date, string $today, int $now, ?int $shiftEnd): bool`: the rule chosen by Q7.
  - `lateNote(array $rowsCfg): ?array`: one start and grace if they are shared, else null.
- **Tests.** `tests/unit/AttendanceCardsTest.php`, plus additions to `tests/unit/LatenessTest.php`.

### Assets
- **New `assets/js/attendance-cards.js`.** It reads the config from `#wfo-ac-sheet`'s data attributes. On a `[data-ews-card]` click it:
  1. sets the sheet's kind class, title, icon and subtitle;
  2. calls `wfoSheet.open(dialog, button)`;
  3. sets `aria-expanded="true"` on the card;
  4. fetches offset 0 and renders groups, rows, foot ("Showing X of N"), "Show more" and "Open in Reports".
  - The By day / By employee toggle (`aria-pressed`) refetches from offset 0.
  - It clears the list on `close` and restores `aria-expanded`.
  - A request id per opening, plus an `AbortController`, ensures a slow answer for an older kind is never drawn.
- **`assets/css/app-attendance.css`:** the mockup's `attendance-cards.css` rules, scoped `.wfo-ac-*`, `.ews-att-stat.wfo-ac-card` and `dialog.wfo-sheet.wfo-ac-sheet`. Regenerate `app-attendance-rtl.css` with rtlcss (`assets/css/README.md`). The rules are logical properties; the mockup's `[dir=rtl]` animation and chevron flips stay.
  - Side-sheet breakpoint: the mockup uses `min-width:782px` while the bottom sheet is `max-width:700px`. Use **701px**, so there is no centred-dialog band between 701 and 781 (**pending Q12**).

---

## 5. Template changes (`templates/app/attendance.php`)

- New `@var array|null $cards`. It is `null` when off. When on it is:
  - `nonce`, `url` (`admin_url('admin-post.php')`)
  - `params` (`week` = `date('Y-m-d',$sun)`; `q` / `team` only if `$list['paged']`; `branch` if `$branch_filter`)
  - `chips`: the week range, team (or "All teams"), branch name, and search, each only when applied
  - `reports_url` (base `app_view_url('reports')` + `report_type`, `start`, `end`, `team`) or `''` without `ews_view_reports`
  - `profile_url` (`app_view_url('employee')`) or `''` when `app_view_allowed('employee')` is false
  - `days` (date → `date_i18n('D d M')`)
- **Off (`$cards === null`).** Lines 105-111 are kept **character for character**, and nothing else is printed. Implement it as `<?php if (!$cards): ?>…the exact current block…<?php else: ?>…buttons…<?php endif; ?>`, so the off branch is the old text. Do not try to generate both from one loop with conditionals, because that risks whitespace drift.
- **On.** Each card becomes `<button type="button" class="ews-att-stat {kind} wfo-ac-card" data-ews-card="{kind}" aria-haspopup="dialog" aria-controls="wfo-ac-sheet" aria-expanded="false">`.
  - The icon `<span>` stays. The text wrapper becomes `<span class="wfo-ac-txt"><b>…</b><small>…</small></span>`, because a `<div>` inside a `<button>` is invalid. Add CSS so it lays out like the current `>div`.
  - A `<i class="wfo-ac-card-go">` chevron follows. The accessible name is the visible "171 Present days".
- **Sheet markup** (once, after the cards, on only). It reuses the sheet's structure from `templates/app/new-request.php:27-30`:
  - `<dialog class="wfo-sheet wfo-ac-sheet" id="wfo-ac-sheet" aria-labelledby="wfo-ac-title" data-…config…>`
  - grip; head (icon slot, `<h2 id="wfo-ac-title">` + count pill, subtitle `<p>`, close `data-wfo-sheet-close`)
  - chips bar; toggle; note slot; `<div class="wfo-ac-body" aria-live="polite" aria-busy>`; foot
  - `<template>` icons (check, overtime, alert, people, insights, chevron, clock, calendar, office, wfh, leave, reports)
  - the translated strings as `data-*`: titles, subtitles, notes, "N min late" (`%d`), "No sign-out", "Planned: %s", "Showing %1$d of %2$d", "Show more", "Open in Reports", "Day by day", "Lowest this week", "%1$d of %2$d days", "Up to 10 people below 100% show here.", the empty-state text per kind, the errors, Retry, and "next day"
  - This is the pattern `attendance-grid.js` uses with `data-one` / `data-many`. It avoids a new wp-i18n JSON.
- **Rows.** When `profile_url` is set, a row is an `<a>` to `profile_url&employee_id=…`; otherwise a plain row with no chevron (**pending Q2**). "Open in Reports" appends `&status=Present|Late|Absent` (Daily Log). For `people` it uses `report_type=summary`. It is hidden when `reports_url === ''` (**pending Q3**).

### Accessibility
- Native `<dialog>`: Esc closes it and focus is trapped.
- `sheet.js` returns focus to the opener card.
- The first focus goes to the close button on a computer (`sheet.js` only focuses inputs, so the new JS focuses the close button).
- The toggle uses `role="group"` + `aria-pressed`. The list is `<ul>` per day with an `<h3>` day header and count.
- "Show more" keeps focus on itself and announces "Showing X of N".
- Reduced motion: the sheet animation is already off under `prefers-reduced-motion`; add the same for `wfo-ac-in`.

---

## 6. What each sheet shows (from the mockups)

- **Present.** Rows with `actual === Present`: in → out, plus a "No sign-out" pill when `missing_out`. Note: "On time. Late days have their own card."
- **Late.** In → out, the "No sign-out" pill, and the "N min late" pill. Note as in §2.
- **Absent.** "Planned: {status}" with the office or WFH icon by `plan_class` (`sanitize_title`), and any other sign-in type by name. Add the planned branch in "By the schedule" mode (Q11). There are no times. "Show more" is hidden when everything is shown. Note: "A working day with no sign-in, past the absent-after time."
- **Employees.** Per person, mini-counts Present / Late / Absent, and Leave only when it is above 0. Grid order, paged by 20. No toggle.
- **Rate.**
  - "Day by day": bars per date, with `inline-size: rate%`, and "—" when there is no required day.
  - "Lowest this week": at most 10 people, "60%" and "3 of 5 days".
  - Hint: "Up to 10 people below 100% show here."
  - No paging and no toggle. "Open in Reports" is not in the mockup; leave it out.
- **Every list.** Chips (week, team, and branch and search when applied), "Showing 20 of N", "Show more", "Open in Reports". An empty list shows the kind's empty state, e.g. "No late days this week.", and no foot.

---

## 7. The switch: off changes nothing

- **Option.** `ews_feature_attendance_cards`, bool, default `false`, saved non-autoloaded (`update_option(..., false)`), like the other switches. It is in the Options registry, so `tests/unit/OptionsTest.php` accepts the read.
- **Off guarantees:**
  1. `attendance_content()` passes `cards => null`. The template's off branch is the old text. No new asset is enqueued, so the `<head>` is unchanged too, except the Office-minimum precedent, which already enqueues sheet assets when that feature is on.
  2. `attendance_scope` / `attendance_walk` are a pure refactor.
  3. The endpoint answers `404 off` without touching data.
  4. The only extra work when off is one `get_option` for a missing non-autoloaded option. That is one query per request at most, cached in `notoptions` by WordPress, and the same for on and off. It is noted as R7.
- **How to prove byte-identical HTML.** Before any code change, on 3.31.124, add `tests/e2e_attendance_cards.py --record` to the e2e. It seeds a fixed last week: 70 people (paged) and 8 people (unpaged), branches in `schedule` mode, a holiday, a leave, an overnight shift, a missing sign-out, a legacy `late_sign_in`, and today with a pending person. It then renders `attendance_content()` through `ReflectionMethod` for an admin and for a department manager, with the variants unpaged, paged with `q` and `team`, `branch=…`, and a week with 0 working days. It normalises only nonces (`_wpnonce` values and `wp_nonce_field` outputs), then stores `tests/fixtures/attendance_cards_off_*.html`.
  - On 3.31.125 with the switch off, the same run compares byte for byte.
  - It also compares the list of enqueued handles (`wp_scripts()->queue` / `wp_styles()->queue`) after rendering.
  - Freeze the clock with the suite's usual time override, or choose a week entirely in the past plus a fixed "today" seed, so "Today" and Pending do not drift.

---

## 8. Module boundaries (`docs/architecture/modules.json`)

- Add `"attendance-cards"` to `schedule.traits`. Otherwise `check_modules.py` fails with "trait in no module".
- Add `insights_load` and `insights_day` to the `attendance` module's `api`. The grid (schedule) already calls them, and so will the walk. They become public interface, not crossings. The schedule→attendance call count drops; run `python3 tests/check_modules.py --update` to lower the baseline.
- Add `attendance_scope` and `attendance_walk` to `schedule.api`. Nothing else calls them yet, but they are the grid's shared interface.
- The new trait calls `branch_planned_bulk` (locations; not api, so +1 crossing) and `department_scoped_employees` (only via the scope, so no new crossing). Either add `branch_planned_bulk` to `locations.api`, which is preferred because the grid already calls it, or accept +1 and update the baseline. The check must not report "MORE THAN BEFORE" without one of these.
- Update `docs/architecture/modules.md` if it lists each module's api.

---

## 9. Translations, upgrade

- **Strings.** All new strings use `__()` / `esc_html__()` in PHP: the template's data attributes, the Features page, and the error texts. Translator comments go on every `sprintf` (`%d`, `%1$d of %2$d`). Arabic goes in `languages/workforce-one-ar.po`. Regenerate `.mo`, `.l10n.php` and `.pot`. No JS translation JSON is needed. Run `python3 tests/check_translations.py`. Arabic wording follows the mockups' Arabic texts.
- **Upgrade.** **No schema change and no DB version change** (`ews_schema_version` untouched), confirmed: everything is read from `schedule`, `time_logs`, the shift history, employees, teams and branches. The new option is absent until saved, which means off. Uninstall already removes `ews_*` options (`uninstall.php:65`). The CHANGELOG entry follows the 3.31.124 style.

---

## 10. Edge cases

| Case | Behaviour (and why it matches the card) |
|---|---|
| Today, not past the cutoff | No sign-in yet means `Pending`: in no list and not in the rate. A late sign-in today shows at once in Late. Today's on-time row follows Q7 for "No sign-out". |
| Company holiday | Everyone is `Leave` that day. It is in no Present/Late/Absent list, adds to the Leave mini-count in Employees (Q5), and is left out of the day's rate (`required = 0`, shown "—"). |
| A person on leave | `Leave` (leave rule or status). The same as a holiday. |
| No shift | Company working hours of that day (`shift_times_on(0,d)` else `ews_working_hours`) and the global grace. Minutes are from the company start. |
| Overnight shift | §2. The `dayOf()` anchor plus the "next day" mark. The today cutoff for overnight uses the existing rule (`insights_day:41-43`). |
| Missing sign-out | `out: null`, "No sign-out" pill on past days. Today per Q7. It does not change Present/Late. |
| Filtered out by branch | Not in `matched`, so in neither card nor sheet. Branch membership is main or other branches, not the day's planned branch (existing rule). |
| Search/team on a list of 60 or fewer | The server ignores them, so the cards count everyone and so does the sheet. No team/search chip is shown (Q1). |
| Empty list | The kind's empty state. The card can still be opened (Q4). |
| Week with 0 working days | `dates = [today]`, as the page does. The rate shows one bar. |
| Future week | Everything is Pending or Not Scheduled. Lists are empty; Employees shows 0s. |
| Department manager without a department | No one in scope. The cards show 0 and the sheet is empty. |
| Employee deactivated or tracking off | Not in `emps()` or filtered out, so in neither. |
| Data changed after the page loaded | `card` in the response differs from the page's number. Shown per Q6. |
| Legacy `late_sign_in` | Late, `late_min: null` (Q9). |
| Sign-out before sign-in | Shown as stored, the same as the grid's data source. |
| Concurrent "Show more" after a new sign-in | One row may repeat or be skipped (R3). |
| Nonce expired (page open for more than 12-24 h) | 403 `nonce`, "Reload the page". |

---

## 11. Risks

- **R1. The refactor breaks the page.** `attendance_content()` is split. This is mitigated by the golden byte-for-byte fixtures recorded on 3.31.124, plus the existing `tests/e2e_attendance_grid.py`, `e2e_big_lists.py`, `e2e_branches.py`, `e2e_office_minimum.py` and `e2e_week_lock.py`.
- **R2. Endpoint cost at 3000 × 7.** It is the same loop as the page, on every open and every "Show more". Mitigations: light keys, enriching 20 rows, bulk shift priming. The test measures the time (target under 1 s on the test box) and a constant query count. A per-user key-list transient is a fallback if the review measures it too slow.
- **R3. Paging drift.** Offset paging over a live list can repeat or skip a row when a sign-in lands between clicks.
- **R4. Overnight anchor.** This is an existing behaviour that minutes late inherits.
  - Shift lookup: `working_hours()` uses the sign-in's calendar day, not the work day. After a shift change on D+1, a D shift's after-midnight sign-in is measured against the D+1 shift.
  - Very early sign-ins: on an overnight shift, a very early sign-in (before the end time) counts against yesterday's start, giving a huge "minutes late". This is also how the card classifies it, so it is consistent, but odd. It is listed for the owner (Q10) and not fixed here.
- **R5. "Open in Reports" may show a different number.**
  - Reports use `DayMetrics`, whose late rule is anchored on the work date and not `dayOf()` of the time.
  - Reports count holidays as "Holiday", not Leave.
  - Reports ignore branch and search.
  - Reports need `ews_view_reports`.
  - In the common case the numbers match; in these cases they can differ (Q3).
- **R6. The page's existing N+1.** The page does one `shift_periods_of` query per employee with a sign-in on a past week. It is not fixed here: off must change nothing, and the page HTML does not depend on it. A separate small fix can prime in the page (Q13).
- **R7. One option read per request** for the switch, on and off alike (non-autoloaded and missing, then cached in `notoptions`).
- **R8. Locale.** admin-post runs with the user's profile locale. This is avoided by sending no translated text in JSON.
- **R9. Scope parity.** If someone later changes the page's filters without going through `attendance_scope()`, the sheet and card could diverge. The parity e2e (sheet total = card for every kind, unpaged, paged, branch, department manager) guards this.

---

## 12. Test list

### Unit (`vendor/bin/phpunit`)
- `LatenessTest`:
  - `minutesLate`: on time (0); exactly at grace (0 + not Late); +23; +62.
  - Overnight: 22:35, then 00:15 the next day → 135, then exactly at the end time.
  - Start equal to end (non-overnight).
- `AttendanceCardsTest`:
  - `accepts` per kind.
  - `order` by day and by employee: stable and grid-position based.
  - `page`: 0, 20, 21, 40 rows; `next` null at the end; an offset past the end is clamped.
  - `personCounts`: holiday as Leave.
  - `rateByDay`: `required=0` → null; equals `Insights::rate`; the week total equals the card's rate, not the average of the days.
  - `lowest`: excludes 100% and `required=0`; at most 10; tie order.
  - `missingOut` for today and past days (per Q7).
  - `lateNote`: shared vs mixed shifts.
- `OptionsTest` (the registry has the new option); `FeatureSettingsTest` if it lists switches.
- `phpstan`, `phpcs`, `python3 tests/check_modules.py`, `python3 tests/check_translations.py`.

### e2e, Python: `tests/e2e_attendance_cards.py`
It follows `tests/e2e_week_lock.py` (`e2e_support`, `e2e_setup.php`, `wp eval`).
- **Off:**
  - Golden byte-identical HTML (§7), all variants.
  - No `wfo-ac-` / `data-ews-card` in the page.
  - The endpoint returns 404 `off`.
  - The enqueued handles are unchanged.
- **Features page:** the card exists and is off; saving turns it on and records `attendance_cards=enabled` in the `feature_update` audit; an older form without `attendance_cards_present` keeps the saved value.
- **On, parity:** for each kind, and each of unpaged, paged + `q`, paged + `team`, `branch`, and a department manager, the endpoint `total` equals the card number parsed from the page. The sum of pages (Show more until `next = null`) equals the total, with no duplicates.
- **Content:**
  - Late rows: sign-in, sign-out and minutes for a shift with grace, the company hours, an overnight sign-in after midnight (and the `next_day` flag), a shift changed mid-week (shift history), and a legacy `late_sign_in` → `late_min null`.
  - Present: on time only. Absent: planned status (and branch in schedule mode).
  - Employees: counts per person. Rate: per-day values (holiday → null) and the lowest list (no 100%, at most 10).
- **Edge:** today pending (in no list), a holiday, a leave, a week with 0 working days, a future week (empty), and a manager without a department (empty).
- **Security:**
  - Not logged in: no JSON data (admin-post `nopriv` is not registered).
  - Bad or missing nonce → 403. No `ews_manage_attendance` → 403.
  - A department manager never receives an employee of another department, even with a crafted `q` / `team` / `branch` / `week`.
  - `kind` / `order` / `offset` junk is rejected or clamped.
  - A name with `<script>` comes back as text.
- **SAVEQUERIES / `$wpdb->num_queries`:**
  - (a) `attendance_content()` runs the same number of queries with the switch on and off. The page is not more expensive.
  - (b) The endpoint's query count is the same for 10 and 70 employees (constant in N).
  - (c) The endpoint's time is logged for 3000 × 7 on a seeded big list, for review. This can run in `e2e_big_lists.py` style.

### Browser: Node + Playwright, `tests/attendance_cards_browser.js`
Like `tests/my_approvals_browser.js`.
- **Computer (1280 px):**
  - Each card is a button with `aria-haspopup`, and Enter opens it.
  - The side sheet sits at the inline end and the list loads.
  - The toggle switches to By employee. "Show more" appends, and "Showing 40 of N" updates.
  - Esc closes it, focus returns to the same card, and `aria-expanded` resets.
  - "Open in Reports" goes to the Daily Log with `status`. A row opens the profile (per Q2).
- **Phone (390 px), Arabic:**
  - A bottom sheet with a grip, no sideways scroll, and the text reads from the right; the chevrons are flipped.
  - A tap outside closes it.
- No JavaScript errors anywhere. Slow network: a "Late" opened after "Present" never shows Present's rows.
- **Off:** clicking a card does nothing and there is no dialog in the DOM.
- Optional screenshots with `ATC_SHOTS`.

### Regression suites to rerun
`e2e_attendance_grid.py`, `e2e_big_lists.py`, `e2e_branches.py`, `e2e_office_minimum.py`, `e2e_week_lock.py`, `e2e_insights.py`, `e2e_app_insights.py`, `e2e_attendance_parity.py`, `e2e_features.py`, `e2e_shift_history.py`, `e2e_timezone.py`.

---

## 13. Open questions for the owner (not decided here)

1. **Small lists (60 people or fewer).** Search and team filter only hide rows in the browser, and the cards count everyone (today's behaviour). Should the sheet follow the cards (everyone; recommended, because "rows = card number" holds), or follow what is visible on screen? Following the screen means the card numbers must also change as you type.
2. **Tapping a row.** The mockup rows have a chevron. Should a row open the employee's profile in the app (only for viewers allowed to see profiles), the employee's Daily Log in Reports, or nothing?
3. **"Open in Reports":**
   - Should it be shown to managers without the Reports permission? (Proposed: hidden.)
   - With a branch or search filter active, Reports cannot apply it. Should the link be hidden, or open without that filter?
   - Is it acceptable that Reports count a public holiday as "Holiday" rather than Leave, and can differ slightly on overnight lateness?
4. **A card showing 0.** Should it still open (with "No late days this week"), or look and act disabled?
5. **The Leave count in the Employees sheet** includes company holidays, because the page counts a holiday as Leave. Should holidays count there, show as a separate "Holiday" count, or be left out?
6. **The page was opened earlier and numbers changed** (someone signed in). The sheet shows today's live rows. Should it show a note "Updated since you opened the page" with a Reload link, update the card's number in place, or show nothing?
7. **"No sign-out" for today.** Someone who signed in this morning has not signed out yet. Show the pill for today never, only after their shift ends (the Reports rule; recommended), or always (what `insights_day` says today)?
8. **Order inside a day.** Should it be the page's team order (as in the mockup), by sign-in time, or (for Late) by most minutes late first? And in "Lowest this week", which comes first on equal rates?
9. **Old "late" records** (`late_sign_in` events from before the lateness rule). They are Late but may not be after the start time. Show "Late" without minutes, or the minutes measured anyway (possibly 0)?
10. **Overnight shifts:**
    - Is a "next day" mark next to an after-midnight sign-in time wanted?
    - A very early sign-in on an overnight shift (before its end time) counts as late for yesterday's start. This is the existing rule, now visible as large minutes. Keep it, or fix the rule in a separate release?
11. **The Absent list in "By the schedule" branch mode.** Show the planned branch next to "Planned: Office"?
12. **Side sheet width.** The mockup switches to the side sheet from 782 px; the app's phone layout ends at 700 px. Use the side sheet from 701 px, so no screen shows a centred box in between?
13. **The page's own speed.** While here, may the Attendance page also load the past shift history in one query instead of one per person? That is faster for big companies, with no visible change. Or should that stay in a separate release, since "off changes nothing" is strict?
14. **Time format.** Rows show 24-hour times ("09:23") like the grid, while the mockup note says "9:00 AM". Use 24-hour everywhere, or the site's time format everywhere?

### Critical files for implementation
- /home/user/WFplugin/workforce-one/includes/trait-attendance-grid.php
- /home/user/WFplugin/workforce-one/includes/trait-attendance-insights.php
- /home/user/WFplugin/workforce-one/templates/app/attendance.php
- /home/user/WFplugin/workforce-one/src/Attendance/Lateness.php (plus new /home/user/WFplugin/workforce-one/src/Attendance/CardLists.php and /home/user/WFplugin/workforce-one/includes/trait-attendance-cards.php)
- /home/user/WFplugin/workforce-one/includes/trait-features.php (with /home/user/WFplugin/workforce-one/templates/admin/features.php, /home/user/WFplugin/workforce-one/src/Settings/Options.php, /home/user/WFplugin/docs/architecture/modules.json)

## Owner's answers (2026-10-25)
1. Small lists (≤60): the sheet follows the cards (everyone); rows always equal the card's number.
2. Tapping a row opens the employee's profile in the app, for viewers allowed to see profiles; otherwise the row is plain (no chevron).
3. "Open in Reports" shows only with the Reports permission. With a branch or search filter active it still opens, without that filter, and says so in a short note.
4. A card at 0 still opens and shows its empty state ("No late days this week.").
5. Employees sheet: company holidays are a separate "Holiday" count; Leave counts the person's own leave only.
6. Numbers changed since the page opened: the card's number is updated in place from the endpoint's `card`.
7. "No sign-out" for today shows only after the person's shift has ended (the Reports rule); on past days always.
8. Order inside a day: Late by most minutes first; Present by sign-in time; Absent by team order (grid position). "Lowest this week": rate ↑, then more absent days, then grid position.
9. Old `late_sign_in` records: "Late" without minutes.
10. Overnight: a "next day" mark next to an after-midnight sign-in. The existing rule for a very early sign-in on an overnight shift is kept (not changed in this release).
11. Absent rows show the planned branch in "By the schedule" mode.
12. The side sheet from 701px (no centred box between the phone and the side sheet).
13. The page's shift-history speed-up is a separate small release (off must change nothing here).
14. Times in 24-hour format everywhere, as the grid (the note reads "Start 09:00, late counts after 09:10").

## Where the implementation differs (3.31.125)
- `attendance_scope()` reads the week through `week_dates()` ($_GET), as the page did; both callers pass the same request.
- The cards open the sheet through sheet.js (`data-wfo-sheet` on each card); attendance-cards.js loads the list on `wfo-sheet-open`.
- Module boundaries: the branch helpers the grid already called are now `locations` api, and `app_view_allowed` is `shell` api,
  instead of only `branch_planned_bulk` (the crossings went down; baseline updated).
- Day labels are the full day name ("Monday 19 Oct"); group headers carry no count.
- Review fixes: each person's shift comes from the rows already loaded (no query per person); "No sign-out" waits for the end of
  that day's shift on every day (a night shift from yesterday may still run), not only today; a Late day shows at least 1 minute.
- The byte-for-byte "off" check (`--record` on 3.31.124, `--compare` on 3.31.125) runs locally, not in CI: the pages carry the
  seeded ids and dates. CI checks the off page has none of the new markup, the endpoint answers 404, and the same query count.
