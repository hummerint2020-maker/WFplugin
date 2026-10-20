# 3.31.114: refresh-token race (security release, design)

Base: `claude/wordpress-plugin-improvement-jb0znc` (3.31.112). The mobile PR (`claude/mobile-api-1`, 3.31.113) edits `trait-api-auth.php`
only around 248-275 and 402-450; this release touches 330-395 and 477-506. Expected conflicts: version header, CHANGELOG, ci.yml
(the new CI step goes after "Daily workers", not after api_auth, to avoid the last one).

## 1. Root cause (traced, `workforce-one/includes/trait-api-auth.php`)
- `api_auth_refresh` (332-351): a plain SELECT reads the token, `Tokens::refreshStatus` routes it. With n concurrent requests all see
  `used_at IS NULL`; the conditional `UPDATE … SET used_at WHERE used_at IS NULL AND revoked_at IS NULL` (348) gives 1 to one winner and 0
  to the rest, who go to `api_refresh_grace` (349); later arrivals see USED and go there too (341).
- `api_refresh_grace` (373-387): `UPDATE … SET revoked_at WHERE id AND revoked_at IS NULL` (377) is a one-use flag. One grace caller gets 1,
  every other falls through to `api_refresh_reused` (390-394): the whole device is revoked and `api_refresh_reuse` is audited.
  n=2 fine, n=3 kills the device.
- A database error is treated as theft: `false!==1` (349) goes to grace, `false===1` fails at 377 → reuse → device revoked. Happens on a
  SQLite busy timeout, a MySQL deadlock or lock-wait timeout.
- The access-token revoke (350) runs after the winner's UPDATE; a grace pair inserted in between gets its access token killed (200 with a
  dead token).
- Also: (a) logout / password change / account disabled during the grace is reported as REFRESH_REUSED (false audit row); (b) a deleted
  user in the grace path falls to reuse; (c) `api_issue_pair` (481-485) does not check its inserts; (d) the daily `api_refresh` audit
  (361-364) is read-then-write and can be written twice.
- Isolation: no transaction, autocommit. A conditional UPDATE is atomic on InnoDB (locking current read) and on SQLite (writer lock);
  the SELECT must only route, every decision must sit in an UPDATE's WHERE.

## 2. Design
### Data model and upgrade
- `ews_api_tokens.grace_uses TINYINT UNSIGNED NOT NULL DEFAULT 0` after `used_at` (`trait-schema.php` CREATE TABLE); dbDelta adds it,
  existing rows get 0. `ews_schema_target()` → `3.31.114`; `Support/SchemaCheck::COLUMNS` gets `'ews_api_tokens' => ['grace_uses']`
  (upgrade is done only once the column exists; otherwise the existing retry and admin notice).
- Column missing (failed upgrade): the grace UPDATE fails → 500, never a revoke; normal rotation unaffected.
- Old-style grace rows (3.31.111/112, `revoked_at` set as the flag): reuse, as before; only matters within 30 s of the upgrade.
- Downgrade: old code ignores the column; no data migration.

### `src/Api/Auth/Tokens.php`
`REFRESH_GRACE_USES = 4` next to `REFRESH_GRACE = 30`; pure `graceCutoff(int $now): int` (= now - 30; 30 s accepted, 31 s refused).
`refreshStatus` unchanged.

### `api_auth_refresh`, new order
1. Shape, SELECT, status as today; DEVICE_REVOKED → 401 DEVICE_REVOKED; other than OK/USED → REFRESH_INVALID.
2. User and account gate for both OK and USED: no user → revoke `user_deleted`, REFRESH_INVALID; gate fails → revoke
   `account_disabled`, the gate's code.
3. OK → winner: `UPDATE SET used_at=:now WHERE id AND used_at IS NULL AND revoked_at IS NULL`.
   false → 500 INTERNAL_ERROR, no revoke. 1 → revoke older access tokens
   `WHERE device_id AND kind='access' AND revoked_at IS NULL AND id < :rt_id` (failure ignored: they expire in 15 min), then issue.
   0 → step 4.
