"""Manager office minimum (owner request 2026-11-18): a minimum number of managers in the office every working day, next
to the Office minimum (a minimum number of people). Owner answer: "a number of managers each day" (company-wide), not a
manager per team nor days per manager. Only a warning, like the Office minimum: saving is never blocked.

Who is a manager (proposal, an open question): the team managers (the "Manager" badge the Attendance page already shows).
The screens start from the real Attendance page (snap/attendance.<lang>.html) with both features on.
"""
import os, re
from build import T, Lang, esc, icon, app_page, admin_page, sheet, write, gallery, Build, KIT
import screens_office as OM

F = 'manager-office-minimum'
CSS = 'manager-office-minimum.css'
MGR_MIN = 2
# the managers (team managers) and where they are planned Sun..Thu
MANAGERS = [
    (('عادل حمدي', 'Adel Hamdy'), ('المبيعات', 'Sales'), 0, ['Office', 'Leave', 'Office', 'Office', 'WFH']),
    (('هشام فتحي', 'Hesham Fathy'), ('خدمة العملاء', 'Customer Service'), 3, ['Office', 'WFH', 'Office', 'WFH', 'Office']),
    (('ريم نبيل', 'Reem Nabil'), ('الحسابات', 'Accounts'), 5, ['WFH', 'Office', 'WFH', 'Office', 'Leave']),
    (('عمرو فتحي', 'Amr Fathy'), ('تكنولوجيا المعلومات', 'IT'), 9, ['Office', 'WFH', 'WFH', 'Trip', 'WFH']),
]
PLAN = {'Office': (('في المكتب', 'Office'), 'office', 'is-office'), 'WFH': (('من البيت', 'WFH'), 'wfh', 'is-wfh'),
        'Leave': (('إجازة', 'Leave'), 'leave', 'is-leave'), 'Trip': (('مأمورية', 'Business Trip'), 'briefcase', 'is-trip')}


def mgr_count(i):
    return sum(1 for m in MANAGERS if m[3][i] == 'Office')


def mgr_counter(i):
    if OM.OFFICE[i] is None:
        return ''
    n = mgr_count(i)
    return '<div class="wfo-mom-day %s" title="%s">%s<b>%d</b><span>/ %d</span></div>' % (
        'is-short' if n < MGR_MIN else 'is-ok', esc(T('مديرين في المكتب / الحد الأدنى', 'Managers in the office / minimum')), icon('people', 13, 2.2), n, MGR_MIN)


def short(i):
    """(office short, managers short) on a working day"""
    if OM.OFFICE[i] is None:
        return (False, False)
    return (OM.OFFICE[i] < OM.MIN, mgr_count(i) < MGR_MIN)


def banner():
    rows, n = '', 0
    for i in range(5):
        o, m = short(i)
        if not (o or m):
            continue
        n += 1
        parts = []
        if o:
            parts.append('<span class="wfo-mom-what is-people">%s%s</span>' % (icon('office', 13, 2.2), esc(T('%d من %d في المكتب' % (OM.OFFICE[i], OM.MIN), '%d of %d in the office' % (OM.OFFICE[i], OM.MIN)))))
        if m:
            parts.append('<span class="wfo-mom-what is-mgr">%s%s</span>' % (icon('people', 13, 2.2), esc(T('%d من %d مديرين' % (mgr_count(i), MGR_MIN), '%d of %d managers' % (mgr_count(i), MGR_MIN)))))
        btns = ''
        if o:
            btns += '<button type="button" class="wfo-om-link">%s%s</button>' % (icon('people', 15, 2.2), esc(T('الفرق', 'Teams')))
        if m:
            btns += '<button type="button" class="wfo-om-link is-mgr">%s%s</button>' % (icon('people', 15, 2.2), esc(T('المديرين', 'Managers')))
        rows += '<li><span class="wfo-om-pill">%s</span><span class="wfo-mom-parts">%s</span><span class="wfo-mom-btns">%s</span></li>' % (esc(OM.day_label(i, True)), ''.join(parts), btns)
    return ('<section class="wfo-om-banner" role="status"><div class="wfo-om-banner-head"><span class="wfo-om-ico">%s</span><div><h3>%s</h3><p>%s</p></div></div>'
            '<ul>%s</ul></section>') % (
                icon('alert', 20, 2.2), esc(T('%d أيام تحت الحد الأدنى' % n, '%d days below a minimum' % n)),
                esc(T('الحد الأدنى %d في المكتب و%d مديرين كل يوم عمل. ده تحذير بس: الجدول بيتحفظ عادي.' % (OM.MIN, MGR_MIN),
                      'The minimum is %d people and %d managers in the office every working day. This is only a warning: the schedule still saves.' % (OM.MIN, MGR_MIN))), rows)


