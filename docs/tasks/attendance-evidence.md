# L5d (3.31.139): sign-in evidence and the time report read model

Design by the read-only architect on main 407794f (L5c merged). A move only: same SQL text and order, same option and transient reads at the same moments, same audits, notifications, JSON answers, redirects and `wp_die` texts. No new option, hook or schema, so `tests/golden/expected.json` stays empty. Known quirks are moved as they are (attendance-store.md §13 decision 2). Owner decisions are in §9.

**Correction to the brief.** The Auto Attendance page's JOIN does not read `time_logs`: `trait-auto-attendance.php:137` reads `auto_attendance_rules` by id and `:138` joins it with `employees`. It stays in scope as the last rules SQL outside the store.

## 1. Data model and upgrade path
None. `ensure_evidence_schema` (`trait-signin-evidence.php:39-75`) and `_once` (`:77`) stay in the trait; `attempts_table()` (`:37`) stays, used only by the schema. With the feature off nothing new runs: `evidence_keep` returns at the option read, the report never runs `SHOW TABLES` (`trait-time-report.php:58`, short-circuit), the offline report answers `{kept:false,reason:'off'}`.

## 2. Scope per function (M moved with delegator, C controller keeps flow and its SQL leaves, L left)
`includes/trait-signin-evidence.php`
- L: `evidence_settings`, `attempts_enabled`, `face_photo_enabled`, `attempts_table`, schema, `evidence_client_ip`, `evidence_device`, `evidence_app_config`, `face_photo_save/hold/take/may_view/url`, `evidence_row`, `evidence_record_url`.
- M: `evidence_keep` (to `EvidenceService::keep`; `evidence_audit` goes into the service), `evidence_reason_label` (to `EvidenceRules::reasonLabel`).
- split: `evidence_maybe_alert` — the count and the `=== N` decision go to the service; `get_users`, the employees lookup (`:164`) and `notify` become the trait adapter `evidence_alert_send($emp,$day,$n)` (People debt).
- C: `evidence_refused` (builds args as today; `face_photo_take()` runs while the array is built, before keep), `evidence_offline_report` (keeps login, nonce, off, `noteBelongs`, `current_employee`, type check, clock math, 7-day check, every `wp_send_json_*`; schema-once, the two "reached" reads, the limit and keep go to `offline()`), `face_photo_serve` (only the row lookup `:274-275` moves to `photo()`; permission check and audit stay), `evidence_cleanup` (reads the clocks, calls `cleanup()`; the glob loop becomes the adapter `evidence_sweep_orphans($limit)`, called last), `evidence_timeline` (two service calls, because `may_view` runs SQL between the logs query and `SHOW TABLES`).

`includes/trait-time-report.php`
- `time_report_scope_sql` replaced by `time_report_scope(): ?int` (null all, 0 none, n department).
- `admin_time_report` and `admin_time_csv` are controllers: every `$wpdb` call goes to `TimeReportQuery`, `evidence()` or `timeLogStore()` in the old order. `time_report_may_manage` stays (People debt).

`includes/trait-auto-attendance.php`: `admin_auto_attendance` — `:137` to `autoRuleStore()->find()`, `:138` to `autoRuleQuery()->listWithNames()`.

## 3. New code (`WorkforceOne\Attendance`)
- `Data/AttemptStore` + `WordPressAttemptStore`: `exists, insert, refusalsOn, typeBetween, notReachedSince, photoOf, day, photoKeysBefore, clearPhotosBefore, deleteBefore` (exact old SQL text per method; no method computes a time).
- `TimeLogStore` additions: `kindsBetween, photoOf, allOn, photoKeysBefore, clearPhotosBefore, find`. `AutoRuleStore::find`.
- Read models (allowed to join, listed under `reads`): `Data/TimeReportQuery` + `WordPressTimeReportQuery` with value object `TimeReportFilter{start,end,search,show,attempts,scope}` (`count, pageKeys, logRows, attemptRows, refusedBy, refusedTotal, employees, csv`); `Data/AutoRuleQuery` + `WordPressAutoRuleQuery::listWithNames`.
- `EvidenceRules` (pure): `REASONS`, `reasonLabel`, `auditAction`, `eventLabel`, `auditText`, `alertDue`.
- Port `EvidenceSite` (in `AttendancePorts` as a fourth argument): `settings, ensureSchemaOnce, distance, alert, deletePhoto, sweepPhotos`.
- `EvidenceService(AttemptStore, TimeLogStore, EvidenceSite, AuditLog, Clock)`: `keep, offline, photo, cleanup, tableExists, dayLogs, dayAttempts`; `Services::attemptStore()/evidence()/timeReport()/autoRuleQuery()`.

