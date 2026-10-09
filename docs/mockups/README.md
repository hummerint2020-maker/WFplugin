# Mockups

Static HTML mockups (Step 1 of the task briefs in `docs/tasks/`), reviewed by the owner before any
code is written. Open `index.html` in a browser from a checkout: the screens load the plugin's real
stylesheets, fonts and icons from `workforce-one/assets/`.

- `attendance-corrections/`: `docs/tasks/attendance-corrections.md`
- `daily-workers/`: `docs/tasks/daily-workers.md`
- `office-minimum/`: `docs/tasks/office-minimum.md` (its Attendance screens start from snapshots of the real page in `_kit/snap/`)

Every screen is written twice, `<screen>.ar.html` (RTL) and `<screen>.en.html`, by
`_kit/build.py`. Edit the screens in `_kit/screens_*.py` and rebuild; don't edit the HTML by hand:

    python3 docs/mockups/_kit/build.py
    npx -y rtlcss@4 docs/mockups/daily-workers/daily-workers.css docs/mockups/daily-workers/daily-workers-rtl.css
    npx -y rtlcss@4 docs/mockups/attendance-corrections/corrections.css docs/mockups/attendance-corrections/corrections-rtl.css
    npx -y rtlcss@4 docs/mockups/office-minimum/office-minimum.css docs/mockups/office-minimum/office-minimum-rtl.css

Each feature's `.css` is the stylesheet Step 2 would ship for the new components. `_kit/mock.css`
holds mockup-only pieces (a stand-in WordPress toolbar, the phone lock screen for push).
`_kit/wp-admin-*.css` is WordPress's own admin CSS (GPL), copied so wp-admin screens look real.
