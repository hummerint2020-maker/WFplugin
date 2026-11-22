"""Requests Hub: a "Reports" tab (owner request 2026-11-22): "every request, even the approved ones, in the Requests Hub under a
new tab called Reports". Owner's answers: every status; read only (deciding stays on the first tab); CSV and Excel export; for
each request the final decision, who took it and when, and the reason of a rejection. Off by default (its own switch): with it
off the Requests Hub is exactly as today, with no tabs.
"""
from build import T, esc, admin_page, write, gallery

F = 'hub-reports'
CSS = 'hub-reports.css'
SUB = [('dash', ('لوحة التحكم', 'Dashboard')), ('emp', ('الموظفين', 'Employees')), ('req', ('مركز الطلبات', 'Requests Hub')),
       ('rep', ('التقارير', 'Reports')), ('appr', ('مسارات الموافقات', 'Approval Workflows')), ('feat', ('المميزات', 'Features'))]

# (status key, Arabic, English)
ST = {'wait': ('مستني قرار', 'Waiting'), 'ok': ('اتوافق', 'Approved'), 'no': ('اترفض', 'Rejected'),
      'wd': ('اتسحب', 'Withdrawn'), 'cx': ('اتلغى', 'Cancelled')}

# Request, employee, details, status, requested, decided by, decided on, note
ROWS = [
    (('إجازة / سنوية', 'Leave / Annual'), ('كريم ناجي', 'Karim Nagi'), ('١٢–١٤ نوفمبر · ٣ أيام', '12–14 Nov · 3 days'), 'ok', ('١٠ نوفمبر ٠٩:١٤', '10 Nov 09:14'), ('أحمد سمير', 'Ahmed Samir'), ('١٠ نوفمبر ١١:٠٢', '10 Nov 11:02'), ('', '')),
    (('وقت إضافي', 'Overtime'), ('منى عادل', 'Mona Adel'), ('٩ نوفمبر · ١٨:٠٠ ← ٢٠:٣٠ · ٢س ٣٠د', '9 Nov · 18:00 → 20:30 · 2h 30m'), 'no', ('٩ نوفمبر ٢١:٠٥', '9 Nov 21:05'), ('هبة فاروق', 'Heba Farouk'), ('١٠ نوفمبر ٠٨:٤٠', '10 Nov 08:40'), ('مفيش شغل متفق عليه بعد الساعة ٦', 'No agreed work after 6 pm')),
    (('تبديل يوم', 'Shift swap'), ('سارة حسن', 'Sara Hassan'), ('١٨ نوفمبر · مكتب ↔ من البيت · مع عمر خالد', '18 Nov · Office ↔ WFH · with Omar Khaled'), 'wait', ('٨ نوفمبر ١٣:٢٠', '8 Nov 13:20'), ('', ''), ('', ''), ('المستوى ١ من ٢', 'Level 1 of 2')),
    (('انصراف مبكر', 'Early leave'), ('عمر خالد', 'Omar Khaled'), ('٧ نوفمبر · ساعة', '7 Nov · 1h'), 'wd', ('٦ نوفمبر ١٠:٠٠', '6 Nov 10:00'), ('عمر خالد', 'Omar Khaled'), ('٦ نوفمبر ١٢:٣٠', '6 Nov 12:30'), ('سحبه الموظف', 'Withdrawn by the employee')),
    (('إلغاء إجازة / سنوية', 'Leave cancellation / Annual'), ('كريم ناجي', 'Karim Nagi'), ('من ١٣ نوفمبر · ٢ يوم يرجعوا', 'From 13 Nov · 2 days back'), 'ok', ('١١ نوفمبر ٠٨:٠٥', '11 Nov 08:05'), ('أحمد سمير', 'Ahmed Samir'), ('١١ نوفمبر ٠٩:٣٠', '11 Nov 09:30'), ('', '')),
    (('تصحيح حضور', 'Attendance correction'), ('ياسمين علي', 'Yasmin Ali'), ('٥ نوفمبر · دخول ٠٩:٤٠ ← ٠٩:٠٥', '5 Nov · Sign-in 09:40 → 09:05'), 'ok', ('٥ نوفمبر ١٠:١٢', '5 Nov 10:12'), ('هبة فاروق (HR)', 'Heba Farouk (HR)'), ('٥ نوفمبر ١٥:٤٤', '5 Nov 15:44'), ('المستوى ٢ من ٢', 'Level 2 of 2')),
    (('خطاب HR', 'HR letter'), ('منى عادل', 'Mona Adel'), ('شهادة مرتب · عربي · إلى بنك مصر', 'Salary certificate · Arabic · to Banque Misr'), 'ok', ('٤ نوفمبر ١١:٠٠', '4 Nov 11:00'), ('هبة فاروق', 'Heba Farouk'), ('٤ نوفمبر ١٢:١٥', '4 Nov 12:15'), ('اتصدر', 'Issued')),
    (('إجازة / عارضة', 'Leave / Casual'), ('سارة حسن', 'Sara Hassan'), ('٣ نوفمبر · يوم', '3 Nov · 1 day'), 'cx', ('١ نوفمبر ٠٩:٠٠', '1 Nov 09:00'), ('أحمد سمير', 'Ahmed Samir'), ('٢ نوفمبر ١٠:٢٠', '2 Nov 10:20'), ('اتلغت بطلب الموظف', 'Cancelled at the employee\'s request')),
    (('إعادة بصمة الوش', 'Face reset'), ('عمر خالد', 'Omar Khaled'), ('الصورة القديمة مش واضحة', 'Old photo unclear'), 'no', ('١ نوفمبر ٠٨:١٥', '1 Nov 08:15'), ('هبة فاروق', 'Heba Farouk'), ('١ نوفمبر ٠٩:٠٠', '1 Nov 09:00'), ('الصورة الحالية سليمة', 'The current photo is fine')),
]


