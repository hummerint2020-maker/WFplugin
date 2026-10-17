/* wp-admin → People → Org chart (3.31.97, templates/admin/org-chart.php).
   Draws the chart from the page's data: the heads open, every other branch drawn when it is opened,
   a person panel, search (opens the branches with a match) and zoom. Narrow screens browse one
   person at a time, as the app's My team. */
(function () {
  'use strict';
  var root = document.getElementById('wfo-org'), src = document.getElementById('wfo-org-data');
  if (!root || !src) return;
  var D = JSON.parse(src.textContent || '{}'), T = D.i18n || {};
  var by = {}, kids = {}, people = D.people || [];
  people.forEach(function (p) { by[p.id] = p; });
  people.forEach(function (p) { if (p.m && by[p.m]) (kids[p.m] = kids[p.m] || []).push(p.id); });
  var tops = people.filter(function (p) { return !p.m && (kids[p.id] || []).length; }).map(function (p) { return p.id; });
  var wide = {}; (D.wide || []).forEach(function (id) { wide[id] = 1; });
  var tree = document.getElementById('wfo-org-tree'), panel = document.getElementById('wfo-org-panel'), qIn = document.getElementById('wfo-org-q');
  var open = {}, q = '', zoom = 1, sel = tops[0] || (people[0] && people[0].id) || 0, focus = sel;
  tops.forEach(function (id) { open[id] = 1; });
  var narrow = window.matchMedia ? window.matchMedia('(max-width: 720px)') : { matches: false };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function dept(p) { var d = D.departments[p.d]; return d ? d : null; }
  function dcls(p) { var d = dept(p); return d ? 'd' + d.n : 'dx'; }
  function dname(p) { var d = dept(p); return d ? d.name : T.noDept; }
  function ini(n) { return String(n).trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join(''); }
  function av(p, size) {
    var c = 'wfo-org-av' + (size ? ' is-' + size : '') + ' c' + (p.id % 8);
    return p.p ? '<img class="' + c + '" src="' + esc(p.p) + '" alt="">' : '<span class="' + c + '" aria-hidden="true">' + esc(ini(p.n)) + '</span>';
  }
  function hit(p) { return !q || (p.n + ' ' + p.t).toLowerCase().indexOf(q) > -1; }
  var subMemo = {};
  function subHit(id) {
    if (subMemo[id] !== undefined) return subMemo[id];
    subMemo[id] = false;
    return (subMemo[id] = hit(by[id]) || (kids[id] || []).some(subHit));
  }
  function isOpen(id) { return q ? subHit(id) : !!open[id]; }
  function chain(id) { var c = [], seen = {}; id = by[id] ? by[id].m : 0; while (id && by[id] && !seen[id]) { seen[id] = 1; c.unshift(id); id = by[id].m; } return c; }
  function chev() { return document.dir === 'rtl' || document.documentElement.dir === 'rtl' ? '‹' : '›'; }

  /* ---- the chart */
  function card(id) {
    var p = by[id], k = kids[id] || [], o = isOpen(id);
    return '<div class="wfo-org-pc ' + dcls(p) + (id === sel ? ' is-sel' : '') + (q && !hit(p) ? ' is-dim' : '') + '" data-sel="' + id + '" role="button" tabindex="0"><span class="wfo-org-top"></span>' + av(p)
      + '<strong>' + esc(p.n) + '</strong>' + (p.t ? '<small>' + esc(p.t) + '</small>' : '') + '<span class="wfo-org-dept">' + esc(dname(p)) + '</span>'
      + (wide[id] ? '<span class="wfo-org-flag" title="' + esc(T.wide.replace('%d', D.wideSpan)) + '">!</span>' : '')
      + (k.length ? '<button type="button" class="wfo-org-more" data-open="' + id + '" aria-expanded="' + o + '">' + (o ? '−' : '+') + ' ' + k.length + ' ' + esc(T.direct) + (p.u > k.length ? ' · ' + p.u + ' ' + esc(T.total) : '') + '</button>' : '')
      + '</div>';
  }
  function leaves(ids) {
    return '<div class="wfo-org-stack">' + ids.map(function (id) {
      var p = by[id];
      return '<button type="button" class="wfo-org-leaf ' + dcls(p) + (id === sel ? ' is-sel' : '') + (q && !hit(p) ? ' is-dim' : '') + '" data-sel="' + id + '">' + av(p, 's') + '<span><b>' + esc(p.n) + '</b>' + (p.t ? '<small>' + esc(p.t) + '</small>' : '') + '</span></button>';
    }).join('') + '</div>';
  }
  function branch(id) {
    var k = kids[id] || [], h = '<li>' + card(id);
    if (k.length && isOpen(id)) {
      var shown = q ? k.filter(subHit) : k;
      h += shown.every(function (c) { return !(kids[c] || []).length; }) ? leaves(shown) : '<ul>' + shown.map(branch).join('') + '</ul>';
    }
    return h + '</li>';
  }
  function drawTree() {
    subMemo = {};
    var heads = q ? tops.filter(subHit) : tops;
    if (!tops.length) { tree.innerHTML = '<p class="wfo-org-empty">' + esc(T.empty) + '</p>'; return; }
    if (!heads.length) { tree.innerHTML = '<p class="wfo-org-empty">' + esc(T.noMatch) + '</p>'; return; }
    tree.innerHTML = '<div class="wfo-org-chart" style="transform:scale(' + zoom + ')"><ul>' + heads.map(branch).join('') + '</ul></div>';
  }

  /* ---- the person panel */
  function drawPanel() {
    var p = by[sel];
    if (!p) { panel.hidden = true; return; }
    panel.hidden = false;
    var k = kids[sel] || [], c = chain(sel), m = by[p.m];
    panel.innerHTML = '<div class="wfo-org-who ' + dcls(p) + '">' + av(p, 'l') + '<strong>' + esc(p.n) + '</strong>' + (p.t ? '<small>' + esc(p.t) + '</small>' : '') + '</div>'
      + (wide[sel] ? '<p class="wfo-org-note">' + esc(T.wide.replace('%d', D.wideSpan)) + '</p>' : '')
      + '<dl class="wfo-org-facts"><div><dt>' + esc(T.department) + '</dt><dd>' + esc(dname(p)) + '</dd></div>'
      + '<div><dt>' + esc(T.manager) + '</dt><dd>' + (m ? '<button type="button" class="button-link" data-sel="' + m.id + '">' + esc(m.n) + '</button>' : esc(T.none)) + '</dd></div>'
      + '<div><dt>' + esc(T.reports) + '</dt><dd>' + k.length + '</dd></div><div><dt>' + esc(T.under) + '</dt><dd>' + (p.u || 0) + '</dd></div></dl>'
      + (c.length ? '<div><div class="wfo-org-label">' + esc(T.line) + '</div><div class="wfo-org-line">' + c.map(function (i) { return '<button type="button" data-sel="' + i + '" title="' + esc(by[i].n) + '">' + av(by[i], 's') + '</button>'; }).join('<span class="sep">' + chev() + '</span>') + '<span class="sep">' + chev() + '</span>' + av(p, 's') + '</div></div>' : '')
      + historyHtml(sel)
      + '<div class="wfo-org-btns"><a class="button" href="' + esc(D.profile + p.id) + '">' + esc(T.profile) + '</a>' + moveBtn(p) + '</div>';
  }
  function moveBtn(p) {
    return D.canMove ? '<button type="button" class="button button-primary" data-move="' + p.id + '">' + esc(T.move) + '</button>'
      : '<a class="button button-primary" href="' + esc(D.setManager + encodeURIComponent(p.n)) + '">' + esc(T.move) + '</a>';
  }
  function nameOf(id) { return id ? (by[id] ? by[id].n : (D.otherNames[id] || '#' + id)) : T.noManager; }
  /* The person's moves: scheduled ones first, then the last ones done. */
  function historyHtml(id) {
    var rows = (D.scheduled || []).filter(function (m) { return m.e === id; }).map(function (m) {
      return '<li class="is-next"><b>' + esc(T.scheduledOn.replace('%s', m.d)) + '</b>' + esc(nameOf(m.f)) + ' → ' + esc(nameOf(m.t)) + (m.r ? '<small dir="auto">' + esc(m.r) + '</small>' : '') + '</li>';
    }).concat(((D.history || {})[id] || []).map(function (m) {
      return '<li><b>' + esc(m.d) + '</b>' + esc(nameOf(m.f)) + ' → ' + esc(nameOf(m.t)) + '<small dir="auto">' + esc([m.r, (D.sources || {})[m.s] || m.s].filter(Boolean).join(' · ')) + '</small></li>';
    }));
    return rows.length ? '<div><div class="wfo-org-label">' + esc(T.history) + '</div><ul class="wfo-org-hist">' + rows.join('') + '</ul></div>' : '';
  }

  /* ---- the Move dialog: the new manager, from a date, a reason; what else moves is said before saving */
  var dlg = document.getElementById('wfo-org-dlg'), dform = dlg ? dlg.querySelector('form') : null, moving = 0, lastFocus = null;
  function under(id) { var out = {}, st = (kids[id] || []).slice(); while (st.length) { var x = st.pop(); if (out[x]) continue; out[x] = 1; st = st.concat(kids[x] || []); } return out; }
  function pickId(v) { var m = /#(\d+)\s*$/.exec(v || ''); return m ? +m[1] : 0; }
  function openMove(id) {
    var p = by[id];
    if (!dlg || !p) return false;
    moving = id; lastFocus = document.activeElement;
    dform.employee_id.value = id;
    dform.manager_ref.value = ''; dform.no_manager.checked = false; dform.reason.value = ''; dform.effective_date.value = D.today;
    dlg.querySelector('#wfo-org-dlg-t').textContent = T.moveTitle.replace('%s', p.n);
    dlg.querySelector('.wfo-org-dlg-now').textContent = T.manager + ': ' + (p.m ? nameOf(p.m) : T.none);
    dlg.hidden = false; notes();
    dform.manager_ref.focus();
    return true;
  }
  function closeMove() { if (!dlg) return; dlg.hidden = true; if (lastFocus && lastFocus.focus) lastFocus.focus(); }
  function notes() {
    var p = by[moving]; if (!p) return;
    var none = dform.no_manager.checked, to = none ? 0 : pickId(dform.manager_ref.value), bad = '', out = [];
    dform.manager_ref.disabled = none;
    if (!none && to && (to === moving || under(moving)[to])) bad = T.loop;
    else if (to === p.m && (none || to) && dform.effective_date.value <= D.today) bad = T.same;
    if (p.u) out.push('<p class="is-info">' + esc(T.withThem.replace('%d', p.u)) + '</p>');
    if (p.w && p.m) out.push('<p class="is-info">' + esc(none ? T.waitingNone : T.waiting.replace('%1$d', p.w).replace('%2$s', nameOf(p.m))) + '</p>');
    if (dform.effective_date.value > D.today) out.push('<p class="is-info">' + esc(T.later) + '</p>');
    if (bad) out.push('<p class="is-warn" role="alert">' + esc(bad) + '</p>');
    dlg.querySelector('.wfo-org-notes').innerHTML = out.join('');
    dlg.querySelector('[data-dlg-save]').disabled = !!bad || (!none && !to);
  }
  if (dlg) {
    dform.addEventListener('input', notes); dform.addEventListener('change', notes);
    dlg.addEventListener('click', function (e) { if (e.target === dlg || (e.target.closest && e.target.closest('[data-dlg-close]'))) closeMove(); });
    dlg.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMove(); });
  }

  /* ---- narrow screens: one person at a time */
  function drawBrowse() {
    var p = by[focus];
    if (!p) { tree.innerHTML = '<p class="wfo-org-empty">' + esc(T.empty) + '</p>'; return; }
    var c = chain(focus), k = (kids[focus] || []).slice(), mates = p.m ? (kids[p.m] || []).filter(function (x) { return x !== focus; }) : [];
    if (q) { var found = people.filter(hit).slice(0, 30).map(function (r) { return r.id; }); k = found; }
    var h = '<div class="wfo-org-browse"><p class="wfo-org-hint">' + esc(T.phoneHint) + '</p>';
    if (c.length && !q) h += '<div class="wfo-org-ups">' + c.map(function (i) { return '<button type="button" class="wfo-org-up" data-focus="' + i + '">' + av(by[i], 's') + esc(by[i].n.split(' ')[0]) + '</button>'; }).join('<span class="sep">' + chev() + '</span>') + '</div>';
    if (!q) h += '<div class="wfo-org-focus ' + dcls(p) + '">' + av(p, 'l') + '<strong>' + esc(p.n) + '</strong><small>' + esc([p.t, dname(p)].filter(Boolean).join(' · ')) + '</small><div class="wfo-org-stats"><span>' + (kids[focus] || []).length + ' ' + esc(T.direct) + '</span><span>' + (p.u || 0) + ' ' + esc(T.total) + '</span></div>'
      + '<div class="wfo-org-btns"><a class="button" href="' + esc(D.profile + p.id) + '">' + esc(T.profile) + '</a>' + moveBtn(p) + '</div></div>';
    if (k.length) h += '<div class="wfo-org-list">' + (q ? '' : '<h4>' + esc(T.reports) + ' · ' + k.length + '</h4>') + k.map(function (i) { var r = by[i], n = (kids[i] || []).length; return '<button type="button" class="wfo-org-row" data-focus="' + i + '">' + av(r) + '<span class="t"><b>' + esc(r.n) + '</b><small>' + esc(r.t) + '</small></span>' + (n ? '<span class="wfo-org-pill">' + r.u + '</span>' : '') + '<span class="chev" aria-hidden="true">' + chev() + '</span></button>'; }).join('') + '</div>';
    else if (q) h += '<p class="wfo-org-empty">' + esc(T.noMatch) + '</p>';
    if (mates.length && !q) h += '<div class="wfo-org-list"><h4>' + esc(T.teammates) + '</h4><div class="wfo-org-mates">' + mates.map(function (i) { return '<button type="button" data-focus="' + i + '">' + av(by[i]) + esc(by[i].n.split(' ')[0]) + '</button>'; }).join('') + '</div></div>';
    tree.innerHTML = h + '</div>';
  }

  function draw() {
    root.classList.toggle('is-narrow', !!narrow.matches);
    if (narrow.matches) { panel.hidden = true; drawBrowse(); return; }
    drawTree(); drawPanel();
  }
  /* Opens the branches above someone so their card is drawn, selects them and scrolls to them. */
  function reveal(id) {
    chain(id).forEach(function (i) { open[i] = 1; });
    sel = focus = id; draw();
    var el = tree.querySelector('[data-sel="' + id + '"]');
    if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest', inline: 'center' });
  }

  root.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : e.target.parentNode;
    var mv = t.closest('[data-move]');
    if (mv && D.canMove && openMove(+mv.getAttribute('data-move'))) { e.preventDefault(); return; }
    var imp = t.closest('[data-org-import-open]');
    if (imp) { var d = document.getElementById('wfo-org-import'); if (d) { e.preventDefault(); d.open = true; d.scrollIntoView({ block: 'start' }); var f = d.querySelector('input[type=file]'); if (f) f.focus(); } return; }
    var o = t.closest('[data-open]'), s = t.closest('[data-sel]'), f = t.closest('[data-focus]'), z = t.closest('[data-zoom]'), w = t.closest('[data-wide]');
    if (o) { var id = +o.getAttribute('data-open'); if (q) { q = ''; qIn.value = ''; } open[id] = !open[id]; sel = id; draw(); return; }
    if (f) { focus = sel = +f.getAttribute('data-focus'); q = ''; qIn.value = ''; draw(); window.scrollTo(0, root.offsetTop); return; }
    if (w) { reveal(+w.getAttribute('data-wide')); return; }
    if (s) { var sid = +s.getAttribute('data-sel'); if (panel.contains(s)) { reveal(sid); return; } sel = focus = sid; draw(); return; }
    if (z) { var v = +z.getAttribute('data-zoom'); zoom = v === 0 ? 1 : Math.max(0.4, Math.min(1.4, zoom + v * 0.15)); draw(); }
  });
  root.addEventListener('keydown', function (e) {
    var s = e.target.closest && e.target.closest('.wfo-org-pc');
    if (s && e.target === s && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); sel = focus = +s.getAttribute('data-sel'); draw(); var again = tree.querySelector('[data-sel="' + sel + '"]'); if (again) again.focus(); }
  });
  var timer;
  if (qIn) qIn.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      q = qIn.value.trim().toLowerCase();
      if (q && !narrow.matches) { var first = people.filter(function (p) { return hit(p) && (p.m || (kids[p.id] || []).length); })[0]; if (first) sel = first.id; }
      draw();
    }, 150);
  });
  if (narrow.addEventListener) narrow.addEventListener('change', draw);
  if (D.focus && by[D.focus]) reveal(D.focus); else draw();
})();
