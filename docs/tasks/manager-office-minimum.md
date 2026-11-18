# Manager office minimum

Owner request (2026-11-18): "عايزين نضيف فيتشر جديدة اسمها manager office minimum… مع الـ office minimum كمان وهي شغالة".

Owner answer: **a number of managers each day**, company-wide. That rules out "a manager per team every day" and "office days per manager".

## Proposal (Step 1: mockups, then STOP)

Mockups: `docs/mockups/manager-office-minimum/` (week, managers, phone, off, leave, settings; Arabic and English).

Everything works like the Office minimum (`docs/tasks/office-minimum.md`): a warning only, never blocking. It is a separate switch under the Office Minimum section and is off by default. Off, the Office minimum is exactly as today.

- **Settings** (Feature Configuration → Office Minimum → Managers in the office):
  - on / off;
  - managers each day (2 by default in the mockup);
  - an optional different number per weekday.
  - It uses the Office minimum's "in the office" statuses, working days and weekly reminder.
- **Who is a manager:** the team managers (the "Manager" badge the Attendance page already shows, from the Teams page). They also count in the people minimum.
- **Attendance page** (whoever sets the schedule):
  - **Counters:** a second counter under each day ("managers in the office / minimum"), in the table head and in the "Set…" row. It is red when short and updates live while editing, like the people counter.
  - **One warning card:** it lists each short day with what is short (people, managers or both) and a Teams and/or Managers button.
  - **The Managers sheet of a day:** each manager with their plan that day (Office, WFH, Leave, Trip); those working from home are marked "Could come in".
- **Approving leave / WFH / swaps:** when the person is a manager and approving would drop the day below the managers' minimum, a second note says so, with who could come in.
- **Weekly reminder:** next week's days short of managers are added to the same reminder.
- **Reports:** the "Days below minimum" list gets a managers column.

## Open questions for the owner

1. **Who counts as a manager?**
   - ★ The team managers (from Teams).
   - Or anyone with a manager role (approvers, department managers).
   - Or a list the administrator picks.
2. **Does the managers' minimum need the Office minimum on,** as in the mockup (a sub-section)? Or is it its own feature that can be on alone?
3. **A manager of two teams:** counted once (★).
4. **The reminder:** the same one as the Office minimum (★), or a separate one only to managers.

## Owner answers (2026-11-18)

1. **Who counts as a manager:** the team managers (from Teams). ★
2. **Where it lives:** inside the Office minimum, as a sub-section that needs the Office minimum on. It uses the same statuses and the same reminder. ★
3. **The reminder:** the same weekly reminder as the Office minimum. ★

Not asked; the recommended default applies: a manager of two teams counts once.

# Design (architect, read-only, 2026-11-18)

All paths below are relative to `/home/user/WFplugin/` unless they start with `workforce-one/`. Line numbers are from the current branch `claude/manager-office-minimum` (HEAD a0f9885).

## 0. Findings that change the brief

Check these before building. They change what "extend the existing Office minimum" can mean.

1. **The Attendance page with the Office minimum on is not in golden today.** `tests/golden/pages.json` has no page whose options include `ews_feature_office_minimum`. The only Office-minimum-related golden pages are the Features page (two entries, both allowed to differ in `tests/golden/expected.json`) and pages where the feature is off. To prove "manager part off means byte-identical with the Office minimum on", we have to **add** those pages (see §11).
2. **There is no Reports "Days below minimum" list and no Location Capacity minimum line.** The 3.31.77 CHANGELOG (`workforce-one/CHANGELOG.md` ~1310) says: "Not in this release: the Location Capacity report line and a 'days below the minimum' report". No report code references `om_*`. A "managers column" has nothing to attach to. This goes to the owner as an open question.
3. **Only leave approval has an Office-minimum note.** There is none for WFH or swaps:
   - The note is built in `workforce-one/includes/trait-leave-view.php:44-48` (`leave_view_office_minimum()` → `om_leave_impact()`) and printed in `workforce-one/templates/app/leave.php:118-120` (App → Leave only; not My Approvals, not wp-admin Requests).
   - WFH is a schedule status, not a request type.
   - `trait-swap.php` has no Office-minimum code.
   - This also goes to the owner as an open question.
