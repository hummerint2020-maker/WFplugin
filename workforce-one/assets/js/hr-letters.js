/* Employee app → My requests → New request → HR letter (HL3, templates/app/new-request.php; mockup docs/mockups/hr-letter/sheet):
   "Other" shows its field; the languages follow the type (a PDF type only in the languages it has a template for); the default
   addressee follows the language; the line under the form says what happens next; the button is not pressed twice. */
(function () {
    'use strict';
    function init() {
        var form = document.querySelector('[data-hr-letter]');
        if (!form) return;
        var cfg = {};
        try { cfg = JSON.parse(form.getAttribute('data-hr-letter') || '{}'); } catch (e) { cfg = {}; }
        var type = form.querySelector('select[name="type"]'), other = form.querySelector('[data-hl-other]'), otherInput = other ? other.querySelector('input') : null;
        var langs = form.querySelectorAll('input[name="language"]'), to = form.querySelector('input[name="addressee"]'), hint = form.querySelector('[data-hl-hint]');
        function pick() {
            var opt = type.options[type.selectedIndex] || {}, key = type.value, isOther = key === 'other';
            var allowed = isOther ? (cfg.languages || []) : ((opt.getAttribute && opt.getAttribute('data-languages')) || '').split(',');
            if (other) { other.hidden = !isOther; if (otherInput) otherInput.required = isOther; }
            var first = null, checked = null;
            langs.forEach(function (r) {
                var ok = allowed.indexOf(r.value) !== -1;
                r.disabled = !ok; r.closest('label').classList.toggle('is-off', !ok);
                if (ok && !first) first = r;
                if (ok && r.checked) checked = r;
            });
            if (!checked && first) first.checked = true;
            lang();
            var delivery = isOther ? 'upload' : (opt.getAttribute ? opt.getAttribute('data-delivery') : '');
            var text = (cfg.hints || {})[delivery] || '';
            if (!isOther && opt.getAttribute && opt.getAttribute('data-salary') === '1') text += ' ' + (cfg.salary || '');
            if (hint) { hint.textContent = text; hint.hidden = text === ''; }
        }
        function lang() {
            var r = form.querySelector('input[name="language"]:checked');
            if (to && r) to.placeholder = (cfg.addressee || {})[r.value] || '';
        }
        type.addEventListener('change', pick);
        langs.forEach(function (r) { r.addEventListener('change', lang); });
        form.addEventListener('submit', function () { var b = form.querySelector('[type="submit"]'); if (b) setTimeout(function () { b.disabled = true; }, 0); });
        pick();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