def tabs(cur, waiting=3):
    items = [('pending', T('مستني قرار', 'Waiting for a decision'), waiting), ('reports', T('تقارير', 'Reports'), None)]
    return '<nav class="nav-tab-wrapper wp-clearfix">%s</nav>' % ''.join(
        '<a href="#" class="nav-tab%s">%s%s</a>' % (' nav-tab-active' if k == cur else '', esc(label),
                                                   ' <span class="ews-hr-count">%d</span>' % n if n else '') for k, label, n in items)


def pill(st):
    return '<span class="ews-hr-st is-%s">%s</span>' % (st, esc(T(*ST[st])))


def filters(typ='all', status='all', who='all'):
    def sel(name, opts, cur):
        return '<label class="ews-hr-f"><span>%s</span><select>%s</select></label>' % (esc(name), ''.join(
            '<option%s>%s</option>' % (' selected' if k == cur else '', esc(T(*l))) for k, l in opts))
    types = [('all', ('كل الأنواع', 'All types')), ('leave', ('إجازة', 'Leave')), ('lc', ('إلغاء إجازة', 'Leave cancellation')), ('ot', ('وقت إضافي', 'Overtime')),
             ('el', ('انصراف مبكر', 'Early leave')), ('sw', ('تبديل يوم', 'Shift swap')), ('cx', ('تصحيح حضور', 'Attendance correction')),
             ('hl', ('خطاب HR', 'HR letter')), ('fr', ('إعادة بصمة الوش', 'Face reset'))]
    states = [('all', ('كل الحالات', 'All statuses'))] + [(k, v) for k, v in ST.items()]
    people = [('all', ('كل الموظفين', 'Everyone')), ('dep', ('قسم: المبيعات', 'Department: Sales')), ('emp', ('كريم ناجي', 'Karim Nagi'))]
    return ('<form class="ews-hr-filters" onsubmit="return false">'
            '<label class="ews-hr-f"><span>%s</span><input type="date" value="2026-11-01"></label>'
            '<label class="ews-hr-f"><span>%s</span><input type="date" value="2026-11-30"></label>%s%s%s'
            '<div class="ews-hr-go"><button class="button">%s</button></div>'
            '<div class="ews-hr-export"><button class="button">%s</button> <button class="button">%s</button></div></form>') % (
                esc(T('من', 'From')), esc(T('إلى', 'To')),
                sel(T('النوع', 'Type'), types, typ), sel(T('الحالة', 'Status'), states, status), sel(T('الموظف أو القسم', 'Employee or department'), people, who),
                esc(T('عرض', 'Show')), esc(T('تصدير CSV', 'Export CSV')), esc(T('تصدير Excel', 'Export Excel')))


def summary(counts):
    return '<ul class="ews-hr-sum">%s</ul>' % ''.join('<li class="is-%s"><b>%s</b><span>%s</span></li>' % (k, esc(n), esc(T(*l))) for k, n, l in counts)