Order inside `keep`: emp null or attempts off gives 0; `ensureSchemaOnce`; clock only when `attempted_at` missing; build row (same key order, `substr`/`mb_substr` limits, null/'' skipped, values untyped); insert; if not `face_ok`: audit, then alert check. Order inside `cleanup`: `exists` (false: return, settings never read), settings, the two cuts, `photoKeysBefore` + `deletePhoto`, clear, delete, time-log keys + delete, clear, `sweepPhotos`.

## 4. Order of the move
1. Harness on unchanged code (base vs base). 2. `check_layers`: HELPERS `attempts_table`, `reads` support, rule L6 "a read model writes nothing" with self-tests. 3. Stores, read models, fakes, storage contract. 4. `EvidenceRules` + tests, port + adapter, `EvidenceService`, `Services` methods. 5. Delegate the evidence trait. 6. Time report page, CSV, Auto Attendance page. 7. `modules.json`, enrolment and baselines in the same commits as 5–6; docs, CHANGELOG, version.

`admin_time_report` order to keep: `can`, dates; `attempts_enabled` then `tableExists`; `count`, `$paged`, `pageKeys`, `logRows`, `attemptRows`; `find(edit)` then `may_manage(edit)`; `shift_prime_people`, `locations_prime`; per row `time_report_site`, `sign_in_classification`, `may_view` twice per photo row; `refusedBy`, `refusedTotal`; the timeline (second `SHOW TABLES`: keep); `may_manage(prefill)`, `employees()`, `face_photo_enabled`. Do not merge the two `SHOW TABLES` or the double `may_view`.

## 5. Golden harness additions
- `exit.php`: `golden_json` (AJAX, `GoldenExit('json', …)`), `golden_page` (HTML, nonces/times normalised).
- `seed_attendance.php`: evidence schema; attempts (omar `outside_location`, omar `face_failed` with a 32-char key, mona `not_reached` D-1, lina `face_ok`, other department, a 70-day-old with photo); `photo_key` on lina's Sign Out; a seeded auto rule.
- `actions_evidence.php` (`evidence:`): keep (off, refused, face_ok, not_reached text, INSERT fault, alert at N / N+1 / dept manager / admins fallback / employee-is-manager), offline (401, nonce, off, account, employee, type, old, reached by time_logs and by attempts, limit, kept, INSERT fault), serve (redirect, die, 403 kinds, admin full with audit, manager thumbnail, `photo_viewers=hr`, other department, settings-only user, bad key, missing file), cleanup (table missing, normal, UPDATE fault, DELETE fault), timeline, report page variants (admin show both/refused/recorded, search, page 2, manager, manager without department, edit/add_time in and out of scope, timeline, attempts off, table missing, UNION COUNT fault), Auto Attendance page, CSV.
- `actions_timecsv.php`, `actions_timecsv_mgr.php` (shutdown function prints the CSV and SQL); `pages.json` entries; `run.py` sanity guards (audit actions, notification, each offline reason, serve results, cleanup effects, `UNION ALL`, CSV BOM, off case without the attempts table). Photo files recreated by the script on each side (the snapshot restores the database only).
- Manual self-test in the PR: dropping `a.result<>'face_ok'`, reading settings before `SHOW TABLES` in cleanup, calling `may_view` once per row must each fail the trace.

## 6. Layer and module baselines
- `check_layers.py`: `HELPERS['attempts_table']='signin_attempts'`; `modules.json` key `reads: [{file, tables}]` (exempt from L1, checked by L3/L4); L6; per-read-file table counts in the baseline, only down. Keep property names off the PROPS list.
- `modules.json` attendance `logs`: tables + `signin_attempts`; store `WordPressAttemptStore`; service `EvidenceService`; moved: `evidence_keep, evidence_offline_report, face_photo_serve, evidence_cleanup, evidence_timeline, admin_time_report, admin_time_csv, admin_auto_attendance`; reads: `WordPressTimeReportQuery` (time_logs, signin_attempts, employees), `WordPressAutoRuleQuery` (auto_attendance_rules, employees).
- Expected L1: `trait-signin-evidence` 10 → ~7, `trait-time-report` 15 → 1, `trait-auto-attendance` 3 → 1. No other file references `signin_attempts`. `check_modules --update`.

