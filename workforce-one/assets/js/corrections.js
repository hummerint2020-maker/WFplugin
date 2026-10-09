/* Employee app: the attendance correction sheet (templates/app/correction-sheet.php, 3.31.74).
   A button with data-cx-open (a day in "My recent days", "Request correction") opens the sheet on
   its day (data-date) and kind (data-type); the end-of-day push opens it with ?cx=out&cx_date=…
   The kind shows the time fields it needs and whether a photo is required. Opening and closing:
   assets/js/sheet.js. */
(function () {
    'use strict';

    function init() {
        var sheet = document.getElementById('wfo-cx-sheet');
        if (!sheet) return;
        var form = sheet.querySelector('form');
        var date = form.querySelector('[name=date]');
        var target = form.querySelector('[name=target]');
        var tin = form.querySelector('[name=time_in]');
        var tout = form.querySelector('[name=time_out]');
        var photo = form.querySelector('[name=photo]');
        var photoLabel = form.querySelector('[data-cx-photo-label]');
        var photoName = form.querySelector('[data-cx-photo-name]');
        var photoBox = form.querySelector('[data-cx-photo]');
        var recIn = form.querySelector('[data-cx-rec-in]');
        var recOut = form.querySelector('[data-cx-rec-out]');
        var photoHint = photoName ? photoName.textContent : '';

        function type() {
            var r = form.querySelector('[name=type]:checked');
            return r ? r.value : '';
        }

        function day() {
            var o = date.options[date.selectedIndex];
            return o ? { inTime: o.getAttribute('data-in') || '', outTime: o.getAttribute('data-out') || '' } : { inTime: '', outTime: '' };
        }

        function update() {
            var t = type(), d = day();
            if (recIn) { recIn.textContent = d.inTime || '—'; recIn.classList.toggle('is-missing', !d.inTime); }
            if (recOut) { recOut.textContent = d.outTime || '—'; recOut.classList.toggle('is-missing', !d.outTime); }
            var keys = [t];
            if (t === 'time') keys.push('time-' + (target.value === 'sign_out' ? 'out' : 'in'));
            form.querySelectorAll('[data-cx-show]').forEach(function (el) {
                var on = el.getAttribute('data-cx-show').split(' ').some(function (k) { return keys.indexOf(k) !== -1; });
                el.hidden = !on;
                el.querySelectorAll('input,select').forEach(function (i) { i.disabled = !on; if (i.type === 'time') i.required = on; });
            });
            if (t === 'time' && target.value === 'sign_in' && d.inTime && !tin.value) tin.value = d.inTime;
            if (t === 'time' && target.value === 'sign_out' && d.outTime && !tout.value) tout.value = d.outTime;
            var r = form.querySelector('[name=type]:checked');
            var mode = r ? r.getAttribute('data-photo') : 'off';
            if (photoBox) photoBox.hidden = mode === 'off';
            if (photo) photo.required = mode === 'required';
            if (photoLabel) photoLabel.textContent = photoLabel.getAttribute(mode === 'required' ? 'data-required' : 'data-optional');
        }

        function pick(t) {
            var r = form.querySelector('[name=type][value="' + t + '"]') || form.querySelector('[name=type]');
            if (r) r.checked = true;
        }

        function openOn(d, t) {
            if (d) {
                for (var i = 0; i < date.options.length; i++) if (date.options[i].value === d && !date.options[i].disabled) { date.selectedIndex = i; break; }
            }
            if (t) pick(t);
            tin.value = ''; tout.value = '';
            update();
            if (window.wfoSheet) window.wfoSheet.open(sheet);
        }

        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-cx-open]');
            if (!b) return;
            e.preventDefault();
            openOn(b.getAttribute('data-date'), b.getAttribute('data-type'));
        });
        form.addEventListener('change', function (e) {
            if (e.target === photo && photoName) photoName.textContent = photo.files && photo.files[0] ? photo.files[0].name : photoHint;
            if (photoBox && e.target === photo) photoBox.classList.toggle('is-set', !!(photo.files && photo.files[0]));
            if (e.target.name === 'type' || e.target === target || e.target === date) update();
        });
        update();
        var start = sheet.getAttribute('data-open');
        if (start || sheet.getAttribute('data-show-error')) openOn(sheet.getAttribute('data-open-date'), start);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
