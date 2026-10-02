/* Employee app manager Attendance grid (templates/app/attendance.php). */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('ews-grid-form');
        if (!form) return;

        // Week picker: open the chosen week straight away.
        document.querySelectorAll('[data-ews-autosubmit]').forEach(function (input) {
            input.addEventListener('change', function () { if (input.form) input.form.submit(); });
        });

        // "Set…" above a day column fills that day for every employee (desktop and mobile copies).
        document.querySelectorAll('[data-ews-fill-day]').forEach(function (select) {
            select.addEventListener('change', function () {
                var day = select.getAttribute('data-ews-fill-day');
                if (select.value) {
                    form.querySelectorAll('.ews-att-cell[data-day="' + day + '"],.ews-mobile-att-cell[data-day="' + day + '"]').forEach(function (cell) {
                        cell.value = select.value;
                        cell.dispatchEvent(new Event('change'));
                    });
                }
                select.value = '';
            });
        });

        // Search and team filter apply to the desktop rows and the mobile cards alike.
        var search = document.getElementById('ews-att-search');
        var teamFilter = document.getElementById('ews-att-team');
        function filterAttendance() {
            var q = (search ? search.value : '').trim().toLowerCase();
            var team = teamFilter ? teamFilter.value.trim().toLowerCase() : 'all';
            document.querySelectorAll('.ews-att-employee-row,.ews-att-mobile-card').forEach(function (row) {
                var name = row.getAttribute('data-employee-name') || '';
                var teams = (row.getAttribute('data-team') || '').split('|');
                row.style.display = (!q || name.indexOf(q) !== -1) && (team === 'all' || teams.indexOf(team) !== -1) ? '' : 'none';
            });
        }
        if (search) search.addEventListener('input', filterAttendance);
        if (teamFilter) teamFilter.addEventListener('change', filterAttendance);

        // Colour a desktop cell by its planned type as soon as it changes.
        function planClass(value) { return 'ews-plan-' + (value || 'not-set').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
        form.querySelectorAll('.ews-att-cell').forEach(function (select) {
            select.addEventListener('change', function () {
                var cell = select.closest('.ews-att-day');
                if (!cell) return;
                var planned = select.value || 'Not Set';
                Array.prototype.slice.call(cell.classList).forEach(function (c) { if (c.indexOf('ews-plan-') === 0) cell.classList.remove(c); });
                cell.classList.add(planClass(planned));
                cell.setAttribute('data-planned', planned);
            });
        });

        /*
         * Submit only the cells changed in this browser, each with the value it had when the page
         * loaded. The server applies a change only if the cell still holds that value, so a stale
         * page cannot overwrite another manager's newer work (it is reported as a conflict).
         */
        form.querySelectorAll('.ews-att-cell,.ews-mobile-att-cell').forEach(function (select) {
            select.dataset.ewsOriginal = select.value || '';
        });
        form.addEventListener('submit', function () {
            var mobile = window.matchMedia && window.matchMedia('(max-width: 700px)').matches;
            var marker = form.querySelector('input[name="attendance_client"]');
            if (marker) marker.value = mobile ? 'mobile' : 'desktop';
            if (mobile) {
                form.querySelectorAll('.ews-mobile-att-cell').forEach(function (mob) {
                    var desk = form.querySelector('.ews-att-cell[data-employee="' + mob.getAttribute('data-employee') + '"][data-day="' + mob.getAttribute('data-day') + '"]');
                    if (desk) desk.value = mob.value;
                });
            }
            var changes = [];
            form.querySelectorAll('.ews-att-cell').forEach(function (select) {
                var current = select.value || '';
                var original = select.dataset.ewsOriginal || '';
                if (current === original) return;
                changes.push({
                    employee: parseInt(select.getAttribute('data-employee') || '0', 10),
                    day: parseInt(select.getAttribute('data-day') || '-1', 10),
                    status: current,
                    original: original === '' ? null : original
                });
            });
            var payload = form.querySelector('#ews-att-changes-json');
            if (payload) {
                payload.value = JSON.stringify(changes);
                // The server reads att_changes_json; keep the full grid (the no-JavaScript fallback) out of the POST.
                form.querySelectorAll('.ews-att-cell,.ews-mobile-att-cell').forEach(function (select) { select.disabled = true; });
            }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
