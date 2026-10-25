"""Attendance cards you can open (3.31.125, owner request 2026-10-25, Step 1 mockups).

The five cards at the top of the manager's Attendance page (Employees, Present days, Late days, Absent days, Attendance rate)
open a side sheet (a bottom sheet on a phone) with who and when: each person and day behind the number, with the sign-in and
sign-out times, the minutes late, or where the person was planned to be. The list follows the page's week, search, team and
branch, and counts exactly what the card counts. Owner decisions: a side sheet; late shows the time and the minutes late; the
rate shows each day and the 10 lowest people. A switch on the Features page, off by default; off, the cards stay as today.

The Attendance page screens start from the real page (snap/attendance.<lang>.html) with last week's numbers.
"""
import os, re
from build import T, Lang, esc, icon, admin_page, write, gallery, Build, KIT

F = 'attendance-cards'
CSS = 'attendance-cards.css'
WEEK = ('18 – 22 أكتوبر 2026', '18 – 22 Oct 2026')
STATS = {'people': 40, 'present': 171, 'late': 14, 'absent': 6, 'rate': 97}
DAYS = [('الأحد 18 أكتوبر', 'Sun 18 Oct'), ('الاثنين 19 أكتوبر', 'Mon 19 Oct'), ('الثلاثاء 20 أكتوبر', 'Tue 20 Oct'),
        ('الأربعاء 21 أكتوبر', 'Wed 21 Oct'), ('الخميس 22 أكتوبر', 'Thu 22 Oct')]
# name, initials, team, tone
PEOPLE = [(('أحمد سمير', 'Ahmed Samir'), ('أس', 'AS'), ('المبيعات', 'Sales'), 2), (('منى عادل', 'Mona Adel'), ('مع', 'MA'), ('خدمة العملاء', 'Customer Service'), 3),
          (('هبة فاروق', 'Heba Farouk'), ('هف', 'HF'), ('المبيعات', 'Sales'), 0), (('كريم ناجي', 'Karim Nagi'), ('كن', 'KN'), ('التسويق', 'Marketing'), 4),
          (('سارة علي', 'Sara Ali'), ('سع', 'SA'), ('خدمة العملاء', 'Customer Service'), 1), (('عمرو فتحي', 'Amr Fathy'), ('عف', 'AF'), ('العمليات', 'Operations'), 2),
          (('ريم نبيل', 'Reem Nabil'), ('رن', 'RN'), ('التسويق', 'Marketing'), 3), (('شريف جمال', 'Sherif Gamal'), ('شج', 'SG'), ('العمليات', 'Operations'), 0),
          (('ندى عادل', 'Nada Adel'), ('نع', 'NA'), ('المبيعات', 'Sales'), 4), (('وائل سمير', 'Wael Samir'), ('وس', 'WS'), ('خدمة العملاء', 'Customer Service'), 1)]
KINDS = {'people': (('الموظفين', 'Employees'), 'people'), 'present': (('أيام الحضور', 'Present days'), 'check'), 'late': (('أيام التأخير', 'Late days'), 'overtime'),
         'absent': (('أيام الغياب', 'Absent days'), 'alert'), 'rate': (('نسبة الحضور', 'Attendance rate'), 'insights')}


def person(i):
    n, ini, team, tone = PEOPLE[i % len(PEOPLE)]
    return ('<span class="ews-att-avatar wfo-att-avatar tone-%d" aria-hidden="true">%s</span><span class="wfo-ac-who"><b>%s</b><small>%s</small></span>') % (
        tone, esc(T(*ini)), esc(T(*n)), esc(T(*team)))


def row(i, right, href=True):
    return '<li><a class="wfo-ac-row" href="#">%s<span class="wfo-ac-right">%s</span><span class="wfo-ac-go" aria-hidden="true">%s</span></a></li>' % (person(i), right, icon('chevron', 16, 2.2))


def times(a, b=None, late=None, missing=False):
    out = '<span class="wfo-ac-time" dir="ltr">%s</span>' % esc(a)
    if b:
        out += '<span class="wfo-ac-sep" aria-hidden="true">→</span><span class="wfo-ac-time" dir="ltr">%s</span>' % esc(b)
    if missing:
        out += '<span class="wfo-ac-pill is-warn">%s</span>' % esc(T('ما سجّلش انصراف', 'No sign-out'))
    if late:
        out += '<span class="wfo-ac-pill is-late">%s</span>' % esc(T('متأخر %d د' % late, '%d min late' % late))
    return out


def group(day, items):
    return '<section class="wfo-ac-day"><h3>%s<span>%d</span></h3><ul class="wfo-ac-list">%s</ul></section>' % (esc(T(*day)), len(items), ''.join(items))


