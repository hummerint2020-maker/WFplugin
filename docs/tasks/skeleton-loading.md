# Skeleton loading (3.31.168)

Owner's request (2026-11-27): "skeleton loading". Mockup and first design: `/mnt/project-files/skeleton-loading/`
(`design.md`, `mockup.html`). The owner said "start" without answering the open questions, so the recommended
defaults below were taken; each can be changed later without a schema change.

## What was found

The employee app is not loaded with AJAX: each view (My requests, My approvals, Schedule, …) is a whole PHP page
(`templates/app/*.php` inside `templates/app/layout.php`), and a menu tap is a page load. The empty moment is between
the tap and the next page: the old page stays still. The one list loaded in place is the Attendance cards sheet
(`assets/js/attendance-cards.js`, `admin-post.php?action=ews_att_cards`), which showed only "Loading…".

## What it does

- **Menu taps** (side menu, bottom bar, the clock button, More): with the switch on, each link carries
  `data-wfo-skel="list|approvals|table|generic"` (`src/Ui/Skeleton.php`, pure). `assets/js/skeleton.js` marks the
  tapped item active at once and, when the next page takes longer than 150 ms, hides `.wfo-content`'s children and
  draws the outline of that view. Nothing is removed: Back from the page cache (`pageshow` persisted) restores the page
  and the active item; a page that never comes restores after 15 s. Ctrl/Cmd/Shift/Alt-click, middle click, another
  origin, `target`, `download` and same-page `#` links are left alone.
- **Attendance cards sheet**: five grey rows instead of "Loading…" (only when the skeleton script is on the page);
  errors and "Try again" unchanged.
- **Style** `assets/css/app-skeleton.css` (+ `-rtl` by rtlcss): the app's own tokens (`--wfo-line`, `--wfo-surface`,
  `--wfo-r-card`); a light shimmer that runs right to left in Arabic and stops with `prefers-reduced-motion`.
- **Accessibility**: the outline is `aria-hidden`; the content gets `aria-busy="true"` and a `role="status"`
  "Loading…" (the existing translated string, in `data-wfo-skel-label`).

## Switch

`ews_feature_skeleton`, Features → "Skeleton loading", **off by default**; turning it on or off is written to the
Audit Log (`skeleton_feature_update`). Off: no attribute, script or style is printed; the pages are byte-for-byte as
before (golden: only the Features page differs, for the new switch).

## Layers

UI only: no table, no SQL, no Store or Service. The rule (which outline a view gets) is a pure class; the controller
part is `skeleton_enabled()` in the shell module (`trait-app-layout.php`) and the enqueue in `trait-frontend.php`.

## Defaults taken for the open questions

1. Scope: own outlines for My requests and the other request lists (`list`), My approvals (`approvals`), Schedule and
   Attendance (`table`); every other view gets `generic`. The Attendance sheet gets rows.
2. Shimmer on (stops with reduced motion).
3. Form buttons keep their existing spinner.
4. wp-admin is out of scope.

## Tests

`tests/unit/SkeletonTest.php` (the rule; every outline exists in the script), `tests/skeleton_browser.js` (off: nothing
printed; on: outline on a slow page only, active item, aria, fast page, phone in Arabic, reduced motion; no JS errors).
