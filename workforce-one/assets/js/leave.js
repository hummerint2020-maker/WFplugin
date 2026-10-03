/*
 * Employee app → Leave (templates/app/leave.php): live working-day count of the requested range,
 * and the Early Leave checks shown before submitting (the server checks them again).
 * Translated texts come from the page's data-* attributes.
 */
(function () {
    'use strict';

    function json(value, fallback) {
        try { return JSON.parse(value || ''); } catch (e) { return fallback; }
    }

    function workingDayCounter() {
        var start = document.getElementById('ews-vac-start');
        var end = document.getElementById('ews-vac-end');
        var out = document.getElementById('ews-vac-days');
        if (!start || !end || !out) return;
        var days = json(out.getAttribute('data-working-days'), []);
        function update() {
            if (!start.value || !end.value || end.value < start.value) { out.textContent = out.getAttribute('data-invalid'); return; }
            var n = 0;
            for (var d = new Date(start.value + 'T00:00:00'), last = new Date(end.value + 'T00:00:00'); d <= last; d.setDate(d.getDate() + 1)) {
                if (days.indexOf(d.getDay()) !== -1) n++;
            }
            out.textContent = (out.getAttribute('data-count') || '%d').replace('%d', n);
        }
        start.addEventListener('change', update);
        end.addEventListener('change', update);
    }

    function showModal(t, message) {
        var old = document.getElementById('ews-early-leave-modal');
        if (old) old.remove();
        var modal = document.createElement('div');
        modal.id = 'ews-early-leave-modal';
        modal.className = 'ews-modal-backdrop';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        var card = document.createElement('div');
        card.className = 'ews-modal-card';
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'ews-modal-close'; x.setAttribute('aria-label', t.close || 'Close'); x.textContent = '×';
        var icon = document.createElement('div');
        icon.className = 'ews-modal-icon'; icon.textContent = '!';
        var title = document.createElement('h3');
        title.textContent = t.title || '';
        var text = document.createElement('p');
        text.textContent = message;
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'ews-modal-ok'; ok.textContent = t.ok || 'OK';
        [x, icon, title, text, ok].forEach(function (el) { card.appendChild(el); });
        modal.appendChild(card);
        document.body.appendChild(modal);
        function close() { modal.remove(); document.removeEventListener('keydown', onKey); }
        function onKey(e) { if (e.key === 'Escape') close(); }
        x.addEventListener('click', close);
        ok.addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', onKey);
        ok.focus();
    }

    function earlyLeaveChecks() {
        var form = document.getElementById('ews-early-form');
        if (!form) return;
        var t = json(form.getAttribute('data-messages'), {});
        var days = json(form.getAttribute('data-working-days'), []);
        var remaining = parseInt(form.getAttribute('data-remaining') || '0', 10);
        form.addEventListener('submit', function (e) {
            var date = document.getElementById('ews-early-date');
            var minutes = document.getElementById('ews-early-minutes');
            if (!date || !minutes) return;
            var max = parseInt(minutes.getAttribute('max') || '120', 10);
            var value = parseInt(minutes.value || '0', 10);
            var error = '';
            if (!date.value) error = t.date;
            else {
                var chosen = new Date(date.value + 'T00:00:00');
                var today = new Date(); today.setHours(0, 0, 0, 0);
                if (!(chosen > today)) error = t.future;
                else if (days.length && days.indexOf(chosen.getDay()) === -1) error = t.working_day;
                else if (!(value >= 1) || value > max) error = t.max;
                else if (value > remaining) error = t.remaining;
            }
            if (error) { e.preventDefault(); showModal(t, error); }
        });
    }

    function init() { workingDayCounter(); earlyLeaveChecks(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
