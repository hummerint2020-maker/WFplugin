/* Employee app, manager Attendance page: the cards open who and when (3.31.125, docs/tasks/attendance-cards.md).
   A card (data-ews-card) opens dialog#wfo-ac-sheet through sheet.js; this script loads that card's list from
   admin-post.php?action=ews_att_cards (GET, JSON) with the filters the page applied, 20 rows at a time, and draws it with
   textContent only (names are data, never markup). The texts come from the dialog's data-text (translated on the page). */
(function () {
    'use strict';

    var ICON = { people: 'people', present: 'check', late: 'overtime', absent: 'alert', rate: 'insights' };

    function init() {
        var dialog = document.getElementById('wfo-ac-sheet');
        if (!dialog) return;
        var T = JSON.parse(dialog.getAttribute('data-text') || '{}');
        var params = JSON.parse(dialog.getAttribute('data-params') || '{}');
        var days = JSON.parse(dialog.getAttribute('data-days') || '{}');
        var profile = dialog.getAttribute('data-profile') || '';
        var q = function (sel) { return dialog.querySelector(sel); };
        var body = q('[data-ac-body]'), foot = q('[data-ac-foot]'), more = q('[data-ac-more]'), note = q('[data-ac-note]'), seg = q('[data-ac-seg]');
        var state = { kind: '', order: 'day', next: null, shown: 0, req: 0, ctrl: null, opener: null, lastGroup: '' };

        function fmt(s) {
            var args = Array.prototype.slice.call(arguments, 1), i = 0;
            return String(s).replace(/%(\d+\$)?[ds]/g, function (m, pos) { var v = pos ? args[parseInt(pos, 10) - 1] : args[i++]; return v === undefined ? '' : String(v); });
        }
        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined && text !== null) n.textContent = String(text);
            return n;
        }
        function icon(name) {
            var t = dialog.querySelector('template[data-ac-icon="' + name + '"]');
            return t ? t.content.cloneNode(true) : document.createDocumentFragment();
        }
        function avatar(p) {
            if (p.picture) { var a = el('span', 'ews-att-avatar wfo-att-avatar'); var img = el('img'); img.src = p.picture; img.alt = ''; a.appendChild(img); return a; }
            var s = el('span', 'ews-att-avatar wfo-att-avatar tone-' + (parseInt(p.tone, 10) || 0), p.initials);
            s.setAttribute('aria-hidden', 'true');
            return s;
        }
        function row(p, title, sub, right) {
            var li = el('li');
            var a = el(profile ? 'a' : 'div', 'wfo-ac-row');
            if (profile) a.href = profile + (profile.indexOf('?') < 0 ? '?' : '&') + 'employee_id=' + encodeURIComponent(p.employee_id);
            a.appendChild(avatar(p));
            var who = el('span', 'wfo-ac-who');
            who.appendChild(el('b', '', title));
            if (sub) who.appendChild(el('small', '', sub));
            a.appendChild(who);
            var r = el('span', 'wfo-ac-right');
            right.forEach(function (n) { r.appendChild(n); });
            a.appendChild(r);
            if (profile) { var go = el('span', 'wfo-ac-go'); go.setAttribute('aria-hidden', 'true'); go.appendChild(icon('chevron')); a.appendChild(go); }
            li.appendChild(a);
            return li;
        }
        function pill(cls, text) { return el('span', 'wfo-ac-pill ' + cls, text); }
        function time(t, nextDay) {
            var s = el('span', 'wfo-ac-time', t);
            s.setAttribute('dir', 'ltr');
            if (nextDay) s.appendChild(el('small', 'wfo-ac-nextday', ' +1'));
            if (nextDay) s.title = T.next_day;
            return s;
        }
        function dayRight(r) {
            var out = [];
            if (state.kind === 'absent') {
                var plan = el('span', 'wfo-ac-plan is-' + r.plan_class);
                if (r.plan_class === 'office' || r.plan_class === 'wfh') plan.appendChild(icon(r.plan_class));
                plan.appendChild(document.createTextNode(fmt(T.planned, r.plan || '—') + (r.branch ? ' · ' + r.branch : '')));
                return [plan];
            }
            out.push(time(r['in'], r.next_day));
            if (r.out) { var sep = el('span', 'wfo-ac-sep', '→'); sep.setAttribute('aria-hidden', 'true'); out.push(sep); out.push(time(r.out, false)); }
            if (r.missing_out) out.push(pill('is-warn', T.no_out));
            if (state.kind === 'late') out.push(pill('is-late', r.late_min === null || r.late_min === undefined ? T.late : fmt(T.late_min, r.late_min)));
            return out;
        }
        function mini(cls, ic, n, label) {
            var s = el('span', 'wfo-ac-mini ' + cls);
            s.title = label;
            s.appendChild(icon(ic));
            s.appendChild(document.createTextNode(String(n)));
            return s;
        }
        // The list, in groups: by day (a day's header) or by employee (a person's header); a page continues the last group.
        function groupFor(key, label, count) {
            var last = body.lastElementChild;
            if (last && last.getAttribute('data-group') === key) return last.querySelector('ul');
            var sec = el('section', 'wfo-ac-day');
            sec.setAttribute('data-group', key);
            if (label !== null) { var h = el('h3', '', label); sec.appendChild(h); }
            var ul = el('ul', 'wfo-ac-list');
            sec.appendChild(ul);
            body.appendChild(sec);
            return ul;
        }
        function drawRows(d) {
            if (state.kind === 'people') {
                var ul = groupFor('people', null);
                d.rows.forEach(function (r) {
                    var right = [mini('is-present', 'check', r.present, T.present), mini('is-late', 'clock', r.late, T.late_word), mini('is-absent', 'alert', r.absent, T.absent)];
                    if (r.leave) right.push(mini('is-leave', 'leave', r.leave, T.leave));
                    if (r.holiday) right.push(mini('is-holiday', 'calendar', r.holiday, T.holiday));
                    ul.appendChild(row(r, r.name, r.sub, right));
                });
                return;
            }
            d.rows.forEach(function (r) {
                if (state.order === 'employee') groupFor('e' + r.employee_id, r.name).appendChild(row(r, days[r.date] || r.date, r.sub, dayRight(r)));
                else groupFor('d' + r.date, days[r.date] || r.date).appendChild(row(r, r.name, r.sub, dayRight(r)));
            });
        }
        function drawRate(d) {
            var sec = el('section', 'wfo-ac-day');
            sec.appendChild(el('h3', '', T.by_day));
            var bars = el('ul', 'wfo-ac-bars');
            d.days.forEach(function (x) {
                var li = el('li');
                li.appendChild(el('span', 'wfo-ac-dayname', days[x.date] || x.date));
                var m = el('span', 'wfo-ac-meter'), i = el('i');
                i.style.inlineSize = (x.rate === null ? 0 : x.rate) + '%';
                m.appendChild(i);
                li.appendChild(m);
                li.appendChild(el('b', '', x.rate === null ? '—' : x.rate + '%'));
                bars.appendChild(li);
            });
            sec.appendChild(bars);
            body.appendChild(sec);
            if (d.lowest.length) {
                var low = el('section', 'wfo-ac-day');
                var h = el('h3', '', T.lowest);
                h.appendChild(el('span', '', d.lowest.length));
                low.appendChild(h);
                var ul = el('ul', 'wfo-ac-list');
                d.lowest.forEach(function (p) { ul.appendChild(row(p, p.name, p.sub, [el('span', 'wfo-ac-pct', p.rate + '%'), el('small', 'wfo-ac-of', fmt(T.of_days, p.attended, p.required))])); });
                low.appendChild(ul);
                body.appendChild(low);
                body.appendChild(el('p', 'wfo-ac-hint', T.lowest_hint));
            }
        }
        function message(text, retry) {
            body.textContent = '';
            var p = el('p', 'wfo-ac-empty', text);
            body.appendChild(p);
            if (retry) { var b = el('button', 'wfo-ac-more', T.retry); b.type = 'button'; b.addEventListener('click', function () { load(0); }); body.appendChild(b); }
            foot.hidden = true;
        }
        function setCard(d) {
            // The card follows the list (owner decision 6): someone may have signed in since the page was opened.
            var n = state.opener && state.opener.querySelector('[data-ac-card-n]');
            var v = state.kind === 'rate' ? d.card + '%' : String(d.card);
            if (n && n.textContent !== v) n.textContent = v;
            q('[data-ac-count]').textContent = v;
        }
        function load(offset) {
            if (state.ctrl && state.ctrl.abort) state.ctrl.abort();
            var req = ++state.req;
            state.ctrl = typeof AbortController === 'function' ? new AbortController() : null;
            var args = { action: 'ews_att_cards', _wpnonce: dialog.getAttribute('data-nonce'), kind: state.kind, order: state.order, offset: offset };
            Object.keys(params).forEach(function (k) { args[k] = params[k]; });
            var qs = Object.keys(args).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(args[k]); }).join('&');
            body.setAttribute('aria-busy', 'true');
            if (!offset) message(T.loading, false);
            more.disabled = true;
            fetch(dialog.getAttribute('data-url') + '?' + qs, { credentials: 'same-origin', cache: 'no-store', signal: state.ctrl ? state.ctrl.signal : undefined })
                .then(function (r) { return r.json().catch(function () { return { success: false, data: { reason: 'error' } }; }); })
                .then(function (j) {
                    if (req !== state.req) return;   // an older answer (another card, or the other order)
                    body.removeAttribute('aria-busy');
                    more.disabled = false;
                    if (!j || !j.success) {
                        var why = j && j.data && j.data.reason;
                        message(why === 'nonce' ? T.expired : (why === 'off' || why === 'access' ? T.unavailable : T.error), why !== 'nonce' && why !== 'off' && why !== 'access');
                        return;
                    }
                    var d = j.data;
                    setCard(d);
                    if (!offset) body.textContent = '';
                    if (state.kind === 'rate') {
                        if (!d.days.some(function (x) { return x.rate !== null; })) { message(T.empty.rate, false); return; }
                        drawRate(d);
                        foot.hidden = true;
                        return;
                    }
                    if (!d.total) { message(T.empty[state.kind], false); return; }
                    if (state.kind === 'late') {
                        note.textContent = d.note ? fmt(T.late_note, d.note.start, d.note.late_after) : T.note.late;
                        note.hidden = false;
                    }
                    drawRows(d);
                    state.shown = d.offset + d.rows.length;
                    state.next = d.next;
                    q('[data-ac-shown]').textContent = fmt(T.shown, state.shown, d.total);
                    more.hidden = d.next === null;
                    foot.hidden = false;
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') return;
                    if (req !== state.req) return;
                    body.removeAttribute('aria-busy');
                    more.disabled = false;
                    message(T.error, true);
                });
        }
        function start(kind) {
            state.kind = kind;
            state.order = 'day';
            dialog.className = 'wfo-sheet wfo-ac-sheet is-' + kind;
            var ico = q('[data-ac-ico]');
            ico.textContent = '';
            ico.appendChild(icon(ICON[kind]));
            q('[data-ac-title]').textContent = T.title[kind];
            q('[data-ac-sub]').textContent = T.sub[kind];
            q('[data-ac-count]').textContent = '';
            seg.hidden = kind === 'people' || kind === 'rate';
            seg.querySelectorAll('[data-ac-order]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-ac-order') === 'day' ? 'true' : 'false'); });
            note.hidden = !T.note[kind] || kind === 'late';
            note.textContent = T.note[kind] || '';
            load(0);
        }

        dialog.addEventListener('wfo-sheet-open', function () {
            var opener = dialog._wfoOpener;
            var kind = opener && opener.getAttribute('data-ews-card');
            if (!kind) return;
            state.opener = opener;
            opener.setAttribute('aria-expanded', 'true');
            opener.classList.add('is-open');
            start(kind);
            var x = q('[data-wfo-sheet-close]');
            if (x && !(window.matchMedia && window.matchMedia('(pointer: coarse)').matches)) x.focus();
        });
        dialog.addEventListener('close', function () {
            if (state.ctrl && state.ctrl.abort) state.ctrl.abort();
            state.req++;
            if (state.opener) { state.opener.setAttribute('aria-expanded', 'false'); state.opener.classList.remove('is-open'); }
            body.textContent = '';
            foot.hidden = true;
        });
        seg.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ac-order]');
            if (!b || b.getAttribute('aria-pressed') === 'true') return;
            state.order = b.getAttribute('data-ac-order');
            seg.querySelectorAll('[data-ac-order]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
            load(0);
        });
        more.addEventListener('click', function () { if (state.next !== null) load(state.next); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
