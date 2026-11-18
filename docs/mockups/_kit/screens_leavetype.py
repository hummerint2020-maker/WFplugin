"""Leave type on the Attendance page (docs/tasks/leave-type-attendance.md, Step 1): a day of approved leave shows its type
(Annual Leave, Sick Leave, Casual Leave, or any type in Leave Types) instead of "Leave". A day set to a leave status by hand,
and a company holiday, show as today. A new "Leave days" card counts them, with the types under it. Same colour for every type.
Off by default (Features); when off the page is exactly as today.

Starts from the real page (snap/attendance.<lang>.html)."""
import os, re
from build import T, Lang, esc, icon, write, gallery, Build, KIT

F = 'leave-type'
CSS = 'leave-type.css'
TYPES = [('إجازة اعتيادية', 'Annual Leave'), ('إجازة مرضية', 'Sick Leave'), ('إجازة عارضة', 'Casual Leave')]


def attendance(variant):
    """variant: on | off"""
    def make():
        h = open(os.path.join(KIT, 'snap', 'attendance.%s.html' % Lang.code)).read()
        h = h.replace('{{ASSETS}}', Build.assets).replace('BA Team', T('شركة النيل للمقاولات', 'Nile Contracting'))
        feat = '<link rel="stylesheet" href="%s">' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS)
        h = h.replace('</head>', feat + '<link rel="stylesheet" href="%smock.css"></head>' % Build.kit, 1)
        if variant == 'off':
            return h
        word = 'الإجازات' if Lang.rtl() else 'Leave'
        n = [0]
        counts = {}
        def typed(m):
            t = TYPES[[0, 1, 0, 2, 0, 1, 0, 0, 2, 0][n[0] % 10]]
            n[0] += 1
            name = T(*t)
            counts[name] = counts.get(name, 0) + 1
            return m.group(1) + '<span title="%s">%s</span></div>' % (esc(name), esc(name))
        h = re.sub(r'(<div class="ews-att-result leave">.*?</svg>)<span>%s</span></div>' % re.escape(word), typed, h)
        total = sum(counts.values())
        split = ' · '.join('%s %d' % (k.replace(T('إجازة ', ''), '') if Lang.rtl() else k.replace(' Leave', ''), v) for k, v in sorted(counts.items(), key=lambda x: -x[1]))
        card = ('<div class="ews-att-stat leave"><span>%s</span><div><b>%d</b><small>%s</small><small class="wfo-lt-split">%s</small></div></div>') % (
            icon('leave', 20), total, esc(T('أيام الإجازة', 'Leave days')), esc(split))
        h = h.replace('class="ews-att-summary wfo-att-stats"', 'class="ews-att-summary wfo-att-stats has-leave"', 1)
        h = re.sub(r'(<div class="ews-att-stat absent">.*?</div></div>)', lambda m: m.group(1) + '\n        ' + card, h, count=1)
        return h
    return make


def build():
    for slug, make in [('on', attendance('on')), ('off', attendance('off'))]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Leave type on the Attendance page — mockups (Step 1)', (
        '<p>A day of <b>approved leave</b> shows its type, <b>Annual Leave</b>, <b>Sick Leave</b>, <b>Casual Leave</b> or any type added in Leave Types, '
        'instead of "Leave", with the same colour. A day set to a leave status by hand in the schedule, and a company holiday, show as today. '
        'A new <b>Leave days</b> card counts the leave days of the week, with the types under it. '
        '<b>Off by default</b> (Features); when off the page is exactly as today.</p>'), [
        ('The manager — app → Attendance (the real page, the week of 11 – 15 Oct)', '', [
            ('on', 'On: each leave day shows its type; the Leave days card with the types', D),
            ('on', 'The same on a phone', P),
            ('off', 'Off (the default): the page as it is today', D)]),
    ])
