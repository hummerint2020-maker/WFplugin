# HL3: the HR letter request type, layered from birth

Architect design (read-only), 2026-11-09, on main after HL1 and HL2 (schema target 3.31.144). It refreshes the design in
`docs/tasks/hr-letter.md` (written on 3.31.141) against today's code. The owner decisions in that file still hold; the open questions are in §9.

## 0. Where today's code differs from the 3.31.141 design
1. **The approvals engine is layered.** `ApprovalEngine` and `ApprovalReads` are exports; the letter services call them directly with the actor passed in. `WorkflowService` is not exported, so "the first request sets the workflow to one level: HR" goes through a port (`LetterWorkflow::ensureReady`), which also writes the self rule and the reject-note options.
2. **Withdraw lock order** is now "engine `closeOpen` first, then the module row" (as L5e2), the same order as a decision, so a withdraw and a decision never deadlock.
3. **The pure settings class** cannot live in `src/Settings/` (L4 forbids a service from using `Settings\*`): it becomes `src/HrLetters/LetterSettings.php`.
4. **Employee since** comes from HL2's `EmployeeDetailsRules::since()`; that class is added to people's `exports` in `modules.json`.
5. **PDF classes** (`Pdf\Document`, `TextBlock`, `Image`, `TrueTypeFont`) are added to `platform.exports` so `LetterPdf` can be a checked service.
6. **No People schema change**: `name_ar`, `gender` and `hire_date` exist (HL2). `{employee_code}` is dropped (never decided, not in the approved chips).
7. **HL1 tools exist**: HL3 only adds the renderer. DejaVu is not used.
8. **QA 6 cards outside a workflow** are not needed: the workflow is configured at the first request.
9. **QA 8 "why with administrators"** already covers `no_hr` / `self`; its notification points to the Requests Hub (see OQ1).
10. **New request sheet:** the `money` group is already "Money and documents"; the tile goes there.
11. **Golden:** `snapshot.php` handles a schema bump (HL2). `expected.json` is release 3.31.144's, so HL3 adds to the HL2 entries. Every new option is registered in `Options.php` (priming), else one more query per page.
12. **One open request** is enforced in the database by a UNIQUE `open_key`, not GET_LOCK.

## 1. Data model and upgrade
Table `{prefix}ews_hr_letters` (SQL in `src/HrLetters/Data/LetterSchema.php::sql()`, the single source for the upgrade and the storage contract):
`id` PK (reference `HR-YYYY-NNNNNN` derived, not stored) · `employee_id` · `requested_by` · `source` (employee | hr) · `type_key` · `type_label`
(snapshot) · `other_text` · `addressee` · `language` (ar | en) · `note` · `delivery` (pdf | upload | none, snapshot; Other is upload) · `with_salary` ·
`status` (pending, approved, issued, ready, rejected, withdrawn, cancelled) · `open_key` (NULL when not open) · `requested_at` · `decided_at` ·
`decision_note` · `issued_at` · `issued_by` · `file_key` · `file_name` · `file_source` (generated | uploaded) · `file_size` · `issue_error`
(template_missing, no_salary, render, write) · `downloaded_at`.
Indexes: PK, UNIQUE `open_key`, `employee (employee_id,id)`, `status (status,requested_at)`. Every UPDATE that closes a letter sets `open_key = NULL`.

Upgrade: a new `ews_schema_target()` value (HL2 sites already have 3.31.144); `ensure_hr_letters_schema()` in `trait-hr-letters.php` (dbDelta of
`LetterSchema::sql`) added to `maybe_upgrade_schema`'s steps; `SchemaCheck::TABLES`; `WorkflowService::seedDefaults` gets an inactive `hr_letter`
row; the capability `ews_manage_hr_letters` for administrator and EWS Administrator; `uninstall.php` drops the table, the folder
`workforce-one-letters` and the options. Options (registered in `Options.php`): `ews_feature_hr_letters` (false), `ews_hr_letters` (languages,
default addressee ar/en, Other, one open, new type delivery 'none', currency names), `ews_hr_letter_types` (defaults from code when absent),
`ews_hr_letter_letterhead`, `ews_hr_letters_ever` (internal).

