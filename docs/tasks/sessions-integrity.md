# 3.31.118: sessions integrity (F04, F08) — design

PR C of `docs/tasks/review-codex-3.31.115.md`. Fixes to existing behaviour: no new switch, no schema change; one per-site user option
written only when a revoke fails (§2.6). Base: main 0da3df9. Paths under `workforce-one/`.

## 1. Root causes
**F04 (offline attempt note)**
- `templates/pwa/footer.php:117-139`: `ewsPendingAttempt` (event, times, GPS) has no account; `:124-130` sends any note on the next page
  with the config; the block returns when the config is empty (`:119`, signed out: `evidence_app_config()` returns [] at
  `trait-signin-evidence.php:212-215`), so the note survives a logout.
- `trait-signin-evidence.php:179-209` `evidence_offline_report()` files it on `current_employee()` (`:183`), no ownership check.
- `trait-app-layout.php:214-220` `after_logout` sends only `Clear-Site-Data: "cache"`.
- Other phone data: `ewsOfflineSchedule` (has `user`, removed on every signed-out app page), `ewsPushSynced` / `ewsPushOff` (kept at
  logout, 3.31.90 decision), sessionStorage `ewsCameBack`; CacheStorage holds static assets and the offline page only; no IndexedDB.
- The app logout lands on the signed-out page with `loggedout=1` (`:131-133`); the `[employee_login]` "Log out" link (`:177`) does not.

**F08 (`includes/trait-api-auth.php`)**
- `api_revoke_device` `:597-603`: `(int)$wpdb->query(...)` turns false into 0; the token UPDATE `:600` unchecked.
- `api_revoke_user_sessions` `:609-617` sums them, never sees a failure; callers ignore it: logout `:491` (signed_out + audit), device delete
  `:539`, the password/user/site hooks `:629-646`, employee admin `trait-employee-admin.php:123,148`.
- Login `:318-322`: `api_device_create` revokes the replaced installation unchecked (`:556`); `api_issue_pair($device,$now)` with
  `$checked=false` (`:573-583`) hands out unsaved tokens; no transaction (a failure leaves a device row).
- Safe refresh `:454`: the older access-token revoke is ignored (3.31.114, deliberate); legacy `:373` the same.
- Safe reuse `:480-485`: a failed device revoke gives `$ended=0`, no audit, yet REFRESH_REUSED.
- Exposure: both token checks read the device row (access `:199/:201`, refresh `:429/:433`), so the device UPDATE is the real guard. Deleted,
  archived and unlinked users are refused anyway (`:204-208`, `:439-448`). Exposed: a password change, a user removed from a site
  (multisite), logout/device revoke reporting success when nothing changed, login handing out unsaved tokens.

## 2. Changes
- **2.1** `SignInEvidence::noteBelongs($posted, int $current): bool` (pure): `$current>0`, scalar, `(string)$posted===(string)$current`.
- **2.2** `evidence_app_config()` adds `'user'=>get_current_user_id()`.
- **2.3** `evidence_offline_report()`: after the nonce, not `noteBelongs` → `wp_send_json_success(['kept'=>false,'reason'=>'account'])`
  (200: old and new JS clear the note on ok); nothing filed or audited. The real guard (old JS, notes from before the upgrade).
- **2.4** `footer.php`: the note gets `user:C.user`; a note whose `user` differs from `C.user` is cleared, never sent; a note with no `user`
  is sent with `user=''` (the server decides, Q2). Before `if(!C||!C.url)return;`: signed out (`EWS_PUSH_USER` 0) and `loggedout=1` →
  remove `ewsPendingAttempt` (Q1).
- **2.5** The `[employee_login]` "Log out" link gets `loggedout=1`. `Clear-Site-Data: "storage"` rejected: unregisters the service worker
  (push, 3.31.90), drops the offline page and `ewsPushOff`, wipes every script's storage on the origin, unsupported in Safari.
- **2.6** `api_revoke_device($id,$reason,$user_id=0)` → `int|false`: device UPDATE first (false → false), then tokens (false → false; the
  device is already ended, a retry gets 401 DEVICE_REVOKED); 1 ended now, 0 already ended. No transaction (callers may hold one).
- **2.7** `api_revoke_user_sessions($uid,$reason)` → `int|false`: SELECT failed or any revoke false → `api_sessions_pending($uid,$reason)`,
  return false (skipped for `user_deleted`). `api_sessions_pending()`: `update_user_option($uid,'ews_api_sessions_before',['before'=>time(),
  'reason'=>$reason])` (per site), Audit Log `api_sessions_revoke_pending`; if that fails, `error_log`. No immediate retry.
- **2.8** Enforcing it: `Tokens::sessionPredates(int $deviceCreated, int $before): bool` = `$before>0 && $deviceCreated<=$before`.
  `api_resolve_access_token` after `get_userdata`: the option (meta cache already loaded) → predates → `api_revoke_device` best effort,
  DEVICE_REVOKED. `api_auth_refresh_safe`: `d.created_at` in the SELECT, same check before the gate → 401 DEVICE_REVOKED. Legacy refresh
  untouched (Q3). `api_auth_cleanup` (daily): revoke the predating devices and tokens per option row; delete the option on success or
  once `before < now − SESSION_MAX`.