4. **Where the "Manager" badge comes from.** `workforce-one/src/Schedule/TeamOrder.php:27-43`, called from `schedule_employees_ordered()` (`workforce-one/includes/trait-schedule-view.php:15-29`), which reads `ews_teams` (active=1) `manager_employee_id`.
   - A manager is grouped under the first team they manage, so `_schedule_team_manager` is 1 exactly when the person manages at least one active team.
   - `_schedule_manager_teams` lists those teams.
   - `om_people()` (`workforce-one/includes/trait-office-minimum.php:39-61`) already runs `schedule_employees_ordered()`, so the annotation is there **with no extra query**.
   - Caveat: when there are no active teams, `schedule_employees_ordered()` returns early with no annotation, so the code must use `$e->_schedule_manager_teams ?? []`.
5. **"A team with two managers" cannot happen in the data model.** `ews_teams.manager_employee_id` is a single column (`trait-teams.php:36`). One person managing two teams counts once automatically: the managers are keyed by employee id in `TeamOrder::arrange` (`$managed[$eid][]`).

## 1. Data model and storage

**Decision: extend `ews_office_minimum`. Do not add a new option.**

- New keys in `OfficeMinimumSettings::DEFAULTS` (`workforce-one/src/Settings/OfficeMinimumSettings.php:12-20`):
  - `'mgr' => 0` (sub-switch)
  - `'mgr_min' => 2`
  - `'mgr_days' => []` (weekday 0..6 ⇒ its own number; 0 = no managers' minimum that day)
- `config()` (`:23-41`) parses them with the same rules as `min`/`days`. Old stored arrays get the defaults filled in, so there is **no upgrade routine, no DB version and no schema change**.
- Why not a separate option (`ews_office_minimum_managers`):
  - It would need a registry entry in `workforce-one/src/Settings/Options.php`, which adds a row to the Settings overview page and changes its HTML.
  - It is non-autoloaded and missing on every existing site, so the first `get_option()` costs **one query**. On the Attendance and Leave pages with the Office minimum on, that breaks golden's "no more queries" rule.
  - Extending the existing option reuses the `get_option('ews_office_minimum')` that `om_settings()` already does (it is cached after the first read).
- **Settings overview:** `settings_overview_readers()` (`workforce-one/includes/trait-settings-overview.php:183`) compares `config($raw)` with `config([])`. Both sides get the same new defaults, so the "changed" status is unchanged. Leave the `Options.php:134` label and `builtin` text as they are, so that page stays byte-identical (open question 10).
- **Byte-identical writes:**
  - `fromPost()` only adds `mgr`, `mgr_min` and `mgr_days` to the returned array when they differ from the defaults (`mgr=1`, or `mgr_min≠2`, or any `mgr_days`).
  - So saving Feature Configuration without touching the managers part stores exactly the array the old code stored.
  - The office-minimum audit line (`workforce-one/includes/trait-features.php:222`) gets `'; managers='.($on?'on':'off').' min='.N` appended **only** when those keys are present or changed.
- **Older forms:**
  - New hidden field `om_mgr_present` (same pattern as `early_leave_self_shown`, `trait-features.php:209`).
  - Without it, the saved manager keys are carried over: `fromPost($post, $known, array $saved = [])` copies `mgr*` from `$saved`.
- **Effective switch:** `om_mgr_on()` = `om_enabled() && cfg['mgr']`. Turning the Office minimum off keeps the managers settings but makes them inactive.

## 2. Pure rules (src/, unit-testable)

New `workforce-one/src/Schedule/ManagerMinimum.php` (pure, no WordPress calls, same style as `OfficeMinimum.php`):

- `minimumFor(array $cfg, string $date): int`
  - Calls `OfficeMinimum::minimumFor(['min'=>$cfg['mgr_min'],'days'=>$cfg['mgr_days']], $date)`.
- `day(int $min, array $plans, array $names): array{min,office,short,rows:list<{id,kind,status}>,can:list<int>}`
  - `$plans`: eid ⇒ `['kind'=>office|wfh|leave|away|off|none, 'status'=>raw]`.
  - `office` = count of kind `office`.
  - `short` = `max(0, min − office)`.
  - `can` = ids with kind `wfh`.
  - Rows sorted office first, then wfh, then the others, then by name.
- `worstDrop(array $before, array $after, array $dates): ?string`
  - The day where the managers in the office drop and the after-count is short; the largest shortfall wins.
  - Same rule as the people loop in `om_leave_impact`, `:122-128`.
- `managers(array $teamsByEmployee): array<int,list<string>>`
  - Normalises `_schedule_manager_teams`: unique, sorted, non-empty only.
  - Trivial, but it pins down "counts once".

Do **not** refactor the people logic in `OfficeMinimum`. Leaving that path untouched is what protects byte-identity.

## 3. Trait changes (`workforce-one/includes/trait-office-minimum.php`)

- **`om_people()` (:39-61):** also return `'managers' => [eid => list<team name>]`.
  - Built from `$e->_schedule_manager_teams ?? []` inside the existing loop. No query.
  - Because it uses `om_people()`'s population, inactive employees and those with attendance off are excluded: a team manager with attendance off is **not counted** and **not listed**.
- **New `om_mgr_on($cfg=null)`:** `om_enabled() && !empty(cfg['mgr'])`. Callers check `om_enabled()` first, so the Office-minimum-off path reads no settings.
- **`om_days()` (:69-96).** Inside the existing per-person loop:
  - Store `$in = $this->om_counts($st)`.
  - Keep `if($in) … elseif(om_could_come)` as today.
  - When `$mgr_on && isset($p['managers'][$eid])`, record `$plans[$eid] = ['kind'=>$in?'office':Icons::forStatus($st)[1], 'status'=>$st]`.
  - After `OfficeMinimum::day(...)`, and only when manager on: `$day['mgr'] = ($mm = ManagerMinimum::minimumFor($cfg,$d)) > 0 ? ManagerMinimum::day($mm,$plans,$names) + team labels : null`.
  - When off, the `mgr` key is not added. There is no extra query per day: the same single schedule query (`:78`) feeds both counts.
  - A day that is null for people (holiday, not a working day, people min 0) stays null (open question 1).
- **`om_attendance()` (:99-107):** when manager on, add `'mgr' => ['ids'=>array_keys(managers), 'short'=>[dates short of managers], 'any'=>[dates short of people or managers, date order], 'count'=>N]`. The existing `short`, `statuses` and `total` keys are unchanged.
- **`om_leave_impact()` (:113-133):**
  - Keep the people computation exactly.
  - Then, if manager on and `isset($p['managers'][$eid])`: `$m = ManagerMinimum::worstDrop(...)` over the same `$before`/`$after` arrays (no extra query).
  - Return null only when both are null.
  - Return the existing keys only when the people part has a day; add `'mgr'=>['date','office','min','can'=>names]`.
  - When off, the output is identical (same keys, same values).
- **New `om_admin_managers()`:** `list<string>` of manager names, plus the count, from `om_people()`, for the Features page (no extra query: `om_admin_teams()` already loads `om_people()` there).
- **`om_remind()` (:164-181):**
  - Short = people short **or** (`mgr` set and `mgr.short > 0`).
  - Per day: the existing part when people are short; add the managers part when managers are short.
  - Title string unchanged ("…below the office minimum").
  - When off, this is identical. `om_tick()` is unchanged (same day, time and dedupe option `ews_office_minimum_reminded`).

## 4. Attendance page

- **`workforce-one/templates/app/attendance.php:48-53`:** `$om_counter` appends a second span **inside the same returned string**, only when `$office_min['mgr']` is set and `$office_min['days'][$d]['mgr']` is not null:
  - `<span class="wfo-mom-day is-short|is-ok" data-mom-day="D" data-mom-base="N" data-mom-min="M" title="Managers in the office / minimum">{people icon}<b>N</b><span>/ M</span></span>`
  - It must **not** use `data-om-base`/`data-om-day`: `tests/e2e_office_minimum.py:62` counts `data-om-base=` (10), and `office-minimum.js` iterates `[data-om-base]`.
  - The two echo sites (`:244` Set row, `:274` head) need no change.
- **Table attribute (`:265`):** inside the existing `if ($office_min)`, add `data-om-managers="[ids]"` only when `mgr` is set. Rows are untouched; the existing `team-manager` row class is page-scoped, while the ids list matches the server's company-wide count.
- **`workforce-one/assets/js/office-minimum.js`:**
  - Read `data-om-managers` (null when the manager part is off, then skip).
  - In `update()`, add `mdelta[d] += counts(planned) − counts(orig)` for cells whose `data-employee` is in the manager set.
  - Update `[data-mom-base]` the same way (classes `is-short`/`is-ok`/`is-changed`).
  - The MutationObserver already catches single cells and "Set…" for the whole day.
- **`workforce-one/templates/app/office-minimum.php`:**
  - Keep `:12-54` as is for the manager-off branch: `if (empty($om['mgr']))` gives the old markup.
  - Manager-on branch:
    - Return if `!$om['mgr']['any']`.
    - The combined banner (mockup `banner()` in `docs/mockups/_kit/screens_mgrmin.py:45-67`): heading `_n('%d day below a minimum','%d days below a minimum')`.
    - Per day, a pill, the parts "%1$d of %2$d in the office" and/or "%1$d of %2$d managers", and a Teams button (people short) and/or a Managers button (`data-wfo-sheet="wfo-mom-D"`).
    - The teams `<dialog>`s for people-short days (same markup as today) and the managers `<dialog>`s for manager-short days.
  - Managers sheet:
    - Summary counter and "%d manager(s) below the minimum".
    - A `ul.wfo-mom-list`: initials avatar (`ProfileSummary::initials`, no picture query), name, "%s manager" (team names joined), plan chip from `Icons::forStatus` (raw status text, "Not Set" when empty), "Could come in" for `wfh`.
    - The rule text.
  - Keep the sub-line "This is only a warning: the schedule still saves." (an existing string). The mockup's sentence has fixed numbers, which would be wrong when weekdays have their own numbers (open question 5).
- **CSS:** `.wfo-mom-*` from `docs/mockups/manager-office-minimum/manager-office-minimum.css` go into `workforce-one/assets/css/app-attendance.css` and `app-attendance-rtl.css`. The note styles go into `app-leave.css` and `-rtl`.

## 5. Approval notes

- **Leave:** `workforce-one/templates/app/leave.php:118-120`.
  - Wrap the existing `<strong>` and team line in `if (isset($r->om['date']))`. Off, `date` is always present, so the output is identical.
  - Then, if `!empty($r->om['mgr'])`:
    - Same day as the people line: "and %1$d of %2$d managers in the office". Otherwise: "Approving makes %1$s %2$d of %3$d managers in the office".
    - Plus "Could come in: %s (working from home)." when `can` is non-empty (at most 3 names and "and %d more", as in the teams sheet).
    - The "only a warning" sentence is printed once.
  - `trait-leave-view.php` needs no change. It already calls `om_leave_impact`, so no new module crossing.
- **WFH / swaps:** nothing exists to extend (§0.3). See open question 3.

## 6. Reminder text

Off: unchanged. On, per day:
- People short only: existing `'%1$s %2$d of %3$d'`.
- Managers short only: `'%1$s %2$d of %3$d managers'`.
- Both: existing part + `', %1$d of %2$d managers'`.

The title and `_n` count include the manager-short days. The audit line `office_minimum_reminder` stays the same.

## 7. Features page

- **`workforce-one/templates/admin/features.php:242-266`.** After the Reminder row, inside `.wfo-om-admin`, add the mockup block `.wfo-mom-admin`:
  - h3 "Managers in the office"
  - checkbox `om_mgr` "A minimum for managers too"
  - `om_mgr_min` (number, min 1) "managers in the office" + "Managers also count in the minimum above."
  - `om_mgr_days[w]` for working days (placeholder = `mgr_min`; "Empty = the managers' minimum. 0 = no managers' minimum that day.")
  - "Who the managers are": "The team managers (%1$d): %2$s" + "From the Teams page. Uses the same "in the office" statuses and reminder as the minimum above."
  - With none: "No team has a manager yet: set them on the Teams page."
  - When `mgr_min` > count: "Only %d team managers are counted: every working day will be below this number." (warning only; open question 2).
  - Hidden `om_mgr_present`.
  - Note "Works only while the Office minimum is on."
- **`trait-features.php`:**
  - `:70` add `'om_managers' => $this->om_admin_managers()`.
  - `:89` pass the saved option to `fromPost`.
  - `:222` audit suffix.
- **`workforce-one/src/Settings/FeatureSettings.php:142-146`:** add `om_mgr_min` ("Office Minimum: enter a managers' minimum of 1 or more. Nothing was saved.") and `om_mgr_day`.
- **Validation in `fromPost`:**
  - `om_mgr_min` must be 1..100000 when `om_mgr` is ticked. When not ticked, an empty value keeps the saved or default number.
  - `om_mgr_days` follows the `om_days` rules.
- **Styles:** `admin-features.css` and `-rtl`.

## 8. modules.json and check_modules

- `trait-features` is **platform** and `trait-office-minimum` is **schedule**. Calls from platform into schedule count against `docs/architecture/module-baseline.json` (`platform->schedule`) unless the function is in schedule's `api` list. The new `om_admin_managers()` call would raise the count and fail `tests/check_modules.py`.
- **Add `om_admin_managers` to `modules.schedule.api`** in `docs/architecture/modules.json`.
- No other new crossings:
  - leave-view keeps calling `om_leave_impact`.
  - The attendance grid is schedule-internal.
  - `ManagerMinimum` is a `src/` class, not a trait call.
- No new tables, hooks, cron, admin-post or REST, so the golden hook inventory is unchanged (`hooks_added: []`).

## 9. Translations

Add the new strings to `workforce-one/languages/workforce-one.pot`, `-ar.po`, `-ar.mo` and `-ar.l10n.php` (Egyptian Arabic wording from the mockups).

New strings:
- Managers in the office / minimum
- %1$d of %2$d managers
- %d day(s) below a minimum
- Managers
- Managers on %s
- %d manager(s) below the minimum
- %s manager
- Could come in
- the rule text
- the leave-note strings
- the reminder parts
- the Features labels and descriptions above

No JS strings, so the JSON files are unchanged. `tests/check_translations.py` checks the placeholders.

## 10. Off by default and byte-identical: the checklist

| Case | Result |
|---|---|
| Office minimum off | Every `om_*` entry point returns before reading settings (unchanged order) |
| Office minimum on, manager part off | No `mgr` key anywhere, so the templates take the old branch; same queries (`om_people` and `om_days` add no query, `om_settings` is cached) |
| Leave page | Same keys; the `isset(date)` wrap prints the same bytes |
| Reminder | Same filter result, parts and title |
| Stored option | Manager keys omitted at default values; audit line unchanged |
| Features page | Changes on purpose; declare it in `expected.json` |

## 11. Tests

**Unit** (`tests/unit/ManagerMinimumTest.php`; extend `tests/unit/OfficeMinimumTest.php` or add `OfficeMinimumSettingsTest`):
- `config()`:
  - an old array gets the defaults
  - invalid `mgr_min`/`mgr_days` are ignored
- `fromPost()`:
  - default manager values omitted, so the result equals the old shape exactly
  - mgr on stores the keys
  - errors `mgr_min`/`mgr_day`
  - without `om_mgr_present`, the saved keys are carried over
- `minimumFor()`: weekday override, 0
- `day()`:
  - counts office only
  - wfh → can
  - leave, away, off and none are neither
  - sort order
  - short is never below 0
  - min higher than the number of managers is always short
- `worstDrop()`:
  - no drop (the manager was not in that day) gives null
  - picks the largest shortfall
- `managers()`: a person managing two teams appears once, with both team names.

**e2e** (extend `tests/e2e_office_minimum.py`, before "off again", ~line 215). Seed `manager_employee_id`:
- Sales, CS, Accounts and IT, with **one person managing both Accounts and IT**
- plus an inactive team whose manager must not count
- plus one manager with `attendance_enabled=0`

Checks:
- Office minimum on, managers off: no `data-mom-`, no `data-om-managers`, banner heading unchanged. **Capture the page HTML now and compare it again after on→off later (byte-equal, nonces stripped).**
- Features page shows the sub-section unticked, with the managers list (the two-team manager once; the attendance-off one absent).
- Validation: `om_mgr` on with `om_mgr_min=0` is refused, nothing saved.
- Saving with managers untouched: `ews_office_minimum` has no `mgr*` keys.
- On with 2: keys stored; the audit has the managers part.
- Counters: `data-mom-base`/`min` per day as seeded; 10 of them (head + Set row); red/green.
- `data-om-managers` ids are exactly the counted managers.
- Banner lists people-only, managers-only and both days, with the right Teams/Managers buttons.
- Managers sheet: plan per manager, "Could come in" only for WFH, not leave or trip.
- Weekday own number; 0 = none; company holiday not checked.
- Approving a manager's leave shows the managers line + who could come in; a non-manager's leave has no managers line; buttons still there.
- Reminder includes the managers-short day ("of 2 managers").
- Managers off: the page equals the captured HTML.
- Office minimum off with managers on: nothing shows.
- An old form without `om_mgr_present` keeps the managers settings.

Optional: a browser check of the live managers counter (`tests/browser_smoke.js`) — change a manager's cell to WFH and the counter goes from 2 to 1 and red.

**Golden:**
- Add to `tests/golden/pages.json`. Office minimum on, managers off: the base cannot differ, so this proves byte-identity and no extra queries. The seed already has team managers Omar (Alpha), Sam (Beta) and Mona (inactive team "Gone"), `tests/golden/seed_people.php:26-31`. Pages:
  - `admin /app/?ews_view=attendance&week={week}` with `{"ews_feature_office_minimum":1}`
  - the same with `{"ews_feature_office_minimum":1,"ews_office_minimum":{"min":3}}` (short days, so the banner renders)
  - `admin /app/?ews_view=vacation` with `{"ews_feature_office_minimum":1}`
- `tests/golden/expected.json`: release 3.31.153; pages = the two Features page entries (reason: "Manager office minimum: a new sub-section under Office Minimum, off by default"); actions `{}`; `hooks_added` `[]`.
- Optional golden action: run `om_remind()` with the Office minimum on and identical rows.

## 12. Edge cases (as designed)

| Case | Behaviour |
|---|---|
| Manager on leave, trip, day off, not set | Not counted, not "could come in" (unless that status is ticked as in the office) |
| Manager working from home | Not counted; "Could come in" |
| Company holiday, not a working day | Day not checked (null) |
| People minimum 0 that weekday | Not checked (open question 1) |
| Managers minimum 0 that weekday | No managers counter or check that day; people unchanged |
| Two teams, one manager | Counted once |
| Two managers in one team | Impossible (one column) |
| Manager with attendance off, or inactive | Not counted, not listed |
| Manager of an inactive team only | Not a manager |
| No active teams | 0 managers; every checked day short (Features page warns) |
| Minimum higher than the number of managers | Always short; warning on the Features page, saving allowed |
| Week lock (read-only grid) | Counters still shown |
| Paged or filtered grid | Base is company-wide; the live change is page cells only (same as people) |

## 13. Risks

- **Changing the `om_days` loop could alter the people results.** Keep the diff to `$in` plus the guarded manager block. The existing 43 e2e checks and the new golden pages guard it.
- **check_modules failure** if `om_admin_managers` is not added to the api list.
- **TeamOrder early return** without annotations: use `?? []`.
- **e2e attribute collision** (`data-om-base` count, `[data-om-base]` in JS): use the `data-mom-*` names.
- **Translation files** (.po/.mo/.l10n.php/.pot) must be regenerated together.
- **RTL CSS twins.**
- **Mockup wording** with fixed numbers.

## 14. Open questions for the owner

1. If the people minimum for a weekday is 0, is that day also free of the managers' check? (Recommended: yes, 0 means no office minimum that day.)
2. If the managers' minimum is higher than the number of team managers, warn on save only, or refuse? (Recommended: warn only.)
3. The proposal mentions WFH and swap approval notes, but neither exists today, even for people (only leave approval in App → Leave). Build swap notes (people and managers) now, later, or managers on leave only? WFH has no approval request at all. (Recommended: leave only now, swaps as a follow-up for both.)
4. Reports "Days below minimum" was never built (deferred in 3.31.77). Build the list now with a managers column, or keep it out? (Recommended: separate task.)
5. Banner sub-line: the mockup's "The minimum is 12 people and 2 managers…" is wrong on days with their own number. Keep the generic "This is only a warning…"? (Recommended: yes.)
6. Reminder title: keep "…below the office minimum" for managers-short days too? (Recommended: yes.)
7. A team manager whose attendance tracking is off: not counted and not listed (as for people). Or list them on the Features page as "not counted"?
8. Should only working-from-home managers be "Could come in" (as for people), or also "Not set"? (Recommended: WFH only, same as today.)
9. Should the managers counter also appear on the phone card layout in the same place? (The mockup says yes; it is the same markup.)
10. Should the Settings overview label in Options.php mention managers? It changes that page's HTML. (Recommended: no.)

## Owner answers to the design's questions (2026-11-18)

- **Q3, approval notes:** leave only for now (★). Swap notes, for people and managers, come later.
- **Q4, the "Days below minimum" report:** a separate task, not in this release (★).
- **Q2, a managers' minimum above the number of team managers:** warn only; saving still works (★).
- **Q7, a team manager with attendance off:** not counted and not listed (★).

The other questions take the recommended answers:
- Q1: a weekday with a people minimum of 0 is not checked for managers either.
- Q5: the banner uses the generic "only a warning" sub-line.
- Q6: the reminder keeps its title.
- Q8: only managers working from home show as "Could come in".
- Q9: the same counter on the phone layout.
- Q10: the Settings overview label is unchanged.

## Review (2026-11-18) and what was done

Reviewer: read-only, on Opus.

1. **CONFIRMED, fixed.** App → Leave printed 12 extra spaces in every pending row, even with the Office minimum off. The managers' note started on its own indented line. It now follows the people's note on the same line. Checked locally: a pending row rendered by the old and the new code is byte for byte the same. (Golden's Leave page has no pending row, so it could not see this.)
2. **CONFIRMED, fixed.** The "Only %d team managers are counted" warning showed while the managers' part was off. It now shows only with the managers' part on.
3. **CONFIRMED, fixed.** The audit line now has the managers' part whenever it changed, including the weekday numbers, or while it is on.
4. **CONFIRMED, fixed.** With the managers' part off, a bad weekday number keeps the saved numbers, as the minimum does, instead of refusing the whole save.
5. **PLAUSIBLE, for the owner.** The Managers sheet lists every team manager in the company with their plan, while a department-scoped editor sees only their department's grid. The existing teams sheet names only the people working from home. This is an open question below; the code shows all managers until the owner decides.
6. **PLAUSIBLE, noted.** Going back to an older version keeps working; the first Feature Configuration save there drops the managers' settings. This is in the CHANGELOG.

The Arabic list of names in the leave note is now joined with "، ", as elsewhere.

**Differences from the design, kept:**
- Plan kinds are office / wfh / other.
- The leave note is a second box with its own day, as in the mockup.
- The teams dialogs moved to `office-minimum-teams.php`, and the managers-on card is its own template. The output is the same bytes with managers off.
- The combined card drops the old "%d short" text when the managers' part is on.
- No golden reminder action; the e2e covers the reminder.

## Open question (from the review)

- Should a department-scoped editor see the plan (Office, WFH, Leave…) of managers outside their department on the Managers sheet, or only their name and whether they are in the office?