def table(rows):
    head = [T('الطلب', 'Request'), T('الموظف', 'Employee'), T('التفاصيل', 'Details'), T('الحالة', 'Status'), T('اتطلب', 'Requested'),
            T('القرار', 'Decided'), T('ملاحظة', 'Note')]
    tr = ''
    for req, emp, det, st, at, by, on, note in rows:
        decided = '<span class="ews-hr-by">%s</span><small>%s</small>' % (esc(T(*by)), esc(T(*on))) if T(*by) else '<span class="ews-hr-none">—</span>'
        tr += '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>' % (
            esc(T(*req)), esc(T(*emp)), esc(T(*det)), pill(st), esc(T(*at)), decided, esc(T(*note)) or '<span class="ews-hr-none">—</span>')
    return '<div class="ews-hr-table"><table class="wp-list-table widefat striped"><thead><tr>%s</tr></thead><tbody>%s</tbody></table></div>' % (
        ''.join('<th>%s</th>' % esc(h) for h in head), tr)


def pager(text):
    return ('<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num">%s</span>'
            '<span class="pagination-links"><span class="button disabled">‹</span> <span class="paging-input">1 / 16</span> <a class="button" href="#">›</a></span></div></div>') % esc(text)


def intro():
    return '<h1>%s</h1><p class="ews-hr-lead">%s</p>' % (esc(T('مركز الطلبات', 'Requests Hub')),
        esc(T('مركز الطلبات لكل طلبات Workforce One. المسؤولين يراجعوا ويوافقوا أو يرفضوا من مكان واحد.', 'Central request center for Workforce One requests. Administrators can review, approve or reject requests from one place.')))


def pending():
    """The first tab: today's table, unchanged."""
    def make():
        rows = [(T('إجازة / سنوية', 'Vacation / Annual'), T('كريم ناجي', 'Karim Nagi'), T('١٨–٢٠ نوفمبر · ٣ أيام', '18–20 Nov · 3 day(s)'), T('موافقة #٢١٤ · المستوى ١', 'Approval #214 · Level 1'), '2026-11-21 09:14'),
                (T('تبديل يوم', 'Shift Swap'), T('سارة حسن', 'Sara Hassan'), T('١٨ نوفمبر · مكتب ↔ من البيت', '18 Nov · Office ↔ WFH'), T('موافقة #٢١١ · المستوى ١', 'Approval #211 · Level 1'), '2026-11-20 13:20'),
                (T('وقت إضافي', 'Overtime'), T('منى عادل', 'Mona Adel'), T('٢١ نوفمبر · ١٨:٠٠ ← ٢٠:٠٠ · ٢س', '21 Nov · 18:00 → 20:00 · 2h'), T('مباشر', 'Legacy / direct'), '2026-11-21 21:05')]
        tr = ''.join('<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><button class="button button-primary">%s</button> <button class="button">%s</button></td></tr>' % (
            esc(a), esc(b), esc(c), esc(d), esc(e), esc(T('موافقة', 'Approve')), esc(T('رفض', 'Reject'))) for a, b, c, d, e in rows)
        body = intro() + tabs('pending') + ('<div class="ews-hr-table"><table class="wp-list-table widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table></div>'
                                            '<p class="description">%s</p>') % (
            esc(T('الطلب', 'Request')), esc(T('الموظف', 'Employee')), esc(T('التفاصيل', 'Details')), esc(T('الموافقة', 'Approval')), esc(T('اتطلب', 'Requested')), esc(T('إجراء', 'Action')), tr,
            esc(T('التاب ده هو الجدول الحالي زي ما هو. التاب الجديد بيظهر بس لما المفتاح يتشغل.', 'This tab is today\'s table as it is. The new tab shows only once the switch is on.')))
        return admin_page(T('مركز الطلبات', 'Requests Hub'), body, SUB, 'req', feature_css=CSS)
    return make


