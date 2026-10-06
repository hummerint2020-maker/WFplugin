/* Employee app Team Schedule (templates/app/schedule.php): swap form, colleague search, PDF / WhatsApp. */
(function () {
    'use strict';

    /* WhatsApp: fetch the week's PDF (schedule_pdf()) and hand it to the phone's share sheet, where
       WhatsApp is one of the choices. Without file sharing (most desktop browsers) the PDF is
       downloaded and WhatsApp opens with a note to attach it. */
    function sharePdf(btn) {
        var url = btn.getAttribute('data-ews-share-pdf');
        var name = btn.getAttribute('data-ews-share-name') || 'team-schedule.pdf';
        var text = btn.getAttribute('data-ews-share-text') || '';
        btn.disabled = true;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('pdf ' + r.status); return r.blob(); })
            .then(function (blob) {
                var file = null;
                try { file = new File([blob], name, { type: 'application/pdf' }); } catch (e) { file = null; }
                if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                    return navigator.share({ files: [file], title: text, text: text }).catch(function () {});
                }
                var link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = name;
                document.body.appendChild(link);
                link.click();
                setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 4000);
                var note = btn.getAttribute('data-ews-share-fallback') || '';
                var wa = 'https://wa.me/?text=' + encodeURIComponent(text + (note ? '\n' + note : ''));
                if (!window.open(wa, '_blank', 'noopener')) window.location.href = wa;
            })
            .catch(function () { window.location.href = url; })
            .then(function () { btn.disabled = false; });
    }

    function init() {
        var open = document.getElementById('ews-open-swap');
        var close = document.getElementById('ews-close-swap');
        var wrap = document.getElementById('ews-swap-form-wrap');
        function showSwap() {
            if (!wrap) return;
            wrap.hidden = false;
            if (open) open.style.display = 'none';
            wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var first = wrap.querySelector('select,button');
            if (first) first.focus({ preventScroll: true });
        }
        if (open && wrap) open.addEventListener('click', showSwap);
        document.querySelectorAll('[data-ews-open-swap]').forEach(function (b) { b.addEventListener('click', showSwap); });
        if (close && wrap) close.addEventListener('click', function () { wrap.hidden = true; if (open) open.style.display = ''; });

        // Colleague search: the table rows (desktop) and the cards (phones) alike.
        var search = document.getElementById('wfo-sched-search');
        var none = document.querySelector('.wfo-sched-noresult');
        if (search) search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var shown = 0;
            document.querySelectorAll('.wfo-sched-pcard, .wfo-sched-table tbody tr[data-employee-name]').forEach(function (el) {
                var hit = !q || (el.getAttribute('data-employee-name') || '').indexOf(q) !== -1;
                el.style.display = hit ? '' : 'none';
                if (hit && el.classList.contains('wfo-sched-pcard')) shown++;
            });
            if (none) none.hidden = !q || shown > 0;
        });

        document.querySelectorAll('[data-ews-share-pdf]').forEach(function (btn) {
            btn.addEventListener('click', function () { sharePdf(btn); });
        });

        document.querySelectorAll('[data-ews-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (!window.confirm(el.getAttribute('data-ews-confirm'))) e.preventDefault();
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
