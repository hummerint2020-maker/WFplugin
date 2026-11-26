# Design: Edit system messages (3.31.166)

**Status:** technical design by the read-only architect on main 3.31.164, saved before implementation. Product design and mockup approved by the owner on 2026-11-25: `/mnt/project-files/system-messages/design.md`, `mockup.html`.

## Owner decisions (2026-11-25)

1. New capability **`ews_manage_messages`** ("Manage system messages"), granted to administrators by default and grantable in Role permissions.
2. Arabic and English are both editable.
3. The raw-English notifications are translated (and so become editable) in this release.
4. Plural (`_n`) texts are out of scope for R1.
5. Edits change new notifications only; stored ones keep their words.

The architect's open questions were settled by the implementer with the recommended answers (to be confirmed in the release report): wrap **all** raw-English notification call sites (≈40, not only 19); the EWS Administrator role gets the capability by default like its other capabilities; tags must stay exactly as in the original; per-kind length limits as below; "Reset all" works per language tab; the page links to the Offline screen, login and Occasions editors.

## 0. Findings

- `languages/workforce-one.pot` is stale (from 3.31.126; 2,930 msgids, 3 msgctxt, 75 plurals, `--skip-js`). `tests/check_translations.py` checks only `.po`/`.json`. This release regenerates the `.pot` and adds a catalog check.
- The text domain loads on `init` priority 1 (`employee-schedule-manager.php:128`) with `determine_locale()`. Only `workforce-one-ar.*` exists, so Arabic loads only for locale `ar`. The plugin already treats `strpos(determine_locale(),'ar')===0` as Arabic; this design does the same.
- `esc_html__`/`esc_attr__`/`_e`/`_x` run `gettext` before escaping, so an override is escaped wherever the original was. Many places print `__()` unescaped, hence rule R5.
- `notify()` stores title and body as text in the actor's language at send time.
- Capabilities: `permission_defs()` (`trait-permissions.php`). New capabilities reach roles only through `ensure_roles_permissions()`, run on a schema upgrade, so `ews_schema_target()` is bumped.
- `check_layers.py` L2 forbids `get_option`, `add_filter`, … in Services; the Store is the only place reading the option.

## 1. Data

| Option (`src/Settings/Options.php`) | Kind | Default |
|---|---|---|
| `ews_feature_system_messages` | feature switch, page `ews31-features` | off |
| `ews_message_overrides` | data, not autoloaded | `[]` |

- Shape `['ar' => [key => text], 'en' => [key => text]]`; key = `msgid` or `msgctxt."\x04".msgid` (the `.l10n.php` key).
- Bucket: `'ar'` when the locale starts with `ar`, otherwise `'en'`.
- Upgrade: schema target `3.31.166` (new capability only, no table). No data migration; nothing is written while off.

## 2. Rules — `src/Messages/MessageRules.php` (pure)

`key()`, `bucket()`, `tokens()`, `check(original, text, kind)` → `[normalised, code]`, `limit()`, `labels(translatorsComment, original)`.

- **R1** empty → `empty` (use Reset).
- **R2 placeholders.** Non-positional tokens of the original are numbered (`%s %s` → `%1$s %2$s`), and the override's are rewritten the same way, so the admin may move them. The set of (number, type) pairs must equal the original's → else `placeholders`. A lone `%` → `percent`. Stored text is always positional when the original has placeholders.
- **R3 tags.** The multiset of tag tokens must equal the original's → else `html`; any other `<`/`>` refused.
- **R4 length** (`mb_strlen`): notification title 80, banner 300, notification body / error 500, other `min(500, max(60, 3 × original))` → `too_long`.
- **R5 quotes (security).** `"`, `'`, `\` allowed only as many times as in the original; otherwise `'`→`’`, `"`→`”`; `\` refused (`chars`).
- **R6** equal to the current default → treated as a reset.

## 3. Catalog

