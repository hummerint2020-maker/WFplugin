/* Skeleton loading (3.31.168, docs/tasks/skeleton-loading.md). The app's views are whole pages: after a menu tap
   (side menu, bottom bar, More) the old page stays still until the next one arrives. With the switch on, the menu links
   carry data-wfo-skel (src/Ui/Skeleton.php) and, when the next page takes longer than DELAY, .wfo-content shows a grey
   outline of that view instead. The page underneath is only hidden, never removed: Back (bfcache) gets it as it was.
   window.wfoSkeleton.rows() draws the same rows for lists loaded in place (assets/js/attendance-cards.js).
   No text: the only words are the screen reader's "Loading…", read from data-wfo-skel-label. */
(function () {
    'use strict';
    var DELAY = 150, GIVE_UP = 15000;
    var timer = 0, giveUp = 0, saved = null;

    function box(cls, parent) {
        var d = document.createElement('div');
        d.className = cls;
        if (parent) parent.appendChild(d);
        return d;
    }
    function bone(cls, parent) { return box('wfo-skel ' + cls, parent); }
    function lines(parent, a, b) {
        var g = box('wfo-skel-grow', parent);
        bone('wfo-skel-line wfo-skel-w' + a, g);
        if (b) bone('wfo-skel-line wfo-skel-w' + b, g);
        return g;
    }
    function chips(root, n) { var c = box('wfo-skel-chips', root); for (var i = 0; i < n; i++) bone('wfo-skel-chip', c); }
    function kpis(root) { var k = box('wfo-skel-kpis', root); for (var i = 0; i < 3; i++) bone('wfo-skel-kpi', k); }

    var SHAPES = {
        list: function (root) {
            chips(root, 3);
            for (var i = 0; i < 5; i++) {
                var r = box('wfo-skel-card wfo-skel-row', root);
                bone('wfo-skel-ico', r); lines(r, i % 2 ? 40 : 60, 80); bone('wfo-skel-pill', r);
            }
        },
        approvals: function (root) {
            kpis(root); chips(root, 3);
            for (var i = 0; i < 3; i++) {
                var c = box('wfo-skel-card', root), r = box('wfo-skel-row', c);
                bone('wfo-skel-av', r); lines(r, 40, 60); bone('wfo-skel-pill', r);
                var b = box('wfo-skel-btns', c); bone('wfo-skel-btn', b); bone('wfo-skel-btn', b);
            }
        },
        table: function (root) {
            chips(root, 2);
            var t = box('wfo-skel-card wfo-skel-table', root);
            for (var i = 0; i < 8; i++) {
                bone('wfo-skel-line wfo-skel-w' + (i ? (i % 2 ? 60 : 80) : 40), t);
                for (var j = 0; j < 6; j++) bone('wfo-skel-cell' + (i ? '' : ' wfo-skel-head'), t);
            }
        },
        generic: function (root) {
            kpis(root);
            for (var i = 0; i < 3; i++) { var c = box('wfo-skel-card', root); lines(c, 40, i % 2 ? 60 : 80); }
        }
    };

    function srText(container, after) {
        var s = document.createElement('span');
        s.className = 'screen-reader-text wfo-skel-sr';
        s.setAttribute('role', 'status');
        s.textContent = (document.querySelector('[data-wfo-skel-label]') || { getAttribute: function () { return ''; } }).getAttribute('data-wfo-skel-label') || '';
        // After the busy region, not in it: some screen readers hold back what changes inside aria-busy.
        if (after && container.parentNode) container.parentNode.insertBefore(s, container.nextSibling); else container.appendChild(s);
    }

    function show(content, shape) {
        if (content.querySelector('.wfo-skel-root')) return;
        var root = box('wfo-skel-root');
        root.setAttribute('aria-hidden', 'true');
        (SHAPES[shape] || SHAPES.generic)(root);
        content.appendChild(root);
        srText(content, true);
        content.classList.add('is-skel');
        content.setAttribute('aria-busy', 'true');
        window.scrollTo(0, 0);
    }

    function restore() {
        clearTimeout(timer); clearTimeout(giveUp);
        var content = document.querySelector('.wfo-content.is-skel');
        if (content) {
            content.querySelectorAll('.wfo-skel-root').forEach(function (n) { n.remove(); });
            content.classList.remove('is-skel');
            content.removeAttribute('aria-busy');
        }
        document.querySelectorAll('.wfo-skel-sr').forEach(function (n) { if (!n.closest('.wfo-skel-rows-host')) n.remove(); });
        if (saved) {
            document.querySelectorAll('a[data-wfo-skel].active').forEach(function (a) { a.classList.remove('active'); a.removeAttribute('aria-current'); });
            saved.forEach(function (a) { a.classList.add('active'); if (a.tagName === 'A') a.setAttribute('aria-current', 'page'); });
            saved = null;
        }
    }

    // The tapped item is the active one at once (all its copies: side menu, bottom bar, More).
    function mark(link) {
        var all = document.querySelectorAll('a[data-wfo-skel]');
        saved = Array.prototype.filter.call(document.querySelectorAll('a[data-wfo-skel],[data-wfo-more-open]'), function (a) { return a.classList.contains('active'); });
        document.querySelectorAll('[data-wfo-more-open].active').forEach(function (b) { b.classList.remove('active'); });
        Array.prototype.forEach.call(all, function (a) {
            var on = a.href === link.href;
            a.classList.toggle('active', on);
            if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var link = e.target.closest ? e.target.closest('a[data-wfo-skel]') : null;
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        var url;
        try { url = new URL(link.href, window.location.href); } catch (err) { return; }
        if (url.origin !== window.location.origin) return;
        if (url.hash && url.pathname === window.location.pathname && url.search === window.location.search) return;
        var content = document.querySelector('.wfo-content[data-wfo-skel-label]');
        if (!content) return;
        restore();
        mark(link);
        var more = link.closest('dialog');
        if (more && more.open) { if (typeof more.close === 'function') more.close(); else more.removeAttribute('open'); }
        var shape = link.getAttribute('data-wfo-skel');
        timer = setTimeout(function () { show(content, shape); }, DELAY);
        // A page that never comes (the tap was refused, a "leave this page?" was answered No): back as it was.
        giveUp = setTimeout(restore, GIVE_UP);
    });

    // "Leave this page?" answered Stay (assets/js/attendance-grid.js asks with unsaved cells): the page is not left, so
    // nothing is drawn. Checked after every listener has run; the 0 ms callback comes before the overdue DELAY timer.
    window.addEventListener('beforeunload', function (e) {
        setTimeout(function () {
            if (e.defaultPrevented || (e.returnValue !== undefined && e.returnValue !== '' && e.returnValue !== true)) restore();
        }, 0);
    });

    // Back / Forward from the browser's page cache: the page as it was left.
    window.addEventListener('pageshow', function (e) { if (e.persisted) restore(); });

    window.wfoSkeleton = {
        /* Grey rows (avatar and two lines) in place of a list that is loading; the caller empties the container
           when the rows arrive. */
        rows: function (container, n) {
            container.textContent = '';
            var wrap = box('wfo-skel-rows', container);
            wrap.setAttribute('aria-hidden', 'true');
            for (var i = 0; i < (n || 5); i++) {
                var r = box('wfo-skel-row', wrap);
                bone('wfo-skel-av', r); lines(r, i % 2 ? 40 : 60, 80);
            }
            container.classList.add('wfo-skel-rows-host');
            srText(container);
        }
    };
})();
