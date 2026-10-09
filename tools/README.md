# i18n helpers

Wrap hard-coded UI text in gettext calls, one function (or region) at a time.
Both print every string they would wrap; review the list, then re-run with `--apply`.

```bash
# Templates (HTML outside <?php ?>):
python3 tools/i18n_wrap.py workforce-one/includes/trait-frontend.php \
  "    private function dashboard_content(){" "    private function week_dates(){"

# HTML built in PHP strings ($html.='<h3>…</h3>'):
php tools/i18n_wrap_php.php workforce-one/includes/trait-frontend.php \
  "    private function vacation_content(){" "    private function people_content(){"
```

Sentences that contain values ("Maximum %s hours") must be converted by hand to
`sprintf(__('… %s …'))` first, otherwise they are split into untranslatable fragments.
Values that are stored or compared in code (statuses such as "Approved") are never
wrapped; translate them at display time (`status_label()`).

# Sign In load test

`tools/load_signin.py` measures the start of a shift: many employees pressing Sign In in the same
second, through the real Web form. Run it against a **staging copy**, never a live site (seed turns
face and QR sign-in off and opens the sign-in window until cleanup).

```bash
# on the server
WP_CLI="wp --path=/var/www/html" python3 tools/load_signin.py seed --count 1600
# from another machine
python3 tools/load_signin.py run --url https://staging.example.com --levels 25,50,100,200,400,800
# back on the server
WP_CLI="wp --path=/var/www/html" python3 tools/load_signin.py count     # one sign-in per employee?
WP_CLI="wp --path=/var/www/html" python3 tools/load_signin.py cleanup
```

`--ramp 300` spreads each burst over 5 minutes instead of one moment; `--json` saves the results.

# New company on the VPS

`tools/new_company.sh` sets up one company on a CloudPanel VPS: its own site at
`https://<slug>.<base domain>` (own Linux user, database and PHP-FPM pool), WordPress, Workforce One,
the app page, Cairo time, a real cron job instead of WP-Cron and a Let's Encrypt certificate. The
logins are saved in `/root/wfo-companies/<slug>.txt` (root only).

```bash
cd /path/to/repo && zip -r /root/workforce-one.zip workforce-one
sudo bash tools/new_company.sh --slug acme --name "Acme Trading" \
     --base workforceone.example --email it@acme.com --plugin /root/workforce-one.zip
```

Needs CloudPanel installed and a DNS record `*.<base domain>` pointing to the VPS.
