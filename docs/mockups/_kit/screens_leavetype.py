"""Leave type on the Attendance page (docs/tasks/leave-type-attendance.md, Step 1): a day of approved leave shows its type
(Annual Leave, Sick Leave, Casual Leave, or any type in Leave Types) instead of "Leave". A day set to a leave status by hand,
and a company holiday, show as today. A new "Leave days" card counts them, with the types under it. Same colour for every type.
Off by default (Features); when off the page is exactly as today.

Starts from the real page (snap/attendance.<lang>.html)."""
import os, re
from build import T, Lang, esc, icon, write, gallery, Build, KIT, admin_page
import screens_attcards as AC
from screens_attcards import SUBMENU

F = 'leave-type'
CSS = 'leave-type.css'
TYPES = [('إجازة اعتيادية', 'Annual Leave'), ('إجازة مرضية', 'Sick Leave'), ('إجازة عارضة', 'Casual Leave')]


def attendance(variant):
    """variant: on | off | hidden (on, with Employees and Present days hidden in the settings)"""
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
        if variant == 'hidden':
            for k in ('people', 'present'):
                h = re.sub(r'\s*<div class="ews-att-stat %s">.*?</div></div>' % k, '', h, count=1)
            h = h.replace('wfo-att-stats has-leave"', 'wfo-att-stats has-leave is-4"', 1)
        return h
    return make