def reports(variant='all'):
    """variant: all (this month, every type and status) | rejected (Leave, Rejected: the reasons) | empty"""
    def make():
        if variant == 'all':
            f = filters()
            s = summary([('all', T('٣١٢', '312'), ('طلب', 'requests')), ('ok', T('٢٦٠', '260'), ('اتوافق', 'approved')),
                         ('no', T('٢٩', '29'), ('اترفض', 'rejected')), ('wd', T('١٤', '14'), ('اتسحب أو اتلغى', 'withdrawn or cancelled')),
                         ('wait', T('٩', '9'), ('مستني قرار', 'waiting'))])
            body = f + s + table(ROWS) + pager(T('٣١٢ طلب · ٢٠ في الصفحة', '312 requests · 20 a page'))
        elif variant == 'rejected':
            f = filters('leave', 'no', 'dep')
            rows = [
                (('إجازة / سنوية', 'Leave / Annual'), ('ليلى مراد', 'Laila Mourad'), ('٢٤–٢٨ نوفمبر · ٥ أيام', '24–28 Nov · 5 days'), 'no', ('١٥ نوفمبر ١٠:٠٠', '15 Nov 10:00'), ('أحمد سمير', 'Ahmed Samir'), ('١٥ نوفمبر ١٣:١٠', '15 Nov 13:10'), ('نص القسم في إجازة الأسبوع ده', 'Half the department is off that week')),
                (('إجازة / عارضة', 'Leave / Casual'), ('حسام فؤاد', 'Hossam Fouad'), ('١٠ نوفمبر · يوم', '10 Nov · 1 day'), 'no', ('٩ نوفمبر ٢٢:٤٠', '9 Nov 22:40'), ('أحمد سمير', 'Ahmed Samir'), ('١٠ نوفمبر ٠٨:٠٥', '10 Nov 08:05'), ('يوم تسليم مشروع', 'Project delivery day')),
                (('إجازة / سنوية', 'Leave / Annual'), ('كريم ناجي', 'Karim Nagi'), ('٢–٦ نوفمبر · ٥ أيام', '2–6 Nov · 5 days'), 'no', ('٢٨ أكتوبر ١٢:٠٠', '28 Oct 12:00'), ('هبة فاروق (المستوى ٢)', 'Heba Farouk (level 2)'), ('٢٩ أكتوبر ٠٩:٣٠', '29 Oct 09:30'), ('الرصيد مش كفاية بعد الموافقات السابقة', 'Not enough balance after earlier approvals')),
            ]
            body = f + summary([('no', T('٣', '3'), ('إجازة اترفضت في المبيعات', 'leave requests rejected in Sales'))]) + table(rows) + pager(T('٣ طلبات', '3 requests'))
        else:
            body = filters('hl', 'all', 'all') + '<div class="ews-hr-empty"><h2>%s</h2><p>%s</p></div>' % (
                esc(T('مفيش طلبات في الفترة دي', 'No requests in this period')), esc(T('غيّر الفترة أو الفلاتر.', 'Change the period or the filters.')))
        note = '<p class="description">%s</p>' % esc(T(
            'للعرض بس: القرار بيتاخد من تاب "مستني قرار" أو من موافقاتي. الأحدث الأول. التصدير بيطلع نفس اللي الفلاتر بتعرضه، كل الصفحات.',
            'View only: decisions are taken on "Waiting for a decision" or in My approvals. Newest first. The export has what the filters show, every page.'))
        return admin_page(T('مركز الطلبات', 'Requests Hub'), intro() + tabs('reports') + body + note, SUB, 'req', feature_css=CSS)
    return make


def features():
    def make():
        body = ('<h1>%s</h1><div class="ews-hr-box"><h2>%s <span class="ews-hr-off">%s</span></h2><p>%s</p><p><label><input type="checkbox"> %s</label></p>'
                '<p class="description">%s</p></div>') % (
            esc(T('المميزات', 'Features')), esc(T('تقارير مركز الطلبات', 'Requests Hub reports')), esc(T('مقفول', 'Off')),
            esc(T('تاب "تقارير" في مركز الطلبات: كل الطلبات بكل حالاتها، مين قرّر وإمتى، مع التصدير. للعرض بس.', 'A "Reports" tab on the Requests Hub: every request in every status, who decided and when, with an export. View only.')),
            esc(T('تشغيل تقارير مركز الطلبات', 'Turn Requests Hub reports on')),
            esc(T('وهو مقفول مركز الطلبات زي ما هو النهارده من غير تابات.', 'Off, the Requests Hub is exactly as today, with no tabs.')))
        return admin_page(T('المميزات', 'Features'), body, SUB, 'feat', feature_css=CSS)
    return make


def build():
    for slug, make in [('pending', pending()), ('reports', reports('all')), ('reports-rejected', reports('rejected')), ('reports-empty', reports('empty')), ('features', features())]:
        write(F, slug, make)
    D = 'desk'
    gallery(F, 'Requests Hub reports — mockups', (
        '<p><b>Owner request (2026-11-22):</b> every request, even the approved ones, in the Requests Hub under a new tab called Reports.</p>'
        '<p><b>Owner answers:</b> every status; view only (decisions stay on the first tab and in My approvals); CSV and Excel export; for each '
        'request the final decision, who took it and when, and a rejection&#39;s reason. Off by default: with the switch off the Requests Hub is '
        'exactly as today.</p>'), [
        ('The Requests Hub', '', [('pending', 'Tab 1 "Waiting for a decision": today\'s table, unchanged', D),
                                  ('reports', 'Tab 2 "Reports": this month, every type and status', D),
                                  ('reports-rejected', 'Filtered: Leave, Rejected, Sales: the reasons', D),
                                  ('reports-empty', 'Nothing in the period', D)]),
        ('The switch', '', [('features', 'Features: Requests Hub reports (off)', D)]),
    ])