## 2. Layered structure
**Pure** (`src/HrLetters/`): `LetterRules` (reference, request checks, delivery, open key, languages per type, who may download / withdraw / cancel /
mark ready / upload / regenerate, `isPdf`, the My requests state), `LetterTemplate` (closed token list, `validate`, `usesSalary`, `render` to
paragraphs, the gender syntax per OQ2), `LetterFacts` (placeholder values: Arabic name fallback, Arabic-Indic digits, Arabic month names, Hijri,
amounts in words, `since()`, title from gender), `LetterSettings` (settings, types, five default types with the mockup's texts, letterhead,
sanitizers), `LetterRenderer` interface and `LetterPdf` (the trial PDF's layout through HL1's tools; font bytes from a port).

**Ports** (`LetterPorts`; adapters in `trait-services.php::letter_ports()` like `correction_ports()`): `LetterSite` (switch, requestable,
settings, types, letterhead, company fallback, currency, markUsed), `LetterWorkflow::ensureReady()`, `LetterNotices` (codes; words in the adapter;
HR = Approval Workflows' HR user, else holders of the capability), `LetterFiles` (font, store, upload with magic bytes, read, delete, image;
private folder, 32-character keys), `LetterSalary::on()` (payroll rates, as advances). Reused: `Transaction`, `AuditLog`, `Clock`.

**Store and read model:** `Data/LetterStore` + `WordPressLetterStore` (insert with duplicate detection, find, forEmployee, openExists, and
conditional updates: markDecided, withdraw, claimIssued, replaceFile, setIssueError, markReady, cancel, markDownloaded). `Data/LetterQuery` +
`WordPressLetterQuery` (facts with the employee and department, admin counts and list with escaped search, Hub rows, employee picker), read only.

**Services** (no SQL, clock, options or current user): `LetterRequests` (request, create for an employee, withdraw, my requests, the form),
`LetterDecisions` (decide, card, cancel, mark ready), `LetterIssuer` (after approval, issue, upload, regenerate, preview, download with audit).

**Controllers:** `src/HrLetters/Hooks.php` and `includes/trait-hr-letters.php` (nonce, capability, sanitising, one service call, code → text,
redirect / download / template; no `$wpdb`). Templates `admin/hr-letters.php` (Letters, Types & templates, Letterhead) and `admin/hr-letter-new.php`.
JS `hr-letters.js` (app sheet) and `admin-hr-letters.js` (chips, token check); CSS `admin-hr-letters(-rtl).css`, a few rules in `app-approvals.css`.

**Existing files changed:** main plugin file (trait, hooks), `Services.php` (letter services), `trait-services.php` (ports), `trait-schema.php`,
`SchemaCheck.php`, `Workflows.php` (IN_USE, NOT_SET_MEANS, NONE_MEANS for `hr_letter`), `WorkflowService::seedDefaults`, `trait-approvals.php`
(card hidden and save refused while off), `trait-approval-deadlines.php` (skip while off; kind label), `trait-my-approvals.php` (card, My requests
rows and notices, the tile, withdraw, kinds, clean URL), Requests Hub (if OQ1), `trait-permissions.php` (capability), `trait-admin.php` (menu while on
or once used), `AdminNav.php` and `trait-admin-nav.php`, `trait-features.php` and `templates/admin/features.php` (the card), `Options.php`,
`trait-private-files.php` (the letters folder; notice only once used), `Notifications/Links.php`, `templates/app/new-request.php`,
`templates/app/requests.php` (optional state label and Download; identical when absent), `templates/app/approvals.php` (optional pills),
`Support/Download.php` (inline PDF), `uninstall.php`, translations, CHANGELOG.

**modules.json:** a new module `hr_letters` (trait `hr-letters`, table `hr_letters`, its api names) with a layered slice `letters` (namespace
`HrLetters`, store, the three services and `LetterPdf`, the read model with tables hr_letters/employees/departments, `traits_clean`), plus the
exports in §0. Rules L1–L6 and `check_modules` are met as listed in the architect's report: the table is new, services are clean, other modules
call only `api` names.

