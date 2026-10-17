/* Employee app → My requests → "+ New request" (3.31.105, templates/app/new-request.php): picking a
   type closes the list and opens that type's form (its sheet), "Back" returns to the list, and the swap
   form lists the colleagues for the chosen day (who can swap: a different day from yours, no swap with
   them waiting that day). The forms themselves are sent as on their own pages. */
(function () {
    'use strict';
    // Salary advance (3.31.109): what comes off each month, as the person types.
    function advance() {
        var form = document.querySelector('[data-new-advance]');
        if (!form) return;
        var amount = form.querySelector('[name="amount"]'), months = form.querySelector('[name="instalments"]'), out = form.querySelector('[data-new-advance-each]');
        function show() {
            var a = parseFloat(amount.value), n = parseInt(months.value, 10);
            if (!(a > 0) || !(n > 0)) { out.textContent = ''; return; }
            var each = Math.floor(a / n * 100) / 100;
            out.textContent = form.getAttribute('data-each').replace('%1$s', each.toLocaleString(document.documentElement.lang || undefined) + ' ' + form.getAttribute('data-currency')).replace('%2$s', n.toLocaleString(document.documentElement.lang || undefined));
        }
        amount.addEventListener('input', show); months.addEventListener('change', show); show();
    }

    function init() {
        advance();
        var chooser = document.getElementById('wfo-new-sheet');
        if (!chooser || !window.wfoSheet) return;
        document.addEventListener('click', function (e) {
            var pick = e.target.closest('[data-new-open],[data-new-pick]');
            if (pick && chooser.contains(pick)) {
                window.wfoSheet.close(chooser);
                var id = pick.getAttribute('data-new-open');
                if (id) { e.preventDefault(); window.wfoSheet.open(document.getElementById(id), pick); }
                return;
            }
            var back = e.target.closest('[data-new-back]');
            if (back) { e.preventDefault(); window.wfoSheet.close(back.closest('dialog')); window.wfoSheet.open(chooser); }
        });

        var form = document.querySelector('[data-new-swap]');
        if (!form) return;
        var cfg = {};
        try { cfg = JSON.parse(form.getAttribute('data-new-swap') || '{}'); } catch (err) { cfg = {}; }
        var day = form.querySelector('select[name="work_date"]'), box = form.querySelector('[data-new-mates]'), send = form.querySelector('[data-new-swap-send]');
        function draw() {
            var d = day.value, any = false;
            box.textContent = '';
            (cfg.people || []).forEach(function (p) {
                var s = (p.days || {})[d] || { status: '', ok: false, busy: false };
                var ok = s.ok && !s.busy;
                var label = document.createElement('label');
                label.className = 'wfo-new-mate' + (ok ? '' : ' is-off');
                var input = document.createElement('input');
                input.type = 'radio'; input.name = 'target_employee_id'; input.value = String(p.id); input.disabled = !ok; input.required = true;
                var b = document.createElement('b'); b.textContent = p.name;
                var small = document.createElement('small');
                small.textContent = s.busy ? cfg.busy : (!s.ok && s.status !== '' && (cfg.labels || {})[s.status] ? cfg.same : (cfg.day || '%s').replace('%s', (cfg.labels || {})[s.status] || s.status || (cfg.labels || {})['']));
                var text = document.createElement('span'); text.appendChild(b); text.appendChild(small);
                label.appendChild(input); label.appendChild(text);
                if (p.team) { var t = document.createElement('i'); t.textContent = cfg.team; label.appendChild(t); }
                box.appendChild(label);
                if (ok) any = true;
            });
            if (!any) { var none = document.createElement('p'); none.className = 'wfo-sheet-note'; none.textContent = cfg.none; box.appendChild(none); }
            send.disabled = !any;
        }
        day.addEventListener('change', draw);
        draw();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
