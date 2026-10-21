/*
 * wp-admin → Attendance Insights: every count with data-names opens a drawer listing the
 * employees behind it (weekly table, overview tiles, attendance buttons, distribution rows).
 */
(function () {
    var drawer = document.getElementById('ews-ai-drawer');
    if (!drawer) return;
    var list = document.getElementById('ews-ai-names');
    var title = document.getElementById('ews-ai-title');
    var subtitle = document.getElementById('ews-ai-subtitle');

    function open(button) {
        var names = [];
        try { names = JSON.parse(button.getAttribute('data-names') || '[]'); } catch (e) { names = []; }
        title.textContent = (button.getAttribute('data-label') || 'Employees') + (names.length ? ' · ' + names.length : '');
        subtitle.textContent = button.getAttribute('data-date') || '';
        list.innerHTML = '';
        if (!names.length) {
            var empty = document.createElement('div');
            empty.className = 'ews-ai-empty';
            empty.textContent = 'No employees in this group.';
            list.appendChild(empty);
        }
        names.forEach(function (name) {
            var row = document.createElement('div');
            row.className = 'ews-ai-name';
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
        var button = e.target.closest && e.target.closest('[data-names]');
        if (button) { open(button); return; }
        if ((e.target.closest && e.target.closest('.ews-ai-close')) || e.target === drawer) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
