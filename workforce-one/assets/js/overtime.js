/* Employee app → Overtime (templates/app/overtime.php), in the request sheet: the Today / Tomorrow
   buttons fill the date, and the live duration of the requested time range. */
(function () {
    'use strict';

    function init() {
        var date = document.getElementById('ews-ot-date');
        var start = document.getElementById('ews-ot-start');
        var end = document.getElementById('ews-ot-end');
        var out = document.getElementById('ews-ot-count');
        var days = Array.prototype.slice.call(document.querySelectorAll('[data-ews-ot-day]'));

        function markDay() {
            days.forEach(function (b) { b.setAttribute('aria-pressed', date && b.getAttribute('data-ews-ot-day') === date.value ? 'true' : 'false'); });
        }
        days.forEach(function (b) {
            b.addEventListener('click', function () {
                if (!date) return;
                date.value = b.getAttribute('data-ews-ot-day');
                date.dispatchEvent(new Event('change', { bubbles: true }));
                markDay();
            });
        });
        if (date) date.addEventListener('change', markDay);

        if (!start || !end || !out) return;
        var text = out.querySelector('span') || out;
        var t = {};
        try { t = JSON.parse(out.getAttribute('data-messages') || '{}'); } catch (e) { t = {}; }

        function show(message, strong, warn) {
            out.classList.toggle('is-warn', !!warn);
            text.textContent = '';
            if (strong === undefined) { text.textContent = message; return; }
            // "You are requesting %s." with the duration in bold.
            var parts = message.split('%s');
            text.appendChild(document.createTextNode(parts[0]));
            var b = document.createElement('strong');
            b.textContent = strong;
            text.appendChild(b);
            text.appendChild(document.createTextNode(parts.slice(1).join('%s')));
        }

        function update() {
            if (!start.value || !end.value) { show(t.empty || ''); return; }
            var a = start.value.split(':'), b = end.value.split(':');
            var from = (+a[0]) * 60 + (+a[1]), to = (+b[0]) * 60 + (+b[1]);
            if (to <= from) { show(t.order || '', undefined, true); return; }
            var h = Math.floor((to - from) / 60), m = (to - from) % 60;
            var duration = m ? (t.hm || '%1$d h %2$d min').replace('%1$d', h).replace('%2$d', m) : (t.h || '%d h').replace('%d', h);
            show(t.req || '%s', duration);
        }
        start.addEventListener('change', update);
        end.addEventListener('change', update);
        start.addEventListener('input', update);
        end.addEventListener('input', update);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
