/* wp-admin → HR letters → Types & templates (HL3): a placeholder chip puts its {token} where the cursor is in the template last used;
   a mistyped placeholder is shown before saving (the server refuses it too). */
(function () {
    'use strict';
    var last = null;
    document.addEventListener('focusin', function (e) { if (e.target.matches && e.target.matches('textarea[data-hl-tpl]')) last = e.target; });
    document.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-hl-chip]');
        if (!chip) return;
        e.preventDefault();
        var area = last || document.querySelector('textarea[data-hl-tpl]');
        if (!area) return;
        var t = chip.getAttribute('data-hl-chip'), s = area.selectionStart || 0, en = area.selectionEnd || 0;
        area.value = area.value.slice(0, s) + t + area.value.slice(en);
        area.focus();
        area.selectionStart = area.selectionEnd = s + t.length;
        check(area);
    });
    function check(area) {
        var known = (area.getAttribute('data-hl-known') || '').split(','), bad = [], m, re = /\{([^{}]*)\}/g;
        while ((m = re.exec(area.value))) { if (!/^g:[^|]*\|[^|]*$/.test(m[1]) && known.indexOf(m[1]) === -1) bad.push('{' + m[1] + '}'); }
        var out = area.parentNode.querySelector('[data-hl-bad]');
        if (out) { out.textContent = bad.length ? (out.getAttribute('data-hl-bad') + ' ' + bad.join(' ')) : ''; out.hidden = !bad.length; }
    }
    document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('textarea[data-hl-tpl]')) check(e.target); });
})();
