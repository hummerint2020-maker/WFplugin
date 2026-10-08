/* Employee app manager Attendance grid (templates/app/attendance.php).
   Every day is a button (td.ews-att-day > .wfo-attg-chip); one picker (#wfo-attg-picker) serves them
   all: next to the day on a computer, a sheet from the bottom on a phone (700px and narrower, the
   width the grid turns into cards). Only changed days are sent, each with the value it had when the
   page loaded, so a stale page cannot overwrite another manager's newer work (the server reports it
   as a conflict). */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('ews-grid-form');
        if (!form) return;
        var cells = Array.prototype.slice.call(form.querySelectorAll('td.ews-att-day[data-employee]'));
        var picker = document.getElementById('wfo-attg-picker');
        var scrim = form.querySelector('.wfo-attg-scrim');
        var title = document.getElementById('wfo-attg-picker-title');
        var count = form.querySelector('.wfo-attg-count');
        var undo = form.querySelector('.wfo-attg-undo');
        var notSet = picker ? (picker.querySelector('[data-value=""]') || {}).textContent || '' : '';
        var phone = window.matchMedia ? window.matchMedia('(max-width: 700px)') : { matches: false };
        var current = null;
        var submitting = false;

        cells.forEach(function (td) {
            td.dataset.value = td.getAttribute('data-planned') || '';
            td.dataset.original = td.dataset.value;
        });

        // Week picker: open the chosen week straight away.
        document.querySelectorAll('[data-ews-autosubmit]').forEach(function (input) {
            input.addEventListener('change', function () { if (input.form) input.form.submit(); });
        });

        function planClass(value) { return 'ews-plan-' + (value || 'not-set').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }

        function changedCells() { return cells.filter(function (td) { return td.dataset.value !== td.dataset.original; }); }

        function updateCount() {
            if (!count) return;
            var n = changedCells().length;
            count.textContent = n === 0 ? count.getAttribute('data-none') : (n === 1 ? count.getAttribute('data-one') : (count.getAttribute('data-many') || '%d').replace('%d', n));
            form.classList.toggle('has-changes', n > 0);
            if (undo) undo.hidden = n === 0;
        }

        function setValue(td, value) {
            td.dataset.value = value;
            td.setAttribute('data-planned', value);
            Array.prototype.slice.call(td.classList).forEach(function (c) { if (c.indexOf('ews-plan-') === 0) td.classList.remove(c); });
            td.classList.add(planClass(value));
            td.classList.toggle('is-changed', value !== td.dataset.original);
            var b = td.querySelector('.wfo-attg-chip b');
            if (b) b.textContent = value || notSet;
        }

        function closePicker(focusBack) {
            if (!picker || picker.hidden) return;
            picker.hidden = true;
            if (scrim) scrim.hidden = true;
            picker.classList.remove('is-sheet');
            var chip = current && current.querySelector('.wfo-attg-chip');
            if (chip) chip.setAttribute('aria-expanded', 'false');
            if (focusBack && chip) chip.focus({ preventScroll: true });
            current = null;
        }

        function openPicker(td) {
            if (!picker) return;
            if (current === td && !picker.hidden) { closePicker(true); return; }
            closePicker(false);
            current = td;
            var chip = td.querySelector('.wfo-attg-chip');
            if (title && chip) title.textContent = chip.getAttribute('data-title') || '';
            picker.querySelectorAll('[role=option]').forEach(function (o) {
                o.setAttribute('aria-selected', o.getAttribute('data-value') === td.dataset.value ? 'true' : 'false');
            });
            picker.hidden = false;
            if (chip) chip.setAttribute('aria-expanded', 'true');
            if (phone.matches) {
                picker.classList.add('is-sheet');
                picker.style.top = picker.style.left = '';
                if (scrim) scrim.hidden = false;
            } else {
                var r = chip.getBoundingClientRect();
                var w = picker.offsetWidth, h = picker.offsetHeight;
                var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
                var top = r.bottom + 6;
                if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
                picker.style.left = left + 'px';
                picker.style.top = top + 'px';
            }
            var sel = picker.querySelector('[aria-selected=true]') || picker.querySelector('[role=option]');
            if (sel) sel.focus({ preventScroll: true });
        }

        form.addEventListener('click', function (e) {
            var chip = e.target.closest('.wfo-attg-chip');
            if (chip) { openPicker(chip.closest('td')); return; }
        });
        if (picker) {
            picker.addEventListener('click', function (e) {
                if (e.target.closest('.wfo-attg-picker-close')) { closePicker(true); return; }
                var opt = e.target.closest('[role=option]');
                if (!opt || !current) return;
                var td = current;
                setValue(td, opt.getAttribute('data-value') || '');
                updateCount();
                closePicker(true);
            });
            picker.addEventListener('keydown', function (e) {
                var opts = Array.prototype.slice.call(picker.querySelectorAll('[role=option]'));
                var i = opts.indexOf(document.activeElement);
                if (e.key === 'Escape') { e.preventDefault(); closePicker(true); }
                else if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); (opts[i + 1] || opts[0]).focus(); }
                else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); (opts[i - 1] || opts[opts.length - 1]).focus(); }
            });
        }
        if (scrim) scrim.addEventListener('click', function () { closePicker(true); });
        document.addEventListener('click', function (e) {
            if (!picker || picker.hidden || picker.classList.contains('is-sheet')) return;
            if (!e.target.closest('#wfo-attg-picker') && !e.target.closest('.wfo-attg-chip')) closePicker(false);
        });
        window.addEventListener('resize', function () { closePicker(false); });
        window.addEventListener('scroll', function () { if (picker && !picker.classList.contains('is-sheet')) closePicker(false); }, { passive: true });

        // "Set…" above a day column fills that day for every employee.
        document.querySelectorAll('[data-ews-fill-day]').forEach(function (select) {
            select.addEventListener('change', function () {
                var day = select.getAttribute('data-ews-fill-day');
                if (select.value !== '') {
                    cells.forEach(function (td) { if (td.getAttribute('data-day') === day) setValue(td, select.value); });
                    updateCount();
                }
                select.value = '';
            });
        });

        // Undo: every day back to what it was when the page opened.
        if (undo) undo.addEventListener('click', function () {
            cells.forEach(function (td) { if (td.dataset.value !== td.dataset.original) setValue(td, td.dataset.original); });
            updateCount();
        });

        // Search and team filter.
        var search = document.getElementById('ews-att-search');
        var teamFilter = document.getElementById('ews-att-team');
        function filterAttendance() {
            var q = (search ? search.value : '').trim().toLowerCase();
            var team = teamFilter ? teamFilter.value.trim().toLowerCase() : 'all';
            document.querySelectorAll('.ews-att-employee-row').forEach(function (row) {
                var name = row.getAttribute('data-employee-name') || '';
                var teams = (row.getAttribute('data-team') || '').split('|');
                row.style.display = (!q || name.indexOf(q) !== -1) && (team === 'all' || teams.indexOf(team) !== -1) ? '' : 'none';
            });
        }
        // A long list (3.31.71) is searched and filtered on the server: Enter or a team change sends the form.
        var server = search && search.closest('[data-ews-server-list]');
        if (server) {
            // Unsaved changes still get the browser's leave-page warning (beforeunload below).
            if (teamFilter) teamFilter.addEventListener('change', function () { server.submit(); });
        } else {
            if (search) search.addEventListener('input', filterAttendance);
            if (teamFilter) teamFilter.addEventListener('change', filterAttendance);
        }

        form.addEventListener('submit', function () {
            submitting = true;
            var marker = form.querySelector('input[name="attendance_client"]');
            if (marker) marker.value = phone.matches ? 'mobile' : 'desktop';
            var payload = form.querySelector('#ews-att-changes-json');
            if (payload) payload.value = JSON.stringify(changedCells().map(function (td) {
                return {
                    employee: parseInt(td.getAttribute('data-employee') || '0', 10),
                    day: parseInt(td.getAttribute('data-day') || '-1', 10),
                    status: td.dataset.value,
                    original: td.dataset.original === '' ? null : td.dataset.original
                };
            }));
        });

        // Leaving with changes that are not saved: ask first.
        window.addEventListener('beforeunload', function (e) {
            if (submitting || changedCells().length === 0) return;
            e.preventDefault();
            e.returnValue = count ? count.getAttribute('data-leave') || '' : '';
        });

        updateCount();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
