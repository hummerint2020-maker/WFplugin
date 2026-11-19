"""Managers counter opens the day's managers (owner request 2026-11-19): "the managers' part, I tap it and see who is coming,
the one written as 2/1". The managers' counter under each day becomes a button; it opens that day's Managers sheet (the
same sheet a short day already has), on every checked day, short or not: who is in the office, who works from home (could
come in), who is away. Nothing new is read: the day's managers are already worked out for the counter.

The screens start from the Manager office minimum mockups (screens_mgrmin.py)."""
import re
from build import T, Lang, esc, icon, sheet, write, gallery
import screens_office as OM
import screens_mgrmin as MM

F = 'manager-counter-list'
CSS = 'manager-counter-list.css'
DAYS = ('الأحد', 'Sunday')


def counter_button(i):
    """The counter as a button (the same pill, with a small arrow saying it opens)."""
    c = MM.mgr_counter(i)
    if not c:
        return ''
    c = c.replace('<div class="wfo-mom-day', '<button type="button" class="wfo-mom-day is-btn', 1).replace('</div>', icon('chevron', 11, 2.4, 'wfo-mcl-open') + '</button>', 1)
    return c.replace('title="%s"' % esc(T('مديرين في المكتب / الحد الأدنى', 'Managers in the office / minimum')),
                     'title="%s"' % esc(T('مين من المديرين في المكتب', 'Which managers are in the office')))


def day_sheet(i):
    """The Managers sheet of any checked day: the short day's sheet, with the summary saying enough when it is."""
    groups = [(('Office',), ('في المكتب', 'In the office')), (('WFH',), ('من البيت', 'Working from home')), (('Leave', 'Trip'), ('مش موجودين', 'Away'))]
    rows = ''
    for keys, head in groups:
        ms = [m for m in MM.MANAGERS if m[3][i] in keys]
        if not ms:
            continue
        rows += '<li class="wfo-mcl-head"><span>%s</span><b>%d</b></li>' % (esc(T(*head)), len(ms))
        for name, team, _, plan in ms:
            label, ic, cls = MM.PLAN[plan[i]]
            can = '<span class="wfo-mom-can">%s</span>' % esc(T('ممكن ييجي', 'Could come in')) if plan[i] == 'WFH' else ''
            rows += ('<li class="%s"><span class="ews-att-avatar wfo-att-avatar tone-%d" aria-hidden="true">%s</span><span class="wfo-mom-who"><b>%s</b><small>%s</small></span>'
                     '<span class="wfo-mom-plan %s">%s%s</span>%s</li>') % (
                         cls, len(T(*name)) % 5, esc(''.join(w[0] for w in T(*name).split()[:2])), esc(T(*name)), esc(T('مدير %s' % T(*team), '%s manager' % T(*team))),
                         cls, icon(ic, 13, 2.2), esc(T(*label)), can)
    n = MM.mgr_count(i)
    if n >= MM.MGR_MIN:
        what = T('كفاية: %d في المكتب، والحد الأدنى %d' % (n, MM.MGR_MIN), 'Enough: %d in the office, the minimum is %d' % (n, MM.MGR_MIN))
    else:
        what = T('ناقص %d مدير عن الحد الأدنى' % (MM.MGR_MIN - n), '%d manager below the minimum' % (MM.MGR_MIN - n))
    body = ('<div class="wfo-om-sheet-sum%s">%s<div><strong>%s</strong><span>%s</span></div></div><ul class="wfo-mom-list">%s</ul><p class="wfo-om-how">%s</p>') % (
        ' is-ok' if n >= MM.MGR_MIN else '', MM.mgr_counter(i), esc(OM.day_label(i, True)), esc(what), rows,
        esc(T('المديرين = مديرين الفرق (اللي عليهم علامة «مدير» في الجدول). المديرين اللي من البيت ممكن ييجوا؛ الإجازة والمأمورية مش بتتحسب.',
              'Managers = the team managers (marked "Manager" on the grid). Those working from home could come in; leave and trips never count.')))
    return sheet(T('المديرين يوم %s' % OM.day_label(i, True), 'Managers on %s' % OM.day_label(i, True)), body, T('تمام', 'OK'), icon_name='check')


def attendance(variant):
    """variant: week (the counters are buttons) | sunday (Sunday's counter tapped: enough managers) | thursday (a short day)"""
    def make():
        h = MM.attendance('week')()
        n = [0]
        def swap(m):
            i = n[0] % 5
            n[0] += 1
            return counter_button(i)
        h = re.sub(r'<div class="wfo-mom-day (?:is-short|is-ok)"[^>]*>.*?</span></div>', swap, h)
        for c in ('manager-office-minimum.css', 'manager-office-minimum-rtl.css'):
            h = h.replace('href="%s"' % c, 'href="../manager-office-minimum/%s"' % c)
        h = h.replace('</head>', '<link rel="stylesheet" href="%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1)
        if variant == 'sunday':
            h = h.replace('</body>', day_sheet(0) + '</body>', 1)
        elif variant == 'thursday':
            h = h.replace('</body>', day_sheet(4) + '</body>', 1)
        return h
    return make


def build():
    for slug, make in [('week', attendance('week')), ('sunday', attendance('sunday')), ('thursday', attendance('thursday')), ('phone', attendance('sunday'))]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Managers counter → who is in — mockups (Step 1)', (
        '<p>The managers\' counter under each day (<b>3 / 2</b>) becomes a button. Tapping it opens that day\'s managers: who is in the office, '
        'who works from home (could come in), who is away. It is the same sheet a short day already opens from the warning, now on every checked day. '
        'Only with the managers\' minimum on; nothing else on the page changes.</p>'), [
        ('App → Attendance', '', [
            ('week', 'The counters are buttons (a small arrow)', D), ('sunday', 'Sunday tapped: 3 of 2, enough', D),
            ('thursday', 'Thursday tapped: 1 of 2, short (as today from the warning)', D), ('phone', 'On a phone', P)]),
    ])
