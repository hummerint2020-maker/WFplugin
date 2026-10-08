/* Employee app → Tasks (templates/app/tasks.php): search, the priority filter, asking before a task is
   deleted, and closing the Edit sheet (the page address forgets the task being edited). */
(function () {
    'use strict';

    function init() {
        var page = document.querySelector('.wfo-tk');
        if (!page) return;

        var search = document.getElementById('wfo-tk-search');
        var none = page.querySelector('.wfo-tk-noresult');
        if (search) search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase(), shown = 0;
            page.querySelectorAll('.wfo-tk-card').forEach(function (c) {
                var hit = !q || (c.getAttribute('data-ews-task') || '').indexOf(q) !== -1;
                c.hidden = !hit;
                if (hit) shown++;
            });
            if (none) none.hidden = shown > 0 || !q;
        });

        page.querySelectorAll('[data-ews-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () { if (el.form) el.form.submit(); });
        });

        page.querySelectorAll('form[data-ews-task-delete]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!window.confirm(form.getAttribute('data-ews-task-delete'))) e.preventDefault();
            });
        });

        // One menu open at a time; a tap elsewhere closes it.
        var menus = Array.prototype.slice.call(page.querySelectorAll('.wfo-tk-more'));
        menus.forEach(function (d) {
            d.addEventListener('toggle', function () { if (d.open) menus.forEach(function (o) { if (o !== d) o.open = false; }); });
        });
        document.addEventListener('click', function (e) {
            menus.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
        });

        var edit = document.getElementById('wfo-tk-edit');
        if (edit && window.history && window.URL) edit.addEventListener('close', function () {
            var url = new URL(window.location.href);
            url.searchParams.delete('edit_task');
            window.history.replaceState(null, '', url.toString());
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