- `tools/build_message_catalog.py` writes `languages/workforce-one-catalog.php` from the `.pot` (msgid, msgctxt, `#:` refs, `#.` translators comments, plural flag) and the two JS JSON files (`j=1`). Module from the reference path via `docs/architecture/modules.json` (traits / namespaces) plus a small prefix table for templates; else `general`. Loaded only on the admin page and on save; excluded from phpcs/phpstan.
- Current Arabic read from `workforce-one-ar.l10n.php`.
- `src/Messages/ImportantMessages.php`: hand-written key → kind (`notification_title`, `notification`, `banner`, `error`); everything else is `ui`. The closed-week banner of the schedule change request adds its `_x(…, 'closed week banner')` key here as `banner`.
- `tests/check_message_catalog.py`: (a) the shipped catalog equals a rebuild; (b) every ImportantMessages key is in it; (c) `--fresh-pot` compares msgids with a freshly made `.pot` (CI, where wp-cli exists).

## 4. Runtime

- `src/Messages/MessageFilter.php`: callbacks for `gettext_workforce-one`, `gettext_with_context_workforce-one`, `load_script_translations`, `pre_load_script_translations`; per-blog map memo; locale memo cleared on `change_locale` and `set_current_user`; `busy` guard. No `ngettext` filter in R1.
- `system_messages_boot()` (constructor, next to `health_boot()`): returns at once unless the switch is on **and** the option is non-empty; else registers the filters via `Messages\Hooks::filters()`.
- `Messages\Hooks::register()` always adds `admin_post_ews_messages_save` / `_reset`; both refuse while off.

## 5. New files

`src/Messages/{MessageRules, ImportantMessages, MessageCatalog, MessageOverrideService, MessageFilter, Hooks}.php`, `src/Messages/Data/{MessageOverrideStore, WordPressMessageOverrideStore, CatalogFiles}.php`, `includes/trait-system-messages.php` (controller), `templates/admin/system-messages.php`, `assets/js/system-messages.js`, `languages/workforce-one-catalog.php`, `tools/build_message_catalog.py`, `tests/check_message_catalog.py`.

- Service: `save(lang, key, text, expected, catalog)` → codes of §2 plus `unknown`, `plural`, `stale`; `reset(lang, scope one|module|all|review, arg, catalog)`; `adopt(lang, oldKey, newKey, catalog)`.
- Audit: `system_message_save` (lang, key, before, after) and `system_message_reset` (lang, scope, count).
- Concurrency: the form posts the text it showed; a different stored text → `stale`.

## 6. Existing files

`employee-schedule-manager.php` (version, trait, boot, hooks), `trait-permissions.php` (capability), `trait-schema.php` (target), `trait-admin.php` (submenu only when on), `src/Ui/AdminNav.php` + `trait-admin-nav.php` (tab), `trait-features.php` + `templates/admin/features.php` (switch + audit), `src/Settings/Options.php`, `src/Platform/Services.php` (`messageStore()`, `messages()`), `docs/architecture/modules.json` (module `messages`), `tests/golden/expected.json`, CHANGELOG.

Raw-English notifications wrapped in `__()` / `sprintf(__())` with translators comments and Arabic in the `.po`: overtime, early leave, swap, leave, leave admin, presence, payroll, HolidayRefundService, admin requests, face reset, schedule updated, breaks, and any other `notify()` caller found passing raw English. "day(s)" stays in `__()` (not `_n`) so it is editable in R1.

## 7. Off is off

Off: no filter, no locale hook, no page, no tab, handlers refuse, nothing written. Still visible when off: the Roles row, the Features section, the Options list, and the translated notifications (a translation fix, not part of the feature).

## 8. Edge cases

Actor's language decides a notification's language (today's behaviour). `ar_EG`-type locales get the `ar` bucket over English defaults (warned on the page). "Used in N places". Plural rows read-only. Needs review = an override whose key is not in the catalog: adopt (revalidated) or drop. JS overrides apply after browser cache refresh. Multisite: own option and switch per site. `uninstall.php` removes `ews_*` options.

## 9. Tests

Unit: MessageRules, MessageCatalog, MessageOverrideService (memory store, fake audit), MessageFilter; update AdminNav/Options tests. E2E `tests/e2e_system_messages.py`: off changes nothing; on edit a notification title and a banner; placeholder refusal; stale; reset one/module/all with audit; off→on keeps overrides; permission; JS override; needs review. Checks: unit, phpstan, phpcs, translations, modules, layers, catalog, golden, related e2e suites.

## 10. Risks

Unescaped output (R5 is the guard); the translation fix ships unconditionally; catalog drift (checked); filter cost (~1 ms/page, not registered when empty); script-translation filters re-verified on CI WordPress.