def managers_sheet(i):
    rows = ''
    for name, team, _, plan in sorted(MANAGERS, key=lambda m: m[3][i] != 'Office'):
        label, ic, cls = PLAN[plan[i]]
        can = '<span class="wfo-mom-can">%s</span>' % esc(T('ممكن ييجي', 'Could come in')) if plan[i] == 'WFH' else ''
        rows += ('<li class="%s"><span class="ews-att-avatar wfo-att-avatar tone-%d" aria-hidden="true">%s</span><span class="wfo-mom-who"><b>%s</b><small>%s</small></span>'
                 '<span class="wfo-mom-plan %s">%s%s</span>%s</li>') % (
                     cls, len(T(*name)) % 5, esc(''.join(w[0] for w in T(*name).split()[:2])), esc(T(*name)), esc(T('مدير %s' % T(*team), '%s manager' % T(*team))),
                     cls, icon(ic, 13, 2.2), esc(T(*label)), can)
    n = mgr_count(i)
    body = ('<div class="wfo-om-sheet-sum">%s<div><strong>%s</strong><span>%s</span></div></div><ul class="wfo-mom-list">%s</ul><p class="wfo-om-how">%s</p>') % (
        mgr_counter(i), esc(OM.day_label(i, True)), esc(T('ناقص %d مدير عن الحد الأدنى' % (MGR_MIN - n), '%d manager below the minimum' % (MGR_MIN - n))), rows,
        esc(T('المديرين = مديرين الفرق (اللي عليهم علامة «مدير» في الجدول). المديرين اللي من البيت ممكن ييجوا؛ الإجازة والمأمورية مش بتتحسب.',
              'Managers = the team managers (marked "Manager" on the grid). Those working from home could come in; leave and trips never count.')))
    return sheet(T('المديرين يوم %s' % OM.day_label(i, True), 'Managers on %s' % OM.day_label(i, True)), body, T('تمام', 'OK'), icon_name='check')


