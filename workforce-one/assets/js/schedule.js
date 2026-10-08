/* Employee app Team Schedule (templates/app/schedule.php): colleague search, PDF / WhatsApp. The swap
   form is a sheet (assets/js/sheet.js). */
(function () {
    'use strict';

    /* WhatsApp: fetch the week's PDF (schedule_pdf()) and hand it to the phone's share sheet, where
       WhatsApp is one of the choices. Without file sharing (most desktop browsers) the PDF is
       downloaded and WhatsApp opens with a note to attach it. */
    function sharePdf(btn) {
        var url = btn.getAttribute('data-ews-share-pdf');
        var name = btn.getAttribute('data-ews-share-name') || 'team-schedule.pdf';
        var text = btn.getAttribute('data-ews-share-text') || '';
        if (btn.disabled) return;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        // Never wait on the server for long, and never leave the page: a failed share keeps the user here.
        var ctl = window.AbortController ? new AbortController() : null;
        var timer = ctl ? setTimeout(function () { ctl.abort(); }, 20000) : 0;
        function download(blob) {
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = name;
            document.body.appendChild(link);
            link.click();
            setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 4000);
            var note = btn.getAttribute('data-ews-share-fallback') || '';
            var wa = 'https://wa.me/?text=' + encodeURIComponent(text + (note ? '\n' + note : ''));
            window.open(wa, '_blank', 'noopener');
        }
        fetch(url, { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
            .then(function (r) {
                if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('application/pdf') !== 0) throw new Error('pdf ' + r.status);
                return r.blob();
            })
            .then(function (blob) {
                var file = null;
                try { file = new File([blob], name, { type: 'application/pdf' }); } catch (e) { file = null; }
                if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                    return navigator.share({ files: [file], title: text, text: text }).catch(function (e) {
                        // Cancelled by the user: nothing to do. Refused (e.g. the tap "expired"): download instead.
                        if (!e || e.name !== 'AbortError') download(blob);
                    });
                }
                download(blob);
            })
            .catch(function () { window.alert(btn.getAttribute('data-ews-share-error') || 'The PDF could not be prepared. Please try again.'); })
            .then(function () { clearTimeout(timer); btn.disabled = false; btn.removeAttribute('aria-busy'); });
    }

    function init() {
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
