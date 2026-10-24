# 3.31.124: shift swaps in My approvals (design)

The approved mockups are in `docs/mockups/swap-inbox/` (generator: `docs/mockups/_kit/screens_swapinbox.py`). The owner decided this on 2026-10-24: no new setting, no database change. When My approvals is off, nothing changes.

## 0. What the code does today

1. **A swap the colleague has not answered has no approval-engine request.** `swap_request_create` (`includes/trait-swap.php:115-144`) only inserts a `Pending` row. The engine starts only in `swap_to_approvers` (`trait-swap.php:249-265`), and only after the colleague accepts with a levels workflow.
   - `swap_manager_mode()` (`:54-59`) returns `''` for PEER ("Colleague only"). `approval_start('shift_swap', …)` is called nowhere else (grep confirms).
   - The PEER / `TARGET_EMPLOYEE` step in `Workflows`/`approval_start` (`trait-approvals.php:566-571`) is never used by swaps.
   - `my_approvals_items()` (`trait-my-approvals.php:79-82`) joins only engine `PENDING` steps, so it can never see a `Pending` swap.
   - **So the inbox needs a second source:** the `ews_schedule_swaps` rows with `target_employee_id = me AND status='Pending'`.
2. The colleague's answer goes to `ews_swap_respond` → `swap_request_respond` (`trait-swap.php:146-175`):
   - It needs `swap_id`, `decision` ∈ {`accept`, `reject`} and the nonce `ews_swap_respond_{id}`. It reads no `approval_step` and no `note`.
   - It redirects through `swap_redirect()` (`:30-38`), which already honours `ews_return=approvals` via `approvals_return_view()` (`trait-my-approvals.php:28-33`). **The handler is reused unchanged.**
3. Withdrawing a swap exists only as `ews_swap_cancel` on the Schedule panel (`trait-swap.php:177-188`). When the swap is no longer `Pending` it still redirects with `swap_done=cancelled` and says nothing about it.
4. Notification links:
   - The swap request notification (`trait-swap.php:141-142`) has no URL, so it gets `Links::VIEWS['swap'] => 'schedule'` (`src/Notifications/Links.php:21`).
   - The approver notification uses an explicit Schedule URL (`trait-swap.php:276`).
   - The link is stored on the row when the notification is created (`trait-notifications.php:146-150`).
5. Mobile API: no swap, My approvals or notifications routes (`src/Api/Routes.php`), and nothing in `/me/bootstrap`. **No impact.**

## 1. Files and functions

### A. `includes/trait-swap.php` (schedule module)

- **New `swap_peer_waiting($employee_id)`** returns `object[]`. One query, with no join to the employees table, so the `schedule->people` count in `check_modules.py` does not grow:
  ```sql
  SELECT s.*, sa.status cur_a, sb.status cur_b FROM {swaps} s
  LEFT JOIN {$this->schedule} sa ON sa.employee_id=s.requester_employee_id AND sa.work_date=s.work_date
  LEFT JOIN {$this->schedule} sb ON sb.employee_id=s.target_employee_id AND sb.work_date=s.work_date
  WHERE s.target_employee_id=%d AND s.status='Pending' AND s.work_date>=%s   -- today
  ORDER BY s.created_at ASC LIMIT 50
  ```
  - It uses the `target_status(target_employee_id,status)` index (`trait-schema.php:94`) and the schedule table's `employee_date` key.
  - `cur_a` / `cur_b` let a card see that a schedule changed (`SwapRules::checkStillCurrent`) without another query.
- **New `swap_peer_card($r, $names, $mode)`** returns the card array without any query. Keys:
  - The usual ones: `employee_id` = requester, `tone=swap`, `kind`, `what`, `since`, `created_at`.
  - `action='ews_swap_respond'`, `nonce='ews_swap_respond_'.id`, `fields=['swap_id'=>id]`. **No `approval_step`.**
  - `peer=>true`, `bulk=>false`, `item_key=>'swap-'.id`, `anchor=>'apv-swap-'.id`.
  - `decisions=>['yes'=>['accept',__('Accept')],'no'=>['reject',__('Decline')]]`.
  - `trade=>[[__('You will work'), requester_status], [sprintf(__('%s will work'), first name), target_status]]`. The colleague receives `requester_status` (see `swap_apply_rows`, `:90`).
  - `hint`: `__('After you accept, it goes to the approver.')` when `$mode!==''`.
  - `stale`: true when `checkStillCurrent` fails. The card then hides Accept and shows the hint "Your or their day changed since; you can only decline it."
