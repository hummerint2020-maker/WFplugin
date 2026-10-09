/* Office minimum on the Attendance page (3.31.77, templates/app/attendance.php): while days are
   changed in the grid, each day's "in the office / minimum" counter follows the unsaved changes.
   The server's count covers the whole company (data-om-base); this adds what the changed cells on
   this page add or remove. The warning card and the teams sheets show the saved schedule. */
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

        function update() {
            var delta = {};
            cells.forEach(function (td) {
                var d = dates[parseInt(td.getAttribute('data-day'), 10)];
                if (!d) return;
                delta[d] = (delta[d] || 0) + counts(td.getAttribute('data-planned') || '') - counts(td.getAttribute('data-om-orig'));
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

        new MutationObserver(update).observe(table, { subtree: true, attributes: true, attributeFilter: ['data-planned'] });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
