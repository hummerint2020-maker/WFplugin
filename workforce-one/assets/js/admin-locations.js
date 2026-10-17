/* wp-admin → Work Locations (templates/admin/locations.php): confirm before archiving a location. */
(function () {
    'use strict';
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form && form.getAttribute ? form.getAttribute('data-ews-confirm') : null;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
})();
