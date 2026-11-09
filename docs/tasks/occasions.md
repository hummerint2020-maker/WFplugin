# Occasions (seasonal overlay): the owner's request and decisions

## The request (owner, 2026-11-09)
"في فيتشر عايز اعملها تكون بتتقفل و تتفتح و يكون في اختيارات خاص بالمناسبات .. زي مثلا عيد ميلاد او عيد حب الابلكيشن كله يبقى في overlay
الوان و انميشن ليها علاقه بالحدث"

A switchable feature (off by default) with a choice of occasions; while one is on, the whole app gets an overlay of colours and an animation
that belong to the occasion.

## Owner decisions (2026-11-09)
1. **How an occasion starts:** the administrator only. Nothing starts by itself on a date; the administrator turns an occasion on and off.
2. **Birthday:** both — the employee's own birthday and the company's birthday (its founding day).
3. **Occasions available from the start:** Valentine's Day; Ramadan and the two Eids (Eid al-Fitr, Eid al-Adha); New Year; national days.
4. **Where the overlay shows:** the employee app and the kiosk (kiosk and QR screens), not wp-admin.

## Not decided yet (for the design's open questions)
- How the employee's birthday is "turned on" when the administrator starts every occasion by hand (a switch that celebrates each employee on
  their own birthday from then on?), where the birth date comes from (a new employee field: who enters it, optional, hidden year), and what
  colleagues see on someone's birthday.
- One occasion at a time or several; an end date or only on/off; whether the employee can hide the overlay.
- Reduced motion, battery and low-end phones; no overlay over buttons or the Sign In / Sign Out action.

---

# Design (architect, read-only, 2026-11-09)

## 0. What exists today
- Birthdays are already stored: wp-admin → Engagement → Employee Moments (`ews31-moments`), option `ews_employee_moments`
  (`[id => ['birthday'=>Y-m-d,'join_date'=>Y-m-d]]`), rules in `src/Settings/Moments.php` (no future birthday; 29 Feb on 28 Feb);
  switch `ews_employee_moments_enabled` (default 1); colleagues already see "Happy birthday, X!" in the Dashboard's Today's Moments card.
- `prime_options()` loads every key in `Options::ALL` in one query: a new listed option costs no query, even unsaved.
- App frame: `trait-app-layout.php:layout()` → `templates/app/layout.php`; login `login_page()` → `templates/app/login.php`; theme from
  `Appearance::cssVars()` (4 presets, dark headers); QR scan is a view inside the layout.
- Kiosk: `presence_kiosk_route` → `templates/kiosk.php` (standalone, never reloaded); `kiosk.js` fetches the next code every slot.
- Service worker precaches only `workforce-one.css/js` and icons; other assets come from the network: a new CSS file needs no SW change.
- Golden harness compares app pages byte for byte, query counts, action traces and the hook inventory.
- z-index: tab bar 9990 (the Sign In / Out button), modals 9999–100000, content ≤100. Module: shell (no tables).

## 1. Data model (recommended: options only, no schema change)
- `ews_feature_occasions` (bool, default false) and `ews_occasions` (grouped), both in `Options::ALL`.
- `ews_occasions`: `active` (''|valentine|ramadan|eid_fitr|eid_adha|new_year|national|company_birthday), `country`, `until` (optional last
  day), `started` (set when `active` changes; the instance key), `message` (≤120), `birthdays` (0|1), `founded`, `kiosk` (0|1).
- Per-employee hide: user meta `ews_occasion_hidden` = the hidden instance key.
- Uninstall: `ews_%` options and user meta are already removed (a test proves it). Upgrade: none; `ews_schema_target()` unchanged.
- Birth date: `ews_employee_moments[id]['birthday']`, read only when `birthdays=1`; month and day only are used.
- Alternative (Q1-B): `birth_date DATE NULL` on `ews_employees` as HL2 did (schema bump, one-time copy from Moments, Moments reads the
  column, EmployeeDetailsRules / import / export / audit, never in directory, org chart, API or public profile).
- Audit: `occasions_update`, only when `Occasions::summary()` changes.

