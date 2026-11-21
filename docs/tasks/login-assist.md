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

## Also noticed
The username and password fields print `autocomplete` twice (WordPress now adds it; our str_replace adds it again).
Harmless; dropped with the helpers.