## 3. Off by default
`hr_letters_enabled()` reads the switch only (primed); every api helper and handler checks it first. While off: no tile, cards, rows, Hub rows,
deadlines or Approval Workflows card; nothing deleted; capability holders can still open the page and download issued letters only once letters
were used (`ews_hr_letters_ever`). The upgrade still runs (table, inactive workflow row, capability).
Golden: pages `ews31-features` (both variants in pages.json: the card, off); no action changes; `hooks_added`: `admin_post_ews_hr_letter_request`,
`_respond`, `_file`, `admin_post_ews31_hr_letter_action`, `_create`, `_types_save`, `_letterhead_save`, `_preview`, `_image`.

## 4. Letter generation
Templates per type and language, plain text, closed token list (unknown refused on save). A template using a salary token is saved only by someone
who also has Manage Payroll. Tokens: `{employee_name}` (Arabic name in Arabic letters, else the name), `{title_mr_ms}`, `{job_title}`,
`{department}`, `{company_name}`, `{addressee}`, `{letter_type}`, `{other_text}`, `{hire_date}` (Employee since), `{date}`, `{date_hijri}`, `{ref_no}`,
`{salary_total}`, `{salary_basic}`, `{allowances}`, `{salary_in_words}`, `{currency}`. Issue: facts → template → salary if needed → `LetterPdf` →
private file → compare-and-set. Values are frozen in the PDF; Regenerate uses today's data and is audited. A failure leaves the letter approved
with `issue_error` and HR told. Download: the employee (own, issued, feature on), capability holders and administrators (any time); nobody else
(approvers get 403). Inline, no-store, audited. Salary is never stored in the row nor the Audit Log.

## 5. Flows, concurrency, permissions
Request: checks → one open → `ensureReady` (before BEGIN) → BEGIN, insert (duplicate → open), engine start, auto-approve in NONE mode → COMMIT →
audit → after commit: issue / tell HR / tell the approver. Decision: the engine's conditional step update and `markDecided(status='pending')` give one
winner (`moved` / `done` for the loser). Withdraw: `closeOpen` then the row. Issue, upload, cancel and ready all claim `approved AND file_key IS
NULL`. HR actions need `ews_manage_hr_letters`, a nonce per letter; HR's direct letter (former employees too) is audited. Archived employees are
decided and issued; notifications skipped without a user. Salary is read only at issue through the payroll port.

## 6. Tests
Unit: settings, rules, template, facts, PDF, the three services (fakes plus the real engine on memory stores), additions to Approvals, Options,
Hub, AdminNav and SchemaCheck tests. Storage contract `tests/e2e_hr_letters_store.php`. e2e `tests/e2e_hr_letters.py` (off; the three delivery
modes end to end; reject, withdraw; HR not set; NONE mode; one open; templates and salary rule; downloads per person; uploads and images; direct
letter; races; upgrade and uninstall) and `_mu`. Browser `tests/hr_letters_browser.js`. Re-run the approval, My approvals, Hub, features, nav,
private files, schema, lifecycle, roles, API, payroll and speed suites. Golden.

## 7. Commits
1 schema, settings and pure rules · 2 store, read model, fakes, contract, modules.json, wiring · 3 rendering and issuer · 4 requests, decisions,
ports · 5 employee side (controllers, My requests, My approvals, JS/CSS, browser test) · 6 admin side (page, direct letter, Features card, menu,
Hub, private files, e2e) · 7 golden, translations, CHANGELOG, "as built".

## 8. Risks
Arabic typography (no kashida, no harakat); salary leakage (preview and direct letter, OQ6); the private-files notice on golden pages (made
conditional); generation in the decision request (after COMMIT, never rolls back the decision); `ensureReady` before BEGIN; the schema target must
change; the capability visible on Roles while off; My approvals menu shown to someone with a letter step after letters were used; gendered Arabic
wording (OQ2); screens without a mockup (OQ9).