- **New `swap_withdraw($id, $employee_id)`** for My requests. It returns `true`, `'done'` (no longer `Pending`) or `'save'`.
  - It reuses `swap_pending_row($id,'AND requester_employee_id=%d')`, `swap_close($id,'Cancelled')` and `swap_audit('SCHEDULE_SWAP_CANCELLED',…)`.
  - `swap_request_cancel` keeps its current behaviour, so the panel with My approvals off is unchanged.
- **New `swap_waiting_mine_count($employee_id)`**: one `COUNT(*)` with `requester_employee_id=%d AND (status='Awaiting' OR (status='Pending' AND work_date>=today))`. It uses the `requester_status` index.
- **New `swap_state_for($id, $employee_id)`** for the deep link. It returns `''` (still waiting for me), or one of `withdrawn | answered | past | other`. One `get_row`, and it reveals something only when the reader is the target or the requester.
- **New `swap_notice($done, $error)`**: the shared text map, moved from `trait-frontend.php:236` and `:241`. The Schedule modal and `my_approvals_notice()` both use it, so there is one list to translate.
- **`swap_notify()` (`:104-107`)**: add an optional `$url=''` that is passed as `notify` option `url`.
- **`swap_request_create` (`:141-142`)**:
  - When `my_approvals_enabled()`, set `url = app_view_url('approvals') + apv_swap={id}#apv-swap-{id}`.
  - Make the title and text translatable, using the mockup copy: title `__('Swap request')`, text `sprintf(__('%1$s asks to swap %2$s with you: you %3$s, them %4$s.'), …)`. "You" gets `requester_status`, "them" gets `target_status`. Today the text is an untranslated English string.