## 7. Quirks (moved as they are; for the fix queue)
Existing: AT-k (multisite), AT-m (alert fires only at exactly N), AT-n (offline time converted with today's offset). New:
- **EV-a (privacy, CONFIRMED)** photo audit bypass: `face_photo_serve` audits only with `full=1` (:281-284) but serves the same full file without it; the thumbnail URL is in the page.
- **EV-b (privacy, CONFIRMED)** turning photos off does not hide them: serve never checks `face_photo_enabled`; the timeline shows the Photo button regardless.
- **EV-c (privacy, CONFIRMED)** `privacy_cleanup_cron` clears `time_logs` location/IP but never the attempts' lat/lng/IP/device (kept up to `attempt_days`, ≤365).
- **EV-d (privacy, PLAUSIBLE)** no attempts table, no photo cleanup (early return at :293).
- **EV-e (privacy, low)** orphan photos (files deleted before keys cleared; refusal with attempts off never takes the held photo; failed INSERT not audited).
- **EV-f (concurrency)** offline report takes no lock: concurrent sends make duplicate rows, double-count toward the alert, can pass the limit of 5.
- **EV-g (low)** real presses dropped as "reached" (±15 min of any attempt of the same type, whatever its result).
- **EV-h (money-adjacent, PLAUSIBLE)** attempt `work_date` is the calendar date of `attempted_at`, not `attendance_day`; an overnight Sign Out after midnight is recorded on the next work day.
- **EV-i (low)** alert says "today" for notes up to 7 days old; with no manager every administrator is alerted; one employees lookup per manager.
- **EV-j (low)** timeline lists superseded rows; neither query has ORDER BY.
- **EV-k (low)** paging ties across two id spaces (`ORDER BY d,t,id`).
- **EV-l (performance)** CSV unbounded; COUNT over the whole UNION per page view; `may_view` one query per call, twice per photo row for managers.
- **EV-m (low)** timeline link on log rows with attempts off is dead.
- **EV-n (privacy)** no personal-data exporter or eraser for attempts or photos.
- **AT-aa** the rules page INNER JOIN hides rules of deleted employees while `dueRules` still picks them (adds to AT-x).

What the photo serve checks today, in order: logged in (`admin_post_ews_face_photo`, else `auth_redirect`); nonce `ews_face_photo_{kind}_{id}`; kind `log|attempt` and the row exists, else 403 (same answer as "not allowed"); `face_photo_may_view` (`manage_options` or `ews_manage_settings` see all; `photo_viewers=hr` refuses everyone else; otherwise `ews_manage_time` AND same department or admin; no department refuses); key matches `^[A-Za-z0-9]{32}\.(jpg|webp)$` and the file is readable, else 404; audit only with `full`; headers no-store, nosniff, inline. Not checked: the feature being on, `ews_manage_time` for the settings path, the employee being active, any rate limit.

## 8. Tests and risks
- Unit: `EvidenceRulesTest`, `EvidenceServiceTest` (keep/offline/photo/cleanup/dayAttempts on memory stores and a fake site), fakes updated for the new `AttendancePorts` argument.
- Contract (`tests/e2e_attendance_store.php`): every new method's SQL equals the old literal, union for each show × search × scope, CSV text, `SHOW TABLES`, unformatted INSERT keeps NULL and int/float text.
- Static: check_layers (L6 self-test), check_modules, phpstan, phpcs, translations, golden. e2e: signin_evidence, time_report, auto_attendance, attendance, attendance_parity, private_files, presence_face, offline_screen, timezone, features, corrections(+mu), payroll, money_safety, speed, query_batching, big_lists, schema, every `*_mu`.
- Risks: option-read moments (`ews_signin_evidence` is not autoloaded; first `settings()` is SQL whose place is pinned); untyped INSERT values (do not cast lat/lng/accuracy); query counts (no "optimising"); output and exits in the harness; photo files not in the snapshot; statics stay in the trait; multisite (`*_mu`); read models must not become a back door for writes (L6). Size about 900 lines of code and 700 of harness and tests.

## 9. Owner decisions
1. **Release split:** one release, 3.31.139.
2. **Read models:** built now inside Attendance (`TimeReportQuery`, `AutoRuleQuery`, `reads` list in `modules.json`); the employees picker moves into `TimeReportQuery`. The alert recipients lookup and `time_report_may_manage` stay as People debt.
3. **Quirks EV-a…EV-m and AT-aa:** moved as they are and queued. Fix order: EV-a, EV-b, EV-c (privacy), EV-h (money), EV-f, then the rest; the direction of each fix is asked when its release is designed.
4. **EV-n** (no exporter / eraser for attempts and photos): deferred to the planned privacy work, not done here.