def toolbar(sort=True):
    chips = '<span class="wfo-ac-chip">%s%s</span><span class="wfo-ac-chip">%s</span>' % (icon('calendar', 14, 2.2), esc(T(*WEEK)), esc(T('كل الفرق', 'All teams')))
    seg = ('<div class="wfo-ac-seg" role="group" aria-label="%s"><button type="button" aria-pressed="true">%s</button><button type="button" aria-pressed="false">%s</button></div>') % (
        esc(T('الترتيب', 'Order')), esc(T('باليوم', 'By day')), esc(T('بالموظف', 'By employee')))
    return '<div class="wfo-ac-bar"><div class="wfo-ac-chips">%s</div>%s</div>' % (chips, seg if sort else '')


def foot(shown, total):
    return ('<div class="wfo-ac-foot"><p>%s</p><div class="wfo-ac-foot-acts"><button type="button" class="wfo-ac-more">%s</button>'
            '<a class="wfo-ac-report" href="#">%s%s</a></div></div>') % (
                esc(T('ظاهر %d من %d' % (shown, total), 'Showing %d of %d' % (shown, total))), esc(T('اعرض المزيد', 'Show more')),
                icon('reports', 16, 2.2), esc(T('افتحها في التقارير', 'Open in Reports')))


def drawer(kind, body, count, sub):
    title, ic = KINDS[kind]
    return ('<dialog class="wfo-sheet wfo-ac-sheet is-%s" id="mock-sheet" aria-labelledby="wfo-ac-title"><div class="wfo-sheet-body">'
            '<div class="wfo-sheet-grip" aria-hidden="true"></div><div class="wfo-sheet-head wfo-ac-head"><span class="wfo-ac-ico">%s</span>'
            '<div><h2 id="wfo-ac-title">%s <span class="wfo-ac-count">%s</span></h2><p>%s</p></div>'
            '<button type="button" class="wfo-sheet-x" aria-label="%s">%s</button></div>%s</div></dialog>'
            '<script>document.getElementById("mock-sheet").showModal()</script>') % (
                kind, icon(ic, 20, 2.2), esc(T(*title)), esc(count), esc(sub), esc(T('إغلاق', 'Close')), icon('close', 18, 2.2), body)


def late_body():
    data = [(0, [(0, '09:23', '17:31', 23), (6, '09:41', '17:05', 41)]), (1, [(3, '09:12', '17:20', 12)]), (2, [(0, '09:18', '17:44', 18), (8, '09:35', None, 35), (4, '09:16', '17:02', 16)]),
            (3, [(5, '10:02', '17:58', 62), (9, '09:20', '17:10', 20)]), (4, [(1, '09:15', '17:03', 15), (7, '09:28', '17:40', 28)])]
    groups = ''.join(group(DAYS[d], [row(p, times(a, b, late, missing=b is None)) for p, a, b, late in items]) for d, items in data)
    note = '<p class="wfo-ac-note">%s%s</p>' % (icon('clock', 14, 2.2), esc(T('ميعاد الحضور 9:00 ص، والتأخير بيتحسب بعد 9:10 ص (شيفت كل موظف).', 'Start 9:00 AM; late counts after 9:10 AM (each person\'s own shift).')))
    return toolbar() + note + groups + foot(10, 14)


def present_body():
    data = [(0, [(1, '08:52', '17:04'), (2, '08:47', '17:12'), (3, '08:58', None), (4, '08:41', '17:00')]),
            (1, [(5, '08:55', '17:08'), (7, '08:49', '17:15'), (9, '09:04', '17:02')])]
    groups = ''.join(group(DAYS[d], [row(p, times(a, b, missing=b is None)) for p, a, b in items]) for d, items in data)
    note = '<p class="wfo-ac-note">%s%s</p>' % (icon('check', 14, 2.4), esc(T('الحضور في الميعاد. التأخير ليه كارت لوحده.', 'On time. Late days have their own card.')))
    return toolbar() + note + groups + foot(20, 171)


def absent_body():
    plan = lambda w: '<span class="wfo-ac-plan is-%s">%s%s</span>' % (w, icon({'office': 'office', 'wfh': 'wfh'}[w], 14, 2.2), esc(T(*{'office': ('كان المفروض في المكتب', 'Planned: Office'), 'wfh': ('كان المفروض من البيت', 'Planned: WFH')}[w])))
    data = [(0, [(5, 'office')]), (2, [(8, 'wfh'), (3, 'office')]), (3, [(5, 'office')]), (4, [(9, 'office'), (1, 'wfh')])]
    groups = ''.join(group(DAYS[d], [row(p, plan(w)) for p, w in items]) for d, items in data)
    note = '<p class="wfo-ac-note">%s%s</p>' % (icon('alert', 14, 2.2), esc(T('يوم عمل من غير تسجيل حضور، بعد ميعاد الغياب.', 'A working day with no sign-in, past the absent-after time.')))
    return toolbar() + note + groups + foot(6, 6).replace('<button type="button" class="wfo-ac-more">%s</button>' % esc(T('اعرض المزيد', 'Show more')), '')


