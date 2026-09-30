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
