"""Office minimum (docs/tasks/office-minimum.md, Step 1): a fixed number of people in the office each
working day; the person who sets the schedule is warned (never blocked) and sees which teams are
under their share. Shares are proportional to team size (largest remainder, so they add up to the
minimum).

The Attendance page screens start from snapshots of the real page (snap/attendance.<lang>.html, taken
from a test site with 40 people in 4 teams + 4 without a team, next week's schedule) and add the new
parts; everything else uses the kit's real frames.
"""
import os, re
from build import T, Lang, esc, icon, app_page, admin_page, page_doc, sheet, write, gallery, Build, KIT

F = 'office-minimum'
CSS = 'office-minimum.css'
MIN = 12
DAYS = [('الأحد', 'Sun', '11'), ('الاثنين', 'Mon', '12'), ('الثلاثاء', 'Tue', '13'), ('الأربعاء', 'Wed', '14'), ('الخميس', 'Thu', '15'),
        ('الجمعة', 'Fri', '16'), ('السبت', 'Sat', '17')]
OFFICE = [14, 12, 9, 13, 10, None, None]          # people in the office per day (None = day off)
# team, size, share of the 12 (largest remainder), in the office Sun..Thu
TEAMS = [(('المبيعات', 'Sales'), 12, 4, [5, 4, 1, 4, 2]), (('خدمة العملاء', 'Customer Service'), 10, 3, [3, 3, 3, 3, 3]),
         (('الحسابات', 'Accounts'), 8, 2, [3, 2, 2, 3, 1]), (('تكنولوجيا المعلومات', 'IT'), 6, 2, [2, 2, 2, 2, 3]),
         (('بدون فريق', 'No team'), 4, 1, [1, 1, 1, 1, 1])]
# who could come in (working from home that day, not on leave)
CAN = {(0, 2): [('كريم فتحي', 'Karim Fathy'), ('سارة نبيل', 'Sara Nabil'), ('عمر حمدي', 'Omar Hamdy')],
       (0, 4): [('كريم فتحي', 'Karim Fathy'), ('عمر حمدي', 'Omar Hamdy'), ('نور سعيد', 'Nour Saeed')],
       (2, 4): [('آية لطفي', 'Aya Lotfy'), ('وائل سمير', 'Wael Samir')]}
CAN_MORE = {(0, 2): 7, (0, 4): 5, (2, 4): 4}


def day_label(i, long=False):
    d = DAYS[i]
    return T('%s %s أكتوبر' % (d[0], d[2]), '%s %s Oct' % (d[1], d[2])) if long else T(d[0], d[1])


def short_days():
    return [i for i, n in enumerate(OFFICE) if n is not None and n < MIN]


# ---------------------------------------------------------------- pieces

def counter(i, n=None):
    n = OFFICE[i] if n is None else n
    if n is None:
        return '<div class="wfo-om-day is-off">%s</div>' % esc(T('إجازة', 'Day off'))
    kind = 'is-short' if n < MIN else 'is-ok'
    return '<div class="wfo-om-day %s" title="%s">%s<b>%d</b><span>/ %d</span></div>' % (
        kind, esc(T('في المكتب / الحد الأدنى', 'In the office / minimum')), icon('office', 13, 2.2), n, MIN)


def banner(over=None):
    over = over or {}
    rows = ''
    for i in short_days():
        n = over.get(i, OFFICE[i])
        rows += ('<li><span class="wfo-om-pill">%s</span><strong>%s</strong><span>%s</span>'
                 '<button type="button" class="wfo-om-link">%s%s</button></li>') % (
                     esc(day_label(i, True)), esc(T('%d من %d في المكتب' % (n, MIN), '%d of %d in the office' % (n, MIN))),
                     esc(T('ناقص %d' % (MIN - n), '%d short' % (MIN - n))), icon('people', 15, 2.2), esc(T('الفرق', 'Teams')))
    return ('<section class="wfo-om-banner" role="status"><div class="wfo-om-banner-head"><span class="wfo-om-ico">%s</span><div><h3>%s</h3><p>%s</p></div></div>'
            '<ul>%s</ul></section>') % (
                icon('alert', 20, 2.2), esc(T('يومين تحت الحد الأدنى للمكتب', '2 days below the office minimum')),
                esc(T('الحد الأدنى %d في المكتب كل يوم عمل. ده تحذير بس: الجدول بيتحفظ عادي.' % MIN,
                      'The minimum is %d in the office every working day. This is only a warning: the schedule still saves.' % MIN)), rows)


