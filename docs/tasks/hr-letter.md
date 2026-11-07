# HR letter request (owner request 2026-11-07)

An employee asks HR for a letter (employment letter, salary certificate, …); it goes to HR through an approval workflow.
New request type: switchable, off by default; HTML mockup before any UI (CLAUDE.md).

## Owner decisions (2026-11-07)
1. **What HR delivers is a setting**, one of three: a PDF the system generates from a template; a file HR uploads (PDF)
   on the request; or the request only (its status, the letter handed over outside the system).
2. **Letter types are a configurable list** the employee picks from, plus **"Other" with free text**.
3. **Decided through Approval Workflows** (a new workflow type; the default to be designed: HR at one level).
4. The employee chooses **who it is addressed to** ("To whom it may concern" by default) and the **language** (Arabic / English).


## Design (architect, read-only, on main ed92a4c / 3.31.141)

### 0. What exists
- The flow to mirror is salary advances (`trait-advances.php`: workflow_ready before the transaction, lock → START → insert →
  `approval_start` → COMMIT → notify; decide with the posted-level guard; withdraw via `approval_close`; card; My requests rows).
  Advances are not layered: **HR letters would be the first request type layered from birth** (§9).
- Engine: `Workflows::IN_USE / NOT_SET_MEANS / NONE_MEANS` (`NONE_MEANS[$key]` read without `??`), `seedDefaults()` runs only in
  `ensure_approval_schema()` → schema target bump. HR resolver = `approval_settings()['hr_user']`; HR not set → administrators (`no_hr`).
  The Approval Workflows page shows every IN_USE key even when its feature is off.
- My requests "+ New request" is the only entry point and needs `ews_feature_my_approvals`.
- **PDF:** the plugin already has a built-in writer with Arabic shaping (`src/Pdf/Document.php`, `ArabicText.php`, `TrueTypeFont.php`,
  DejaVu Sans; used by payslips, schedules, payouts). Missing for letters: images, paragraph wrapping (right-aligned Arabic), justification, a real bold.
- Private files (`trait-private-files.php`), served like `correction_photo()`.
- Employees: one `name` field, `job_title`, `created_at`; **no hire date, no gender, no Arabic name**. Salary from `payroll_rates_on()` as `advance_salary()`.
- No WordPress personal-data exporter exists; no generic requests routes in the mobile API.

### 1. Journeys
J1 employee asks (type or Other + text, addressee prefilled, language, optional note; the sheet says what will happen per mode) ·
J2 approver decides in My approvals (facts, "Shows the salary"; reject needs a reason by default) · J3 on final approval: **pdf** →
generated, stored privately, *Ready*, employee downloads from My requests; **upload** → *Approved, waiting for the letter*, HR uploads a
PDF → *Ready*; **none** → *Approved* ("HR will hand it to you") · J4 withdraw while waiting · J5 rejection with reason · J6 HR admin page
(Letters / Types & templates / Letterhead: upload, replace, regenerate, preview).

### 2. Data model
Table `{prefix}ews_hr_letters`: id (also the reference HR-YYYY-NNNNNN), employee_id, requested_by, type_key, type_label (snapshot),
other_text, addressee, language (ar/en), note, **delivery (snapshot of the mode)**, status (pending, approved, issued, rejected,
withdrawn, cancelled), requested_at, decided_at, decision_note, issued_at, issued_by (0 = generated), file_key, file_name, file_source
(generated/uploaded), file_size, issue_error (template_missing, no_salary, font, write), with_salary, downloaded_at. No file history
(Audit Log keeps it). Upgrade: `ensure_hr_letters_schema()` in the step list, schema target bump, `seedDefaults` gets an inactive
`hr_letter` row, new capability via `ensure_roles_permissions`; uninstall drops the table and folder.

### 3. Settings
`ews_feature_hr_letters` (0; greyed while My approvals is off). `ews_hr_letters`: delivery (Q1), delivery_per_type (Q2), languages,
default addressee en/ar, Other on, one open request per type, note field (Q8). `ews_hr_letter_types` (key fixed, names en/ar, active,
templates en/ar as plain text with placeholders, salary derived, optional delivery). `ews_hr_letter_letterhead` (company, address,
footer, signatory en/ar; logo, signature, stamp as **private** images, JPEG/PNG ≤ 1 MB). Approval workflow card "HR Letter" (first
request sets LEVEL_1 = HR; self rule `admins` and reject note seeded on first use). Templates: plain text, fixed placeholder list, an
unknown token refused on save.

