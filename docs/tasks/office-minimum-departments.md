# Office minimum by department

Owner request (2026-11-18): "عايزين نخلي manager office minimum و office minimum مرتبطة بالإدارة عشان كل إدارة هيبقى ليها الطبيعة بتاعتها".

## Owner answers (2026-11-18)

1. **The company and departments.** The company's minimum stays as it is, so nothing changes for the current company. Any department may have its own minimum, for people and for managers, checked on its own. A department without a number has no check of its own.
2. **A department's managers** are the team managers in that department (as in 3.31.153).
3. **Who sees what.** Someone who sees only their department sees their department's counters and warning. The administrator sees the company and can pick a department to see its numbers.
4. **A separate release after 3.31.153** (PR #54 is merged as it is, when the owner says so).

## Step 1: mockups, then STOP

Mockups: `docs/mockups/office-minimum-departments/` (dept, phone, admin, company, settings; Arabic and English), built by `docs/mockups/_kit/screens_omdept.py`.

- **Feature Configuration → Office Minimum → "Each department's own minimum":** a table of departments with people, "in the office each day" and "managers each day". Empty means no minimum of its own.
- **Attendance page, a department manager** (Sales: 4 people and 1 manager):
  - A bar above the day counters says whose minimum the page shows.
  - The counters, the card and the sheets use the department's numbers and people.
- **Attendance page, the administrator:** the same bar, with "Minimum for: The whole company / Sales / …". The whole company shows exactly what it does today.

## Owner answers on the design (2026-11-18)

5. **A department's number is the same every working day** (★); no per-weekday number for departments for now.
6. **Teams' shares inside a department:** the department's number is shared among its teams by size, as the company's is. The administrator can also **fix a team's share inside its department** (a fixed number per team, per department), like the fixed team numbers of the company.
7. **The weekly reminder:** a department with its own number goes to the schedule managers of that department (★). The administrator keeps the company's reminder as today.
8. **A department manager whose department has its own number sees only their department's numbers** (★), not the company's warning.

## Correction (owner, 2026-11-18): a full copy of the settings per department

"انت فاهمني غلط، أنا عايز الموضوع زي ما هو شغال في الأصل، بس أضيف لكل إدارة طريقة شغلها وتنظيمها لجدولها."

This replaces answers 1, 5 and 6 above:

- **Each department can have its own full Office minimum settings,** exactly the form there is today:
  - on / off;
  - the minimum, and a number per weekday;
  - which statuses count as in the office;
  - the teams' shares, with fixed numbers for its own teams;
  - the managers' minimum;
  - the reminder.
- **Feature Configuration → Office Minimum** has a tab per department. A department tab has "<Department> has its own settings". Ticked, the form below is that department's: its people, its teams, its managers.
- **A department without its own settings works with the company's,** exactly as today. So nothing changes for the current company.
- **Answers 2, 3, 7 and 8 still hold:**
  - A department's managers are the team managers in it.
  - A department manager sees their department's numbers, and only those, when it has its own settings.
  - The administrator sees the company and can pick a department.
  - The reminder goes to that department's schedule managers.

Mockups: `settings` (the Sales tab, with its own settings) and `settings-company` (the company tab, as today), next to the Attendance screens.

# Design (architect, read-only, 2026-11-19): Office minimum by department

Paths are relative to `/home/user/WFplugin/` unless they start with `workforce-one/`. Line numbers are from branch `claude/office-minimum-departments` (3.31.153 merged).

## 0. Findings that shape the design

1. **`fromPost()` drops every key it does not know.** `OfficeMinimumSettings::fromPost()` (`workforce-one/src/Settings/OfficeMinimumSettings.php:62-91`) builds a fresh array. Any `depts` key would be lost on the next company save unless it is carried over on purpose.
   - The same holds for **3.31.153 itself**. Going back to it keeps working, but its first Feature Configuration save drops every department's settings. This is the same kind of downgrade note as the managers' keys in 3.31.153; put it in the CHANGELOG.
2. **Teams belong to a department** (`ews_teams.department_id`, required on the Teams page). `TeamService::save` (`workforce-one/src/People/TeamService.php:79-86`) refuses members or a manager from another department.
   - An employee who moves department later stays a member of the old team, so a team *can* span departments.
   - Archiving a department needs it empty (no active employees, no teams), `DepartmentService::archive` (`workforce-one/src/People/DepartmentService.php:78-84`). So an archived department never has active people. Its stored settings become orphans, not live data.
3. **`om_people()` already reads `SELECT *` from employees** (`workforce-one/includes/trait-office-minimum.php:48`), so `department_id` is in hand. A department's people, team sizes and managers can be derived **in PHP with no query**.
4. **The live counter adds every changed cell on the page** (`workforce-one/assets/js/office-minimum.js:146-154`). Today that is right: the base is company-wide and a department viewer's grid holds only their department. An administrator looking at *Sales* has the whole company in the grid, so the JS must count only Sales's cells.
5. **`check_modules` counts call sites.** `trait-office-minimum` (schedule) calling `current_department_id()` (people, not in people's `api`) would raise `schedule->people` above the baseline (6) and fail. New Features-page calls into schedule must be in schedule's `api`, as `om_admin_managers` is.
6. **Golden** (`tests/golden/run.py:281`) checks "no *more* queries" (`0 <= new <= base`) and identical HTML.
   - The Attendance page with Office minimum on is rendered twice for `admin`, and the Leave page once (`tests/golden/pages.json:367-392`).
   - `emp1` (Mona, department manager with `ews_manage_attendance`, Ops) has an Attendance page **without** Office minimum. There is no department-viewer page with it on; add one (§11).
   - The Settings overview page is not in golden.
7. **Registering a new option in `src/Settings/Options.php` adds a row and a `get_option` query to the Settings overview.** That page changes on purpose only if we add the department reminder bookkeeping option (§6).

## 1. Data model and storage

Extend `ews_office_minimum` with **one optional key**, stored only when at least one department has its own settings:

```php
'depts' => [ <dept_id:int> => [ 'min'=>int, 'days'=>[w=>n], 'statuses'=>[...], 'teams'=>[team_id=>n],
                                'reminder'=>0|1, 'reminder_day'=>0..6, 'reminder_time'=>'HH:MM',
                                /* only when not default, exactly as the company: */ 'mgr'=>1, 'mgr_min'=>n, 'mgr_days'=>[...] ] ]
```

- **Presence of `depts[D]` = "D has its own settings".** There is no separate `own` flag (open question 1 asks whether a department also needs its own on/off).
- **Unticking "has its own settings" removes `depts[D]`.** When `depts` becomes empty the key is unset, so the stored option returns to exactly what the company alone stored.
- **`OfficeMinimumSettings::config()` is unchanged and ignores `depts`.** So `om_settings()`, the audit comparisons, the Settings overview reader (`workforce-one/includes/trait-settings-overview.php:183`) and every company path see the same array as today.
- **Upgrade:** none (no schema, no DB version). Downgrade: see §0.1.
- **The global switch `ews_feature_office_minimum` stays the master.** Off means no department check, no bar, no note and no reminder.

New pure methods in `workforce-one/src/Settings/OfficeMinimumSettings.php`:

| Method | What it does |
|---|---|
| `departments($saved): array<int,array>` | Each `depts[D]` through `config()` (defaults for missing keys). Bad ids or non-arrays are skipped. |
| `fromPost()` (change, `:62-91`) | After building the company array: `if (is_array($saved) && !empty($saved['depts'])) $out['depts'] = $saved['depts'];` (carried verbatim, so a company save that does not touch departments stores what it stored before). |
| `departmentFromPost(array $post, array $known, $saved, int $dept): array{0:array,1:string}` | Returns the **whole option to store**. Company keys come from `$saved` untouched. Without `om_dept_own`: `unset(depts[$dept])` (and `depts` when empty). With it: validates the same `om_*` fields through the same code as `fromPost` (extract a private `partFromPost($post,$known,$savedPart)`, so the managers' "keep when off" rules use the department's saved part) and writes `depts[$dept]`. Same error codes (`min`, `day`, `team`, `statuses`, `time`, `mgr_min`, `mgr_day`). |

## 2. Pure rules (new `workforce-one/src/Schedule/OfficeMinimumScope.php`, no WordPress)

| Function | Rule |
|---|---|
| `own(array $depts, array $activeIds): array<int,array>` | Departments with own settings that are still active. |
| `people(array $p, int $dept): array` | `om_people()`'s shape restricted to people whose `dept === $dept`. Team **sizes recounted among the department's people** (each person in their primary team, as today). Teams kept in the same order (named, natural order, "No team" last). `managers` = team managers whose **own** `department_id` is `$dept` (open question 3). |
| `forViewer(bool $admin, int $viewerDept, int $picked, array $own): int` | Administrator: `$picked` if it is in `$own`, else 0. Anyone else: `$viewerDept` if it is in `$own`, else 0 (no department means 0 = the company, as today). |
| `reminderGroups(array $userDept, array $adminIds, array $own): array<int,list<int>>` | Splits the schedule managers into `0 => company` and `D => department`. Each user lands in **exactly one** group. Admins are always in 0. A user whose department has own settings goes to D, never to 0. |

Unit-testable. `OfficeMinimum` and `ManagerMinimum` stay untouched.

## 3. Trait changes (`workforce-one/includes/trait-office-minimum.php`)

- **`om_people()` (`:44-67`).** Add `'dept' => (int)($e->department_id ?? 0)` to each `people` entry. It is an extra array key only: no output and no query change.
- **New scope helpers** (no query unless `depts` is stored):
  - `om_dept_settings()`: `OfficeMinimumSettings::departments(get_option('ews_office_minimum',[]))`, static-cached.
  - `om_own_depts()`: `[]` when `om_dept_settings()` is empty. **Otherwise one query** for the active department names, through a new people-api function `people_department_names()` (in `trait-departments.php`, wrapping the existing `services()->teams()->pageDepartments()`, memoized). Returns `OfficeMinimumScope::own(...)` plus names.
  - `om_cfg($dept)`: `$dept ? om_dept_settings()[$dept] : om_settings()`.
  - `om_people_for($dept)`: `$dept ? OfficeMinimumScope::people(om_people(), $dept) : om_people()`, static-cached per department.
  - `om_viewer_dept($picked=0)`: `OfficeMinimumScope::forViewer(current_user_can('manage_options'), (int)$this->current_department_id(), $picked, array_keys(om_own_depts()))`. Returns 0 at once when `om_dept_settings()` is empty, so the company path makes no extra call.
- **`om_counts`/`om_could_come` (`:31-37`)** take an optional `$cfg`; the default is the company's, as now.
- **`om_mgr_on($dept=0)`**: `om_enabled() && !empty(om_cfg($dept)['mgr'])`.
- **`om_days(array $dates, array $override=[], int $dept=0, ?array $map=null)` (`:75-110`)**
  - `$cfg = om_cfg($dept)`, `$p = om_people_for($dept)`.
  - When `$map` is given, the schedule query at `:85` is skipped. The reminder loads it once for the company and every department, so there is **no query per department per day**.
  - With `$dept=0, $map=null` it is the same code, the same single query and the same result.
- **`om_attendance($dates)` (`:113-130`)**
  - `$dept = om_viewer_dept(admin ? absint($_GET['om_dept'] ?? 0) : 0)`; everything else is computed for `$dept`.
  - Adds keys **only when departments have own settings**:
    - `'scope' => ['dept'=>D, 'name'=>…, 'min'=>cfg.min, 'mgr_min'=>cfg.mgr ? cfg.mgr_min : null, 'choices'=>admin ? [0=>company] + own names : null]`
    - `'people' => ids` (only when `$dept > 0`)
  - `om_mgr_visible()` is unchanged. In a department's own scope a department viewer sees all its managers unmasked; an admin sees all.
- **`om_leave_impact($eid, $start, $end)` (`:136-170`)**
  - `$dept = (int)om_people()['people'][$eid]['dept']`, used only if it is in `om_own_depts()`, else 0.
  - Same two `om_days` reads, with `$dept` (same query count as today).
  - When `$dept > 0`, add `'dept' => name`. The company note is not computed (open question 5).
- **`om_admin_teams($dept=0)`, `om_admin_managers($dept=0)` (`:173-217`)** take the department; the company default is unchanged.
- **New `om_admin_departments()`** for Feature Configuration: `[id => ['name', 'own'=>bool, 'people'=>count]]` from `people_department_names()` and `om_people()`.
- **`om_tick()` / `om_remind()` (`:224-266`)**: see §6.

## 4. Attendance page, per viewer

| Viewer | Departments with own settings? | Sees |
|---|---|---|
| Anyone | none stored | **Exactly today**: no bar, no new attributes, same queries |
| Administrator | yes | Bar "The whole company · N in the office · M managers" with the **"Minimum for"** select (company + departments with own settings). Default is the company, as today. `?om_dept=D` shows D's counters, card, sheets and managers. |
| Department viewer, own department has own settings | yes | Bar "Sales department · 4 in the office · 1 manager" (no select). Only Sales's numbers, never the company's. |
| Department viewer, own department has no own settings | yes | As today (company numbers, no bar) |
| Non-admin with no department | any | As today (company) |

**Files:**
- `workforce-one/includes/trait-attendance-grid.php:145-158`: pass `'office_min_scope_html'` (render the new `templates/app/office-minimum-scope.php` only when `$office_min['scope']` is set, else `''`).
- `workforce-one/templates/app/attendance.php:239`: print the scope HTML **on the same line, before** `<div class="ews-day-quick-actions">` (where the mockup puts it), so `''` adds no byte.
- `workforce-one/templates/app/attendance.php:269`: inside the existing `if ($office_min)`, add `data-om-people="[ids]"` only when `isset($office_min['people'])`. `data-om-managers` already carries the scope's managers.
- **New `templates/app/office-minimum-scope.php`:** `.wfo-omd-bar` with `.wfo-omd-what` and, for admins, a GET `<form class="wfo-omd-pick">`. It keeps `list_keep_args('attendance')` (view, week, branch, …), then `<select name="om_dept" data-omd-pick>` and a `<noscript>` submit.
- `workforce-one/includes/trait-core.php:142-146` (`list_keep_args`): add `'om_dept'`. It is added only when it is in `$_GET`, so this is byte-identical otherwise. The search form and the team filter keep the choice; prev/next already keep it (`add_query_arg`).
  - The grid save redirect returns to the company: acceptable; list it as a note.
- `templates/app/office-minimum.php:21` and `office-minimum-managers.php:90`: with `$om['scope']['dept'] > 0`, the heading uses "Sales: %d day(s) below its minimum" (the mockup). Otherwise the existing strings.
- `office-minimum-teams.php:65`: with a department, "÷ the department's employees (%1$d)".
- **`assets/js/office-minimum.js`:**
  - `var only = JSON.parse(table.getAttribute('data-om-people') || 'null')`.
  - In `update()`, skip cells whose `data-employee` is not in `only` when it is set.
  - Bind `change` on `[data-omd-pick]` to submit its form.
  - No new strings.
- **Grid rows: the admin's grid is not filtered when a department is picked** (open question 6). The counters still count only the department, through `data-om-people`.

## 5. Leave approval note

- `templates/app/leave.php:118-122`: when `isset($r->om['dept'])`, prefix both notes with `<span class="wfo-omd-for">Sales</span>`. No other change; without `dept` the output is identical.
- **Proposed rule:** an employee in a department with own settings gets **the department's note only**, for every approver. Otherwise the company note, as today.
- Department ids and names come from the already-loaded `om_people()` and `om_own_depts()`, so there are no queries per row.

## 6. Weekly reminder

- **`om_tick()`:**
  1. The company part is unchanged (same day, time and `ews_office_minimum_reminded`).
  2. Then, only when `om_own_depts()` is non-empty: for each department with `reminder=1` whose day and time have come, dedupe in the new non-autoloaded option `ews_office_minimum_reminded_depts` (`[dept => 'o-W']`).
- **Recipients:** `get_users(capability ews_manage_attendance)` as today. Only when departments have own settings, also:
  - `get_users(capability manage_options, fields ID)` (1 query);
  - `emps()` (people api, 1 query) for user → department;
  - then `OfficeMinimumScope::reminderGroups()`.
- **One notification per person per week.** Each user is in one group:
  - administrators and people without a department, or whose department has no own settings, get the **company** reminder (as today);
  - schedule managers of D get **D's** reminder. If D's reminder is off, they get none: they see only D's numbers.
- **`om_remind($day, int $dept=0, ?array $users=null, ?array $map=null)`.** Same text. For a department, the title starts with "Sales: " (new strings). Audit `office_minimum_reminder` with entity `department`, id D.
  - With no departments: the same users, text and audit as today.
- **Options:** register `ews_office_minimum_reminded_depts` in `src/Settings/Options.php` (system, internal), next to `ews_office_minimum_reminded` (`:201`). The Settings overview gains one row (declare it if that page is ever in golden).

## 7. Feature Configuration: tabs and save

- **Tabs** (`templates/admin/features.php:243-282`):
  - Only when at least one active department exists, a `<nav class="wfo-omd-tabs">` before `.wfo-om-admin`: "The whole company", then each active department with a `<small>` reading "own settings" or "as the company".
  - Tabs are **links**: `?page=ews31-features&om_dept=D#ews-office-minimum` (server-side; one form; one department per page load).
  - Tab 0 is exactly today's section plus the nav.
- **Department tab:**
  - Hidden `om_dept=D` and `om_dept_present=1`.
  - `.wfo-omd-own` checkbox `om_dept_own`: "Sales has its own settings", with the mockup's description.
  - Below it, the same form (same field names). Values are D's settings, or **the company's as a starting copy** when D has none, without team fixed numbers.
  - The teams table is `om_admin_teams(D)`, the managers list is `om_admin_managers(D)`, and the "only N managers" warning is per department.
  - Unticked: fields are shown but ignored on save (optional small JS to disable them).
  - Warn when the department has 0 counted people.
- **`includes/trait-features.php`:**
  - `:70`: pass `om_dept` (from `$_GET`, validated against `om_admin_departments()`, else 0), `om_departments`, and the tab's settings, teams and managers.
  - `:88-91`: if `isset($post['om_dept_present']) && (int)$post['om_dept'] > 0` and it is an active department, call `departmentFromPost(...)`. Else call `fromPost(...)` (also for older forms without these fields).
  - Error redirect: `features_redirect(['features_error'=>'om_'.$e, 'om_dept'=>D])`. The success redirect also carries `om_dept`.
  - `:218-228` audit:
    - compare **company keys only** (strip `depts`), so a department save writes no company line;
    - a department save writes `office_minimum_department_update` (entity `department`, id D) with `own=on|off; min=…; statuses=…; reminder=…[; managers=…]`.
- **`src/Settings/FeatureSettings.php:142-148`:** the messages are unchanged. The tab the admin lands on shows which department failed.
- **Settings overview:** no change to `Options.php:134`. Optionally, a case in `settings_overview_grouped` that appends "; N departments with their own settings" only when `depts` is non-empty.

## 8. modules.json / check_modules

- `docs/architecture/modules.json`:
  - people `api` += `current_department_id`, `people_department_names`;
  - schedule `api` += `om_admin_departments` (and keep `om_admin_managers`; also add `om_admin_teams` if its count would rise).
- `emps` and `notify` are already in an api.
- No new tables, hooks, cron, admin-post or REST: `hooks_added: []`.

## 9. Translations

New strings (`.pot`, `-ar.po`, `-ar.mo`, `-ar.l10n.php`, Egyptian Arabic from the mockups; no JS strings):

- Minimum for
- The whole company
- %s department
- "· %d in the office" / "· %d managers" (`_n`)
- "%1$s: %2$d day below its minimum" (`_n`)
- Each team's share = minimum × team size ÷ the department's employees (%1$d)…
- Departments
- own settings
- as the company
- %s has its own settings
- the description of the own-settings checkbox
- %s's team managers (%1$d): %2$s
- the reminder title prefix "%1$s: %2$s"
- "This department has no people counted yet."

## 10. Performance

| Case | Cost |
|---|---|
| No `depts` stored | Zero extra queries anywhere (all new code exits on an empty `om_dept_settings()`) |
| Departments used, Attendance or Leave page | +1 query (active department names, memoized) |
| A department's scope | Derived from the one `om_people()` read |
| Reminder | One schedule read for next week shared by the company and all departments, +2 queries for the recipient split |

## 11. Edge cases

| Case | Behaviour |
|---|---|
| Employee without a department | Company only |
| Department archived | Has no active people; its entry is ignored (not a tab, not selectable); left in the option until that tab's next save |
| Team spanning departments | Appears in each department with the members it has there; fixed numbers are per department (`depts[D].teams`) |
| Manager in another department than their team | Counted in **their own** department (open question 3) |
| Department with own settings, feature off | Nothing anywhere (the global switch is checked first) |
| Viewer with no department (non-admin) | Company, as today |
| Admin `?om_dept=` a department without own settings, or garbage | Company; the select shows "The whole company" |
| Department viewer with `?om_dept=` | Ignored (non-admins never pick) |
| Department with own settings and 0 people | Every checked day short; the Features tab warns |
| Department minimum above its managers | Same warning as today, per tab |

## 12. Tests

**Unit**
- `tests/unit/OfficeMinimumScopeTest.php`:
  - `people()`: department filter, team sizes recounted, team order, "No team" last, a manager counted by their own department, a spanning team;
  - `forViewer()`: all rows of §4;
  - `own()`: archived ignored;
  - `reminderGroups()`: each user once, admins in company, a department with reminder off.
- `OfficeMinimumSettingsTest`:
  - `fromPost` carries `depts` verbatim;
  - without `depts` the result equals the 3.31.153 shape;
  - `departmentFromPost`: own on stores a full part, own off removes it, the last removal unsets `depts`, company keys untouched, errors per field, managers' keys only when used;
  - `departments()` parsing.

**e2e: new `tests/e2e_office_minimum_departments.py`** (add to `.github/workflows/ci.yml` next to `:243`).

Seed:
- departments Sales and Ops plus one archived;
- Sales teams Cairo and Alex, Ops team Field, a person in no department;
- a Sales member moved to Ops (spanning team);
- an Ops person managing a Sales team;
- users: admin, a Sales schedule manager, an Ops schedule manager, a schedule manager with no department.

Checks:
- **Baseline:** Office minimum on, no department settings: capture the admin and Sales-manager Attendance pages and the Leave page. They must be byte-equal to the pages after a company-tab save (nonces stripped). The stored option has no `depts` key.
- **Features page:**
  - tabs list the active departments only;
  - the department tab shows the department's teams and sizes and its managers;
  - validation errors land back on the tab, nothing saved;
  - an older form (no `om_dept_present`) keeps `depts`;
  - a company save keeps `depts`;
  - unticking removes it;
  - the audit lines (`office_minimum_department_update`; no company line on a department save).
- **Attendance:**
  - Sales manager: the bar, Sales counters (people and managers), card heading "Sales: …", sheets with Sales teams only, `data-om-people` equal to the Sales ids;
  - Ops manager (no own settings): page as captured;
  - admin default: company unchanged plus the bar with the select;
  - admin `om_dept=Sales`: Sales numbers;
  - admin `om_dept=Ops`: company.
- **Leave note:** a Sales employee's leave shows the Sales note with its label; an Ops employee's shows the company note.
- **Reminder:**
  - Sales managers get the Sales reminder only;
  - admin and Ops get the company one;
  - each user gets exactly one notification;
  - dedupe per department.
- **Feature off:** nothing shows, settings kept.
- **Browser check (optional):** change a Sales cell while the admin views Sales; a non-Sales cell does not move the counter.

**Golden** (`tests/golden/pages.json`, declarations in `expected.json`):
- Add:
  - `emp1 /app/?ews_view=attendance&week={week}` with `{"ews_feature_office_minimum":1}` (department viewer, no department settings);
  - `admin` Attendance with `{"ews_feature_office_minimum":1}` and `&om_dept={ops}` (ignored without settings, so it must equal the base).
- `expected.json`:
  - release 3.31.154;
  - pages: the two Features page entries (reason "Office minimum by department: department tabs");
  - actions `{}`;
  - `hooks_added: []`.
- Every other page, including the three existing Office-minimum pages, must stay identical with no extra query.

## 13. Risks

- **Downgrade drops `depts`** on the first Features save in 3.31.153 (CHANGELOG).
- **The company-path refactor of `om_days`/`om_attendance`.** Keep `$dept=0` on the exact current code; golden and the two existing e2e suites guard it.
- **Tab switching reloads the Features page** and loses unsaved edits elsewhere on the form. Say "Save before switching" in the tab note, or add a dirty-form prompt.
- **`check_modules`:** both api additions are needed, or CI fails.
- **The JS counter for an admin viewing a department** relies on `data-om-people`. Without it the counter would add other departments' cells.
- **The reminder recipient split** must never send two notifications to one person (unit test on `reminderGroups`).
- **Paging:** the grid can be paged or filtered. The base stays server-side for the scope, and the live delta is page cells only (as today).

## 14. Open questions for the owner

1. **A department's own on/off.** The mockup has only "Sales has its own settings". Is that the department's switch (★), or should a department with own settings also be able to turn its minimum off (no check at all for that department)?
2. **The company check.** Should it keep counting everyone, including departments with their own settings (★, nothing changes for the company), or only the people in departments without their own settings?
3. **A team manager whose own department differs from their team's department.** Counted in their own department (★) or in the team's department?
4. **Teams' shares inside a department.** Team size counted among the department's people, each person in their primary team (★). Or use only teams whose Teams-page department is this department?
5. **Leave note.** For an employee in a department with own settings, show the department's note only (★), or also the company's note to the administrator?
6. **Admin picks a department.** Should the grid also show only that department's people, or only the numbers change (★, as in the mockup)?
7. **The "Minimum for" list.** Only departments with their own settings (★, as in the mockup), or all departments, with "as the company" for the others?
8. **A department with own settings and its reminder off.** Its schedule managers get no reminder (★, they see only their department), or the company's?
9. **First tick of "own settings".** Start from a copy of the company's settings without team fixed numbers (★), or from the plugin defaults?

## Owner answers to the design's questions (2026-11-19)

1. **No separate on/off for a department** (★). "Has its own settings" is the switch; a minimum of 0 means no check.
2. **The company check keeps counting everyone** (★), as today.
5. **Leave note:** an employee in a department with its own settings gets the department's note only (★).
6. **An administrator picking a department:** only the numbers change; the grid stays the whole company (★).

Not asked; the recommended answers apply:
- Q3: a manager counts in their own department.
- Q4: teams' sizes are counted among the department's people.
- Q7: the list offers only departments with their own settings.
- Q8: a department with its reminder off gets no reminder.
- Q9: the first tick starts from a copy of the company's settings, without the team fixed numbers.
