/* Employee login (templates/app/login.php): the "show password" eye button, when wp-admin → Appearance →
   Login screen keeps it on (the card then has .has-eye and the two labels), and the sign-in helpers when that
   screen turns them on (data-assist on the card; 3.31.157, docs/tasks/login-assist.md). */
(function () {
    'use strict';
    var EYE = '<svg class="is-on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
        + '<svg class="is-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

    // Sign-in helpers: an empty field is pointed at before sending; the username comes back after a wrong password
    // (this tab only; the password is never kept); a Caps Lock line; one tap on Sign In.
    var KEY = 'ewsLoginUser';
    function store(fn) { try { return fn(window.sessionStorage); } catch (e) { return null; } }

    function assist(card) {
        var form = card.querySelector('form');
        var user = card.querySelector('input[name=log]');
        var pass = card.querySelector('input[name=pwd]');
        var submit = form && form.querySelector('input[type=submit]');
        if (!form || !user || !pass || !submit || form.getAttribute('data-assist-on')) return;
        form.setAttribute('data-assist-on', '1');

        function line(field, cls, id) {
            var el = document.createElement('span');
            el.className = cls;
            el.id = id;
            el.hidden = true;
            el.setAttribute('aria-live', 'polite');
            var p = field.closest('p') || field.parentNode;
            p.appendChild(el);
            return el;
        }
        var msgs = { log: line(user, 'wfo-login-field-msg', 'ews_login_user_msg'), pwd: line(pass, 'wfo-login-field-msg', 'ews_login_pass_msg') };
        var caps = line(pass, 'wfo-login-caps', 'ews_login_caps');
        // "Signing in..." for screen readers: the button that had the cursor is swapped for a disabled one.
        var said = document.createElement('span');
        said.className = 'wfo-login-sr';
        said.setAttribute('role', 'status');
        submit.parentNode.appendChild(said);
        caps.textContent = card.getAttribute('data-caps') || 'Caps Lock is on';

        function describe(field) {
            var ids = [msgs[field.name].hidden ? '' : msgs[field.name].id, field === pass && !caps.hidden ? caps.id : ''].filter(Boolean).join(' ');
            if (ids) field.setAttribute('aria-describedby', ids); else field.removeAttribute('aria-describedby');
        }
        function mark(field, text) {
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
            msgs[field.name].textContent = text;
            msgs[field.name].hidden = false;
            describe(field);
            field.focus();
        }
        function clear(field) {
            if (!field.classList.contains('is-invalid')) return;
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
            msgs[field.name].hidden = true;
            describe(field);
        }
        [user, pass].forEach(function (f) { f.addEventListener('input', function () { if (f.value.trim() !== '') clear(f); }); });

        function capsState(e) {
            if (!e.getModifierState) return;
            caps.hidden = !e.getModifierState('CapsLock');
            describe(pass);
        }
        pass.addEventListener('keydown', capsState);
        pass.addEventListener('keyup', capsState);
        pass.addEventListener('blur', function () { caps.hidden = true; describe(pass); });

        var busyBtn = null, safety = 0;
        function idle() {
            if (busyBtn) { busyBtn.remove(); busyBtn = null; }
            clearTimeout(safety);
            said.textContent = '';
            submit.hidden = false;
            form.removeAttribute('data-busy');
        }
        form.addEventListener('submit', function (e) {
            if (form.getAttribute('data-busy')) { e.preventDefault(); return; }
            if (user.value.trim() === '') { e.preventDefault(); mark(user, card.getAttribute('data-need-user') || ''); return; }
            if (pass.value === '') { e.preventDefault(); mark(pass, card.getAttribute('data-need-pass') || ''); return; }
            var name = user.value.trim();
            // After every other submit listener: when another script (a captcha, a second step) stops the sending, nothing waits.
            setTimeout(function () {
                if (e.defaultPrevented || form.getAttribute('data-busy')) return;
                store(function (s) { s.setItem(KEY, name); });
                form.setAttribute('data-busy', '1');
                var label = card.getAttribute('data-busy') || 'Signing in...';
                busyBtn = document.createElement('button');
                busyBtn.type = 'button';
                busyBtn.className = 'wfo-login-submit is-busy';
                busyBtn.disabled = true;
                busyBtn.innerHTML = '<span class="wfo-login-spin" aria-hidden="true"></span>';
                busyBtn.appendChild(document.createTextNode(label));
                submit.parentNode.insertBefore(busyBtn, submit);
                submit.hidden = true;
                said.textContent = label;
                // A request stopped or lost on a bad connection: the form works again after a while.
                safety = setTimeout(idle, 20000);
            }, 0);
        });
        // Back from the next page (the browser's page cache): the form works again.
        window.addEventListener('pageshow', function (e) { if (e.persisted) idle(); });

        if (card.getAttribute('data-failed') === '1') {
            var kept = store(function (s) { return s.getItem(KEY); });
            if (kept && user.value === '') { user.value = kept; pass.focus(); }
        } else {
            store(function (s) { s.removeItem(KEY); });
        }
    }

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
        // After the eye: wrapping the password field moves it, and a moved field loses the cursor.
        document.querySelectorAll('.wfo-login-card[data-assist="1"]').forEach(assist);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