def team_table(i):
    rows = ''
    order = sorted(range(len(TEAMS)), key=lambda t: TEAMS[t][3][i] - TEAMS[t][2])
    for t in order:
        (name, size, share, office) = TEAMS[t]
        gap = office[i] - share
        cls = 'is-short' if gap < 0 else ('is-over' if gap > 0 else 'is-ok')
        gap_txt = T('ناقص %d' % -gap, '%d short' % -gap) if gap < 0 else (T('زيادة %d' % gap, '+%d above' % gap) if gap > 0 else T('تمام', 'On share'))
        can = ''
        if (t, i) in CAN:
            names = '، '.join(T(*n) for n in CAN[(t, i)]) if Lang.rtl() else ', '.join(T(*n) for n in CAN[(t, i)])
            can = '<div class="wfo-om-can">%s<span>%s</span> %s</div>' % (
                icon('wfh', 13, 2.2), esc(T('ممكن ييجوا (من البيت):', 'Could come in (working from home):')),
                esc(names + T(' و%d غيرهم' % CAN_MORE[(t, i)], ' and %d more' % CAN_MORE[(t, i)])))
        rows += ('<tr class="%s"><th scope="row">%s<small>%s</small>%s</th><td>%d</td><td><b>%d</b></td><td><span class="wfo-om-gap %s">%s</span></td></tr>') % (
            cls, esc(T(*name)), esc(T('%d شخص' % size, '%d people' % size)), can, share, office[i], cls, esc(gap_txt))
    return ('<table class="wfo-om-teams"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody>'
            '<tfoot><tr><th>%s</th><td>%d</td><td><b>%d</b></td><td><span class="wfo-om-gap is-short">%s</span></td></tr></tfoot></table>') % (
                esc(T('الفريق', 'Team')), esc(T('نصيبه', 'Share')), esc(T('في المكتب', 'In office')), '',
                rows, esc(T('الإجمالي', 'Total')), MIN, OFFICE[i], esc(T('ناقص %d' % (MIN - OFFICE[i]), '%d short' % (MIN - OFFICE[i]))))


def teams_sheet(i):
    body = ('<div class="wfo-om-sheet-sum">%s<div><strong>%s</strong><span>%s</span></div></div>%s'
            '<p class="wfo-om-how">%s</p>') % (
                counter(i), esc(day_label(i, True)), esc(T('ناقص %d عن الحد الأدنى' % (MIN - OFFICE[i]), '%d below the minimum' % (MIN - OFFICE[i]))),
                team_table(i), esc(T('نصيب كل فريق = الحد الأدنى × عدد الفريق ÷ كل الموظفين (40)، متقرّب بحيث المجموع يطلع %d بالظبط.' % MIN,
                                   'Each team\'s share = minimum × team size ÷ all employees (40), rounded so the shares add up to exactly %d.' % MIN)))
    return sheet(T('الفرق يوم %s' % day_label(i, True), 'Teams on %s' % day_label(i, True)), body, T('تمام', 'OK'), icon_name='check')