def people_body():
    def week(p, l, a, lv=0):
        out = '<span class="wfo-ac-mini is-present" title="%s">%s%d</span>' % (esc(T('حضور', 'Present')), icon('check', 12, 2.6), p)
        out += '<span class="wfo-ac-mini is-late" title="%s">%s%d</span>' % (esc(T('تأخير', 'Late')), icon('clock', 12, 2.4), l)
        out += '<span class="wfo-ac-mini is-absent" title="%s">%s%d</span>' % (esc(T('غياب', 'Absent')), icon('alert', 12, 2.4), a)
        if lv:
            out += '<span class="wfo-ac-mini is-leave" title="%s">%s%d</span>' % (esc(T('إجازة', 'Leave')), icon('leave', 12, 2.2), lv)
        return out
    rows = [row(0, week(3, 2, 0)), row(1, week(4, 1, 0)), row(2, week(4, 1, 0)), row(3, week(3, 1, 1)), row(4, week(5, 0, 0)), row(5, week(2, 1, 2)), row(6, week(4, 1, 0)), row(7, week(4, 1, 0, 0))]
    bar = '<div class="wfo-ac-bar"><div class="wfo-ac-chips"><span class="wfo-ac-chip">%s%s</span><span class="wfo-ac-chip">%s</span></div></div>' % (
        icon('calendar', 14, 2.2), esc(T(*WEEK)), esc(T('كل الفرق', 'All teams')))
    return bar + '<ul class="wfo-ac-list">%s</ul>' % ''.join(rows) + foot(8, 40)


def rate_body():
    rates = [97, 100, 95, 92, 98]
    bars = ''.join('<li><span class="wfo-ac-dayname">%s</span><span class="wfo-ac-meter"><i style="inline-size:%d%%"></i></span><b>%d%%</b></li>' % (esc(T(*d).split()[0] + ' ' + T(*d).split()[1]), r, r) for d, r in zip(DAYS, rates))
    low = [(5, 60, ('3 من 5 أيام', '3 of 5 days')), (8, 80, ('4 من 5', '4 of 5')), (3, 80, ('4 من 5', '4 of 5')), (9, 80, ('4 من 5', '4 of 5')), (1, 80, ('4 من 5', '4 of 5'))]
    lows = ''.join(row(p, '<span class="wfo-ac-pct">%d%%</span><small class="wfo-ac-of">%s</small>' % (r, esc(T(*o)))) for p, r, o in low)
    return (toolbar(sort=False) + '<p class="wfo-ac-note">%s%s</p>' % (icon('insights', 14, 2.2), esc(T('الحضور (في الميعاد + متأخر) من أيام العمل المطلوب فيها حضور. الإجازات والعطلات مش محسوبة.',
                                                                                                    'Present + late out of the working days that need a sign-in. Leave and holidays are left out.'))) +
            '<section class="wfo-ac-day"><h3>%s</h3><ul class="wfo-ac-bars">%s</ul></section>' % (esc(T('يوم بيوم', 'Day by day')), bars) +
            '<section class="wfo-ac-day"><h3>%s<span>5</span></h3><ul class="wfo-ac-list">%s</ul></section>' % (esc(T('أقل الموظفين في النسبة', 'Lowest this week')), lows) +
            '<p class="wfo-ac-hint">%s</p>' % esc(T('بيظهر لحد 10 موظفين أقل من 100%.', 'Up to 10 people below 100% show here.')))