## 2. Code
- Module shell; `modules.json`: trait `occasions`, api `occasion_now`.
- New: `src/Settings/Occasions.php` (CATALOG of palette / motif / greeting; COUNTRIES colours only; `config`, `fromPost` with
  bad_key / bad_country / bad_date / until_past / founded_future / message_long; `instance`; `resolve`; `cssVars` from catalog colours only;
  `summary`); `includes/trait-occasions.php` (`occasion_settings`, `occasions_enabled`, `occasion_now($where)` memoised and null first
  when off, preview `?wfo_occasion_preview=` for `ews_manage_settings`; `occasion_view`; `occasion_assets`; admin-post `occasion_hide`);
  `templates/app/occasion.php` (aria-hidden layer, ≤14 particles, optional greeting, no script); `assets/css/occasions(-rtl).css`
  (pure CSS, inline SVG data URIs, ≤14 KB raw / 5 KB gzip).
- Changed: `trait-app-layout.php` (layout and login pass `occasion_class` / `occasion_html`, '' when off); `templates/app/layout.php`,
  `login.php`; `trait-frontend.php:enqueue_frontend_assets` (enqueue only when on); `trait-presence.php` (kiosk variables; payload route
  sends `X-WFO-Occasion` only when on); `templates/kiosk.php`; `assets/js/kiosk.js` (reload when the header differs from `data-occasion`);
  `trait-features.php` + `templates/admin/features.php` (the Occasions card, validation, `started`, audit); profile menu "Hide decorations";
  `employee-schedule-manager.php` (trait, `admin_post_ews31_occasion_hide`); Options, translations, CHANGELOG, version, expected.json, CI.
- CSS rules: the layer is behind the content (`pointer-events:none; position:absolute; inset:0; z-index:0` inside `.wfo-shell`), never over
  the tab bar, Sign In / Out, modals or the QR; a header tint over the brand colours; only transform / opacity animated, a finite run
  (~2 × 8 s) then static; nothing flashes more than 3 times a second; `prefers-reduced-motion` → no animation; ≤7 particles under 600px;
  no blur or shadows; symmetric motifs with logical insets for RTL; the offline page gets no overlay.

## 3. Off by default
`occasion_now()` returns null first when off: no CSS, empty template variables (byte-identical HTML), no header, no user meta read, no
query, no menu link. Only the Features card differs (declared in expected.json). Never in wp-admin. On with nothing chosen → null.

## 4. Edge cases
- `resolve()`: off → null; admin → null; `until` < today (site time zone) → ended; a birthday today with `birthdays=1` and not kiosk /
  login → the birthday for that employee only; else the company occasion; a hidden instance → null.
- One company occasion at a time (radio); the end date only ends, never starts. No birth date → company occasion; admin without an
  employee record → company occasion. The site time zone decides the day; kiosks follow within one QR slot.
- Kiosk and login: company occasion only, never names; the QR card stays opaque and uncovered.
- Hide: per user and per instance; a birthday hide lasts that year. Preview: admins only, works while off, saves nothing.
- National days: colours only, never a flag or emblem; default Egypt. Custom message escaped, never in CSS. Mobile API out of scope.

## 5. Tests
Unit `OccasionsTest` (catalog, contrast ≥ `Appearance::MIN_CONTRAST`, config, fromPost errors, resolve cases incl. 29 Feb, kiosk/login,
hidden, re-activation, preview); OptionsTest. E2E `tests/e2e_occasions.py` (off: no class / CSS / header and the same query count;
turning on through the Features form, audit once; each occasion in English and Arabic; kiosk header; wp-admin none; end date; birthdays;
hide; access; escaping). Browser `tests/occasions_browser.js` (phone 390×844 ar/en: `elementFromPoint` on Sign In and the tab bar,
`pointer-events:none`, no sideways scroll; reduced motion; finite animation; kiosk QR corners uncovered and reload on switch-off; same
origin only; no console errors; CSS size). Golden, upgrade, uninstall; re-run features, layout, appearance, presence, PWA, offline,
engagement, smoke, translations, modules, layers, phpstan, phpcs, RTL.

