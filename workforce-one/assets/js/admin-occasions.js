/* wp-admin → Feature Configuration → Occasions (3.31.145): the picked occasion and mode are marked; the words' picker shows one
   occasion's boxes; the preview card follows what is typed (the page's language, else the other one). */
(function () {
    'use strict';
    var root = document.getElementById('ews-occasions');
    if (!root) return;
    function mark(name, cls) {
        root.querySelectorAll('input[name="' + name + '"]').forEach(function (r) { var l = r.closest('.' + cls); if (l) l.classList.toggle('is-on', r.checked); });
    }
    root.addEventListener('change', function (e) {
        if (e.target.name === 'occ_active') mark('occ_active', 'occ-pick');
        if (e.target.name === 'occ_mode') mark('occ_mode', 'occ-mode');
        if (e.target.matches('[data-occ-text-pick]')) {
            root.querySelectorAll('[data-occ-text-set]').forEach(function (s) { s.hidden = s.getAttribute('data-occ-text-set') !== e.target.value; });
        }
    });
    var rtl = document.documentElement.dir === 'rtl';
    function preview(set) {
        ['card_title', 'card_line'].forEach(function (f) {
            var ar = set.querySelector('input[name$="[' + f + '][ar]"]'), en = set.querySelector('input[name$="[' + f + '][en]"]'), out = set.querySelector('[data-occ-preview="' + f + '"]');
            if (!out) return;
            if (!out.hasAttribute('data-default')) out.setAttribute('data-default', out.textContent);
            var mine = rtl ? (ar.value || en.value) : (en.value || ar.value);
            out.textContent = mine.trim() !== '' ? mine : out.getAttribute('data-default');
        });
    }
    root.addEventListener('input', function (e) { var set = e.target.closest('[data-occ-text-set]'); if (set) preview(set); });
    root.querySelectorAll('[data-occ-text-set]').forEach(preview);
})();
