# Login page: empty form fix and sign-in helpers

## Owner request (2026-11-21)
"In the login page, when I press Sign In without entering username and password it redirects me to the WordPress login
page, not the app login page." Then: "Before you fix it, see what else we can improve."

## 1. The fix (always on: a bug)
WordPress does not fire `wp_login_failed` for `empty_username` / `empty_password`, so `app_login_failed()` never ran and
wp-login.php showed its own form. `app_login_empty()` on `authenticate` (last priority) sends the app's form (marked
`ews_app_login`) back with `login_error=empty` and "Please enter your username and password." wp-login.php's own form is
unchanged. Tests: tests/e2e_layout.py.

## 2. Sign-in helpers (owner chose all four; one switch, off by default)
wp-admin → Appearance → Login screen → "Sign-in helpers" (`login_assist`, default '0'). Off: the page is exactly as today.
- **Check before sending:** an empty field gets a red border, a line under it and the cursor; no page reload.
- **Keep the username after a wrong password:** in sessionStorage of this tab, removed once a sign-in works or the tab
  closes. The password is never kept.
- **"Signing in..." button:** after the first tap, so a second tap on a slow connection does nothing.
- **Caps Lock line** under the password while it is on.
Mockups: docs/mockups/login-assist/ (Step 1, waiting for the owner).

## 3. Later, its own release (owner: "later, on its own")
"Forgot password?" opens WordPress's own pages (lost password, new password, then wp-login.php). App-styled pages need
their own mockups and design.

## Also noticed (not changed)
The username and password fields print `autocomplete` twice (WordPress now adds it; our str_replace adds it again).
Harmless, and removing it would change the page while the switch is off; left as is.

## As built (3.31.157)
- `Appearance::DEFAULTS['login_assist']='0'`; only '1' turns it on. Saved by the Login screen box (trait-appearance.php).
- templates/app/login.php prints `data-assist` and the four labels on the card only while it is on (and not signed in);
  `data-failed` says the page came back from a failed sign-in.
- assets/js/login.js: the helpers run after the eye button (wrapping the password field moves it, which drops the cursor).
  The username is kept in sessionStorage (`ewsLoginUser`) on sending, brought back only when `data-failed=1`, removed on any
  other load of the login. The busy button is a separate disabled button; the real submit is hidden, and the page cache
  ("back") restores it.
- Differs from the mockup: the field and Caps Lock lines carry no icon (text only).
- Tests: tests/login_assist_browser.js (18, in CI), tests/e2e_layout.py (the fix, 5), tests/e2e_appearance.py (2),
  tests/unit/AppearanceTest.php. With the switch off the app's login page is byte-identical to 3.31.156 (checked locally).