def attendance(variant):
    """variant: week | managers | phone | off (Office minimum alone, as today)"""
    def make():
        h = OM.attendance('week')()
        h = h.replace('</head>', '<link rel="stylesheet" href="%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1)
        h = h.replace('href="%s"' % ('office-minimum-rtl.css' if Lang.rtl() else 'office-minimum.css'),
                      'href="../office-minimum/%s"' % ('office-minimum-rtl.css' if Lang.rtl() else 'office-minimum.css'))
        if variant == 'off':
            return h
        # the managers' counter after each office counter (table head and the "Set…" row)
        n = [0]
        def add(m):
            i = n[0] % 5   # five working days in the "Set…" row, then five in the table head
            n[0] += 1
            return m.group(0) + mgr_counter(i)
        h = re.sub(r'<div class="wfo-om-day (?:is-short|is-ok|is-off)"[^>]*>.*?</div>', add, h)
        # the managers' columns: a day short of managers
        # the warning: both minimums
        h = re.sub(r'<section class="wfo-om-banner".*?</section>', lambda m: banner(), h, count=1, flags=re.S)
        # the "Manager" badge on the team managers' rows
        rows = list(re.finditer(r'<td class="ews-att-employee">.*?</strong>', h, re.S))
        for k in sorted((m[2] for m in MANAGERS), reverse=True):
            if k < len(rows):
                m = rows[k]
                end = m.end() - len('</strong>')
                h = h[:end] + '<span class="ews-manager-badge">%s</span>' % esc(T('مدير', 'Manager')) + h[end:]
        if variant == 'managers':
            h = h.replace('</body>', managers_sheet(4) + '</body>', 1)
        return h
    return make


def leave_decide():
    def make():
        card = ('<div class="ews-vac-card wfo-leave-card wfo-decide"><div class="wfo-card-title"><span class="wfo-card-icon is-amber">%s</span><h3>%s</h3></div>'
                '<div class="ews-vac-row"><div class="wfo-req-main"><div class="ews-vac-date"><strong>%s</strong><span>%s</span></div>'
                '<div class="ews-vac-meta">%s</div>'
                '<div class="wfo-om-note">%s<div><strong>%s</strong>%s</div></div>'
                '<div class="wfo-om-note is-mgr">%s<div><strong>%s</strong>%s</div></div>'
                '<div class="ews-vac-actions"><form onsubmit="return false"><button class="button button-primary wfo-btn-approve">%s%s</button><button class="button wfo-btn-reject">%s%s</button></form></div>'
                '</div><span class="ews-vac-status ews-vac-pending">%s%s</span></div></div>') % (
                    icon('tasks', 20), esc(T('طلبات إجازة مستنية موافقتك', 'Leave Requests Awaiting Your Approval')),
                    esc(T('هشام فتحي', 'Hesham Fathy')), esc(T('إجازة اعتيادية · مدير خدمة العملاء', 'Vacation · Customer Service manager')),
                    esc(T('الخميس 15 أكتوبر · يوم واحد', 'Thu 15 Oct · 1 day')),
                    icon('office', 18, 2.2), esc(T('الموافقة هتخلّي الخميس 9 من 12 في المكتب', 'Approving makes Thursday 9 of 12 in the office')),
                    esc(T('ده تحذير بس، تقدر توافق عادي.', 'This is only a warning; you can still approve.')),
                    icon('people', 18, 2.2), esc(T('ومن غير ولا مدير في المكتب (0 من 2)', 'and no manager in the office (0 of 2)')),
                    esc(T('ممكن ييجي: عادل حمدي (من البيت).', 'Could come in: Adel Hamdy (working from home).')),
                    icon('check', 16, 2.4), esc(T('موافقة', 'Approve')), icon('close', 16, 2.2), esc(T('رفض', 'Reject')),
                    icon('overtime', 14, 2.2), esc(T('قيد الانتظار', 'Pending')))
        return app_page(T('الإجازات', 'Leave'), '<div class="ews-page ews-vac wfo-leave">%s</div>' % card, active='vacation', css_files=['app-leave.css'],
                        feature_css='../office-minimum/' + ('office-minimum-rtl.css' if Lang.rtl() else 'office-minimum.css'), who=('منى عادل', 'Mona Adel')).replace(
            '</head>', '<link rel="stylesheet" href="%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1)
    return make


def admin_settings():
    def make():
        h = OM.admin_settings()()
        days = ''.join('<label class="wfo-om-wd"><span>%s</span><input type="number" min="0" class="small-text" placeholder="%d"></label>' % (esc(OM.day_label(i)), MGR_MIN) for i in range(5))
        mgr = ('<div class="wfo-mom-admin"><h3>%s</h3><table class="form-table" role="presentation">'
               '<tr><th scope="row">%s</th><td><label><input type="checkbox" checked> %s</label></td></tr>'
               '<tr><th scope="row">%s</th><td><input type="number" min="1" class="small-text" value="%d"> %s<p class="description">%s</p></td></tr>'
               '<tr><th scope="row">%s</th><td><div class="wfo-om-wds">%s</div><p class="description">%s</p></td></tr>'
               '<tr><th scope="row">%s</th><td><p>%s</p><p class="description">%s</p></td></tr>'
               '</table></div>') % (
                   esc(T('المديرين في المكتب', 'Managers in the office')),
                   esc(T('تشغيل', 'Turn on')), esc(T('حد أدنى للمديرين كمان', 'A minimum for managers too')),
                   esc(T('الحد الأدنى للمديرين كل يوم', 'Managers each day')), MGR_MIN, esc(T('مديرين في المكتب', 'managers in the office')),
                   esc(T('المديرين بيتحسبوا في الحد الأدنى العام كمان.', 'Managers also count in the minimum above.')),
                   esc(T('يوم مختلف (اختياري)', 'A different day (optional)')), days, esc(T('فاضي = زي الحد الأدنى للمديرين.', 'Empty = the managers\' minimum.')),
                   esc(T('مين المديرين', 'Who the managers are')),
                   esc(T('مديرين الفرق (4): عادل حمدي، هشام فتحي، ريم نبيل، عمرو فتحي', 'The team managers (4): Adel Hamdy, Hesham Fathy, Reem Nabil, Amr Fathy')),
                   esc(T('من صفحة الفرق. بنفس حالات «في المكتب» والتذكير بتوع الحد الأدنى.', 'From the Teams page. Uses the same "in the office" statuses and reminder as the minimum above.')))
        return h.replace('</div><p class="submit">', mgr + '</div><p class="submit">', 1).replace(
            '</head>', '<link rel="stylesheet" href="%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1).replace(
            'href="%s"' % ('office-minimum-rtl.css' if Lang.rtl() else 'office-minimum.css'), 'href="../office-minimum/%s"' % ('office-minimum-rtl.css' if Lang.rtl() else 'office-minimum.css'))
    return make


def build():
    for slug, make in [('week', attendance('week')), ('managers', attendance('managers')), ('phone', attendance('week')), ('off', attendance('off')),
                       ('leave', leave_decide()), ('settings', admin_settings())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Manager office minimum — mockups (Step 1)', (
        '<p>A minimum number of <b>managers</b> in the office every working day (here <b>2</b> of the 4 team managers), next to the Office minimum (<b>12</b> people). '
        'It works like the Office minimum: a second counter under each day, the short days at the top, a sheet with the managers of a day, and a note when approving leave. '
        'Only a warning: nothing is blocked. Off by default.</p>'), [
        ('Whoever sets the schedule — app → Attendance (both minimums on)', 'Monday is short of managers only, Tuesday of people only, Thursday of both.', [
            ('week', 'The week: two counters under each day (people, managers)', D), ('managers', 'Managers on Thursday: who is in, who could come in', D),
            ('phone', 'On a phone', P), ('off', 'Manager minimum off: the Office minimum as today', D)]),
        ('Approving leave', '', [('leave', 'A manager\'s leave: what approving does to the day', P)]),
        ('wp-admin', '', [('settings', 'Feature Configuration → Office Minimum, with "Managers in the office" under it', D)]),
    ])
