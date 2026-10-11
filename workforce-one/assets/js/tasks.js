/* Employee app → Tasks (templates/app/tasks.php): search, the priority filter, asking before a task is
   deleted, the New task form (who gets it, repeat), the chosen file's name, closing a task or the Edit
   sheet (the page address forgets it), and dragging a card to another column on a computer (it sends
   the same Start / Done / Back form as the buttons; only the allowed moves accept a drop). */
(function () {
    'use strict';

    var NEXT = { todo: ['in_progress'], in_progress: ['todo', 'completed'], completed: ['in_progress'] };

    function init() {
        var page = document.querySelector('.wfo-tk');
        if (!page) return;

        var search = document.getElementById('wfo-tk-search');
        var none = page.querySelector('.wfo-tk-noresult');
        if (search) search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase(), shown = 0;
            page.querySelectorAll('.wfo-tk-card').forEach(function (c) {
                var hit = !q || (c.getAttribute('data-ews-task') || '').indexOf(q) !== -1;
                c.hidden = !hit;
                if (hit) shown++;
            });
            if (none) none.hidden = shown > 0 || !q;
        });

        page.querySelectorAll('[data-ews-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () { if (el.form) el.form.submit(); });
        });

        page.querySelectorAll('form[data-ews-task-delete]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!window.confirm(form.getAttribute('data-ews-task-delete'))) e.preventDefault();
            });
        });

        // One menu open at a time; a tap elsewhere closes it.
        var menus = Array.prototype.slice.call(page.querySelectorAll('.wfo-tk-more'));
        menus.forEach(function (d) {
            d.addEventListener('toggle', function () { if (d.open) menus.forEach(function (o) { if (o !== d) o.open = false; }); });
        });
        document.addEventListener('click', function (e) {
            menus.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
        });

        // New task: show the fields of the chosen "Assign To" and "Repeat".
        page.querySelectorAll('.wfo-tk-form').forEach(function (form) {
            function sync() {
                var kind = form.querySelector('input[name="assign_kind"]:checked');
                var rep = form.querySelector('input[name="repeat"]:checked');
                form.querySelectorAll('[data-ews-for-kind]').forEach(function (el) {
                    var on = kind && el.getAttribute('data-ews-for-kind') === kind.value;
                    el.hidden = !on;
                    el.querySelectorAll('select,input').forEach(function (i) { i.disabled = !on; });
                });
                form.querySelectorAll('[data-ews-for-repeat]').forEach(function (el) {
                    var on = rep && el.getAttribute('data-ews-for-repeat') === rep.value;
                    el.hidden = !on;
                    el.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
                });
            }
            form.addEventListener('change', sync);
            sync();
        });

        page.querySelectorAll('.wfo-tk-attach input[type=file]').forEach(function (input) {
            var label = input.parentNode.querySelector('[data-ews-file-label]'), first = label ? label.textContent : '';
            input.addEventListener('change', function () {
                if (label) label.textContent = input.files && input.files[0] ? input.files[0].name : first;
            });
        });

        // Closing a task or the Edit sheet: the address forgets it, so a reload does not open it again.
        page.querySelectorAll('dialog[data-ews-forget]').forEach(function (dialog) {
            if (!window.history || !window.URL) return;
            dialog.addEventListener('close', function () {
                var url = new URL(window.location.href);
                url.searchParams.delete(dialog.getAttribute('data-ews-forget'));
                window.history.replaceState(null, '', url.toString());
            });
        });

        // Dragging on a computer.
        var board = page.querySelector('.wfo-tk-board[data-ews-drag]');
        if (!board || (window.matchMedia && !window.matchMedia('(pointer: fine) and (min-width: 901px)').matches)) {
            page.querySelectorAll('.wfo-tk-card[draggable]').forEach(function (c) { c.removeAttribute('draggable'); });
            return;
        }
        var dragged = null;
        board.addEventListener('dragstart', function (e) {
            var card = e.target.closest && e.target.closest('.wfo-tk-card[draggable]');
            if (!card) return;
            dragged = card;
            card.classList.add('is-dragging');
            board.classList.add('is-dragging');
            var from = card.getAttribute('data-ews-status');
            board.querySelectorAll('[data-ews-col]').forEach(function (col) {
                col.classList.toggle('can-drop', (NEXT[from] || []).indexOf(col.getAttribute('data-ews-col')) !== -1);
            });
            if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', card.getAttribute('data-ews-task-id')); }
        });
        board.addEventListener('dragend', function () {
            if (dragged) dragged.classList.remove('is-dragging');
            dragged = null;
            board.classList.remove('is-dragging');
            board.querySelectorAll('[data-ews-col]').forEach(function (col) { col.classList.remove('can-drop', 'is-over'); });
        });
        board.addEventListener('dragover', function (e) {
            var col = e.target.closest && e.target.closest('[data-ews-col].can-drop');
            if (!col || !dragged) return;
            e.preventDefault();
            board.querySelectorAll('[data-ews-col]').forEach(function (c) { c.classList.toggle('is-over', c === col); });
        });
        board.addEventListener('drop', function (e) {
            var col = e.target.closest && e.target.closest('[data-ews-col].can-drop');
            if (!col || !dragged) return;
            e.preventDefault();
            var form = document.createElement('form');
            form.method = 'post';
            form.action = board.getAttribute('data-action');
            var fields = { action: 'ews_task_status_update', task_id: dragged.getAttribute('data-ews-task-id'), status: col.getAttribute('data-ews-col'), _wpnonce: dragged.getAttribute('data-ews-nonce') };
            Object.keys(fields).forEach(function (k) {
                var i = document.createElement('input');
                i.type = 'hidden'; i.name = k; i.value = fields[k];
                form.appendChild(i);
            });
            document.body.appendChild(form);
            form.submit();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