def confirm_modal(i, n):
    return ('<div class="ews-ux-modal is-open mock-open" data-type="warning" role="dialog" aria-modal="true"><div class="ews-ux-modal-card wfo-om-confirm">'
            '<button type="button" class="ews-ux-close" aria-label="%s">×</button><div class="ews-ux-icon">!</div><h3>%s</h3><p>%s</p>%s'
            '<div class="ews-ux-actions"><button type="button" class="ews-ux-cancel">%s</button><button type="button" class="ews-ux-confirm">%s</button></div></div></div>') % (
                esc(T('إغلاق', 'Close')), esc(T('اليوم ده هيبقى تحت الحد الأدنى', 'This day will be below the minimum')),
                esc(T('%s هيبقى %d من %d في المكتب (ناقص %d). تحفظ برضه؟' % (day_label(i, True), n, MIN, MIN - n),
                      '%s will have %d of %d in the office (%d short). Save anyway?' % (day_label(i, True), n, MIN, MIN - n))),
                '<ul class="wfo-om-mini"><li><b>%s</b> %s</li><li><b>%s</b> %s</li></ul>' % (
                    esc(T('المبيعات', 'Sales')), esc(T('1 من 4 · ناقص 3', '1 of 4 · 3 short')), esc(T('الحسابات', 'Accounts')), esc(T('1 من 2 · ناقص 1', '1 of 2 · 1 short'))),
                esc(T('أرجع أعدّل', 'Go back')), esc(T('احفظ برضه', 'Save anyway')))


# ---------------------------------------------------------------- the Attendance page (from the real page)

def attendance(variant):
    """variant: week | teams | save | phone"""
    def make():
        h = open(os.path.join(KIT, 'snap', 'attendance.%s.html' % Lang.code)).read()
        h = h.replace('{{ASSETS}}', Build.assets).replace('BA Team', T('شركة النيل للمقاولات', 'Nile Contracting'))
        req = Build.assets + 'css/' + ('app-requests-rtl.css' if Lang.rtl() else 'app-requests.css')
        feat = '<link rel="stylesheet" href="%s"><link rel="stylesheet" href="%s">' % (req, CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS)
        h = h.replace('</head>', feat + '<link rel="stylesheet" href="%smock.css"></head>' % Build.kit, 1)
        # the counter under each day: the table head and the "Set… for the whole day" pills
        heads = list(re.finditer(r'(<div class="ews-att-day-meta">)', h))
        for i, m in reversed(list(enumerate(heads))):
            h = h[:m.start()] + counter(i, 9 if (variant == 'save' and i == 4) else None) + h[m.start():]
        quick = list(re.finditer(r'(</select>\s*</div>)', h[h.index('ews-day-quick-actions'):h.index('ews-att-table-wrap')]))
        base = h.index('ews-day-quick-actions')
        for i, m in reversed(list(enumerate(quick))):
            if i < 7:
                pos = base + m.end() - len('</div>')
                h = h[:pos] + counter(i, 9 if (variant == 'save' and i == 4) else None) + h[pos:]
        # the short days' columns
        h = re.sub(r'(<th class=")([^"]*)(" scope="col">\s*<div class="ews-att-day-name">)', lambda m: m.group(1) + m.group(2) + ' wfo-om-col' + m.group(3), h)
        h = h.replace('<div class="ews-att-table-wrap', banner({4: 9} if variant == 'save' else None) + '<div class="ews-att-table-wrap', 1)
        if variant == 'save':
            # one change not saved yet: Thursday, Ahmed Samir Office → Vacation
            h = h.replace('No changes yet. Tap a day to change it.', '1 change not saved yet').replace('لا توجد تغييرات بعد. اضغط على يوم لتغييره.', 'تغيير واحد لم يُحفظ بعد')
            h = h.replace('</body>', confirm_modal(4, 9) + '</body>', 1)
        if variant == 'teams':
            h = h.replace('</body>', teams_sheet(4) + '</body>', 1)
        return h
    return make


# ---------------------------------------------------------------- the leave decision