def attendance(kind):
    def make():
        h = open(os.path.join(KIT, 'snap', 'attendance.%s.html' % Lang.code)).read()
        h = h.replace('{{ASSETS}}', Build.assets).replace('BA Team', T('شركة النيل للمقاولات', 'Nile Contracting'))
        req = Build.assets + 'css/' + ('app-requests-rtl.css' if Lang.rtl() else 'app-requests.css')
        feat = '<link rel="stylesheet" href="%s"><link rel="stylesheet" href="%s">' % (req, CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS)
        h = h.replace('</head>', feat + '<link rel="stylesheet" href="%smock.css"></head>' % Build.kit, 1)
        # last week, with its numbers; each card is a button that opens its list
        h = re.sub(r'(<strong dir="ltr">)[^<]*(</strong>)', lambda m: m.group(1) + '18 – 22 Oct 2026' + m.group(2), h, count=1)
        for k, v in STATS.items():
            h = re.sub(r'<div class="ews-att-stat %s"><span>(.*?)</span><div><b>[^<]*</b>' % k,
                       lambda m, k=k, v=v: '<button type="button" class="ews-att-stat %s wfo-ac-card%s" aria-haspopup="dialog"><span>%s</span><div><b>%s</b>' % (
                           k, ' is-open' if k == kind else '', m.group(1), ('%d%%' % v) if k == 'rate' else v), h, count=1, flags=re.S)
            h = re.sub(r'(<button type="button" class="ews-att-stat %s[^"]*"[^>]*>.*?</small></div>)</div>' % k,
                       lambda m: m.group(1) + '<i class="wfo-ac-card-go" aria-hidden="true">%s</i></button>' % icon('chevron', 16, 2.2), h, count=1, flags=re.S)
        for d in range(11, 16):   # the snapshot's week, moved to last week
            h = h.replace('>%d Oct<' % d, '>%d Oct<' % (d + 7))
        if kind:
            body, count, sub = {'late': (late_body(), '14', T('مين اتأخر وإمتى، وحضر الساعة كام', 'Who was late, which day, and when they signed in')),
                                'present': (present_body(), '171', T('مين حضر في الميعاد، وساعة الحضور والانصراف', 'Who signed in on time, with sign-in and sign-out')),
                                'absent': (absent_body(), '6', T('مين غاب، وكان المفروض يبقى فين', 'Who was absent, and where they were planned')),
                                'people': (people_body(), '40', T('كل موظف وأسبوعه', 'Each person and their week')),
                                'rate': (rate_body(), '97%', T('النسبة يوم بيوم، وأقل الموظفين', 'Day by day, and the lowest people'))}[kind]
            h = h.replace('</body>', drawer(kind, body, count, sub) + '</body>', 1)
        return h
    return make


SUBMENU = [('emp', ('الموظفون', 'Employees')), ('sched', ('إعدادات الجدول', 'Schedule Configuration')),
           ('features', ('إعدادات الميزات', 'Feature Configuration')), ('roles', ('الأدوار والصلاحيات', 'Roles & Permissions')), ('audit', ('سجل التدقيق', 'Audit Log'))]


def admin_settings():
    def make():
        body = ('<h1>%s</h1><div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div>'
                '<label class="wfo-feature-status"><input type="checkbox"> %s</label></div><p class="description">%s</p></div><p class="submit"><button class="button button-primary">%s</button></p>') % (
                    esc(T('إعدادات الميزات', 'Feature Configuration')), esc(T('كروت الحضور بالتفاصيل', 'Attendance card details')),
                    esc(T('في صفحة الحضور، كل كارت (الموظفين، الحضور، التأخير، الغياب، النسبة) بيفتح ليستة بالموظف واليوم والساعة. مقفولة افتراضيًا.',
                          'On the Attendance page, each card (Employees, Present, Late, Absent, Rate) opens a list with the person, the day and the time. Off by default.')),
                    esc(T('مفعّل', 'Enabled')), esc(T('بتوري نفس الناس اللي المدير يقدر يشوفهم في الصفحة (قسمه)، وبنفس فلاتر الصفحة.', 'Shows the same people the manager sees on the page (their department), with the page\'s own filters.')),
                    esc(T('حفظ التغييرات', 'Save Changes')))
        return admin_page(T('إعدادات الميزات', 'Feature Configuration'), body, SUBMENU, 'features', css_files=['admin-features.css'], feature_css=CSS)
    return make


def build():
    for slug, make in [('cards', attendance(None)), ('late', attendance('late')), ('present', attendance('present')), ('absent', attendance('absent')),
                       ('people', attendance('people')), ('rate', attendance('rate')), ('settings', admin_settings())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Attendance cards you can open — mockups (3.31.125)', (
        '<p>The <b>cards at the top of the Attendance page open a list</b> of who and when: each person and day behind the number, with the sign-in and sign-out '
        'times, the minutes late, or where the person was planned to be. A side sheet on a computer, a bottom sheet on a phone.</p>'
        '<p>The list follows the page\'s week, search, team and branch, and always counts what the card counts. It loads when the card is opened '
        '(the first 20, then "Show more"), so a large company does not slow the page. A switch on the Features page, off by default; off, the cards stay as today.</p>'), [
        ('The cards', '', [('cards', 'The cards look and work like buttons (focus ring, arrow)', D)]),
        ('Late days', '', [('late', 'Who was late, which day, sign-in time and minutes late', D), ('late', 'The same on a phone', P)]),
        ('Present days', '', [('present', 'On time, with sign-in and sign-out (and a missing sign-out)', D)]),
        ('Absent days', '', [('absent', 'Who was absent and where they were planned', P)]),
        ('Employees', '', [('people', 'Each person and their week', P)]),
        ('Attendance rate', '', [('rate', 'Day by day, and the lowest people this week', P), ('rate', 'The same on a computer', D)]),
        ('The switch', '', [('settings', 'Features page: off by default', D)]),
    ])
