/* Employee app → a colleague's profile (templates/app/employee.php): show / hide the Kudos form. */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var button = e.target.closest && e.target.closest('[data-wfo-kudos]');
        var form = document.getElementById('wfo-kudos-form');
        if (!button || !form) return;
        form.hidden = button.getAttribute('data-wfo-kudos') === 'close' ? true : !form.hidden;
        if (!form.hidden) form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
})();
