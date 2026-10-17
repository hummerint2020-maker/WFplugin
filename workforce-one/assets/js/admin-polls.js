/* wp-admin → Employee Polls (templates/admin/polls.php): add / remove choices (at least two), and
   confirm before archiving. */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('a[data-ews-confirm]') : null;
        if (link && !window.confirm(link.getAttribute('data-ews-confirm'))) e.preventDefault();
    });
    function init() {
        var box = document.getElementById('wfo-poll-options'), add = document.getElementById('wfo-poll-add');
        if (!box || !add) return;
        add.addEventListener('click', function () {
            var row = document.createElement('div'), input = document.createElement('input'), remove = document.createElement('button');
            row.className = 'wfo-poll-option-row';
            input.type = 'text'; input.name = 'options[]'; input.maxLength = 255; input.required = true; input.placeholder = 'Choice'; input.setAttribute('aria-label', 'Choice');
            remove.type = 'button'; remove.className = 'button wfo-poll-remove'; remove.textContent = 'Remove';
            row.appendChild(input); row.appendChild(remove); box.appendChild(row); input.focus();
        });
        box.addEventListener('click', function (e) {
            if (!e.target.classList.contains('wfo-poll-remove')) return;
            if (box.querySelectorAll('.wfo-poll-option-row').length > 2) e.target.parentNode.remove();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