## 6. Mockups (`docs/mockups/occasions/`, ar and en)
1 Features card (off; Ramadan with an end date; National day with countries; birthdays with "N of M saved"; errors) · 2 Dashboard on a
phone per occasion · 3 the employee's birthday and a colleague's view · 4 Time view (Sign In / Out uncovered) · 5 QR scan view ·
6 login · 7 kiosk (landscape) · 8 desktop frame · 9 profile menu "Hide decorations" and the static look · 10 only if Q1-B: the field.

## 7. Risks
Byte-identity when off; covering taps or the QR (highest impact on a kiosk); battery; seizures (no flashing); cultural and religious
sensitivity (no religious text, no flags, no people); birthday privacy (no names on kiosk / login / API, no year); two birthday sources if
Q1-B; cached login pages; natural Arabic greetings; contrast on the four presets.

## 8. Open questions for the owner (★ recommended)
1. Birthdays from: ★A Employee Moments (exists) · B a new employee field · C the employee types it in My Profile.
2. Employee birthdays started by: ★A one standing switch · B none, company birthday only.
3. On a birthday: ★A only the person sees decorations, colleagues the existing Today's Moments card · B small decorations on that card · C nothing.
4. Year of birth: ★A stored, never shown · B day and month only.
5. ★A one occasion at a time · B several together.
6. A birthday during a company occasion: ★A the person sees the birthday, others the occasion · B both mixed.
7. End: A only when switched off · ★B switched off, or an optional last day.
8. Movement: ★A a few seconds per page, then still colours · B always moving · C colours only.
9. Placement: ★A top bar and background only, never over buttons, cards, Sign In / Out or the QR; brand colours kept · B falling over the screen.
10. Kiosks: ★A company occasion only, slow and continuous, no names · B birthdays with names · C none.
11. Hide: ★A "Hide decorations" until the next occasion · B no.
12. National days: A Egypt only · ★B Egypt plus SA, AE, KW, QA, BH, OM, JO, MA (colours only) · C admin picks colours.
13. Greeting: ★A a built-in greeting per occasion, replaceable · B none.
14. Company birthday: ★A founding date, "N years together" · B no number.
15. Login page: ★A shows the company occasion · B unchanged.
16. Native phone apps: ★A later · B this release.
17. A notification when switched on: ★A no · B yes.

## Owner decisions on the open questions (2026-11-09)
1. (A) Birthdays from Employee Moments.
2. (A) One standing switch "Celebrate each employee on their birthday".
3. Only the birthday person sees the decorations, **plus** an option for the administrator to pick certain people whose birthday everyone
   sees decorated (e.g. the manager). [Owner's own answer: "صاحب عيد الميلاد بس يشوف الزينه وفي اختيار اخلي الكل يشوف الزينه لاشخاص معينه"]
4. (A) The year is stored, never shown.
5. (A) One company occasion at a time.
6. (A) On their birthday the person sees the birthday; others see the company occasion.
7. (B) Switched off, or an optional last day.
8. (A) A few seconds of movement, then quiet colours.
9. **The administrator chooses** for each occasion: top bar and background only, or falling over the whole screen for a few seconds.
   [Owner: "على حسب ما اختار"] Either way the layer never takes a tap (pointer-events none) and never stays over Sign In / Out or the QR.
10. (A) Kiosks: the company occasion only, slow and continuous, no names.
11. (A) "Hide decorations" until the next occasion.
12. **Egypt only** for national days.
13. (A) A built-in greeting per occasion, replaceable.
14. (A) Company birthday with "N years together".
15. (A) The login page shows the company occasion.
16. (A) Native apps later. 17. (A) No notification.
- **Added by the owner:** Halloween is one of the occasions ("عايزين نعمل الهالوين كمان ضيفها").

## Mockups approved (owner, 2026-11-09)
`docs/mockups/occasions/` after two rounds: the decorations made stronger ("حاسس الزينه قليله") and a new greeting card ("الكارت … تصميمه
وحش"). The owner said "موافق ابدا بعد دمج الخطابات": implementation starts after the HR letters release (HL3) is merged.