### 4. Placeholders
{employee_name} (one name field, Q11), {job_title}, {department}, {hire_date} (no source, Q12), {employee_code} (login, Q12),
{addressee}, {letter_type}, {other_text}, {date}, {ref_no} (Hijri / digits Q13), {company_name}, {salary_total} {salary_basic}
{allowances} {currency} (Q7), gendered words (no gender field, Q11). Values are frozen at issue; Regenerate uses today's data (audited).
A salary template with no pay rate is refused at request (pdf) and at generation (`no_salary`).

### 5. Flow per mode
Snapshot of the mode at create. Final approve: status `approved` in the transaction; **after COMMIT** pdf renders → stores → claims
`UPDATE … SET status='issued', file_key=… WHERE status='approved' AND file_key IS NULL` (lost claim deletes its file; a failed render
stays `approved` with `issue_error`, HR told); upload → HR told; none → done (Q5 "Handed over"). Reject → reason, employee told.
Withdraw only while pending (conditional update + `approval_close` in one transaction). Schema and workflow seeding before START;
notifications and files after COMMIT; posted-level guard → `moved`.

### 6. PDF options
**A (recommended): extend the built-in writer** — `Document::image()` + `src/Pdf/Image.php` (JPEG pass-through, PNG with alpha → SMask),
`src/Pdf/TextBlock.php` (wrap in logical order, then `ArabicText::visual()` per line; Arabic right-aligned, English left), renderer
`src/HrLetters/LetterPdf.php` (letterhead, ref/date, addressee, subject, body over pages, signatory with signature/stamp, footer).
DejaVu Sans works but is not a formal Naskh; another font must be TrueType glyf with FE70–FEFF presentation forms (Q14). Admin Preview.
B printable HTML: only as preview. C bundle TCPDF (+10–15 MB): fallback. D mPDF/Dompdf: no. QR verification (Q15) out of scope.

### 7. Permissions and files
New capability `ews_manage_hr_letters` (admin page, all letters, upload/regenerate, types, letterhead); default for EWS Administrator Q7.
Deciding is the engine's; an approver never gets the file. Download (`ews_hr_letter_file`, nonce per id): the requesting employee when
issued, cap holders, administrators; else 403; served inline no-store; non-owner downloads audited; first owner download sets
downloaded_at. Salary templates saved only with `ews_manage_payroll` too (Q7). Folder `workforce-one-letters` (+ brand images) added
to the legacy move, the Nginx notice and uninstall. HR user without EWS admin: an "HR letters" top-level menu like Finance's Advances (Q7).

### 8. Integration points (all behind `hr_letters_enabled()` = switch and My approvals)
New request sheet tile and `wfo-new-letter` dialog (JS: Other text, languages per type); `requests_content` merge, Download button on
the row; `request_withdraw` case `letter`; `my_approvals_card` case `hr_letter`, kind `letter`, tone; Requests Hub (`Hub::TYPES`, rows,
decision); `Workflows::IN_USE['hr_letter']` (NONE, LEVEL_1–4) with NOT_SET/NONE texts, **the card hidden while off**;
`approval_kind_label`; notifications (`Links::VIEWS['hr_letter']='requests'`); Features card with warnings; admin page `ews31-hr-letters`
(3 tabs); Settings Overview sanitizers; admin-post handlers; no REST in this release (Q16); no exporter (Q17); Arabic for every string.

### 9. Layered shape (module `hr_letters`)
`src/HrLetters/`: LetterRules (pure), LetterTemplate (pure), LetterPdf, LetterService (create, decide, withdraw, issue, upload,
regenerate, card, myRequests, hubRows, hubDecide, preview), LetterPorts (People, Salary, Files, Notifier, Workflow), Data/LetterStore +
WordPressLetterStore (the only SQL) + LetterSchema; `src/Settings/HrLetterSettings.php`; `src/Pdf/Image.php`, `TextBlock.php`;
`includes/trait-hr-letters.php` controllers only; ports in `trait-services.php`; `Services::letterStore()/hrLetters()`. The Service
calls the exported `ApprovalEngine`/`ApprovalReads` with the actor passed in (L2). Salary via a port onto payroll (not layered).