def leave_sheet():
    """The cards open lists (Attendance cards on): Leave days opens one too (owner, 2026-11-18): who was on leave, which day, which type."""
    def make():
        h = AC.attendance(None)()
        h = h.replace('</head>', '<link rel="stylesheet" href="%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1)
        split = T('اعتيادية 4 · مرضية 2 · عارضة 1 · إجازات أخرى 1', 'Annual 4 · Sick 2 · Casual 1 · Other leave 1')
        card = ('<button type="button" class="ews-att-stat leave wfo-ac-card is-open" aria-haspopup="dialog"><span>%s</span><div><b>8</b><small>%s</small>'
                '<small class="wfo-lt-split">%s</small></div><i class="wfo-ac-card-go" aria-hidden="true">%s</i></button>') % (
            icon('leave', 20), esc(T('أيام الإجازة', 'Leave days')), esc(split), icon('chevron', 16, 2.2))
        h = h.replace('class="ews-att-summary wfo-att-stats"', 'class="ews-att-summary wfo-att-stats has-leave"', 1)
        h = re.sub(r'(<button type="button" class="ews-att-stat absent.*?</button>)', lambda m: m.group(1) + '\n        ' + card, h, count=1, flags=re.S)
        pill = lambda t: '<span class="wfo-ac-pill is-leave">%s</span>' % esc(T(*t))
        other = ('إجازة', 'Leave')
        data = [(0, [(3, TYPES[0]), (5, TYPES[1])]), (1, [(3, TYPES[0]), (5, TYPES[1])]), (2, [(3, TYPES[0]), (9, TYPES[2])]), (3, [(3, TYPES[0]), (7, other)])]
        groups = ''.join(AC.group(AC.DAYS[d], [AC.row(p, pill(t)) for p, t in items]) for d, items in data)
        note = '<p class="wfo-ac-note">%s%s</p>' % (icon('leave', 14, 2.2), esc(T('أيام الإجازة بنوعها. العطلات الرسمية مش محسوبة؛ اليوم اللي اتحط إجازة بالإيد بيظهر «إجازة».',
                                                                         'Leave days with their type. Company holidays are left out; a day set to leave by hand shows "Leave".')))
        foot = AC.foot(8, 8).replace('<button type="button" class="wfo-ac-more">%s</button>' % esc(T('اعرض المزيد', 'Show more')), '')
        foot = re.sub(r'<a class="wfo-ac-report".*?</a>', '', foot)
        AC.KINDS['leave'] = (('أيام الإجازة', 'Leave days'), 'leave')
        sheet = AC.drawer('leave', AC.toolbar() + note + groups + foot, '8', T('مين كان في إجازة، وإمتى، ونوعها إيه', 'Who was on leave, which day, and which type'))
        return h.replace('</body>', sheet + '</body>', 1)
    return make


def admin_settings():
    def make():
        rows = [('Employees', 'الموظفين'), ('Present days', 'أيام الحضور'), ('Late days', 'أيام التأخير'), ('Absent days', 'أيام الغياب'),
                ('Leave days', 'أيام الإجازة'), ('Attendance rate', 'نسبة الحضور')]
        checks = ''.join('<label class="wfo-lt-card"><input type="checkbox" checked> %s%s</label>' % (
            esc(T(ar, en)), (' <span class="description">%s</span>' % esc(T('(مع نوع الإجازة)', '(with Leave type)'))) if en == 'Leave days' else '') for en, ar in rows)
        body = ('<h1>%s</h1>'
                '<div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div>'
                '<label class="wfo-feature-status"><input type="checkbox"> %s</label></div></div>'
                '<div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div></div>'
                '<fieldset class="wfo-lt-cards">%s</fieldset><p class="description">%s</p></div>'
                '<p class="submit"><button class="button button-primary">%s</button></p>') % (
            esc(T('إعدادات الميزات', 'Feature Configuration')),
            esc(T('نوع الإجازة في صفحة الحضور', 'Leave type on the Attendance page')),
            esc(T('يوم الإجازة المتوافق عليها يظهر بنوعها (اعتيادية، مرضية، عارضة، أو أي نوع في أنواع الإجازات) بدل «إجازة»، ومعاها كارت «أيام الإجازة». مقفولة افتراضيًا.',
                  'A day of approved leave shows its type (Annual, Sick, Casual, or any type in Leave Types) instead of "Leave", with a Leave days card. Off by default.')),
            esc(T('مفعّل', 'Enabled')),
            esc(T('كروت صفحة الحضور', 'Attendance page cards')),
            esc(T('الكروت اللي تظهر فوق جدول الحضور لكل المديرين.', 'The cards shown above the Attendance grid, for every manager.')),
            checks,
            esc(T('كلها ظاهرة افتراضيًا، زي دلوقتي. الكارت المخفي بيتشال من الصفحة بس، والأرقام زي ما هي في التقارير.',
                  'All shown by default, as today. A hidden card is only taken off the page; the numbers stay in the reports.')),
            esc(T('حفظ التغييرات', 'Save Changes')))
        return admin_page(T('إعدادات الميزات', 'Feature Configuration'), body, SUBMENU, 'features', css_files=['admin-features.css'], feature_css=CSS)
    return make


def build():
    for slug, make in [('on', attendance('on')), ('hidden', attendance('hidden')), ('off', attendance('off')), ('settings', admin_settings()), ('sheet', leave_sheet())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Leave type on the Attendance page — mockups (Step 1)', (
        '<p>A day of <b>approved leave</b> shows its type, <b>Annual Leave</b>, <b>Sick Leave</b>, <b>Casual Leave</b> or any type added in Leave Types, '
        'instead of "Leave", with the same colour. A day set to a leave status by hand in the schedule, and a company holiday, show as today. '
        'A new <b>Leave days</b> card counts the leave days of the week, with the types under it. '
        '<b>Off by default</b> (Features); when off the page is exactly as today.</p>'
        '<p>The administrator chooses, for every manager, <b>which cards are shown</b> above the grid (Employees, Present days, Late days, Absent days, Leave days, Attendance rate). All shown by default, as today.</p>'), [
        ('The manager — app → Attendance (the real page, the week of 11 – 15 Oct)', '', [
            ('on', 'On: each leave day shows its type; the Leave days card with the types', D),
            ('on', 'The same on a phone', P),
            ('hidden', 'Employees and Present days hidden in the settings', D),
            ('sheet', 'With Attendance cards on: Leave days opens who was on leave, which day and which type', D),
            ('sheet', 'The same on a phone', P),
            ('off', 'Off (the default): the page as it is today', D)]),
        ('wp-admin', '', [('settings', 'Feature Configuration: the Leave type switch, and the cards to show (all shown by default)', D)]),
    ])
