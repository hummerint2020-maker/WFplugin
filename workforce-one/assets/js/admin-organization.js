/* wp-admin → Departments and Teams (templates/admin/departments.php, teams.php): confirm Archive, and
   on the team form list only the chosen department's employees as manager and members. */
(function () {
    'use strict';
    document.addEventListener('submit', function (e) {
        var msg = e.target && e.target.getAttribute ? e.target.getAttribute('data-ews-confirm') : null;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
    function filter(form) {
        var dep = form.querySelector('.ews-team-department');
        if (!dep) return;
        var value = dep.value;
        form.querySelectorAll('[data-department]').forEach(function (el) {
            var show = !value || el.getAttribute('data-department') === value;
            el.hidden = !show;
            if (!show && el.tagName === 'OPTION' && el.selected) el.parentNode.value = '0';
            var box = el.tagName === 'LABEL' ? el.querySelector('input[type=checkbox]') : null;
            if (box && !show) box.checked = false;
        });
    }
    function init() {
        document.querySelectorAll('.ews-team-form').forEach(function (form) {
            var dep = form.querySelector('.ews-team-department');
            if (dep) dep.addEventListener('change', function () { filter(form); });
            filter(form);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