### 10. Off by default
`hr_letters_enabled()` first, before any query, everywhere; no submenu; handlers refuse; the workflow card hidden. Golden: only
`ews31-features` (and Settings Overview) change. The upgrade still creates the table, the inactive workflow row and the capability.
Turned off with requests open: nothing deleted; deadline reminders skip `hr_letter` while off.

### 11. Edge cases
HR not set → administrators (`no_hr`) + warning; HR's own letter → self rule admins (Q9); archived employee → stays decidable, PDF still
made, HR hands it over (Q10); type deactivated → snapshot kept, types never hard-deleted; template missing → languages offered only
with a template, else `template_missing`; Other in pdf mode (Q3); no salary → refused/`no_salary`; double approve → `moved`;
concurrent issue → claim; withdraw vs final approve → conditional on pending; bad upload → refused (PDF magic, ≤ 10 MB, Q6);
unsupported image → refused; long body → more pages; mode changed → snapshot; multisite per prefix.

### 12. Mockups (`docs/mockups/hr-letter/`)
New request tile; letter sheet; My requests rows (waiting, approved, ready + Download, rejected, withdrawn); My approvals card; Hub row;
Approval Workflows card; Features card; admin Letters (upload / regenerate); Types & templates editor (chips, unknown token, Preview);
Letterhead; **the letter itself en and ar** (with one real PDF from the prototype renderer); notification texts.

### 13. Tests
Unit: HrLetterSettings, LetterRules, LetterTemplate, LetterPdf, PdfImage, TextBlock, LetterService (fakes), every IN_USE key has its
texts. e2e: `e2e_hr_letters.py` (off, three modes, download access, HR not set, self rule, deactivated type, missing template, Arabic,
upload refusals, withdraw, moved, off with requests open, deadlines skipped), `_mu.php`, schema/lifecycle/private files/features
extended, a browser test for the sheet. Golden: pages unchanged when off; `ews31-features` and Settings Overview declared; a trace for the
new handlers. Checks: layers (new slice), modules, translations, phpstan, phpcs.

### 14. Open questions for the owner
1. Default delivery mode when first switched on; does a request keep its mode or follow the setting at approval?
2. One global mode, or a mode per letter type?
3. "Other" in PDF mode: a generic template with the employee's text, or always HR upload / outside?
4. Starting list of types (proposal: Employment certificate, Salary certificate, Experience letter, Embassy / visa, Bank letter); a new "Documents" group?
5. Request-only mode: "Handed over" mark? May HR cancel an approved, unissued request? While off, may employees still download issued letters?
6. Files: keep old versions? audit every download? max upload size? keep letters forever or N months?
7. Salary and access: may letters show salary? who may write a salary template (proposal: also Manage Payroll)? EWS Administrator gets "Manage HR letters"? the HR user automatically?
8. Employee note: none / optional / required?
9. HR's own letter: administrators (proposal), next level, or themselves?
10. May HR create a letter for an employee, including a former one?
11. Arabic name and gender fields on employees (People schema change), or gender-neutral templates with the stored name?
12. Add a hire date field? login name as employee number?
13. Gregorian only or Hijri too; Western or Eastern Arabic digits; salary amount in words?
14. Font: DejaVu Sans acceptable, or bundle a formal Naskh (Amiri, about +0.5 MB, to be checked), or TCPDF (+10–15 MB)?
15. QR code to a verification page?
16. Mobile API now or later?
17. A WordPress personal-data export entry for letters?

### 15. Risks
Arabic typography (no justification/kashida, harakat dropped; mitigated by Preview and a mockup PDF; TCPDF fallback); salary leakage
(private files, access on every download, audit, approvers never get the file); signature/stamp images private only; synchronous
generation (failure leaves `approved` + `issue_error`); NONE + pdf issues salary certificates with no human check (owner's choice,
said in the NONE text); depends on My approvals; golden diffs only on Features / Settings Overview; schema bump + capability; first
request type layered from birth (review the port design against check_layers L2/L4 before coding).

## Owner decisions on the design (2026-11-07)
- Q2: **the delivery mode is per letter type**, with a general default.
- Q7: **salary may appear**; a template that shows it is saved only by someone who also has Manage Payroll; the file is seen only by
  the employee and HR, non-owner downloads audited.
- Q11/Q12: **add optional employee fields: Arabic name, gender, hire date** (People module schema change); letters use them when filled.
- Q14: **try a formal Arabic font (Amiri)** with the built-in writer and send the owner a real sample PDF before the code; fall back to DejaVu.
- The other questions: pending (recommended answers proposed in §14).