4. Grace (`api_refresh_grace_take`, replaces the body of `api_refresh_grace`), one conditional UPDATE:
   `UPDATE SET grace_uses=grace_uses+1 WHERE id AND kind='refresh' AND used_at IS NOT NULL AND used_at >= :cutoff AND grace_uses < :max AND revoked_at IS NULL`.
   false → 500, no revoke, no audit. 1 → issue a pair (no access-token revoke). 0 → re-read: device revoked meanwhile → 401 DEVICE_REVOKED,
   no reuse audit; otherwise (older than 30 s, uses spent, old-style flag) → `api_refresh_reused` as today; the re-read fails → 500.
- Two single-statement conditional UPDATEs, not one merged statement (SET evaluation order differs between MySQL and SQLite, and the
  affected-row count must tell the winner from a grace use). At most 1 + 4 pairs per token whatever the interleaving.
- Clock: `:now` and `:cutoff` from PHP `time()` (the clock that wrote `used_at`), never the database's NOW().
- `id < rt_id`: pairs are inserted access first, so the old pair's access token is below `rt_id`; every pair issued afterwards is above it
  (MySQL auto-increment and SQLite). `created_at` has 1 s resolution and cannot be used.
- Each grace pair's access token lives its normal 15 minutes; its refresh token is an ordinary chain with its own reuse detection. A device
  may hold up to 5 live chains after one burst; all end at logout, revocation, a password change or the 180-day session limit.

### Small hardening in the same functions
- `api_issue_pair`: check both inserts; false → null → callers return 500 (a retry within 30 s is a grace use).
- `api_refresh_issue`: the daily audit becomes a conditional UPDATE of `refresh_audited_at`; the row is written only when 1 row changed.
- Docblocks (369-372) and the cleanup comment (587) updated.

### Logs and audit
Unchanged: `api_refresh` (daily, now race-safe), `api_refresh_reuse` (theft), `api_sessions_revoked`. Grace uses: no audit row (question 4);
the count stays on the token row. A database error writes no audit row.

### Theft detection and the trade-off
Outside the window nothing changes: a used token after 30 s, or a 6th use within 30 s, ends the device and is audited. Inside the window a
thief replaying an intercepted used token gets up to 4 pairs instead of 1; one chain is already a full compromise, and the realistic theft
(copying the current unused token) is not affected by the grace.

## 3. Switch
None recommended: a security fix to behaviour shipped in 3.31.111, not a new feature; off by default would keep the known bug on every site.
Owner decides (question 3).

## 4. Contract
No change for well-behaved clients. Visible: uses 3-5 within 30 s return 200 instead of 401 REFRESH_REUSED; a database error returns 500
INTERNAL_ERROR (existing code) instead of revoking; a use during logout / password change / revocation returns 401 DEVICE_REVOKED instead
of REFRESH_REUSED (both "wipe and log in").
OpenAPI lives on the mobile branch only (`docs/api/openapi.yaml`, `/auth/refresh`): drop the "third use ends the session" sentence, say
"within 30 s of its first use a refresh token is accepted up to 4 more times; a later or further use ends the device session", add a 500
response, run `tests/check_openapi.py`. Client rules: keep the six written there and add (7): on 500 or a network failure retry the same
refresh token with backoff, at most 3 times within 30 s; never fall back to an older refresh token.

## 5. Edge cases
| Case | Outcome |
|---|---|
| Two devices | Per token and per device; no interaction. |
| Logout / revoke during the grace | Grace UPDATE fails, re-read sees the device revoked → 401 DEVICE_REVOKED, no reuse audit; a pair that slipped in is dead at use. |
| Password change during the grace | Same as logout. |
| Account disabled during the grace | Gate → revoke `account_disabled` → 403 ACCOUNT_DISABLED. |
| User deleted | REFRESH_INVALID, revoke `user_deleted`, no reuse audit. |
| Clock skew | Client clocks play no part; skew between several web servers moves the window (NTP assumed). |
| 6th concurrent use | Exactly 5 × 200 and 1 × 401 REFRESH_REUSED, device revoked (the counter decides, not timing). |
| Sequential retries | Uses 2-5 within 30 s accepted, the 6th reused; a failed grace UPDATE does not consume a use. |
| Used 31 s ago | Reused; 30 s accepted. |
| Refresh after a grace pair's access token expired | Its own chain, normal rotation. |
| Login replacing a device by installation_id | Unchanged. |
| SQLite vs MySQL | Both serialise the conditional UPDATE; a timeout → 500, never a revoke. |