- **2.9** Login: shape, throttle, password, gate as today; START TRANSACTION (false → 500); `api_device_create` with the replaced revoke
  checked and the insert checked (false → ROLLBACK, 500); `api_issue_pair($device,$now,true)` (null → ROLLBACK, 500); COMMIT (false →
  ROLLBACK, 500); audit `api_login`, 200. Inline `Response::internalError` → `api_internal_error()`.
- **2.10** Logout: revoke false → 500, no audit; 0/1 → 200 as today. **2.11** Device delete: the same.
- **2.12** Safe refresh: the access-token revoke moves before the winner UPDATE, checked (false → 500, nothing used); still `id<rt_id`;
  legacy `:373` unchanged.
- **2.13** Safe reuse: `$ended===false` → 500, no audit, no REFRESH_REUSED; legacy unchanged.
- **2.14** Safe `user_deleted` / `account_disabled` revokes (`:442/:447`): checked and logged; the answer stays the refusal.
- **2.15** OpenAPI: `500 INTERNAL_ERROR` on `POST /auth/login`, `POST /auth/logout`, `DELETE /me/devices/{id}`; "a database error answers
  500 and changes nothing; retry" (logout: "a 401 DEVICE_REVOKED on the retry means it is done"); token routes may answer DEVICE_REVOKED
  after a password change before the session list shows it. `tests/check_openapi.py`.

## 3. Order, transactions, concurrency
Revokes write the device row first (the guard). The only new transaction is login's, after `wp_authenticate` (a rehash fires
`wp_set_password`, ignored at `:630`). 3.31.114 refresh behaviour kept (grace counter, `id<rt_id`). Logout vs device delete: the second
sees 0 or 401. InnoDB rolls back, MyISAM does not (existing). The lazy revoke on a GET is a write, like `api_touch_device`.

## 4. Upgrade
No migration; tokens keep working; the option exists only after a future failure. Notes from the old JS (no `user`) are dropped by the
server and cleared by the client (Q2). Downgrade ignores the option. Version 3.31.118.

## 5. Success path / unaffected
Login, logout, refresh, device delete answer as today when the database works; only the safe refresh's access revoke moves before the
rotation. No extra query per request. A note of the same account is filed as before. Attempts feature off: `off` as before.

## 6. Edge cases
Shared phone A→B (logout clears; otherwise B's page drops it and the server would too); A's session expires offline then A signs in (note
kept, filed for A); logout while offline (cannot reach the server, A stays signed in); logout from wp-admin (note stays on the phone,
dropped for anyone else); two tabs; the offline page after a logout; password changed by an admin for another user (option per target);
password change while the devices table is locked (option written, tokens refused, ended at next use or daily); option write fails too
(`error_log`, residual); same-second login after a failed revoke (refused once); user deleted (no option, refused); removed from a site
(option covers a failed revoke; the existing re-sign-in gap out of scope); refresh switch on/off; login after a failed token insert (500,
no device row, old session works).

## 7. Tests
- Unit: `SignInEvidenceTest::testNoteBelongs`; `ApiAuthTest::testSessionPredates`.
- `e2e_signin_evidence.py`: config has `user`; a note of the account kept; another account / no account → `reason:'account'`, no row.
- `tests/offline_browser.js`: logout clears the note; another user's note cleared, not sent; a saved note has `user`.
- `e2e_api_auth.py` with `break_writes()`: devices → logout 500, /me 200, then 200; tokens → logout 500 then /me 401, login 500 with no
  device row and the earlier session still 200; device delete 500; password change (`wp_set_password`, admin `wp_update_user`) under
  broken devices → old token 401, option + audit row, then device `password_changed`, a new login works, cleanup removes the option.
- `e2e_refresh_mu.php` modes `access`, `device_revoke`; `e2e_api_refresh_race.py` (switch on): access → 500 then retry 200; reuse with
  device_revoke → 500 then REFRESH_REUSED once; pending option → DEVICE_REVOKED; bursts unchanged.
- Also: `e2e_api_foundation.py`, `check_openapi.py`, phpstan, phpcs, modules, translations, `e2e_pwa.py`, `e2e_offline_screen.py`.

## 8. Risks
Notes from before the upgrade lost (evidence only); a logout outside the app leaves the note readable on the phone; same-second login
refused once; option write failing too; logout 500 new for clients (Q5); login transaction new (MyISAM); lazy writes on GET; multisite
password change ends only this site's sessions (existing).

## 9. Owner questions (recommended first)
1. Clear the note only after an explicit logout (`loggedout=1`) / on any signed-out page.
2. Notes with no account (old JS): drop / accept for 7 days.
3. Switch-off refresh: leave untouched (3.31.114) / apply the checks there too.
4. A failed revoke on a password change: per-site "sessions before" option + check on every use + daily retry / log + cron only / a column.
5. App rule when logout answers 500: retry up to 3 times, then wipe the tokens on the phone and say so.
6. Tell the admin about a pending revoke: Audit Log row only / a wp-admin notice.

## Owner's answers (2026-10-21)
All six as recommended: Q1 clear the note only after an explicit logout; Q2 drop notes with no account; Q3 leave the switch-off refresh
untouched; Q4 per-site "sessions before" option + check on every token use + daily retry; Q5 the app retries logout up to 3 times, then
wipes its tokens and says so (documented in the OpenAPI); Q6 an Audit Log row only.
