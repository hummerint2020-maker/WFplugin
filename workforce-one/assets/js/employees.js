/* Employee app → Employees (templates/app/employees.php): search by name, user name or email. */
(function () {
    'use strict';
    function init() {
        var search = document.getElementById('wfo-emps-search');
        var none = document.querySelector('.wfo-emps-none');
        if (!search) return;
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase(), shown = 0;
            document.querySelectorAll('.wfo-emps-rows li').forEach(function (li) {
                var hit = !q || (li.getAttribute('data-ews-emp') || '').indexOf(q) !== -1;
                li.hidden = !hit;
                if (hit) shown++;
            });
            if (none) none.hidden = shown > 0;
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
