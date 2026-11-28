/* System messages (3.31.166): the edit panel's placeholder buttons and live preview. No texts of its own. */
(function () {
    'use strict';
    var box = document.querySelector('[data-sm-editor]');
    if (!box) return;
    var ta = box.querySelector('[data-sm-text]');
    var pv = box.querySelector('[data-sm-preview]');
    var labels = {};
    try { labels = JSON.parse(pv.getAttribute('data-labels') || '{}') || {}; } catch (e) { labels = {}; }
    var kind = box.getAttribute('data-kind') || 'ui';
    var token = /%(?:(\d+)\$)?[-+ 0'#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/g;

    function el(tag, cls) { var e = document.createElement(tag); if (cls) e.className = cls; return e; }

    /* The text with each placeholder shown by its name, in bold; tags are shown as text (never as HTML). */
    function fill(target, text) {
        var n = 0, last = 0, m;
        target.textContent = '';
        token.lastIndex = 0;
        while ((m = token.exec(text)) !== null) {
            target.appendChild(document.createTextNode(text.slice(last, m.index)));
            var key = m[1] ? m[0] : '%' + (++n) + '$' + m[0].slice(1);
            var b = el('b');
            b.textContent = labels[key] || labels[m[0]] || m[0];
            target.appendChild(b);
            last = token.lastIndex;
        }
        target.appendChild(document.createTextNode(text.slice(last).replace(/<\/?[a-zA-Z][^<>]*>/g, '')));
    }

    function render() {
        if (!pv) return;
        var text = (ta && ta.value.trim()) || (ta ? ta.getAttribute('placeholder') : '') || '';
        pv.textContent = '';
        var card;
        if (kind === 'notification' || kind === 'notification_title') {
            card = el('div', 'ews-sm-push');
            var app = el('div'); app.style.fontSize = '11px'; app.style.color = '#50575e'; app.textContent = 'Workforce One';
            var p = el('div');
            fill(p, text);
            card.appendChild(app); card.appendChild(p);
        } else {
            card = el('div', kind === 'banner' ? 'ews-sm-strip' : (kind === 'error' ? 'ews-sm-err' : 'button button-primary'));
            fill(card, text);
        }
        if (ta) card.setAttribute('dir', ta.getAttribute('dir') || 'auto');
        pv.appendChild(card);
    }

    box.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-sm-insert]') : null;
        if (!b || !ta) return;
        e.preventDefault();
        var s = ta.selectionStart || 0, t = ta.selectionEnd || 0, v = b.getAttribute('data-sm-insert');
        ta.value = ta.value.slice(0, s) + v + ta.value.slice(t);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = s + v.length;
        render();
    });
    if (ta) ta.addEventListener('input', render);
    render();
}());
