/* Daily workers in the app (3.31.76, templates/app/dw-*.php).
   - Day sheet: present / half day / absent and extra hours per worker, the live counts and cost of
     the day; the ⋯ button opens "Move" (before saving) or "Ask to change" (after); a quick check of
     the national ID's length while typing.
   - Confirming the day, a worker's "I am at the site": the phone's position (GPS) is read and sent
     with the form; the server decides whether it is inside the site.
   - Photo boxes show that a photo was taken. Sheets: assets/js/sheet.js. */
(function () {
    'use strict';

    function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }

    function moneyFormat(box) {
        var sample = box.getAttribute('data-money') || '1,234';
        return function (v) {
            var s = (Math.round(v * 100) / 100).toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: 2 });
            return sample.replace(/[\d٠-٩][\d٠-٩.,٬٫]*/, s);
        };
    }

    function initDay(box) {
        var fmt = moneyFormat(box);
        function update() {
            var c = { in: 0, half: 0, out: 0 }, extra = 0, cost = 0;
            box.querySelectorAll('[data-dw-worker]').forEach(function (row) {
                if (row.hasAttribute('data-elsewhere')) return;
                var mark = row.querySelector('input[name^="mark["]').value;
                var x = num(row.querySelector('input[name^="extra["]').value);
                var rate = num(row.getAttribute('data-rate')), hourly = num(row.getAttribute('data-hourly'));
                c[mark] = (c[mark] || 0) + 1;
                row.classList.toggle('is-out', mark === 'out');
                if (mark !== 'out') { extra += x; cost += x * hourly; }
                cost += mark === 'in' ? rate : (mark === 'half' ? rate / 2 : 0);
            });
            ['in', 'half', 'out'].forEach(function (k) { var el = box.querySelector('[data-dw-count="' + k + '"]'); if (el) el.textContent = c[k]; });
            var ex = box.querySelector('[data-dw-count="extra"]'); if (ex) ex.textContent = extra;
            var co = box.querySelector('[data-dw-cost]'); if (co) co.textContent = fmt(cost);
        }
        box.addEventListener('click', function (e) {
            var b = e.target.closest('[data-dw-mark]');
            if (b && !b.disabled) {
                var row = b.closest('[data-dw-worker]');
                row.querySelector('input[name^="mark["]').value = b.getAttribute('data-dw-mark');
                row.querySelectorAll('[data-dw-mark]').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
                update();
                return;
            }
            var x = e.target.closest('[data-dw-extra]');
            if (x && !x.disabled) {
                var r = x.closest('[data-dw-worker]');
                var input = r.querySelector('input[name^="extra["]');
                var v = Math.max(0, Math.min(12, num(input.value) + num(x.getAttribute('data-dw-extra'))));
                input.value = v;
                r.querySelector('[data-dw-extra-val]').textContent = v;
                update();
            }
        });
        update();
    }

    /* The ⋯ button: Move (before saving) or Ask to change (after). */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-dw-more]');
        if (!b) return;
        e.preventDefault();
        var kind = b.getAttribute('data-dw-more');
        var dialog = document.getElementById(kind === 'change' ? 'wfo-dw-change' : 'wfo-dw-move');
        if (!dialog) return;
        var who = dialog.querySelector('[data-dw-who]'); if (who) who.textContent = b.getAttribute('data-name');
        if (kind === 'change') {
            dialog.querySelector('[name=day]').value = b.getAttribute('data-day');
            dialog.querySelector('[name=mark]').value = b.getAttribute('data-mark');
            dialog.querySelector('[name=extra]').value = b.getAttribute('data-extra');
        } else {
            dialog.querySelector('[name=worker]').value = b.getAttribute('data-worker');
        }
        if (window.wfoSheet) window.wfoSheet.open(dialog, b);
    });

    /* Photo boxes. */
    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input.matches || !input.matches('[data-dw-photo] input[type=file]')) return;
        var box = input.closest('[data-dw-photo]');
        var has = input.files && input.files[0];
        box.classList.toggle('is-set', !!has);
        box.classList.remove('is-error');
        var t = box.querySelector('[data-dw-photo-text]');
        if (t && has) t.textContent = box.getAttribute('data-taken');
    });

    /* National ID: Egyptian numbers are 14 digits. */
    document.addEventListener('input', function (e) {
        if (!e.target.matches || !e.target.matches('[data-dw-nid]')) return;
        var msg = e.target.parentNode.querySelector('[data-dw-nid-msg]');
        if (!msg) return;
        var v = e.target.value.replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 1632); }).replace(/[\s-]/g, '');
        var ok = /^[23]\d{13}$/.test(v);
        msg.textContent = v === '' ? '' : msg.getAttribute(ok ? 'data-ok' : 'data-bad');
        msg.className = 'wfo-dw-hint' + (v === '' ? '' : (ok ? ' is-ok' : ' is-bad'));
    });

    /* Forms that need the phone's position: fill it, then send. */
    function withPosition(form, button, onFail) {
        form.addEventListener('submit', function (e) {
            if (form.getAttribute('data-located') === '1') return;
            e.preventDefault();
            var photo = form.querySelector('input[type=file][required]');
            if (photo && !(photo.files && photo.files[0])) {
                var box = photo.closest('[data-dw-photo]'); if (box) box.classList.add('is-error');
                photo.click();
                return;
            }
            var label = button ? button.innerHTML : '';
            if (button) { button.disabled = true; button.textContent = button.getAttribute('data-busy') || '…'; }
            function send(p) {
                if (p) {
                    form.querySelector('[name=latitude]').value = p.coords.latitude;
                    form.querySelector('[name=longitude]').value = p.coords.longitude;
                    form.querySelector('[name=accuracy]').value = p.coords.accuracy;
                    form.querySelector('[name=location_timestamp]').value = p.timestamp || Date.now();
                }
                form.setAttribute('data-located', '1');
                if (button) button.disabled = false;
                if (form.requestSubmit) form.requestSubmit(button || undefined); else form.submit();
            }
            if (!navigator.geolocation) { send(null); return; }
            navigator.geolocation.getCurrentPosition(send, function () {
                if (button) { button.disabled = false; button.innerHTML = label; }
                onFail();
            }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
        });
    }

    function init() {
        document.querySelectorAll('[data-dw-day]').forEach(initDay);
        var sheet = document.querySelector('[data-dw-sheet-form]');
        if (sheet) {
            var gps = document.querySelector('[data-dw-gps]');
            withPosition(sheet, sheet.querySelector('.wfo-dw-confirm'), function () {
                if (!gps) return;
                gps.classList.add('is-bad');
                var t = gps.querySelector('[data-dw-gps-text]'); if (t) t.textContent = gps.getAttribute('data-fail');
            });
        }
        var self = document.querySelector('[data-dw-self-form]');
        if (self) {
            var btn = self.querySelector('button[type=submit]');
            withPosition(self, btn, function () { window.alert(btn.getAttribute('data-fail')); });
        }
    }

    /* Back to a page from the browser history: read the position again before the next send. */
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-located]').forEach(function (f) { f.removeAttribute('data-located'); });
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