def leave_decide():
    def make():
        card = ('<div class="ews-vac-card wfo-leave-card wfo-decide"><div class="wfo-card-title"><span class="wfo-card-icon is-amber">%s</span><h3>%s</h3></div>'
                '<div class="ews-vac-row"><div class="wfo-req-main"><div class="ews-vac-date"><strong>%s</strong><span>%s</span></div>'
                '<div class="ews-vac-meta">%s</div>'
                '<div class="wfo-om-note">%s<div><strong>%s</strong>%s</div></div>'
                '<div class="ews-vac-actions"><form onsubmit="return false"><button class="button button-primary wfo-btn-approve">%s%s</button><button class="button wfo-btn-reject">%s%s</button></form></div>'
                '</div><span class="ews-vac-status ews-vac-pending">%s%s</span></div></div>') % (
                    icon('tasks', 20), esc(T('طلبات إجازة مستنية موافقتك', 'Leave Requests Awaiting Your Approval')),
                    esc(T('أحمد سمير', 'Ahmed Samir')), esc(T('إجازة اعتيادية', 'Vacation')),
                    esc(T('الخميس 15 أكتوبر · يوم واحد · مشوار عائلي', 'Thu 15 Oct · 1 day · Family errand')), icon('office', 18, 2.2),
                    esc(T('الموافقة هتخلّي الخميس 9 من 12 في المكتب', 'Approving makes Thursday 9 of 12 in the office')),
                    esc(T('المبيعات هيبقى عندهم 1 من نصيبهم 4. ده تحذير بس، تقدر توافق عادي.',
                          'Sales would have 1 of their share of 4. This is only a warning; you can still approve.')),
                    icon('check', 16, 2.4), esc(T('موافقة', 'Approve')), icon('close', 16, 2.2), esc(T('رفض', 'Reject')),
                    icon('overtime', 14, 2.2), esc(T('قيد الانتظار', 'Pending')))
        return app_page(T('الإجازات', 'Leave'), '<div class="ews-page ews-vac wfo-leave">%s</div>' % card, active='vacation', css_files=['app-leave.css'], feature_css=CSS,
                        who=('هبة فاروق', 'Heba Farouk'))
    return make


# ---------------------------------------------------------------- wp-admin

SUBMENU = [('emp', ('الموظفون', 'Employees')), ('teams', ('الفرق', 'Teams')), ('sched', ('إعدادات الجدول', 'Schedule Configuration')),
           ('features', ('إعدادات الميزات', 'Feature Configuration'))]


