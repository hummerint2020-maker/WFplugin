/* Employee app → My approvals (3.31.103, templates/app/approvals.php): a reason before rejecting when the
   request type asks for one, and "Select" to approve several requests at once. Each one is sent with its
   own form, one after the other, to the same handler as its Approve button; the page then reloads with
   how many were approved. Without this script every card still works on its own. */
(function () {
    'use strict';
    var root = document.querySelector('[data-apv]');
    if (!root) return;
    var i18n = {};
    try { i18n = JSON.parse((root.querySelector('[data-apv-i18n]') || {}).textContent || '{}'); } catch (e) { i18n = {}; }
    var max = parseInt(root.getAttribute('data-bulk-max'), 10) || 20;
    var errSel = /[?&](leave_error|overtime_error|cx_error|swap_error|adv_error)=/;

    // Caught on the way down (capture), before the app's own "Are you sure?" dialog on the form
    // (workforce-one.js) opens for a reject that cannot be sent without a reason.
    root.addEventListener('submit', function (e) {
        var form = e.target, btn = e.submitter;
        if (!form.matches('[data-apv-form][data-note-required]') || !btn || btn.value !== 'reject') return;
        var note = form.querySelector('textarea[name="note"]');
        if (note && note.value.trim() !== '') return;
        e.preventDefault();
        e.stopPropagation();
        var more = form.querySelector('details');
        if (more) more.open = true;
        var err = form.querySelector('[data-apv-note-err]');
        if (err) err.hidden = false;
        if (note) note.focus();
    }, true);

    var pickable = root.querySelectorAll('[data-apv-form]:not([data-apv-nobulk])');
    var selectBtn = root.querySelector('[data-apv-select]');
    var bar = root.querySelector('[data-apv-bulk]');
    if (!selectBtn || !bar || pickable.length < 2) return;
    selectBtn.hidden = false;
    var count = bar.querySelector('[data-apv-count]'), go = bar.querySelector('[data-apv-bulk-go]');

    function picked() { return Array.prototype.filter.call(root.querySelectorAll('[data-apv-pick]'), function (c) { return c.checked; }); }
    function sync() {
        var n = picked().length;
        count.textContent = n > max ? (i18n.max || '').replace('%d', max) : (i18n.selected || '%d').replace('%d', n);
        go.disabled = n === 0 || n > max;
        root.querySelectorAll('[data-apv-item]').forEach(function (item) {
            var c = item.querySelector('[data-apv-pick]');
            item.classList.toggle('is-picked', !!(c && c.checked));
        });
    }
    function mode(on) {
        root.querySelectorAll('.wfo-apv-pick').forEach(function (l) { l.hidden = !on; });
        root.querySelectorAll('.wfo-apv-acts').forEach(function (a) { a.hidden = on; });
        bar.hidden = !on;
        selectBtn.hidden = on;
        if (!on) root.querySelectorAll('[data-apv-pick]').forEach(function (c) { c.checked = false; });
        sync();
    }
    selectBtn.addEventListener('click', function () { mode(true); });
    bar.querySelector('[data-apv-select-done]').addEventListener('click', function () { mode(false); });
    root.addEventListener('change', function (e) { if (e.target.matches('[data-apv-pick]')) sync(); });

    go.addEventListener('click', function () {
        var forms = picked().map(function (c) { return c.closest('form'); }).slice(0, max);
        if (!forms.length) return;
        go.disabled = true;
        count.textContent = i18n.working || '';
        var ok = 0, i = 0;
        function next() {
            if (i >= forms.length) {
                var url = root.getAttribute('data-done-url').replace('__N__', String(ok));
                window.location.href = url + (url.indexOf('?') < 0 ? '?' : '&') + 'apv_asked=' + forms.length;
                return;
            }
            var data = new FormData(forms[i++]);
            data.set('decision', 'approve');
            data.delete('note');
            fetch(forms[0].getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin', redirect: 'follow' })
                .then(function (r) { if (r.ok && !errSel.test(r.url)) ok++; })
                .catch(function () {})
                .then(next);
        }
        next();
    });
})();
