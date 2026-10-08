/* Employee app: forms in a sheet (Overtime, swap requests, Tasks). A button with
   data-wfo-sheet="<dialog id>" opens that <dialog class="wfo-sheet">: from the bottom on a phone,
   in the middle on a computer (styles: assets/css/app-requests.css). Esc, the close button
   (data-wfo-sheet-close) and a tap outside close it. A sheet with data-wfo-sheet-start opens by
   itself when the page loads (used to show a form again). */
(function () {
    'use strict';

    function open(dialog, opener) {
        if (!dialog || dialog.open) return;
        dialog._wfoOpener = opener || null;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
        var first = dialog.querySelector('[autofocus], input:not([type=hidden]), select, textarea');
        if (first && window.matchMedia && !window.matchMedia('(pointer: coarse)').matches) first.focus();
        dialog.dispatchEvent(new CustomEvent('wfo-sheet-open'));
    }

    function close(dialog) {
        if (!dialog || !dialog.open) return;
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }

    function init() {
        document.addEventListener('click', function (e) {
            var opener = e.target.closest('[data-wfo-sheet]');
            if (opener) {
                e.preventDefault();
                open(document.getElementById(opener.getAttribute('data-wfo-sheet')), opener);
                return;
            }
            var closer = e.target.closest('[data-wfo-sheet-close]');
            if (closer) { e.preventDefault(); close(closer.closest('dialog')); return; }
            // A tap on the dimmed area around the sheet (the dialog box itself, not its content).
            if (e.target.matches && e.target.matches('dialog.wfo-sheet')) {
                var r = e.target.getBoundingClientRect();
                if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close(e.target);
            }
        });
        document.querySelectorAll('dialog.wfo-sheet').forEach(function (dialog) {
            dialog.addEventListener('close', function () {
                if (dialog._wfoOpener && document.contains(dialog._wfoOpener)) dialog._wfoOpener.focus({ preventScroll: true });
            });
            if (dialog.hasAttribute('data-wfo-sheet-start')) open(dialog);
        });
    }

    window.wfoSheet = { open: open, close: close };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
