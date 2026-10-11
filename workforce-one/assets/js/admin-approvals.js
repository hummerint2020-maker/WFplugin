/* wp-admin → Approval Workflows (templates/admin/approvals.php): show the help, levels and approver
   fields that the chosen mode and approver source need. */
(function () {
    'use strict';
    function sync(card) {
        var mode = card.querySelector('.ews-approval-mode');
        if (!mode) return;
        var value = (mode.value || 'NONE').toUpperCase();
        card.querySelectorAll('.ews-approval-help [data-mode]').forEach(function (el) { el.style.display = el.getAttribute('data-mode') === value ? 'block' : 'none'; });
        card.querySelectorAll('.ews-approval-level').forEach(function (level) {
            var modes = (level.getAttribute('data-visible-modes') || '').split(/\s+/);
            level.style.display = modes.indexOf(value) >= 0 ? 'block' : 'none';
        });
        card.querySelectorAll('.ews-approval-source').forEach(function (source) {
            var row = source.closest('.ews-approval-level'), type = (source.value || '').toUpperCase();
            var emp = row ? row.querySelector('.ews-specific-employee') : null, user = row ? row.querySelector('.ews-specific-user') : null;
            if (emp) emp.style.display = type === 'SPECIFIC_EMPLOYEE' ? 'block' : 'none';
            if (user) user.style.display = type === 'SPECIFIC_USER' ? 'block' : 'none';
        });
    }
    function init() {
        document.querySelectorAll('.ews-approval-card').forEach(function (card) {
            card.querySelectorAll('.ews-approval-mode, .ews-approval-source').forEach(function (el) { el.addEventListener('change', function () { sync(card); }); });
            sync(card);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
