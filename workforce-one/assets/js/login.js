/* Employee login (templates/app/login.php): the "show password" eye button, when wp-admin → Appearance →
   Login screen keeps it on (the card then has .has-eye and the two labels). */
(function () {
    'use strict';
    var EYE = '<svg class="is-on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
        + '<svg class="is-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

    function init() {
        document.querySelectorAll('.wfo-login-card.has-eye').forEach(function (card) {
            var field = card.querySelector('input[type=password]');
            if (!field || field.parentNode.classList.contains('wfo-login-pass')) return;
            var wrap = document.createElement('span');
            wrap.className = 'wfo-login-pass';
            field.parentNode.insertBefore(wrap, field);
            wrap.appendChild(field);
            var show = card.getAttribute('data-show-label') || 'Show password';
            var hide = card.getAttribute('data-hide-label') || 'Hide password';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wfo-login-eye';
            btn.setAttribute('aria-pressed', 'false');
            btn.setAttribute('aria-label', show);
            btn.setAttribute('aria-controls', field.id);
            btn.innerHTML = EYE;
            btn.addEventListener('click', function () {
                var visible = field.type === 'password';
                field.type = visible ? 'text' : 'password';
                btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
                btn.setAttribute('aria-label', visible ? hide : show);
                field.focus({ preventScroll: true });
            });
            wrap.appendChild(btn);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
