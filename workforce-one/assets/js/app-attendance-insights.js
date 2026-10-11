/*
 * Employee app → Attendance Insights (templates/app/attendance-insights.php): every count with
 * data-names opens a drawer listing the employees behind it; team and day pickers apply at once.
 */
(function () {
    'use strict';

    function init() {
        document.querySelectorAll('.ews-fi [data-ews-autosubmit]').forEach(function (field) {
            field.addEventListener('change', function () { if (field.form) field.form.submit(); });
        });

        var drawer = document.getElementById('ews-fi-drawer');
        if (!drawer) return;
        var list = document.getElementById('ews-fi-names');
        var title = document.getElementById('ews-fi-title');
        var subtitle = document.getElementById('ews-fi-subtitle');

        function open(button) {
            var names = [];
            try { names = JSON.parse(button.getAttribute('data-names') || '[]'); } catch (e) { names = []; }
            title.textContent = button.getAttribute('data-label') || title.textContent;
            subtitle.textContent = button.getAttribute('data-date') || '';
            list.innerHTML = '';
            if (!names.length) {
                var empty = document.createElement('div');
                empty.className = 'ews-fi-empty';
                empty.textContent = drawer.getAttribute('data-empty') || '';
                list.appendChild(empty);
            }
            names.forEach(function (name) {
                var row = document.createElement('div');
                row.className = 'ews-fi-name';
                row.textContent = name;
                list.appendChild(row);
            });
            drawer.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
        }

        function close() {
            drawer.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
        }

        document.addEventListener('click', function (e) {
            var button = e.target.closest && e.target.closest('.ews-fi [data-names]');
            if (button) { open(button); return; }
            if ((e.target.closest && e.target.closest('.ews-fi-close')) || e.target === drawer) close();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