## 6. Tests
- Unit (`ApiAuthTest`): constants, `graceCutoff`, `refreshStatus` unchanged.
- e2e, existing (`e2e_api_auth.py` 172-179): third use → now 200; uses 2-5 → 200, same device, no reuse row; 6th → REFRESH_REUSED, device
  revoked `refresh_reuse`, one audit row; the previous access token is still revoked (via `id < rt_id`).
- e2e, new `tests/e2e_api_refresh_race.py`:
  - serial: uses 2-5 accepted, 6th reused; `used_at` now-31 → reused, now-30 → accepted; logout / password change between uses →
    DEVICE_REVOKED, no reuse row; account disabled → ACCOUNT_DISABLED; an access token with `id > rt_id` survives a rotation, the older is revoked.
  - database error: a test-only mu-plugin (the `e2e_health_mu.php` pattern) breaks the winner or the grace UPDATE on a header → 500, the device
    stays, no reuse row, `grace_uses` unchanged; the next retry → 200.
  - concurrent: a second server only for this step (`PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8081`), threads with a barrier, one fresh login
    per scenario: n = 2, 3, 5 all 200 and every new access token works, a follow-up refresh works, the device stays, no reuse row; n = 6 exactly
    five 200 and one REFRESH_REUSED, device revoked, one audit row; a concurrent first refresh of the day writes one `api_refresh` row.
    The outcomes depend on the counter, not timing, and fail on the old code.
- Also: phpstan, phpcs, modules, translations, `e2e_schema.py`, `e2e_api_foundation.py`, `e2e_api_auth.py`.

## 7. Risks
Up to 4 silent extra pairs for a token replayed within 30 s; up to 5 live chains per device after a burst. A failed schema upgrade makes the
grace and reuse paths return 500 (admin notice shows it). The gate moving before the USED branch changes the audit trail for a disabled
account's reused token. Server clock skew. The multi-worker CI server is new (separate port, one step). Merge order with 3.31.113.

## 8. Open questions for the owner (recommended default first)
1. Extra uses within the window: **4** (5 pairs). Alternatives 2 or 9.
2. Window: **keep 30 s**.
3. A switch: **none**, always on. Alternative: "strict mode = old behaviour", off by default.
4. Audit grace uses: **no per-use row**. Optional: one `api_refresh_grace` row when a token reaches its 4th grace use.
5. OpenAPI: **merge the mobile PR (3.31.113) first, then build 3.31.114 on it and update `openapi.yaml` in the same PR**.
6. Gate order: **check account and user before the grace too**.
7. Sibling chains: **leave them** (they end at logout, revocation or session end).
8. Version: **3.31.114** (schema target 3.31.114).

## Owner's answers (2026-10-20)
1. Extra uses within the window: 4 (as recommended).
2. Window: not asked, 30 s kept.
3. **A switch, off by default** (not the recommendation). Off = the 3.31.111 refresh path exactly as today; on = this design (the grace
   counter, 500 on a database error, DEVICE_REVOKED during a logout, the gate before the grace, the access-token revoke by id, the checked
   inserts, the race-safe daily audit). The `grace_uses` column is added either way (unused while off). Name: "Safer app sign-in refresh",
   in wp-admin → Features next to "Mobile app API" (a single checkbox in the existing section, no new layout).
4. Audit grace uses: no row; the count stays on the token.
5. Order: the mobile PR (3.31.113) first; this release is built on `claude/mobile-api-1` and updates `docs/api/openapi.yaml` in the same PR.
6-8. Not asked, defaults kept: the gate before the grace too, sibling chains left, version 3.31.114.