- **`swap_notify_approver` (`:276`)**: URL becomes `approvals` when enabled, `schedule` otherwise. Without this the approver lands on a page that no longer lists the swap.
- **`swap_my_requests` (`:367-379`)**:
  - `state`: through the pure helper (B). A past `Pending` swap shows as `expired`; this is display only (Q4).
  - `withdraw`: `'swap'` when `Pending` and the day has not passed.
  - `sub`, per the mockup:
    - "With %s · waiting for their answer"
    - "· they accepted"
    - "· the days were exchanged"
    - "· they declined" (when `responded_by` is the target's user) or "· not approved"
    - "· withdrawn"
    - "· the day passed before they answered"
  - `path` (fallback when there is no engine request): a colleague step `now` ("Waiting for their answer"), plus, when levels are on, `{state:'next', label:__('Approver'), sub:__('After they accept')}`. `level=0`, `levels=0`.
  - `path_before`: a colleague step `done` ("Accepted") for `Awaiting` and later.
  - `link`: `''`, because the Schedule page no longer lists swaps.
- **`swap_approval_card` (`:353-364`)**: add `path_before` (colleague done) so the approver's path matches the mockup.
- **`swap_redirect` (`:33`)**: add `apv_swap` to `remove_query_arg`. Otherwise the referrer brings the deep link back after a decision.
- **Optional (Q6):** in `swap_withdraw`, notify the colleague.

### B. New `src/Schedule/SwapInbox.php` (pure, unit tested)

- `state(string $status, string $workDate, string $today): string` returns `waiting | approved | rejected | withdrawn | expired | cancelled`.
- `canWithdraw($status, $workDate, $today)`.
- `waitsForPeer($status, $workDate, $today)`.

The listing, the counts and My requests all use these three, so they always agree.

### C. `includes/trait-my-approvals.php` (approvals module)

- **`my_approvals_items()` (`:69-111`)**, after the engine rows:
  - When `$me` is set, merge the cards built from `swap_peer_waiting((int)$me->id)`.
  - Each peer card gets a synthetic path: `['id'=>0,'level'=>0,'levels'=>0,'hours'=>WorkingHours::between(created_at, now, working_days, approval_settings day_start/day_end),'overdue'=>false,'moved_from'=>'','note'=>'','steps'=>[]]`.
  - It also gets `admins=false`, `note_required=false`, `for=''`, `with=''`, `id=0`.
  - Replace the per-item `people_employee()` at `:95` with one `people_by_ids()` batch for all items (new people API, D). Skip peer cards whose requester is inactive.
  - Generic merge: `if(!empty($card['path_before'])) $path['steps']=array_merge($card['path_before'],$path['steps'])`.
  - `:96` stays limited to engine rows. A peer card never carries `approval_step`.
- **`approvals_content()` (`:139-156`)**:
  - Read `apv_swap` and pass `focus='apv-swap-'.id` to the template.
  - If no item has that anchor, call `swap_state_for()`. The notice "This swap request is no longer waiting for your answer: it was withdrawn / already answered / its day has passed." only appears when no `swap_done`, `swap_error` or other notice is present.
- **`my_approvals_notice()` (`:173-178`)**: use `swap_notice()`. The current fallback, "Open it on the Schedule page", is wrong once the Schedule page no longer lists swaps.
  - `accepted`: "You accepted: both schedules are updated."
  - `awaiting`: "You accepted. It now goes to the approver."
  - `rejected`: "Rejected, and they were told." (the same code is used for a decline and an approver's rejection)
  - `not_found` / `expired`: "This swap is no longer waiting: it was withdrawn or already answered."
  - The existing texts for `changed`, `past_date`, `moved`, `note`, `access` and `save` stay.
- **`my_approvals_clean_url()` (`:185`)**: add `apv_swap`.
- **`requests_content()` (`:197`)**: `$it['path']=$paths[$key]??($it['path']??null);` then merge `path_before`.
- **`request_withdraw()` (`:259-265`)**: add `case 'swap': $r=$this->swap_withdraw($id,(int)$emp->id);break;`. `req_done=swap` uses the generic "Withdrawn" text; `req_error=done` uses "already decided".

### D. `includes/trait-employees.php` (people module)

- New `people_by_ids(array $ids)`: `SELECT *` in chunks of 500, keyed by id.

### E. `includes/trait-schedule-view.php` (`schedule_content`, `:95-138`)

- When `my_approvals_enabled()` and `$current_emp_id`, pass `swap_inbox`:
  - `answer` = the number of swap-tone items in `my_approvals_items()`. That list is already loaded once per page for the menu badge, so this costs no query. It counts both peer and approver swaps (Q2).
  - `answer_url` = `approvals&apv_kind=swap`.
  - `mine` = `swap_waiting_mine_count()`, which is the one new query on this page.
  - `mine_url` = `requests&req_state=waiting`.
- In that case it **does not call** `swap_requests_for_user` or `swap_awaiting_my_approval`, so the page runs one query fewer than today.
- Otherwise `swap_inbox=null` and today's code runs.

### F. `templates/app/schedule.php`

- `:178-256`: if `$swap_inbox !== null`, render up to two `a.wfo-si-line` links, each only when its count is above 0. They use the mockup markup with `_n()` texts:
  - "%d swap request waits for your answer" / "Open them in My approvals"
  - "%d swap you asked for is waiting" / "Follow it in My requests"
- Where they go: above My week, as in the mockup, or at the panel's place (implementation choice).
- When `!$has_myweek`, also show the "Request a swap" button (today `:221`).
- Otherwise the panel stays exactly as it is. The `#wfo-swap-sheet` dialog (`:258`) stays in both cases.

### G. `includes/trait-app-layout.php:78`

- Do not prepend `my_approvals_link()` on `schedule` when the new lines are shown; the mockup has no general link there (Q3).
- At `:51`: when `$view==='approvals'` is not allowed but `my_approvals_enabled()` and `$_GET['apv_swap']` is set, render `approvals` anyway. The inbox shows its empty state plus the "no longer waiting" notice, instead of falling back to the Dashboard (an expired or withdrawn swap makes `my_approvals_allowed()` false for an ordinary employee).
  - Do this in `render_app_view` only. `app_view_allowed`, and so the menu, stays as it is.

### H. `templates/app/approvals.php` (`:55-99`)

- **Item attributes:** `data-apv-item="<?= esc_attr($it['item_key'] ?? (int)$it['id']) ?>"`, `id="<?= anchor ?>"`, and `is-focus` when the anchor equals focus.
- **Bulk:** add `data-apv-nobulk` when `!$can || ($it['bulk'] ?? true) === false`. Show the checkbox (`:67`) only when bulk is allowed.
- **Peer cards:**
  - Show the `.wfo-si-trade` block after `what`.
  - In the meta row, show the pills "Your answer" (`is-brand`) and the time since the request (`sprintf(__('%s ago'), human_time_diff(...))`) in place of level/working hours.
  - Show `.wfo-si-note` for the hint.
  - No `<details>` and no note textarea (the handler ignores `note`).
- **Buttons:** use `$it['decisions']`, defaulting to approve/Approve and reject/Reject. The no-button keeps `data-apv-reject`.
  - The decline still opens the app's existing "Reject Shift Swap" confirmation (`assets/js/workforce-one.js:57`, action `ews_swap_respond`). Those strings are not translated today; note this or fix it.

### I. `assets/js/approvals.js`

- **Bulk recommendation: exclude peer swaps.** Reasons:
  - Accepting changes the person's own schedule, and the mockup shows no checkbox.
  - The bulk script posts `decision=approve` (`:72`), which `swap_request_respond` rejects with `wp_die` (`:150`). The script would count that as approved, because the response is a 200 without `swap_error`.
- The `data-apv-nobulk` attribute already removes them from `pickable` (`:30`), so no JS change is needed beyond a comment.
- The approver's swap cards stay bulk-approvable, as today.

### J. Other files

- **`templates/app/requests.php`:**
  - `:55`: add a swap branch to the withdraw text: "Withdraw it? Your colleague will no longer be asked."
  - `:59-60`: "Open it on the Schedule page" no longer appears for swaps (`link=''`).
- **CSS:** add the mockup rules to `assets/css/app-approvals.css` (`.wfo-si-trade`, `.wfo-si-arrow`, `.wfo-si-note`, `.wfo-apv-item.is-focus`) and `assets/css/app-schedule.css` (`.wfo-si-line*`), plus their `-rtl.css` versions (mirror the `›` chevron as in `swap-inbox-rtl.css`).
- **`trait-frontend.php`:**
  - `:236` and `:241`: use `swap_notice()`.
  - `:142` and `:618`: no change, because `apv_swap` is not a one-time key.
- **`docs/architecture/modules.json`:**
  - Schedule `api`: add `swap_peer_waiting`, `swap_peer_card`, `swap_withdraw`, `swap_waiting_mine_count`, `swap_state_for`, `swap_notice`.
  - People `api`: add `people_by_ids`.
  - Run `tests/check_modules.py`; the counts must not grow. Schedule → approvals calls (`my_approvals_enabled`, `my_approvals_items`): `my_approvals_items` is **not** in the approvals API, so either add it or expose a new `my_approvals_swap_count()` in the approvals API and use that (preferred).
- **`src/Notifications/Links.php`:** unchanged. `swap` stays `schedule` as the fallback for old notifications and when My approvals is off.
  - An administrator's "Opens" choice for `swap` still overrides the URL (`Links::apply`).

## 2. Data flow of the peer card

1. Card from (A) → form posts `action=ews_swap_respond`, `_wpnonce` (`ews_swap_respond_{id}`), `ews_return=approvals` (template `:61`), `swap_id`, `decision=accept|reject`.
2. `swap_request_respond` makes the same checks as today: nonce, `current_employee`, `Pending AND target=me`, past day, levels or direct apply.
3. `swap_redirect` sends the person to `?ews_view=approvals&swap_done=…` or `&swap_error=…`.
4. `my_approvals_notice()` shows the text.

With levels, the swap becomes `Awaiting` and the engine notifies the approver with the approvals URL. If the colleague is also the level-1 approver, the approver card for the same swap appears straight away (Q5).

## 3. Counts and their cost

- The menu badge and the home card (`my_approvals_count` / `my_approvals_home`) and the Schedule line 1 all read the memoised `my_approvals_items()`. The added cost per page is 1 indexed query for peer swaps, plus one `people_by_ids` batch.
  - The batch replaces one `people_employee` per item, so it costs less as soon as two items wait.
  - Peer cards add no query per row.
- Schedule line 2: one `COUNT(*)`.
- Visible side effect: `my_approvals_allowed()` (`:39`) becomes true for any employee with a peer request waiting, so "My approvals" appears in their menu while one waits (Q1).

## 4. With My approvals off

- No peer source: `my_approvals_items` returns `[]` early (`:72`).
- The Schedule page keeps today's panel (current week).
- Notifications keep their Schedule links.
- `request_withdraw` cannot be reached (`my_requests_allowed`).
- `e2e_swap.py` passes unchanged.

## 5. Notifications sent before 3.31.124

They keep the link stored on their row (Schedule); there is no migration. The Schedule page then shows the line to My approvals. Pushes already delivered behave the same way.

## 6. Edge cases

| Case | Behaviour |
|---|---|
| Requester withdraws while the colleague's page is open | `swap_pending_row` misses → `swap_error=not_found` → "no longer waiting" |
| Double post or two tabs | `swap_close`/`swap_to_approvers` use a conditional `UPDATE … status='Pending'` → the second gets `expired` or `not_found` |
| Schedule changed (leave, grid, another swap) | Card marked `stale`: Decline only. If posted anyway: `changed`, the swap stays Pending |
| Past day | Left out of the inbox and the counts. An open card gets `past_date` on accept; decline still works. My requests shows Expired, no withdraw |
| Swap admin-decided in wp-admin while the card is open | `not_found` |
| Peer moved to another department | Shown and acceptable, as today (the handler does not re-check the department; Q7) |
| Requester deactivated | Left out of the inbox; the handler would still accept |
| Final week (week lock) | Accept applies and is recorded (`swap_apply_rows:99`), as today; no extra UI |
| `Awaiting` with levels | Comes from the engine (`swap_approval_card`), shown as "Level x of y". Level guard (`approval_step` → `swap_manager_decide:299-300`) as in 3.31.122. The peer form has no level, and `swap_request_respond` ignores `approval_step` |
| PEER-only workflow | No hint; accept → `accepted`, card disappears |
| Substitutes | Peer requests are personal: never routed through `for_employee_id` and not shown to a substitute. The approver levels keep the existing substitute logic |
| Admin without an employee | No peer source, no Schedule block |
| Deep link to a swap that has moved on, for a user who cannot open the inbox | Fallback in (G) |

## 7. New strings to translate

- Peer card: "Accept", "Decline", "Your answer", "%s ago", "You will work", "%s will work", "After you accept, it goes to the approver.", "Your or their day changed since; you can only decline it."
- Notification: "Swap request", "%1$s asks to swap %2$s with you: you %3$s, them %4$s."
- My approvals notices (C), and the deep-link texts: "This swap request is no longer waiting for your answer: …" in three variants.
- Schedule lines: the two `_n()` pairs and their two sub-lines.
- My requests subs (A): "Waiting for their answer", "Approver", "After they accept", "Accepted", "Withdraw it? Your colleague will no longer be asked."

Add them to `languages/workforce-one-ar.po`, regenerate `.mo` and `l10n.php`, and run `tests/check_translations.py`.

## 8. Tests

**Unit**
- `tests/unit/SwapInboxTest.php`: every status, past/today/future, `canWithdraw`, `waitsForPeer`.

**e2e: new `tests/e2e_swap_inbox.py`** (My approvals on)
- The colleague sees the peer card, including for a day in a later week, with:
  - the nonce for `ews_swap_respond`
  - no `approval_step` and no checkbox
  - `data-apv-nobulk`
  - the trade block
  - the hint only when levels are on
- The menu badge and the home card count it, and the number of queries does not grow per item (`SAVEQUERIES` with 1 vs 5 peer swaps).
- Accept in PEER mode → `ews_view=approvals&swap_done=accepted`, days exchanged.
- Accept with LEVEL_1 → `awaiting`; the approver's inbox has it and the approver notification link is `ews_view=approvals`.
- Decline → `rejected`, requester notified.
- Twice, or after a withdraw → `not_found` with the inbox notice.
- Stale card: no Accept; posting it anyway gives `changed`. A past day is left out of the inbox and the counts.
- The notification created links to `approvals&apv_swap=ID`. Opening it after the swap is withdrawn shows the notice, not the Dashboard.
- The Schedule page shows the two lines with the right counts and links, no `data-swap-approvals`, no `ews_swap_respond` form, and the "Request a swap" button.
- My requests:
  - Pending swap: colleague step now, Approver next, a working Withdraw (`req_done=swap`, `Cancelled`, audit row).
  - Withdraw of an `Awaiting` swap → `req_error=done`.
  - Someone else's withdraw is refused.
  - `Awaiting` shows "level 1 of 1" with the colleague ✓.
- Off: the Schedule panel exactly as before, notification link to Schedule, no peer items.

**Updates to existing tests**
- `tests/e2e_level_guard.py:250,255` read the `ews_swap_respond`/`ews_swap_decide` forms from `?ews_view=schedule` with My approvals on. Read them from `?ews_view=approvals` instead.
- Check `e2e_my_approvals.py:116-166`: the KPI count of 4 still holds, because the seeded swap is `Awaiting`.
- `e2e_notification_links.py:60,118` stay as they are.

**Browser: extend `tests/my_approvals_browser.js`**
- Arabic phone width: the peer card has no sideways scroll, and in Select mode it shows no checkbox.
- The deep link scrolls to and highlights the card.
- Decline opens the confirmation.
- The Schedule lines do not overflow and the RTL chevron is mirrored.

**Checks**
- unit, phpstan, phpcs, `check_modules.py`, `check_translations.py`, `e2e_swap.py`, `e2e_schedule_view.py`, `e2e_week_lock.py`, `e2e_substitutes.py`.

## 9. Risks

- The menu item appearing and disappearing for ordinary employees (Q1).
- `check_modules.py`: keep every table use in its own module and every call on an API function (`my_approvals_swap_count`, `people_by_ids`).
- Notice and modal texts duplicated: mitigated by `swap_notice()`.
- `apv_swap` sticking to redirects of other modules: notice precedence and the strip in `swap_redirect`.
- Bulk counting a failed swap as approved, if `bulk=false` is ever lost: keep a test for it.
- A past `Awaiting` swap stays in the approver's inbox and can only be rejected. This is today's behaviour, outside this release.

## 10. Open questions for the owner

1. An employee with no approver role now sees "My approvals" in the menu only while a colleague's request waits. Is that acceptable, or should it always be visible to every employee when the feature is on?
2. Schedule line 1: should it count only "asked of you" swaps, or also swaps waiting for you as approver? The proposal counts both and links to `apv_kind=swap`.
3. With the new lines, should the Schedule page drop the general "All your approvals in one place · N waiting" link, as the mockup shows?
4. A request whose day passed before the colleague answered: show it as "Expired" in My requests (display only, no change to the database) and leave it out of every count?
5. A colleague who is also the approver: today they decide twice (accept, then approve). Keep that?
6. When the requester withdraws, should the colleague be notified? Today nobody is.
7. Should the inbox re-check that both people are still in the same department (and active) before Accept, or keep today's behaviour?
8. Should the requester's outcome notifications (accepted, waiting for approval, rejected, completed) open My requests instead of the Schedule?
9. Order in the inbox: put "Your answer" cards first, as in the mockup, or keep the existing order (overdue first, then the longest wait)?
10. While the swap waits for the approvers (`Awaiting`), should the requester be able to withdraw it? The owner's note covers only "waiting for the colleague".

### Critical files for implementation
- /home/user/WFplugin/workforce-one/includes/trait-swap.php
- /home/user/WFplugin/workforce-one/includes/trait-my-approvals.php
- /home/user/WFplugin/workforce-one/templates/app/approvals.php
- /home/user/WFplugin/workforce-one/templates/app/schedule.php
- /home/user/WFplugin/workforce-one/includes/trait-schedule-view.php
- Also: /home/user/WFplugin/workforce-one/includes/trait-app-layout.php, /home/user/WFplugin/workforce-one/templates/app/requests.php, /home/user/WFplugin/docs/architecture/modules.json, /home/user/WFplugin/tests/e2e_level_guard.py

## Owner's answers (2026-10-24)
1. My approvals shows in an ordinary employee's menu only while something waits for them (as designed).
2. The Schedule line counts every swap waiting for the person's decision (asked of them, and as approver); it opens My approvals on
   the swap filter.
3. (Mockup, approved) The Schedule page shows only the two lines, not the general My approvals link.
4. (Not asked: display only) A Pending swap whose day passed shows as Expired in My requests and is left out of every count.
5. A colleague who is also the approver decides twice, as today.
6. The colleague is notified when the requester withdraws.
7. (Not asked: no change) No new department / active re-check on Accept; today's checks stay.
8. The requester's outcome notifications open My requests (the Schedule page when My approvals is off).
9. Order unchanged: overdue first, then the longest waiting.
10. Withdraw only while waiting for the colleague (not once Awaiting).
