/* Office minimum on the Attendance page (3.31.77, templates/app/attendance.php): while days are
   changed in the grid, each day's "in the office / minimum" counter follows the unsaved changes.
   The server's count covers the whole company (data-om-base); this adds what the changed cells on
   this page add or remove. The warning card and the teams sheets show the saved schedule; a day's
   Managers sheet (3.31.155) says so while a manager's cell that day has an unsaved change. */
(function () {
    'use strict';

    function init() {
        var table = document.querySelector('table[data-om-statuses]');
        if (!table) return;
        var statuses = JSON.parse(table.getAttribute('data-om-statuses') || '[]');
        var dates = JSON.parse(table.getAttribute('data-om-dates') || '[]');
        var cells = Array.prototype.slice.call(table.querySelectorAll('td[data-day]'));
        cells.forEach(function (td) { td.setAttribute('data-om-orig', td.getAttribute('data-planned') || ''); });
        function counts(v) { return statuses.indexOf(v) !== -1 ? 1 : 0; }
        // Manager office minimum (3.31.153): the team managers' ids, only with it on; their cells also move the managers' counter.
        var managers = JSON.parse(table.getAttribute('data-om-managers') || 'null');
        // By department (3.31.154): only that department's people move its counters (an administrator's grid shows everyone).
        var only = JSON.parse(table.getAttribute('data-om-people') || 'null');
        var inScope = {};
        (only || []).forEach(function (id) { inScope[String(id)] = true; });
        var isManager = {};
        (managers || []).forEach(function (id) { isManager[String(id)] = true; });

        function update() {
            var delta = {}, mdelta = {}, mchanged = {};
            cells.forEach(function (td) {
                var d = dates[parseInt(td.getAttribute('data-day'), 10)];
                if (!d) return;
                if (only && !inScope[td.getAttribute('data-employee')]) return;
                var change = counts(td.getAttribute('data-planned') || '') - counts(td.getAttribute('data-om-orig'));
                delta[d] = (delta[d] || 0) + change;
                if (managers && isManager[td.getAttribute('data-employee')]) {
                    mdelta[d] = (mdelta[d] || 0) + change;
                    if ((td.getAttribute('data-planned') || '') !== td.getAttribute('data-om-orig')) mchanged[d] = true;
                }
            });
            if (managers) document.querySelectorAll('[data-mom-base]').forEach(function (el) {
                var d = el.getAttribute('data-mom-day');
                var n = parseInt(el.getAttribute('data-mom-base'), 10) + (mdelta[d] || 0);
                var min = parseInt(el.getAttribute('data-mom-min'), 10);
                var b = el.querySelector('b');
                if (b) b.textContent = n;
                el.classList.toggle('is-short', n < min);
                el.classList.toggle('is-ok', n >= min);
                el.classList.toggle('is-changed', !!mdelta[d]);
                var note = document.querySelector('#wfo-mom-' + d + ' [data-mom-unsaved]');
                if (note) note.hidden = !mchanged[d];
            });
            document.querySelectorAll('[data-om-base]').forEach(function (el) {
                var d = el.getAttribute('data-om-day');
                var n = parseInt(el.getAttribute('data-om-base'), 10) + (delta[d] || 0);
                var min = parseInt(el.getAttribute('data-om-min'), 10);
                var b = el.querySelector('b');
                if (b) b.textContent = n;
                el.classList.toggle('is-short', n < min);
                el.classList.toggle('is-ok', n >= min);
                el.classList.toggle('is-changed', !!delta[d]);
            });
        }

        document.querySelectorAll('[data-omd-pick]').forEach(function (sel) { sel.addEventListener('change', function () { sel.form.submit(); }); });
        new MutationObserver(update).observe(table, { subtree: true, attributes: true, attributeFilter: ['data-planned'] });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