def admin_settings(state='default'):
    def make():
        days = ''.join('<label class="wfo-om-wd"><span>%s</span><input type="number" min="0" class="small-text" placeholder="%d"%s></label>' % (
            esc(day_label(i)), MIN, ' value="8"' if i == 4 else '') for i in range(5))
        teams = ''.join('<tr><td>%s</td><td>%d</td><td>%d</td><td><input type="number" min="0" class="small-text" placeholder="%s"%s></td></tr>' % (
            esc(T(*n)), size, share, esc(T('تلقائي', 'Auto')), ' value="3"' if n[1] == 'Customer Service' else '') for n, size, share, _ in TEAMS[:4])
        statuses = ''.join('<label><input type="checkbox"%s> %s</label><br>' % (' checked' if k == 'Office' else '', esc(T(*l))) for k, l in [
            ('Office', ('مكتب', 'Office')), ('WFH', ('من البيت', 'WFH')), ('Business Trip', ('رحلة عمل', 'Business Trip')), ('Training Course', ('دورة تدريبية', 'Training Course'))])
        notice = '<div class="notice notice-success is-dismissible"><p>%s</p></div>' % esc(T('تم حفظ الإعدادات.', 'Settings saved.')) if state == 'saved' else ''
        switch = '<label class="wfo-feature-status"><input type="checkbox" checked> %s</label>' % esc(T('مفعّل', 'Enabled'))
        rows = [
            (T('الحد الأدنى كل يوم', 'Minimum each day'), '<input type="number" min="1" class="small-text" value="%d"> %s<p class="description">%s</p>' % (
                MIN, esc(T('شخص في المكتب', 'people in the office')), esc(T('أيام العمل بس؛ الإجازات الرسمية وأيام الراحة مش بتتحسب.', 'Working days only; company holidays and days off are not checked.')))),
            (T('يوم مختلف (اختياري)', 'A different day (optional)'), '<div class="wfo-om-wds">%s</div><p class="description">%s</p>' % (days, esc(T('فاضي = زي الحد الأدنى.', 'Empty = the minimum above.')))),
            (T('مين بيتحسب في المكتب', 'Who counts as in the office'), statuses + '<p class="description">%s</p>' % esc(T('الإجازات والمأموريات مش بتتحسب.', 'Leave and trips never count.'))),
            (T('نصيب كل فريق', 'Each team\'s share'), ('<p>%s</p><table class="widefat striped wfo-om-share"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>'
                                                    '<p class="description">%s</p>') % (
                esc(T('تلقائي بالنسبة لعدد الفريق. تقدر تحط رقم ثابت لفريق معيّن.', 'Automatic, by team size. You can fix a number for a team.')),
                esc(T('الفريق', 'Team')), esc(T('العدد', 'People')), esc(T('النصيب التلقائي', 'Automatic share')), esc(T('رقم ثابت', 'Fixed number')), teams,
                esc(T('الناس اللي مش في فريق ليهم نصيب مع بعض («بدون فريق»).', 'People in no team share one line ("No team").')))),
            (T('التذكير', 'Reminder'), '<label><input type="checkbox" checked> %s</label><br><label>%s <select><option>%s</option></select></label>' % (
                esc(T('كل أسبوع، ابعت لمديري الجدول الأيام اللي تحت الحد الأدنى في الأسبوع الجاي', 'Every week, send schedule managers the days below the minimum next week')),
                esc(T('يوم التذكير', 'Reminder day')), esc(T('الخميس 12:00', 'Thursday 12:00')))),
        ]
        form = '<table class="form-table" role="presentation">%s</table>' % ''.join('<tr><th scope="row">%s</th><td>%s</td></tr>' % (esc(k), v) for k, v in rows)
        body = ('<h1>%s</h1>%s<div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div>%s</div>'
                '<div class="wfo-om-admin">%s</div></div><p class="submit"><button class="button button-primary">%s</button></p>') % (
                    esc(T('إعدادات الميزات', 'Feature Configuration')), notice, esc(T('الحد الأدنى في المكتب', 'Office Minimum')),
                    esc(T('عدد ثابت لازم يبقى في المكتب كل يوم عمل. اللي بيعمل الجدول بيشوف تحذير والفرق اللي تحت نصيبها؛ الحفظ عمره ما بيتمنع.',
                          'A fixed number of people in the office every working day. Whoever sets the schedule sees a warning and the teams under their share; saving is never blocked.')),
                    switch, form, esc(T('حفظ التغييرات', 'Save Changes')))
        return admin_page(T('إعدادات الميزات', 'Feature Configuration'), body, SUBMENU, 'features', css_files=['admin-features.css'], feature_css=CSS)
    return make


def build():
    for slug, make in [('week', attendance('week')), ('teams', attendance('teams')), ('save', attendance('save')), ('phone', attendance('phone')),
                       ('leave', leave_decide()), ('settings', admin_settings())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Office minimum — mockups (Step 1)', (
        '<p>A fixed number of people in the office every working day (here <b>12</b> of 40). The Attendance page is the real page (a test site with 4 teams of 12, 10, 8 and 6 people '
        'and 4 people without a team, next week\'s schedule) with the new parts added. Only a warning: nothing is blocked. Each team\'s share is proportional to its size.</p>'), [
        ('Whoever sets the schedule — app → Attendance', 'Every day shows "in the office / minimum"; days below it are red and listed at the top with their teams.', [
            ('week', 'The week: Tuesday 9 of 12, Thursday 10 of 12', D), ('teams', 'Teams on Thursday: who is under their share, who could come in', D),
            ('save', 'Saving a change that makes a day short: warning, "Save anyway"', D), ('phone', 'On a phone', P)]),
        ('Approving leave', '', [('leave', 'The manager sees what approving does to the day', P)]),
        ('wp-admin', '', [('settings', 'Feature Configuration → Office Minimum (on / off and its settings)', D)]),
    ])
