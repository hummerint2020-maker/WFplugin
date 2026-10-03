/* Employee app → Report Center (templates/app/reports.php): sort a table by clicking a column title. */
(function () {
    'use strict';
    function init() {
        document.querySelectorAll('table[data-ews-sortable]').forEach(function (table) {
            var heads = table.querySelectorAll('thead th[data-sort]');
            heads.forEach(function (th, index) {
                th.tabIndex = 0;
                th.setAttribute('role', 'button');
                function sort() {
                    var numeric = th.getAttribute('data-sort') === 'num';
                    var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
                    heads.forEach(function (h) { h.removeAttribute('aria-sort'); });
                    th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
                    var body = table.tBodies[0];
                    var rows = Array.prototype.filter.call(body.rows, function (r) { return r.cells.length > index && r.cells[index].hasAttribute('data-value'); });
                    rows.sort(function (a, b) {
                        var x = a.cells[index].getAttribute('data-value'), y = b.cells[index].getAttribute('data-value');
                        if (numeric) return (parseFloat(x || '0') - parseFloat(y || '0')) * dir;
                        return x.localeCompare(y) * dir;
                    });
                    rows.forEach(function (r) { body.appendChild(r); });
                }
                th.addEventListener('click', sort);
                th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
