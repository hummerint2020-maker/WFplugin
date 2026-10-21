/* wp-admin → Approval Workflows (templates/admin/approvals.php): show the help, levels and approver
   fields that the chosen mode and approver source need, each level's "only if above" amount, and the
   path the request takes (3.31.100: up to four levels). */
(function () {
    'use strict';
    function sync(card) {
        var mode = card.querySelector('.ews-approval-mode');
        if (!mode) return;
        var value = (mode.value || 'NONE').toUpperCase();
        card.querySelectorAll('.ews-approval-help [data-mode]').forEach(function (el) { el.style.display = el.getAttribute('data-mode') === value ? 'block' : 'none'; });
        card.querySelectorAll('[data-visible-modes]').forEach(function (el) {
            var modes = (el.getAttribute('data-visible-modes') || '').split(/\s+/);
            el.style.display = modes.indexOf(value) >= 0 ? '' : 'none';
        });
        card.querySelectorAll('.ews-approval-level').forEach(function (row) {
            var source = row.querySelector('.ews-approval-source'), type = source ? (source.value || '').toUpperCase() : '';
            var emp = row.querySelector('.ews-specific-employee'), user = row.querySelector('.ews-specific-user');
            if (emp) emp.style.display = type === 'SPECIFIC_EMPLOYEE' ? 'block' : 'none';
            if (user) user.style.display = type === 'SPECIFIC_USER' ? 'block' : 'none';
            var when = row.querySelector('.ews-approval-when'), min = row.querySelector('.ews-approval-min');
            var above = !!(when && when.value === 'above');
            if (min) min.style.display = above ? '' : 'none';
            var item = card.querySelector('[data-path-level="' + row.getAttribute('data-level') + '"]');
            if (!item) return;
            var who = item.querySelector('[data-path-who]'), cond = item.querySelector('[data-path-when]');
            if (who && source) who.textContent = source.options[source.selectedIndex] ? source.options[source.selectedIndex].text : '';
            if (cond) {
                var n = min ? min.querySelector('input') : null, unit = min ? (min.textContent || '').trim() : '';
                cond.textContent = above && n && n.value !== '' ? 'only if above ' + n.value + ' ' + unit + '; skipped otherwise' : '';
            }
        });
    }
    function init() {
        document.querySelectorAll('.ews-approval-card').forEach(function (card) {
            card.addEventListener('change', function () { sync(card); });
            card.addEventListener('input', function () { sync(card); });
            sync(card);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
