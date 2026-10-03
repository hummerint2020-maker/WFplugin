/* Employee app → Overtime (templates/app/overtime.php): live duration of the requested time range. */
(function () {
    'use strict';

    function init() {
        var start = document.getElementById('ews-ot-start');
        var end = document.getElementById('ews-ot-end');
        var out = document.getElementById('ews-ot-count');
        if (!start || !end || !out) return;
        var t = {};
        try { t = JSON.parse(out.getAttribute('data-messages') || '{}'); } catch (e) { t = {}; }

        function show(icon, text, strong) {
            out.textContent = '';
            out.appendChild(document.createTextNode(icon + ' '));
            var span = document.createElement('span');
            if (strong === undefined) span.textContent = text;
            else {
                // "You are requesting %s." with the duration in bold.
                var parts = text.split('%s');
                span.appendChild(document.createTextNode(parts[0]));
                var b = document.createElement('strong');
                b.textContent = strong;
                span.appendChild(b);
                span.appendChild(document.createTextNode(parts.slice(1).join('%s')));
            }
            out.appendChild(span);
        }

        function update() {
            if (!start.value || !end.value) { show('🕐', t.empty || ''); return; }
            var a = start.value.split(':'), b = end.value.split(':');
            var from = (+a[0]) * 60 + (+a[1]), to = (+b[0]) * 60 + (+b[1]);
            if (to <= from) { show('⚠️', t.order || ''); return; }
            var h = Math.floor((to - from) / 60), m = (to - from) % 60;
            var duration = m ? (t.hm || '%1$d h %2$d min').replace('%1$d', h).replace('%2$d', m) : (t.h || '%d h').replace('%d', h);
            show('🕐', t.req || '%s', duration);
        }
        start.addEventListener('change', update);
        end.addEventListener('change', update);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
