# Leave type on the Attendance page

Owner request 2026-11-18: "بدل ما يظهر Leave بس يظهر نوع الـ leave نفسها سواء عادية، عارضة أو مرضي، أو لو فيها حاجة تانية في الكونفجريشن اتضافت".

## Owner's decisions (2026-11-18)

1. **Where:** the Attendance page only (app → Attendance, the manager's grid). Reports, exports and Attendance Insights do not change.
2. **Summary:** one card, Leave days, with the count by type under it ("Annual 5 · Sick 2 · Casual 1"). The page has no Leave card today (Employees, Present, Late, Absent, Attendance rate), so the card is new and appears only with the feature on; six cards in a row on a wide screen.
3. **Colour:** the same as Leave today for every type; only the word changes.
4. **Without an approved leave request:** as today. A day set by hand to a leave status in the schedule shows that status as now; a company holiday shows "Leave" with the holiday's name, as now.
5. **Off by default** (project convention): a switch in Features. Off, the page is exactly as today.
6. **Which cards are shown is a setting** (2026-11-18, "خلي ظهور الكروت بكونفيجريشن، خصوصاً employees and present days"): the administrator chooses, for the whole company, which cards appear above the Attendance grid (Employees, Present days, Late days, Absent days, Leave days, Attendance rate), on Feature Configuration. All shown by default, as today; Leave days only when the leave type is on. A hidden card is only taken off the page; the reports keep every number. This setting is not behind the leave-type switch.

## Where the type comes from (first reading of the code, to be confirmed by the design)

- Approving a leave sets the day's schedule to `Vacation` with the note `Approved leave #<id>` (`includes/trait-leave.php:184-188`), and the request carries `leave_type_id` → `ews_leave_types.name`, the types configured on the Leave Types page (Annual Leave, Sick Leave, Casual Leave, and any added).
- The grid's badge comes from `Insights::actual()` → `GridRules::badge()` (`src/Attendance/GridRules.php:27`), built in `includes/trait-attendance-grid.php:84`.

## Mockups

`docs/mockups/leave-type/` (`on`, `hidden` (Employees and Present days hidden), `off`, `settings`; Arabic and English; `index.html`).

# Design (architect, read-only, 2026-11-18)

This design covers the owner's decisions 1–6 above and the mockups in `docs/mockups/leave-type/`. Nothing was run. Each claim is marked **VERIFIED** (read in the code) or **ASSUMED**.

## 0. Findings that shape the design

1. **The page already counts leave, but does not show the count.** `$summary` has a `Leave` key (`src/Attendance/GridRules.php:13` SUMMARY; incremented at `includes/trait-attendance-grid.php:81`). No card prints it (`templates/app/attendance.php:107-113`, `:115-117`). That count includes company holidays and hand-set leave statuses, because `Insights::actual()` returns `Leave` for `holiday || rule==='leave' || planned==='Leave'` (`src/Attendance/Insights.php:85`). VERIFIED.
2. **The schedule note `Approved leave #<id>` is not reliable.** The grid save keeps the note when a manager changes the status (`src/Schedule/ScheduleDayService.php:93-95`, `updateStatusIf(..., (string)$row->note, ...)`). A day changed by hand from Vacation to another leave status, or to Office and back, would keep a stale note. `insights_load()` also does not read the note (`includes/trait-attendance-insights.php:19`). VERIFIED.
3. **The days an approval wrote are already recorded exactly.** `leave_apply_final_approval()` writes one `ews_leave_schedule_snapshots` row per day it set to `Vacation`, working days only (`includes/trait-leave.php:179-191`). Every approval path goes through it: normal (`:311`), engine (`:377`, `:506`), and admin Record Leave (`includes/trait-leave-admin.php:96`). Cancellation restores the schedule and sets `status='Cancelled'` (`includes/trait-leave.php:213-226`). Withdrawing works on Pending only (`:622-634`). Payroll already reads the leave type per day with this join (`includes/trait-payroll.php:137`: snapshots ⋈ requests `status='Approved'` ⋈ types). VERIFIED.
4. **There is no half-day leave.** No "half" anywhere in the leave code (grep). `requested_days` is a count of working days (`src/Leave/WorkingDays.php`). VERIFIED.
5. **Overlapping requests are refused.** No two Pending/Approved requests of one employee overlap (`includes/trait-leave.php:58-61`, checked after insert at `:154`). VERIFIED.
6. **Legacy rows:**
   - The pre-3.x vacation table writes `Vacation` with no type and no snapshot (`includes/trait-admin-requests.php:130-152`).
   - Migrated requests may have `leave_type_id=0` (`includes/trait-schema.php:153`).
   - Both fall back to "Leave", as today. VERIFIED.
7. **Settings reads cost no query.** Every option listed in `Options::all()` is primed in the constructor (`employee-schedule-manager.php:124` → `includes/trait-core.php:81-84`). VERIFIED.
8. **No `src/Leave/Data` exists.** The leave module has no layered slice (`docs/architecture/modules.json`, `leave`). `check_modules.py` scans `includes/` only. A call from the grid trait (module `schedule`) to a leave function must use a function in the leave `api`. VERIFIED.
9. **Golden covers these pages:**
   - The Attendance page: plain, with week lock, with attendance cards, with search, and as emp1.
   - The Features page: twice (`tests/golden/pages.json`).
   - The Attendance pages must stay byte-identical with no more queries (`tests/golden/run.py:268-285`).
   - The Features page will change (new sections), so it must be declared. VERIFIED.
10. **CSS conflicts with the mockup:**
    - Leave in the grid is purple (`assets/css/app-attendance.css:112`, `:123` `#5B34B5`). The mockup's card icon is amber (`leave-type.css:2`).
    - The mockup rule `.ews-att-result.leave span{…ellipsis}` would also match today's "Leave" cells. Shipped as written, it would change the page with (A) off. VERIFIED.

## 1. (A) Where the type comes from

**Recommended: one batched read of the approval's snapshot days, not the note.**

```sql
SELECT r.employee_id, s.work_date, r.id, t.name
FROM {p}ews_leave_requests r
JOIN {p}ews_leave_schedule_snapshots s ON s.leave_request_id=r.id AND s.employee_id=r.employee_id AND s.work_date BETWEEN %s AND %s
LEFT JOIN {p}ews_leave_types t ON t.id=r.leave_type_id
WHERE r.employee_id IN (...) AND r.status='Approved' AND r.start_date<=%s AND r.end_date>=%s
ORDER BY r.id ASC
```

- The query starts from requests so it uses `KEY employee_status` and the snapshots' `request_date` (`includes/trait-schema.php:126`, `:130`).
- It returns `[eid][Y-m-d] => name` (the later id wins; `''` when the type is missing).
- A `NULL` from the type join is treated as "untyped" (Leave).

**A day shows the type only when all of these hold.** This is a pure rule, `GridRules::leaveType()`:

- (A) is on;
- `actual === 'Leave'`;
- the day is not a company holiday (`isset($holidays[$d])`, `includes/trait-attendance-grid.php:29`, `:84`);
- `planned === 'Vacation'`, the status the approval writes (`includes/trait-leave.php:184`, `:188`);
- the map has a non-empty name for that employee and day.

Otherwise the badge is exactly today's.

**Edge cases:**

| Case | Result | Why |
|---|---|---|
| Cancelled leave | Never typed | `status='Cancelled'`; the schedule was restored by `:218-221` |
| Cancellation requested but not decided | Typed | Still `Approved`, still on leave |
| Multi-day leave | One snapshot per day | `:179` |
| Leave on a non-working day | No snapshot, no Vacation written, so as today | A Vacation set by hand there stays "Leave", since there is no snapshot |
| Holiday and leave on the same day | Holiday wins: "Leave · National Day" | Decision 4; the holiday name comes from `:84` |
| Type renamed later | Current name | Read through the join |
| Type deactivated | Still its name | `active` is not filtered |
| Type deleted, or `leave_type_id=0` | "Leave" | |
| Day changed by hand to another leave status after approval | "Leave" | `planned !== 'Vacation'`, decision 4 |
| Day changed by hand to Office | Not Leave at all | |
| Legacy vacation table, or approvals older than snapshots | "Leave" | ASSUMED: the snapshot table exists since the 3.x leave module |
| Overlapping requests | Impossible | Finding 5; the ORDER BY makes the result deterministic anyway |

**Translation.** Type names are admin data and are shown as entered in both languages. They never go through `$result_words` (`templates/app/attendance.php:74-78`). Otherwise a type named e.g. "Present" would be translated, and a name starting with an emoji would be stripped by the `preg_replace` at `:77`.

## 2. (A) Query cost

- **Off:** zero extra queries. The flag is read through `option()` (primed, finding 7). No leave call is made.
- **On:** exactly one query, for all matched employees (the same id list `insights_load()` uses, `includes/trait-attendance-grid.php:56`). It is skipped when the list is empty.
- The cards' JSON endpoint (`att_cards_data`, `includes/trait-attendance-cards.php:66`) does not run it.
- The settings for (B) are a registered option, so they add no query.

## 3. (B) Which cards are shown

**Storage** (`src/Settings/Options.php`, Features block near `:129`):

- `'ews_feature_leave_type' => ['group'=>'features','label'=>'Leave type on the Attendance page (a day of approved leave shows its type, with a Leave days card)','kind'=>'bool','default'=>false,'page'=>'ews31-features']`
- `'ews_attendance_page_cards' => ['group'=>'features','label'=>'Attendance page: the cards shown above the grid','kind'=>'grouped','default'=>[],'builtin'=>'all six shown (Leave days only with the leave type)','page'=>'ews31-features']`
- Stored shape: `['hidden'=>['people','present']]`. Storing the hidden keys means a card added later is shown by default.

**New pure class `src/Settings/AttendancePageCards.php`** (pattern: `WeekLockSettings`):

- `KEYS = ['people','present','late','absent','leave','rate']`, the display order (Leave after Absent, as in the mockup).
- `config($raw): array{hidden:string[]}` keeps only known keys, without duplicates.
- `fromPost(array $checked): array` gives hidden = KEYS minus checked.
- `shown(array $config, bool $leaveTypeOn): string[]` is the ordered keys to render. It drops `leave` when (A) is off.

**Readers** (schedule module; add both to `modules.json` `schedule.api`, next to `attendance_cards_enabled`):

- `leave_type_enabled()` returns `(bool)$this->option('ews_feature_leave_type')`.
- `attendance_page_cards()` returns `AttendancePageCards::config($this->option('ews_attendance_page_cards'))`.
- Both go in `includes/trait-attendance-grid.php`.

**Features page:**

- `includes/trait-features.php:23-48`: add `'leave_type'=>$this->leave_type_enabled()` to `$on` and `'att_page_cards'=>$this->attendance_page_cards()`.
- `templates/admin/features.php`:
  - Add the hidden inputs `leave_type_present` and `att_page_cards_present` near `:67`.
  - After the `#ews-attendance-cards` section (`:88-91`), add two sections, as in `settings.en.html`:
    1. `#ews-leave-type`: title, description and `$switch('leave_type_enabled', …)`.
    2. `#ews-attendance-page-cards`: a `fieldset.wfo-lt-cards` of six checkboxes `att_page_cards[]`. Leave days carries "(with Leave type)" and the help text from the mockup.
  - Use `esc_html_e()`, as the cards section does.
- Save (`includes/trait-features.php` near `:123`):
  - `if(isset($post['leave_type_present']))update_option('ews_feature_leave_type',$flag('leave_type_enabled')?1:0,false);`
  - For the cards: compute `AttendancePageCards::fromPost((array)($post['att_page_cards']??[]))` and write it only when it differs from `attendance_page_cards()`, so a default site stores nothing (the `app_api` rule at `:124`).
  - Add `leave_type=` to the audit line at `:222`.
- Settings overview: add `'ews_attendance_page_cards'=>[\WorkforceOne\Settings\AttendancePageCards::class,'config']` to `settings_overview_readers()` (`includes/trait-settings-overview.php:173-187`). The bool shows on its own via `Options::isChanged`.
- CSS: `.wfo-lt-cards` and `.wfo-lt-card` (`leave-type.css:7-8`) go to `assets/css/admin-features.css`, with its rtl copy regenerated by rtlcss.

**Hidden cards** are only taken off the page. The cards' endpoint still answers every kind (it is the same data the grid shows). The reports are untouched.

## 4. The page: code changes

**`src/Attendance/GridRules.php`:**

- `badge(string $actual, string $time = '', ?string $leaveType = null)`. With a type it returns `['class'=>'leave','label'=>'▣ '.$type,'detail'=>$time,'type'=>$type]`. Without one, the output is byte-identical to today, so `AttendanceGridTest` still passes.
- `leaveType(string $actual, bool $holiday, string $planned, ?string $name): ?string` is the rule in §1. It trims the name; empty gives null.
- `leaveSummary(array $byType, int $other): array{total:int,split:array<int,array{0:string,1:int}>}`, ordered by count descending, then name. The "other" entry is last (Q1).
- `shortType(string $name): string` removes a trailing " Leave" (any case) or a leading "إجازة ", but never leaves the name empty (Q2).

**Leave module API.** In `includes/trait-leave.php`, next to `leave_away_between` (`:64`), add `leave_types_by_day(array $employee_ids,$start,$end)`. It has no SQL and returns `$this->services()->leaveDays()->typesByDay(...)`. Add it to `modules.json` `leave.api`.

**New read model** in `src/Leave/Data/LeaveDayQuery.php` (interface) and `WordPressLeaveDayQuery.php` (the SQL in §1, mirroring `src/HrLetters/Data/WordPressLetterQuery.php`). Add the accessor `Services::leaveDays()` in `src/Platform/Services.php` (pattern `:324-327`). It writes nothing. A failed query gives `[]`, so the page falls back to "Leave".

**`includes/trait-attendance-grid.php` `attendance_content()`:**

- Before `:79`: set `$lt_on=$this->leave_type_enabled()` and `$lt=$lt_on?$this->leave_types_by_day(ids of $emps,$dates[0],$week_end):[]`.
- In the callback: compute `$type=$lt_on?GridRules::leaveType($day['actual'],isset($holidays[$d]),$planned,$lt[$eid][$d]??null):null`.
  - When `$lt_on && actual==='Leave' && !holiday`, count `$type` into `$leave_by[$type]` or `$leave_other++`. Count over all matched employees, as the cards do today.
  - Pass `$type` to `badge()` at `:84`.
- Template data:
  - `'cards_shown'=>AttendancePageCards::shown($this->attendance_page_cards(),$lt_on)`;
  - `'leave_card'=>$lt_on?GridRules::leaveSummary($leave_by,$leave_other):null`.
- `data-status` stays `Leave`, so `attendance-grid.js` is unchanged (it reads nothing from the result, grep).

**`templates/app/attendance.php`:**

- `$result` (`:76-81`): when `isset($badge['type'])`, print `<span title="…">name</span>` (escaped) instead of the word lookup. The icon stays `leave`.
- Summary wrapper (`:107`, `:143`):
  - The class gets ` has-leave` when Leave days is shown.
  - It gets ` is-N` when the number of shown cards is not 5.
  - Nothing is added in the default case.
- **Plain cards** (`:107-113`): render from an ordered list filtered by `$cards_shown`. Each line must be echoed with exactly today's bytes: indentation, `\n`, and no `?>` newline eaten. With all five shown and (A) off, golden must see identical HTML (risk 1).
  - Leave card: `<div class="ews-att-stat leave"><span>{icon leave}</span><div><b>N</b><small>Leave days</small><small class="wfo-lt-split">Annual 5 · Sick 2</small></div></div>`.
  - The split line is omitted when empty.
- **Openable cards** (`:115-146`): filter `$ac` by `$cards_shown`. The Leave card is the same plain `<div>`, with no `data-ews-card`, no `data-wfo-sheet` and no chevron. It is not clickable, and `attendance-cards.js` (`:248` reads `data-ews-card`) ignores it (Q3).
- **No card shown:** the summary div is not printed (Q4).

**`assets/css/app-attendance.css`** (+ rtl via rtlcss, `assets/css/README.md`):

- Ship the mockup's rules with the file's prefix, so they only match new markup:
  - `.wfo-app .wfo-att .ews-att-stat.leave>span{background:#F1EAFF;color:#5B34B5}`, the grid's Leave colour (Q5).
  - `.wfo-app .wfo-att .ews-att-stat .wfo-lt-split{…}`.
  - `.wfo-app .wfo-att .ews-att-result.leave span[title]{…ellipsis, max-inline-size:110px}`. `[title]` limits it to typed labels.
- Columns over 1100px:
  - `.wfo-att-stats.has-leave{repeat(6,…)}` when six cards are shown;
  - `.is-1` … `.is-6{repeat(N,…)}`.
- At ≤480px: `.is-2/.is-4/.is-6 .ews-att-stat.rate{grid-column:auto}`. Today Rate spans the row, which would leave a hole with an even count (`:242`).
- ≤1100 (3 columns) and ≤700 (auto-fit) need nothing.
- Not a separate stylesheet: the page already loads this file, and the (B) layout classes are needed with (A) off.

**Strings** (`languages/*.po` + pot/mo/l10n.php, `languages/README.md`):

- New: "Leave days", "Other leave" (if Q1 keeps it), the two Features titles, the descriptions, "(with Leave type)" and the help text.
- The card labels already exist ("Employees", "Present days", "Late days", "Absent days", "Attendance rate"; VERIFIED in `workforce-one-ar.po`).
- Then run `tests/check_translations.py`.

## 5. Off by default

- (A) is off: the badge is called without a type, there are no extra template keys in the markup and no query.
- (B) is unset: `shown()` gives the same five cards in the same order, and no modifier class is added.
- Off therefore means a byte-identical Attendance page. Golden's four Attendance pages plus emp1's prove it. Nothing is declared for them.
- `tests/golden/expected.json` declares only the two Features pages: `/wp-admin/admin.php?page=ews31-features` with `{}` and with `{"ews_feature_week_lock":1}`, because of the new sections.

## 6. Tests

**Unit**

- `tests/unit/AttendanceGridTest.php`:
  - `leaveType`: holiday wins; planned other than Vacation gives null; actual not Leave gives null; an empty or space-only name gives null; a typed day returns the trimmed name.
  - `badge` with a type: class `leave`, `type` key, time kept.
  - `badge` without a type: equal to today.
  - `leaveSummary`: ordering, ties, the other entry.
  - `shortType`: English and Arabic, never empty.
- New `tests/unit/AttendancePageCardsTest.php`: default is all shown; unknown or duplicate keys are dropped; `fromPost`; `shown()` without (A) drops `leave`; the order is fixed.
- `OptionsTest` covers the two registrations.

**e2e**

Extend `tests/e2e_attendance_grid.py`. It already has `cell()`, `stat()` and a holiday case (`:42-51`, `:101-103`). Record leave through the real path, wp-admin Record Leave (`includes/trait-leave-admin.php:53`), as `tests/e2e_leave_admin.py` does.

- Three types (Annual, Sick, Casual) for three people, one of them over two days.
- A leave approved and then cancelled through the cancellation flow: the planned status is restored and there is no type.
- A holiday on a leave day: "Leave" with the holiday name.
- A Vacation set by hand with no request: "Leave".
- An approved day changed by hand to a custom leave-rule status: "Leave".
- The type renamed after approval: the new name. The type deactivated: still shown.
- A request with `leave_type_id=0`: "Leave".
- The Leave days card: total and split. The class `has-leave`.
- Off: no "Leave days", no `has-leave`, the cells read "Leave", and the HTML is unchanged before and after seeding the setting with off.
- Query count from on to off is at most +1 (the `EWSQ` counter golden normalises).
- (B): hide people and present. Check they are absent, the class is `is-4`, and the others are present.

`tests/e2e_attendance_cards.py`, with `ews_feature_attendance_cards=1`:

- Hidden cards are not printed.
- The Leave card is a `div` without `data-ews-card`.
- The other cards still open.

`tests/e2e_features.py`:

- The switch round trip.
- Checkbox round trip; saving the defaults stores nothing.
- The Settings overview shows "Customised" after hiding a card.

**Static checks:** phpstan, phpcs, `check_modules.py` (no new crossing, since the functions are in the apis), `check_layers.py`, `check_translations.py`.

**Golden:** run it. The Attendance pages must be the same; only the Features pages are declared.

## 7. Risks

1. **Lost newlines in the plain-cards loop.** Turning the five literal lines into a loop is easy to get one byte wrong: a `?>` at the end of a line eats the newline. Echo strings with explicit `\n` and indentation, and let golden judge.
2. **The mockup's ellipsis rule** would restyle today's "Leave" cells. It is scoped to `span[title]` here.
3. **Type names are admin text.** Escape them everywhere (`esc_html`, `esc_attr` for `title`). A very long name is cut with an ellipsis in the cell; the full name is in `title`.
4. **Pre-snapshot approvals** show "Leave". This is acceptable, as today (ASSUMED rare).
5. **Large teams.** One `IN (…)` over all matched ids, like `insights_load()`. If >5,000 employees ever matter, chunking adds queries (none today).
6. **Two meanings of "Leave".** `$summary['Leave']` (holidays included) stays as it is and is not shown. The Leave days card uses its own count (Q1), so the two numbers can differ. This is intended.

## 8. Open questions for the owner (★ = recommended)

1. **What does the Leave days card count?**
   - ★ (a) Days of leave per person, without company holidays. Approved leave by type, plus the days set to a leave status by hand or old leave as "Other leave N" in the line under it, so the line adds up to the number.
   - (b) Approved leave requests only; the number equals the types under it, as in the mockup.
   - (c) Every day the grid shows as Leave, holidays included.
2. **Short names in the line under the card.**
   - ★ "Annual 5 · Sick 2 · Casual 1": " Leave" or "إجازة " is removed from the name, as in your decision and the mockup.
   - Or the full names as entered: "Annual Leave 5 · Sick Leave 2".
3. **When the cards open lists**, does Leave days open one too?
   - ★ No: it is a plain card that does not open.
   - Or yes: a list of who was on leave, which day and which type. That needs a new list, so a bigger change.
4. **May the administrator hide all six cards?**
   - ★ Yes: the row of cards simply disappears.
   - Or at least one must stay.
5. **Colour of the Leave days card icon.**
   - ★ The purple that Leave already has in the grid (decision 3: same colour as Leave).
   - Or the amber in the mockup.
6. **A day of approved leave that a manager later changed by hand to another leave status:**
   - ★ It shows "Leave", as a hand-set day (decision 4).
   - Or it still shows the approved leave's type.

## 9. Owner answers (2026-11-18)

1. Leave days counts days of leave per person without company holidays: approved leave by type, plus "Other leave N" for hand-set or old leave, so the line adds up to the number. (★ a)
2. Short names ("Annual 5"), as recommended and as decided earlier.
3. **Yes: Leave days opens a list** when the cards open lists (who was on leave, which day, which type). This changes the design; see section 10.
4. The administrator may hide all six cards; the row of cards then disappears.
5. The icon is the purple Leave already has in the grid.
6. An approved day changed by hand to another leave status shows "Leave", as a hand-set day (decision 4).

## 10. The Leave days list (owner answer 3, replaces "the Leave card does not open")

Mockup: `docs/mockups/leave-type/sheet.{en,ar}.html`. It is the same sheet as the other cards (`docs/tasks/attendance-cards.md`).

**When it exists:** only with both Attendance cards (`ews_feature_attendance_cards`) and Leave type (`ews_feature_leave_type`) on. With cards off, the Leave days card is the plain `div` from §4.

**Card:** `button.ews-att-stat.leave.wfo-ac-card[data-ews-card=leave]`. It looks like the other card buttons, with the split line under the label.

**Endpoint** `att_cards_data` with `kind=leave`:
- Refused with `reason=kind` (400) unless Leave type is on. `CardLists::KINDS` gains `leave`.
- One extra query: `leave_types_by_day()` for the matched ids and the week, made only for this kind.
- A row is a day with `actual === 'Leave'` that is not a company holiday. These are the same days the card counts (§4).
- Each row gives the person, `date` and `type`: the approved type's name, or `''` for a hand-set or untyped day.
- Order: by day (grid order within a day) or by employee, the same segmented control as Late, Present and Absent. 20 per page.
- `card` is the count, so the card follows the list as the others do.

**Sheet:**
- The icon is `leave`, in purple.
- Title "Leave days". Subtitle "Who was on leave, which day, and which type".
- Note: "Leave days with their type. Company holidays are left out; a day set to leave by hand shows "Leave"."
- Each row has a purple pill with the type name, or "Leave" when the type is `''`.
- There is no Open in Reports link, since the Reports have no leave-type view.
- Empty: "No leave days this week."

**Texts:** these keys are added to `data-text` only when Leave type is on, so the Attendance page with cards on and Leave type off stays byte-identical.

**Tests** (`tests/e2e_attendance_cards.py`):
- `kind=leave` is refused when Leave type is off.
- With it on, it gives the rows with types, leaves out the holiday, and its total equals the card.
- Paging and order by employee work.
- In the browser, the Leave card opens the sheet with the pills.

## 11. Review (2026-11-18) and what was done

Reviewer: read-only, on Opus. No money, permission, SQL or off-by-default defect was found. Byte identity of both card branches was traced by hand.

1. **CONFIRMED, fixed.** The version was not bumped; scripts and styles would be served from the browser cache. It is now 3.31.152.
2. **CONFIRMED, fixed.** The audit action `leave_type_update` already means "a leave type was edited". The switch is audited as `leave_type_feature_update`.
3. **PLAUSIBLE, fixed.** With Leave type switched off while a page is open, the Leave days list answered `kind` (400), and the page offered "Try again" for ever. It now answers `off` (404), so the page says "Not available".
4. **PLAUSIBLE, fixed.** The line under Leave days did not follow the list when a leave changed since the page was opened. The list now returns the line, and the card updates it with the number.
5. **PLAUSIBLE, fixed.** Two types with the same short name ("Annual", "Annual Leave") are now one entry in the line.
6. **CONFIRMED, fixed.** Four stray spaces were printed when every card is hidden with the card lists on.

**Differences from the design, kept:**
- The switch and the card setting each have their own audit, written only on change, instead of a `leave_type=` field in the `feature_update` line. That line is unchanged, so a save that changes nothing writes nothing new.
- Six cards sit in one row only from 1,500 px wide. From 1,101 to 1,499 px they are two rows of three, because six with the open-list chevron broke words ("Employee s") at 1,300 px. The `has-leave` class is printed but has no rule of its own.
- `badge()` with a type still returns its label, which the template does not use.
