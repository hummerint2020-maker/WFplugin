/* Employee app Team Schedule (templates/app/schedule.php). */
(function () {
    'use strict';

    function shareOnWhatsApp() {
        var message = 'Employee Weekly Schedule - PDF\n\nThe weekly schedule PDF has been prepared. Please attach the downloaded PDF to the WhatsApp group.';
        var url = 'https://wa.me/?text=' + encodeURIComponent(message);
        if (!window.open(url, '_blank', 'noopener')) window.location.href = url;
    }

    function init() {
        var open = document.getElementById('ews-open-swap');
        var close = document.getElementById('ews-close-swap');
        var wrap = document.getElementById('ews-swap-form-wrap');
        if (open && wrap) open.addEventListener('click', function () { wrap.hidden = false; open.style.display = 'none'; });
        if (close && wrap) close.addEventListener('click', function () { wrap.hidden = true; if (open) open.style.display = ''; });

        // PDF and WhatsApp: print with a meaningful document title (used as the PDF file name).
        document.querySelectorAll('[data-ews-print]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var previous = document.title;
                document.title = btn.getAttribute('data-ews-print');
                window.print();
                document.title = previous;
                if (btn.hasAttribute('data-ews-whatsapp')) setTimeout(shareOnWhatsApp, 1200);
            });
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