## 9. Open questions for the owner (asked 2026-11-09)
1. Letters on the Requests Hub? (a) a pending row decided there — recommended; (b) not in HL3.
2. Gendered wording beyond the title (يعمل/تعمل, له/لها): (a) an inline pair `{g:male|female}` — recommended; (b) fixed gender tokens; (c) two templates; (d) title only.
3. Missing Arabic name, gender or hire date: (a) fall back (name, neutral title, the day added) and show ✗ to the approver — recommended; (b) refuse PDF letters; (c) warn and let the approver decide.
4. Currency: (a) names in Arabic and English in the settings, default the code — recommended; (b) the code only.
5. Basic salary and allowances tokens too (recommended), and fractions in words: (a) "and 50/100" — recommended; (b) rounded.
6. Without Manage Payroll, may HR preview or issue a direct letter showing salary? Recommended: no (both need Manage Payroll); downloading a requested letter stays with HR.
7. HR's direct letter for their own record: (a) refused, they ask in the app — recommended; (b) allowed and audited.
8. The reference number in Arabic letters: Western digits (as the trial) — recommended; or Arabic-Indic.
9. A small mockup addition before the screens: "Letter for an employee", the Hub row, Mark ready with a note, Cancel with a reason, notification texts — recommended.
10. One open request: (a) while waiting only; (b) until issued / ready / rejected / withdrawn — recommended; Other exempt — recommended.
11. Default types: (a) as the approved mockup (the salary certificate as an automatic PDF) — recommended; (b) all request-only until HR saves a template.
12. Regenerate: tell the employee and count the next download as new — recommended.
13. Letters in bulk approve in My approvals (each makes a PDF; capped at 20) — recommended.

## Owner decisions on the open questions (2026-11-09)
The owner agreed to every recommendation: 1 (a) letters on the Requests Hub · 2 (a) `{g:male|female}` · 3 (a) fall back, ✗ on the approver's card ·
4 (a) currency names · 5 basic and allowances tokens, fractions as "and 50/100" · 6 preview and direct salary letters need Manage Payroll ·
7 (a) no direct letter for one's own record · 8 Western digits in the reference · 9 a mockup addition first · 10 (b) open until issued / ready /
rejected / withdrawn, Other exempt · 11 (a) default types as the mockup · 12 regenerate tells the employee and resets the first download ·
13 letters in bulk approve (20 at most).

## Mockup addition approved (owner, 2026-11-09)
`docs/mockups/hr-letter/`: admin-new (Letter for an employee), admin-ready (ready to collect with a note, cancel with a reason, a letter that
could not be made), hub (a waiting letter on the Requests Hub), notices (the notification texts). The owner said "موافق ابدأ".

## Review (independent, read-only) and what was done
- Fixed: the e2e suite's withdraw check queried a column that does not exist (the rest of the suite never ran); HR's download used the
  employee's link (its nonce is per user) and another employee's refusal came from the nonce, not the access check — now each uses a link
  of their own; the "off" request now carries a valid nonce; the unknown-placeholder message lost its braces in the redirect; a failed
  letter in a bulk approve counted as approved (approvals.js); HR's actions (upload, regenerate, ready, cancel) are refused while letters
  are off, and Upload is hidden then; regenerating a salary letter needs Manage Payroll too (the spirit of decision 6: it reads today's
  salary); the preview's reference number used the employee's id; the e2e suite leaves nothing behind; inactive types are not offered in
  "Letter for an employee"; "I upload a PDF" without a file is refused; a docblock moved back.
- Added at the owner's request before the PR ("عايز الاختبارات دي الاول"): `tests/hr_letters_browser.js` (the request sheet, approve and
  download, template chips, a phone); `tests/e2e_hr_letters_more.py` with `tests/e2e_hr_letters_mu.php` (requests at the same moment: two
  asks for one open letter, decision vs withdrawal both ways, two deciders, ready vs cancel; faults: the approval cannot be written, the letter
  decided meanwhile, the approval decided while withdrawing, the file claimed by another request; the upgrade from 3.31.144); uninstall
  checks in CI (the letters folder, the settings and the capability are removed). The schema target is `3.31.144.1` (CI reads digits only).
- Bugs those tests found, fixed: a decision on a waiting letter was always refused as "moved" (the engine's open status is
  WAITING_FOR_LEVEL_n, not PENDING; the unit fake now uses the real statuses); the deadlines tick read the letters switch on every run
  while off (golden); the "Which letter?" field of "Other" showed for every type (CSS). Also fixed for CI: PHPStan (`$hr_letters`).
- Not done (deviations, to be raised with the owner): no multisite suite; the Requests Hub keeps its standard Approve label; the letter's
  own page "Letter for an employee" is a tab, not a separate page.
