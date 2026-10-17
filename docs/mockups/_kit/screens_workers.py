"""Daily workers: the screens of docs/tasks/daily-workers.md (Step 1)."""
from build import T, Lang, esc, icon, app_page, admin_page, page_doc, sheet, ux_modal, write, gallery, Build, css

F = 'daily-workers'
CSS = 'daily-workers.css'
APP_CSS = ['app-requests.css']
FOREMAN = ('سيد رجب', 'Sayed Ragab')
RAIL = [('dashboard', 'home', ('الرئيسية', 'Dashboard')), ('sites', 'site', ('مواقعي', 'My sites')), ('time', 'clock', ('الحضور / الانصراف', 'Sign In / Out')),
        ('payout', 'cash', ('اليومية', 'Payout'))]
TABS = [('dashboard', 'home', ('الرئيسية', 'Dashboard')), ('sites', 'site', ('مواقعي', 'My sites')), ('time', None, None), ('payout', 'cash', ('اليومية', 'Payout')),
        ('more', 'more', ('المزيد', 'More'))]

# name, trade, daily rate (EGP), hourly rate, mark, extra hours, subcontractor
WORKERS = [
    (('محمد عبد الله', 'Mohamed Abdallah'), ('نجّار مسلّح', 'Formwork carpenter'), 450, 60, 'in', 2, None),
    (('إبراهيم فتحي', 'Ibrahim Fathy'), ('حدّاد مسلّح', 'Steel fixer'), 450, 60, 'in', 2, None),
    (('سعيد عبد الرحيم', 'Saeed Abdelrahim'), ('مبيّض محارة', 'Plasterer'), 400, 55, 'in', 0, None),
    (('أحمد شعبان', 'Ahmed Shaaban'), ('مساعد', 'Helper'), 300, 40, 'half', 0, None),
    (('مصطفى ربيع', 'Mostafa Rabie'), ('سبّاك', 'Plumber'), 480, 65, 'in', 0, ('مقاولات الأمل', 'Al-Amal Contracting')),
    (('علي حسن', 'Ali Hassan'), ('مساعد', 'Helper'), 300, 40, 'out', 0, None),
    (('رمضان عوض', 'Ramadan Awad'), ('نجّار مسلّح', 'Formwork carpenter'), 450, 60, 'in', 0, None),
    (('عادل منصور', 'Adel Mansour'), ('مساعد', 'Helper'), 280, 40, 'in', 1, ('مقاولات الأمل', 'Al-Amal Contracting')),
]
SITE = ('التجمع الخامس – عمارة 12', 'Fifth Settlement – Building 12')


def egp(n):
    return T('%s ج.م' % format(n, ','), 'EGP %s' % format(n, ','))


def initials(name):
    return ''.join(p[0] for p in name.split()[:2])


def chip(text, kind=''):
    return '<span class="wfo-dw-chip %s">%s</span>' % (kind, esc(text))


def fpage(title, body, active, fab=''):
    return app_page(title, body, active=active, css_files=APP_CSS, feature_css=CSS, who=FOREMAN, rail=RAIL, tabs=TABS, fab=fab)


# ---------------------------------------------------------------- foreman app

def fm_sites(state='default'):
    def make():
        if state == 'empty':
            body = ('<div class="wfo-dw"><section class="wfo-rq-card"><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true">%s</span><strong>%s</strong><p>%s</p></div></section></div>') % (
                icon('site', 24, 2), esc(T('مفيش مواقع متسجّلة لك', 'No sites assigned to you')),
                esc(T('اطلب من مدير الموقع يضيفك كمشرف على موقع من لوحة التحكم.', 'Ask the site manager to add you as foreman of a site in wp-admin.')))
            return fpage(T('مواقعي', 'My sites'), body, 'sites')
        sites = [
            (SITE, T('مشروع جاردن هايتس · من 1 سبتمبر', 'Garden Heights project · since 1 Sep'), [chip(T('18 عامل', '18 workers'), 'is-pri'), chip(T('اليومية أسبوعية', 'Paid weekly'))],
             ('go', T('سجّل يومية النهارده', 'Record today'))),
            (('الشيخ زايد – فيلا 7', 'Sheikh Zayed – Villa 7'), T('تشطيبات · من 20 سبتمبر', 'Finishing works · since 20 Sep'),
             [chip(T('9 عمال', '9 workers'), 'is-pri'), chip(T('اليومية يومية', 'Paid daily'))], ('done', T('تم التسجيل 08:10 · 9 حاضر', 'Recorded 08:10 · 9 present'))),
        ]
        cards = ''
        for name, sub, chips, (kind, label) in sites:
            cards += ('<a class="wfo-dw-site" href="#"><div class="wfo-dw-site-top"><span class="wfo-dw-site-ico">%s</span><div><h3>%s</h3><p>%s</p></div></div>'
                      '<div class="wfo-dw-site-stats">%s</div><div class="wfo-dw-site-go%s">%s%s</div></a>') % (
                          icon('site', 22, 2), esc(T(*name)), esc(sub), ''.join(chips), ' is-done' if kind == 'done' else '',
                          icon('check' if kind == 'done' else 'edit', 18, 2.2), esc(label))
        hero = ('<section class="wfo-rq-hero"><div class="wfo-rq-hero-main"><p class="wfo-rq-hero-label">%s</p><p class="wfo-rq-hero-value">%s</p><div class="wfo-rq-hero-chips">'
                '<span class="wfo-rq-hero-chip is-wait">%s%s</span><span class="wfo-rq-hero-chip">%s</span></div></div></section>') % (
                    esc(T('الخميس 8 أكتوبر', 'Thursday 8 October')), esc(T('موقعين', '2 sites')), icon('clock', 14, 2.2),
                    esc(T('موقع واحد لسه ما اتسجّلش', '1 site not recorded yet')), esc(T('27 عامل', '27 workers')))
        return fpage(T('مواقعي', 'My sites'), '<div class="wfo-dw">%s%s</div>' % (hero, cards), 'sites')
    return make


def worker_rows(marks=None, locked=False):
    out = ''
    for i, (n, tr, rate, hr, mark, extra, sub) in enumerate(WORKERS):
        if marks:
            mark, extra = marks.get(i, (mark, extra))
        seg = ''.join('<button type="button" class="%s" aria-pressed="%s"%s>%s</button>' % (cls, 'true' if mark == k else 'false', ' disabled' if locked else '', esc(T(*l)))
                      for k, cls, l in [('in', 'is-in', ('حاضر', 'Present')), ('half', 'is-half', ('نص يوم', 'Half day')), ('out', 'is-out', ('غايب', 'Absent'))])
        ext = ('<div class="wfo-dw-extra"><button type="button" aria-label="%s">%s</button><span>%s<small>%s</small></span><button type="button" aria-label="%s">%s</button></div>') % (
            esc(T('أقل', 'Less')), icon('minus', 14, 2.4), esc(T('%d س' % extra, '%d h' % extra)), esc(T('إضافي', 'extra')), esc(T('أكتر', 'More')), icon('plus', 14, 2.4))
        subtxt = (' · ' + T(*sub)) if sub else ''
        out += ('<div class="wfo-dw-worker%s"><div class="wfo-dw-who"><span class="wfo-dw-av">%s</span><div><strong>%s</strong><small>%s · %s%s</small></div>'
                '<button type="button" class="wfo-dw-more" aria-label="%s">%s</button></div><div class="wfo-dw-mark"><div class="wfo-dw-seg">%s</div>%s</div></div>') % (
                    ' is-out' if mark == 'out' else '', esc(initials(T(*n))), esc(T(*n)), esc(T(*tr)), esc(egp(rate)), esc(subtxt), esc(T('المزيد', 'More')),
                    icon('dots', 18, 2), seg, ext)
    return out


def day_cost():
    total = 0
    for n, tr, rate, hr, mark, extra, sub in WORKERS:
        total += {'in': rate, 'half': rate // 2, 'out': 0}[mark] + extra * hr
    return total


def fm_day(state='default'):
    """state: default | outside | nophoto | saved"""
    def make():
        counts = [('6', T('حاضر', 'Present')), ('1', T('نص يوم', 'Half day')), ('1', T('غايب', 'Absent')), ('5', T('س إضافي', 'extra h'))]
        hero = ('<section class="wfo-dw-hero"><small>%s</small><strong>%s</strong><div class="wfo-dw-counts">%s</div></section>') % (
            esc(T('اليوم · الخميس 8 أكتوبر', 'Today · Thursday 8 October')), esc(T(*SITE)),
            ''.join('<div><b>%s</b><span>%s</span></div>' % (a, esc(b)) for a, b in counts))
        if state == 'outside':
            gps = '<div class="wfo-dw-gps is-bad">%s<div>%s<small>%s</small></div></div>' % (
                icon('pin', 20, 2.2), esc(T('أنت بعيد عن الموقع 1.2 كم', 'You are 1.2 km from the site')),
                esc(T('اليومية تتسجّل من داخل الموقع بس (نطاق 150 م). قرّب وجرّب تاني.', 'The day sheet is saved from inside the site only (150 m). Move closer and try again.')))
        else:
            gps = '<div class="wfo-dw-gps">%s<div>%s<small>%s</small></div></div>' % (
                icon('pin', 20, 2.2), esc(T('أنت داخل الموقع (40 م)', 'You are inside the site (40 m)')), esc(T('الموقع اتأكد الساعة 08:05 · الجهاز سليم', 'Checked at 08:05 · device check passed')))
        locked = state == 'saved'
        lst = ('<section class="wfo-dw-list"><div class="wfo-dw-list-head"><h3>%s</h3>%s</div>%s<div class="wfo-dw-total"><span>%s</span><b>%s</b></div></section>') % (
            esc(T('العمال (8)', 'Workers (8)')),
            '' if locked else '<button type="button">%s%s</button>' % (icon('plus', 15, 2.4), esc(T('عامل جديد', 'New worker'))),
            worker_rows(locked=locked), esc(T('تكلفة اليوم', 'Cost of the day')), esc(egp(day_cost())))
        if state == 'nophoto':
            photo = ('<div class="wfo-dw-photo is-error"><span class="wfo-dw-site-ico">%s</span><div><strong>%s</strong>%s</div></div>') % (
                icon('camera', 22, 2.2), esc(T('صوّر العمال الأول', 'Take the group photo first')), esc(T('الصورة مطلوبة لتأكيد اليومية، بالكاميرا مش من المعرض.', 'A photo is needed to confirm the day, from the camera, not the gallery.')))
        elif state in ('default', 'outside'):
            photo = ('<div class="wfo-dw-photo"><span class="wfo-dw-site-ico">%s</span><div><strong>%s</strong>%s</div></div>') % (
                icon('camera', 22, 2.2), esc(T('صورة جماعية للعمال', 'Group photo of the workers')), esc(T('تتاخد بالكاميرا وقت التسجيل', 'Taken with the camera when you record')))
        else:
            photo = ('<div class="wfo-dw-photo is-set"><span class="wfo-dw-shot"></span><div><strong>%s</strong>%s</div></div>') % (
                esc(T('الصورة الجماعية', 'Group photo')), esc(T('08:12 · داخل الموقع', '08:12 · inside the site')))
        if locked:
            confirm = '<div class="wfo-dw-locked">%s%s</div>' % (icon('lock', 18, 2.2), esc(T(
                'اتسجّلت 08:12 بواسطة سيد رجب. أي تعديل على يوم فات بيبقى طلب تصحيح بسبب وموافقة.',
                'Saved at 08:12 by Sayed Ragab. Changing a past day is a correction request with a reason and an approval.')))
        else:
            confirm = '<button class="wfo-dw-confirm" type="button"%s>%s%s</button><p class="wfo-dw-confirm-note">%s</p>' % (
                ' disabled' if state == 'outside' else '', icon('check', 18, 2.4), esc(T('تأكيد يومية النهارده', 'Confirm today\'s sheet')),
                esc(T('بعد التأكيد اليوم يتقفل.', 'Once confirmed, the day is locked.')))
        body = '<div class="wfo-dw">%s%s%s%s%s</div>' % (hero, gps, lst, photo, confirm)
        if state == 'saved':
            body += ux_modal('success', T('تم تسجيل اليومية', 'Day sheet saved'), T(
                '8 عمال في التجمع الخامس – عمارة 12: 6 حاضر، 1 نص يوم، 1 غايب. تكلفة اليوم %s.' % egp(day_cost()),
                '8 workers at Fifth Settlement – Building 12: 6 present, 1 half day, 1 absent. Cost of the day %s.' % egp(day_cost())))
        return fpage(T('يومية الموقع', 'Today at the site'), body, 'sites')
    return make


def fm_add(state='default'):
    def make():
        err = state == 'error'
        f = lambda label, inner, extra='': '<div class="wfo-rq-field%s"><label>%s</label>%s</div>' % (extra, esc(label), inner)
        body = f(T('الاسم', 'Name'), '<input type="text" value="%s">' % esc(T('حسن عبد الفتاح', 'Hassan Abdelfattah')))
        body += f(T('الموبايل', 'Mobile'), '<input type="tel" dir="ltr" value="0101 234 5678">')
        body += '<div class="wfo-rq-two">%s%s</div>' % (
            f(T('المهنة', 'Trade'), '<select><option>%s</option></select>' % esc(T('مساعد', 'Helper'))),
            f(T('اليومية', 'Daily rate'), '<input type="number" value="300">'))
        idv = '29013450101234' if err else '29001150101234'
        msg = ('<p class="wfo-cx-field-msg" style="margin:4px 2px 0;font-size:12px;color:#A51D3E">%s</p>' % esc(T(
            'الرقم القومي غير صحيح: الشهر في تاريخ الميلاد 13.', 'The national ID is not valid: the birth month is 13.'))) if err else (
            '<p style="margin:4px 2px 0;font-size:12px;color:#0F6B43">%s</p>' % esc(T('صحيح · مواليد 15 يناير 1990 · القاهرة', 'Valid · born 15 January 1990 · Cairo')))
        body += f(T('الرقم القومي', 'National ID'), '<input type="text" inputmode="numeric" dir="ltr" value="%s"%s>%s' % (
            idv, ' style="border-color:#E5486B;box-shadow:0 0 0 3px #FFE8EC"' if err else '', msg))
        body += f(T('مقاول الباطن (اختياري)', 'Subcontractor (optional)'), '<select><option>%s</option><option>%s</option></select>' % (esc(T('— بدون —', '— None —')), esc(T('مقاولات الأمل', 'Al-Amal Contracting'))))
        note = T('العامل مالوش إيميل ولا حساب. هيتضاف لموقع التجمع الخامس من النهارده.', 'No email or account needed. He is added to Fifth Settlement from today.')
        return fpage(T('يومية الموقع', 'Today at the site'),
                     '<div class="wfo-dw"><section class="wfo-dw-hero"><small>%s</small><strong>%s</strong></section></div>' % (esc(T('اليوم · الخميس 8 أكتوبر', 'Today · Thursday 8 October')), esc(T(*SITE))) +
                     sheet(T('إضافة عامل', 'Add a worker'), body, T('إضافة', 'Add'), note), 'sites')
    return make


def fm_move():
    def make():
        f = lambda label, inner: '<div class="wfo-rq-field"><label>%s</label>%s</div>' % (esc(label), inner)
        body = ('<div class="wfo-dw-who" style="padding:10px 12px;border-radius:14px;background:var(--wfo-bg)"><span class="wfo-dw-av">%s</span><div><strong>%s</strong><small>%s</small></div></div>') % (
            esc(initials(T('رمضان عوض', 'Ramadan Awad'))), esc(T('رمضان عوض', 'Ramadan Awad')), esc(T('نجّار مسلّح · 450 ج.م', 'Formwork carpenter · EGP 450')))
        body += '<div class="wfo-rq-two">%s%s</div>' % (f(T('من', 'From'), '<input type="text" value="%s" readonly>' % esc(T('التجمع الخامس', 'Fifth Settlement'))),
                                                       f(T('إلى', 'To'), '<select><option>%s</option></select>' % esc(T('الشيخ زايد – فيلا 7', 'Sheikh Zayed – Villa 7'))))
        body += ('<div class="wfo-rq-field"><span class="wfo-rq-label">%s</span><div class="wfo-rq-days"><button type="button" aria-pressed="false">%s</button><button type="button" aria-pressed="true">%s</button>'
                 '<input type="date" value="2026-10-09"></div></div>') % (esc(T('من يوم', 'Starting')), esc(T('النهارده', 'Today')), esc(T('بكرة', 'Tomorrow')))
        note = T('أيامه في التجمع الخامس بتفضل في سجلّه وفي تكلفة المشروع.', 'His days at Fifth Settlement stay in his history and in that project\'s cost.')
        return fpage(T('يومية الموقع', 'Today at the site'), '<div class="wfo-dw"><section class="wfo-dw-hero"><small>%s</small><strong>%s</strong></section></div>' % (
            esc(T('اليوم · الخميس 8 أكتوبر', 'Today · Thursday 8 October')), esc(T(*SITE))) + sheet(T('نقل لموقع تاني', 'Move to another site'), body, T('نقل', 'Move'), note), 'sites')
    return make


EXTRA_WEEK = [6, 4, 0, 0, 0, 0, 0, 3]   # extra hours in the week; amount = days × rate + extra × hourly rate
PAY = [  # name, days, amount, advance
    (('محمد عبد الله', 'Mohamed Abdallah'), '5', 2610, 500), (('إبراهيم فتحي', 'Ibrahim Fathy'), '5', 2490, 0),
    (('سعيد عبد الرحيم', 'Saeed Abdelrahim'), '4.5', 1800, 0), (('أحمد شعبان', 'Ahmed Shaaban'), '4', 1200, 200),
    (('مصطفى ربيع', 'Mostafa Rabie'), '5', 2400, 0), (('علي حسن', 'Ali Hassan'), '3', 900, 0),
    (('رمضان عوض', 'Ramadan Awad'), '5', 2250, 0), (('عادل منصور', 'Adel Mansour'), '5', 1520, 300),
]


def pay(state='default'):
    """state: default | paid | error"""
    def make():
        total = sum(a - b for n, d, a, b in PAY)
        hero = ('<section class="wfo-rq-hero"><div class="wfo-rq-hero-main"><p class="wfo-rq-hero-label">%s</p><p class="wfo-rq-hero-value">%s</p><div class="wfo-rq-hero-chips">'
                '<span class="wfo-rq-hero-chip">%s</span><span class="wfo-rq-hero-chip%s">%s</span></div></div></section>') % (
                    esc(T('التجمع الخامس · الأسبوع 3 – 8 أكتوبر', 'Fifth Settlement · week 3 – 8 Oct')), esc(egp(total)), esc(T('8 عمال', '8 workers')),
                    '' if state == 'paid' else ' is-wait', esc(T('اتصرف · مقفول', 'Paid · locked') if state == 'paid' else T('لسه ما اتصرفش', 'Not paid yet')))
        rows = ''
        for n, d, a, b in PAY:
            rows += ('<div class="wfo-dw-pay-row"><strong>%s</strong><small>%s%s</small><div class="wfo-dw-net">%s<small>%s</small></div></div>') % (
                esc(T(*n)), esc(T('%s أيام · %s' % (d, egp(a)), '%s days · %s' % (d, egp(a)))),
                ('<span class="is-adv"> · %s</span>' % esc(T('سلفة −%s' % egp(b), 'advance −%s' % egp(b)))) if b else '', esc(egp(a - b)), esc(T('الصافي', 'net')))
        lst = '<section class="wfo-dw-pay">%s<div class="wfo-dw-total"><span>%s</span><b>%s</b></div></section>' % (rows, esc(T('الإجمالي بعد السلف', 'Total after advances')), esc(egp(total)))
        if state == 'paid':
            actions = ('<div class="wfo-dw-photo is-set"><span class="wfo-dw-shot" style="background:linear-gradient(135deg,#f2efe6,#d9d2bf)"></span><div><strong>%s</strong>%s</div></div>'
                       '<div class="wfo-dw-locked">%s%s</div>') % (
                esc(T('صورة الكشف الموقّع', 'Signed sheet photo')), esc(T('رفعها سيد رجب الخميس 17:40', 'Uploaded by Sayed Ragab, Thu 17:40')), icon('lock', 18, 2.2),
                esc(T('الأسبوع اتصرف واتقفل. أي سلفة جديدة تتخصم من الأسبوع الجاي.', 'The week is paid and locked. A new advance comes off next week.')))
        else:
            photo_cls = ' is-error' if state == 'error' else ''
            photo_txt = T('ارفع صورة الكشف بعد التوقيع عشان تقفل الأسبوع.', 'Upload the signed sheet to close the week.') if state == 'error' else T('بعد ما العمال يوقّعوا أو يبصموا', 'After the workers sign or thumbprint it')
            actions = ('<div class="wfo-dw-pay-actions"><button class="wfo-dw-btn" type="button">%s%s</button><button class="wfo-dw-btn" type="button">%s%s</button></div>'
                       '<div class="wfo-dw-photo%s"><span class="wfo-dw-site-ico">%s</span><div><strong>%s</strong>%s</div></div>'
                       '<button class="wfo-dw-confirm" type="button">%s%s</button><p class="wfo-dw-confirm-note">%s</p>') % (
                icon('printer', 18, 2), esc(T('طباعة الكشف', 'Print the sheet')), icon('plus', 18, 2.2), esc(T('سلفة', 'Advance')), photo_cls, icon('upload', 22, 2.2),
                esc(T('صورة الكشف الموقّع', 'Signed sheet photo')), esc(photo_txt), icon('check', 18, 2.4), esc(T('تم الصرف · اقفل الأسبوع', 'Paid · lock the week')),
                esc(T('المبالغ ما بتتكتبش في سجل التدقيق، بيتكتب «تم الصرف» بس.', 'Amounts are not written to the Audit Log, only "payout recorded".')))
        body = '<div class="wfo-dw">%s%s%s</div>' % (hero, lst, actions)
        if state == 'paid':
            body += ux_modal('success', T('تم تسجيل الصرف', 'Payout recorded'), T('أسبوع 3 – 8 أكتوبر في التجمع الخامس اتقفل.', 'The week of 3 – 8 Oct at Fifth Settlement is locked.'))
        return fpage(T('اليومية', 'Payout'), body, 'payout')
    return make


# ---------------------------------------------------------------- wp-admin

SUBMENU = [('emp', ('الموظفون', 'Employees')), ('workers', ('العمالة اليومية', 'Daily Workers')), ('sites', ('مواقع العمل', 'Work Locations')),
           ('report', ('تقرير الحضور والانصراف', 'Sign In / Out Report')), ('payroll', ('الرواتب', 'Payroll')), ('features', ('إعدادات الميزات', 'Feature Configuration'))]


def dw_tabs(current):
    items = [('list', T('العمال', 'Workers')), ('reports', T('التقارير', 'Reports')), ('settings', T('الإعدادات', 'Settings'))]
    return '<nav class="nav-tab-wrapper">%s</nav>' % ''.join('<a href="#" class="nav-tab%s">%s</a>' % (' nav-tab-active' if k == current else '', esc(l)) for k, l in items)


def dw_head(tab):
    return '<h1 class="wp-heading-inline">%s</h1> <a href="#" class="page-title-action">%s</a><hr class="wp-header-end">%s' % (
        esc(T('العمالة اليومية', 'Daily Workers')), esc(T('إضافة عامل', 'Add worker')), dw_tabs(tab))


ADMIN_WORKERS = [
    (('محمد عبد الله', 'Mohamed Abdallah'), ('نجّار مسلّح', 'Formwork carpenter'), '0100 445 2189', '2850••••••3317', 450, None, ('التجمع الخامس', 'Fifth Settlement'), 5, 'ok'),
    (('إبراهيم فتحي', 'Ibrahim Fathy'), ('حدّاد مسلّح', 'Steel fixer'), '0112 908 7741', '2920••••••1458', 450, None, ('التجمع الخامس', 'Fifth Settlement'), 4, 'ok'),
    (('مصطفى ربيع', 'Mostafa Rabie'), ('سبّاك', 'Plumber'), '0122 330 5106', '2880••••••2291', 480, ('مقاولات الأمل', 'Al-Amal'), ('التجمع الخامس', 'Fifth Settlement'), 4, 'ok'),
    (('كريم جمال', 'Karim Gamal'), ('كهربائي', 'Electrician'), '0155 812 0094', '2950••••••0876', 500, None, ('الشيخ زايد', 'Sheikh Zayed'), 5, 'ok'),
    (('سعيد عبد الرحيم', 'Saeed Abdelrahim'), ('مبيّض محارة', 'Plasterer'), '0109 671 2235', '2780••••••5532', 400, None, ('التجمع الخامس', 'Fifth Settlement'), 3, 'ok'),
    (('عادل منصور', 'Adel Mansour'), ('مساعد', 'Helper'), '0114 200 8817', '3010••••••4410', 280, ('مقاولات الأمل', 'Al-Amal'), ('التجمع الخامس', 'Fifth Settlement'), 3, 'ok'),
    (('وليد سامي', 'Walid Samy'), ('مساعد', 'Helper'), '0128 554 0912', T('جواز سفر', 'Passport') + ' ••••7781', 300, None, ('—', '—'), 2, 'bad'),
]


def stars(n):
    return '<span class="ews-dw-stars">%s<i>%s</i></span>' % ('★' * n, '★' * (5 - n))


def adm_workers(state='default'):
    def make():
        sel = lambda opts: '<select>%s</select>' % ''.join('<option>%s</option>' % esc(o) for o in opts)
        filters = '<div class="ews-dw-filters"><input type="search" placeholder="%s">%s%s%s%s%s<button class="button">%s</button></div>' % (
            esc(T('الاسم أو الموبايل أو الرقم القومي كامل', 'Name, mobile or full national ID')),
            sel([T('كل المهن', 'All trades'), T('نجّار مسلّح', 'Formwork carpenter'), T('حدّاد مسلّح', 'Steel fixer'), T('مساعد', 'Helper')]),
            sel([T('كل مقاولي الباطن', 'All subcontractors'), T('بدون', 'None'), T('مقاولات الأمل', 'Al-Amal Contracting')]),
            sel([T('كل المواقع', 'All sites'), T('التجمع الخامس', 'Fifth Settlement'), T('الشيخ زايد', 'Sheikh Zayed')]),
            sel([T('كل الحالات', 'All statuses'), T('نشط', 'Active'), T('لا يُعاد تشغيله', 'Do not rehire')]),
            sel([T('أي تقييم', 'Any rating'), '★★★★+', '★★★+']), esc(T('تصفية', 'Filter')))
        head = [T('العامل', 'Worker'), T('المهنة', 'Trade'), T('الموبايل', 'Mobile'), T('الرقم القومي', 'National ID'), T('اليومية', 'Daily rate'), T('مقاول الباطن', 'Subcontractor'),
                T('موقع النهارده', 'Site today'), T('التقييم', 'Rating'), T('الحالة', 'Status')]
        if state == 'empty':
            tbody = '<tr class="no-items"><td colspan="9">%s</td></tr>' % esc(T('مفيش عمال لسه. أضف أول عامل، أو خلّي المشرف يضيفهم من الموقع.', 'No workers yet. Add the first one, or let a foreman add them on site.'))
        else:
            tbody = ''.join(('<tr><td><span class="ews-dw-name"><span class="ews-dw-av">%s</span><span><strong><a href="#">%s</a></strong><span class="ews-dw-sub">dw-%d</span></span></span></td>'
                             '<td>%s</td><td dir="ltr">%s</td><td><span class="ews-dw-id">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><span class="ews-dw-badge %s">%s</span></td></tr>') % (
                esc(initials(T(*n))), esc(T(*n)), 1040 + i, esc(T(*tr)), mob, esc(nid), esc(egp(rate)), esc(T(*sub)) if sub else '—', esc(T(*site)), stars(r),
                'is-ok' if st == 'ok' else 'is-bad', esc(T('نشط', 'Active') if st == 'ok' else T('لا يُعاد تشغيله', 'Do not rehire'))) for i, (n, tr, mob, nid, rate, sub, site, r, st) in enumerate(ADMIN_WORKERS))
        table = '<table class="wp-list-table widefat fixed striped"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>' % (''.join('<th>%s</th>' % esc(x) for x in head), tbody)
        nav = '<div class="tablenav top"><div class="tablenav-pages"><span class="displaying-num">%s</span></div><br class="clear"></div>' % esc(T('0 عامل', '0 workers') if state == 'empty' else T('46 عامل', '46 workers'))
        note = '<p class="description">%s</p>' % esc(T('العمالة اليومية مش بتظهر في قوائم الموظفين ولا الحضور ولا الرواتب الشهرية. الرقم القومي متشفّر ومش بيظهر كامل غير لصلاحية «عرض الأرقام القومية».',
                                                    'Daily workers do not appear in staff lists, Attendance or monthly Payroll. National IDs are encrypted and shown in full only with "View national IDs".'))
        return admin_page(T('العمالة اليومية', 'Daily Workers'), dw_head('list') + filters + nav + table + note, SUBMENU, 'workers', feature_css=CSS)
    return make


def adm_profile(state='default'):
    def make():
        revealed = state == 'revealed'
        nid = '<span class="ews-dw-id">%s</span>' % ('29001150101234' if revealed else '2900••••••1234')
        nid += (' <span class="ews-dw-badge is-wait">%s</span>' % esc(T('ظاهر لك · اتسجّل في سجل التدقيق 14:32', 'Shown to you · logged in the Audit Log at 14:32'))) if revealed else (
            ' <button class="button button-small">%s %s</button>' % ('<span class="dashicons dashicons-visibility" style="font-size:16px;line-height:1.6"></span>', esc(T('إظهار', 'Show'))))
        dl = [(T('المهنة', 'Trade'), T('نجّار مسلّح', 'Formwork carpenter')), (T('الموبايل', 'Mobile'), '<span dir="ltr">0101 234 5678</span>'), (T('الرقم القومي', 'National ID'), nid),
              (T('مواليد', 'Born'), T('15 يناير 1990 · القاهرة', '15 January 1990 · Cairo')), (T('اليومية', 'Daily rate'), egp(450)), (T('الساعة الإضافية', 'Extra hour'), egp(60)),
              (T('مقاول الباطن', 'Subcontractor'), '—'), (T('التقييم', 'Rating'), stars(5)), (T('الحالة', 'Status'), '<span class="ews-dw-badge is-ok">%s</span>' % esc(T('نشط', 'Active')))]
        left = ('<div class="ews-dw-card"><div class="ews-dw-photo">%s</div><h2 style="font-size:18px;margin:0">%s</h2><p class="ews-dw-sub" style="margin:2px 0 12px">dw-1040 · %s</p><dl class="ews-dw-dl">%s</dl>'
                '<p style="margin:14px 0 0"><label>%s<textarea class="large-text" rows="2">%s</textarea></label></p><p><button class="button">%s</button> <button class="button">%s</button></p></div>') % (
                    esc(initials(T('محمد عبد الله', 'Mohamed Abdallah'))), esc(T('محمد عبد الله السيد', 'Mohamed Abdallah El-Sayed')), esc(T('منذ 3 مارس 2026', 'since 3 March 2026')),
                    ''.join('<dt>%s</dt><dd>%s</dd>' % (esc(k), v if '<' in str(v) else esc(v)) for k, v in dl), esc(T('ملاحظات', 'Notes')),
                    esc(T('شاطر في الشدّات الخشب. ممكن يمسك وردية.', 'Skilled at timber formwork. Can lead a crew.')), esc(T('تعديل', 'Edit')), esc(T('لا يُعاد تشغيله', 'Do not rehire')))
        hist = [(T('التجمع الخامس – عمارة 12', 'Fifth Settlement – Building 12'), T('من 14 سبتمبر', 'since 14 Sep'), '21', egp(10170)),
                (T('الشيخ زايد – فيلا 7', 'Sheikh Zayed – Villa 7'), T('1 – 13 سبتمبر', '1 – 13 Sep'), '11', egp(4950)),
                (T('مدينة نصر – ترميم', 'Nasr City – Renovation'), T('مارس – أغسطس', 'Mar – Aug'), '118', egp(49600))]
        h = '<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>' % (
            esc(T('الموقع', 'Site')), esc(T('الفترة', 'Period')), esc(T('الأيام', 'Days')), esc(T('المبلغ', 'Amount')), ''.join('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>' % tuple(map(esc, r)) for r in hist))
        pays = [(T('3 – 8 أكتوبر', '3 – 8 Oct'), '5', egp(2610), egp(500), egp(2110), ('is-wait', T('لسه', 'Due'))),
                (T('26 سبتمبر – 1 أكتوبر', '26 Sep – 1 Oct'), '6', egp(2700), '—', egp(2700), ('is-ok', T('اتصرف · مقفول', 'Paid · locked'))),
                (T('19 – 24 سبتمبر', '19 – 24 Sep'), '5.5', egp(2475), '—', egp(2475), ('is-ok', T('اتصرف · مقفول', 'Paid · locked')))]
        p = '<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>' % (
            esc(T('الفترة', 'Period')), esc(T('الأيام', 'Days')), esc(T('المبلغ', 'Amount')), esc(T('سلفة', 'Advance')), esc(T('الصافي', 'Net')), esc(T('الحالة', 'Status')),
            ''.join('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td><span class="ews-dw-badge %s">%s</span></td></tr>' % (
                esc(a), esc(b), esc(c), esc(d), esc(e), f[0], esc(f[1])) for a, b, c, d, e, f in pays))
        right = '<div class="ews-dw-card"><h2>%s</h2>%s</div><div class="ews-dw-card"><h2>%s</h2>%s</div>' % (esc(T('المواقع', 'Sites')), h, esc(T('اليوميات', 'Payouts')), p)
        notice = ('<div class="notice notice-info"><p>%s</p></div>' % esc(T('الرقم القومي الكامل ظاهر لك لأن عندك صلاحية «عرض الأرقام القومية». الإظهار ده اتسجّل باسمك.',
                                                                           'The full national ID is shown because you have "View national IDs". This reveal is logged under your name.'))) if revealed else ''
        body = '<h1>%s</h1>%s<div class="ews-dw-grid"><div>%s</div><div>%s</div></div>' % (esc(T('محمد عبد الله السيد', 'Mohamed Abdallah El-Sayed')), notice, left, right)
        return admin_page(T('ملف عامل', 'Worker profile'), body, SUBMENU, 'workers', feature_css=CSS)
    return make


def adm_settings(state='default'):
    def make():
        notice = '<div class="notice notice-success is-dismissible"><p>%s</p></div>' % esc(T('تم حفظ الإعدادات.', 'Settings saved.')) if state == 'saved' else ''
        mode = ''.join('<label><input type="radio" name="mode"%s> %s</label><br>' % (' checked' if k == 'f' else '', esc(T(*l))) for k, l in [
            ('f', ('المشرف بس يسجّل اليومية', 'Foreman only records attendance')), ('s', ('العامل يسجّل لنفسه (موبايل + رقم سري، بدون إيميل)', 'Workers sign in themselves (mobile + PIN, no email)')),
            ('b', ('الاتنين', 'Both'))])
        sites = [(T('التجمع الخامس – عمارة 12', 'Fifth Settlement – Building 12'), T('سيد رجب', 'Sayed Ragab'), T('زي الشركة (المشرف)', 'Company setting (foreman)'), T('أسبوعي', 'Weekly')),
                 (T('الشيخ زايد – فيلا 7', 'Sheikh Zayed – Villa 7'), T('سيد رجب', 'Sayed Ragab'), T('الاتنين', 'Both'), T('يومي', 'Daily')),
                 (T('مدينة نصر – ترميم', 'Nasr City – Renovation'), T('جمال طه', 'Gamal Taha'), T('زي الشركة (المشرف)', 'Company setting (foreman)'), T('أسبوعي', 'Weekly'))]
        st = '<table class="widefat striped" style="max-width:820px"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>' % (
            esc(T('الموقع', 'Site')), esc(T('المشرف', 'Foreman')), esc(T('التسجيل', 'Recording')), esc(T('الصرف', 'Pay period')),
            ''.join('<tr><td><strong>%s</strong></td><td>%s</td><td><select><option>%s</option></select></td><td><select><option>%s</option></select></td></tr>' % tuple(map(esc, r)) for r in sites))
        trades = '<div class="ews-dw-tags">%s<button class="button button-small">+ %s</button></div>' % (''.join('<span>%s</span>' % esc(T(*t)) for t in [
            ('نجّار مسلّح', 'Formwork carpenter'), ('حدّاد مسلّح', 'Steel fixer'), ('مساعد', 'Helper'), ('مبيّض محارة', 'Plasterer'), ('سبّاك', 'Plumber'), ('كهربائي', 'Electrician'), ('نقّاش', 'Painter')]),
            esc(T('مهنة', 'Trade')))
        subs = '<div class="ews-dw-tags">%s<button class="button button-small">+ %s</button></div>' % (''.join('<span>%s</span>' % esc(T(*t)) for t in [
            ('مقاولات الأمل', 'Al-Amal Contracting'), ('النور للتشطيبات', 'Al-Nour Finishing')]), esc(T('مقاول', 'Subcontractor')))
        rows = [
            (T('مين يسجّل الحضور', 'Who records attendance'), mode + '<p class="description">%s</p>' % esc(T('ممكن كل موقع يغيّرها في الجدول تحت.', 'Each site can override it below.'))),
            (T('المواقع', 'Sites'), st),
            (T('الحساب', 'Pay'), '<p>%s</p><label>%s <input type="number" class="small-text" value="60"> %s</label>' % (
                esc(T('يوم كامل = اليومية · نص يوم = اليومية ÷ 2 · الساعة الإضافية × سعر الساعة', 'Full day = daily rate · half day = rate ÷ 2 · extra hours × hourly rate')),
                esc(T('سعر الساعة الافتراضي', 'Default hourly rate')), esc(T('ج.م', 'EGP')))),
            (T('عند التسجيل', 'When recording'), '<label><input type="checkbox" checked disabled> %s</label><br><label><input type="checkbox" checked> %s</label>' % (
                esc(T('المشرف لازم يكون داخل الموقع (نفس قواعد تسجيل الحضور)', 'The foreman must be inside the site (same rules as Sign In)')),
                esc(T('صورة جماعية بالكاميرا (مش من المعرض)', 'A group photo with the camera (not the gallery)')))),
            (T('المهن', 'Trades'), trades), (T('مقاولو الباطن', 'Subcontractors'), subs),
            (T('الصلاحيات', 'Permissions'), '<p>%s <a href="#">%s</a></p>' % (esc(T('«مشرف موقع» و«عرض الأرقام القومية» من', '"Foreman" and "View national IDs" are in')), esc(T('الأدوار والصلاحيات', 'Roles & Permissions')))),
        ]
        form = '<table class="form-table" role="presentation">%s</table>' % ''.join('<tr><th scope="row">%s</th><td>%s</td></tr>' % (esc(k), v) for k, v in rows)
        body = dw_head('settings') + notice + '<div class="ews-dw-card" style="max-width:1100px;margin-top:14px">%s<p class="submit"><button class="button button-primary">%s</button></p></div>' % (
            form, esc(T('حفظ التغييرات', 'Save Changes')))
        return admin_page(T('العمالة اليومية', 'Daily Workers'), body, SUBMENU, 'workers', feature_css=CSS)
    return make


def adm_reports():
    def make():
        kpis = ('<div class="ews-dw-kpis"><div class="ews-dw-kpi is-hero"><b>%s</b><span>%s</span></div><div class="ews-dw-kpi"><b>%s</b><span>%s</span></div><div class="ews-dw-kpi"><b>%s</b><span>%s</span></div></div>') % (
            esc(egp(284000)), esc(T('مشروع جاردن هايتس (التجمع الخامس): تكلفة العمالة لحد النهارده', 'Garden Heights project (Fifth Settlement): labour so far')),
            esc(egp(41260)), esc(T('أكتوبر لحد دلوقتي · كل المواقع', 'October so far · all sites')), esc(egp(13940)), esc(T('مستحق ولسه ما اتصرفش', 'owed, not paid yet')))
        by = lambda title, rows, w: '<div class="ews-dw-card"><h2>%s</h2><table class="widefat striped"><tbody>%s</tbody></table></div>' % (esc(title), ''.join(
            '<tr><td>%s</td><td style="width:55%%"><span class="ews-dw-bar" style="width:%dpx"></span>%s</td></tr>' % (esc(a), int(b / w), esc(egp(b))) for a, b in rows))
        proj = by(T('حسب المشروع / الموقع (أكتوبر)', 'By project / site (October)'), [(T('التجمع الخامس – عمارة 12', 'Fifth Settlement – Building 12'), 24600), (T('الشيخ زايد – فيلا 7', 'Sheikh Zayed – Villa 7'), 11240), (T('مدينة نصر – ترميم', 'Nasr City – Renovation'), 5420)], 160)
        trade = by(T('حسب المهنة', 'By trade'), [(T('نجّار مسلّح', 'Formwork carpenter'), 13500), (T('حدّاد مسلّح', 'Steel fixer'), 9900), (T('مساعد', 'Helper'), 8380), (T('سبّاك', 'Plumber'), 4800), (T('كهربائي', 'Electrician'), 4680)], 110)
        sub = by(T('حسب مقاول الباطن', 'By subcontractor'), [(T('بدون (عمالة مباشرة)', 'None (direct)'), 33860), (T('مقاولات الأمل', 'Al-Amal Contracting'), 7400)], 200)
        days = ['3', '4', '5', '6', '7', '8']
        grid = [(T('التجمع الخامس', 'Fifth Settlement'), [18, 18, 17, 0, 16, 8]), (T('الشيخ زايد', 'Sheikh Zayed'), [9, 9, 8, 0, 9, 9]), (T('مدينة نصر', 'Nasr City'), [6, 5, 6, 0, 6, 0])]
        lvl = lambda n: 'h0' if n == 0 else ('h1' if n < 7 else ('h2' if n < 12 else 'h3'))
        heat = '<div class="ews-dw-card"><h2>%s</h2><table class="widefat ews-dw-heat"><thead><tr><th></th>%s</tr></thead><tbody>%s</tbody></table><p class="description">%s</p></div>' % (
            esc(T('العمال في كل موقع كل يوم', 'Workers per site and day')), ''.join('<th>%s</th>' % esc(T('%s أكتوبر' % d, '%s Oct' % d)) for d in days),
            ''.join('<tr><th>%s</th>%s</tr>' % (esc(s), ''.join('<td class="%s">%s</td>' % (lvl(n), n if n else '—') for n in r)) for s, r in grid),
            esc(T('الثلاثاء 6 أكتوبر إجازة رسمية · 8 أكتوبر لسه بيتسجّل.', 'Tue 6 Oct was a public holiday · 8 Oct is still being recorded.')))
        filters = '<div class="ews-dw-filters"><input type="date" value="2026-10-01"> <input type="date" value="2026-10-08"><select><option>%s</option></select><button class="button">%s</button><span style="flex:1"></span><a class="button" href="#">%s</a> <a class="button" href="#">PDF</a> <a class="button" href="#">%s</a></div>' % (
            esc(T('كل المشاريع', 'All projects')), esc(T('عرض', 'Show')), esc(T('تصدير Excel', 'Export to Excel')), esc(T('تقرير التأمينات للمحاسب', 'Insurance report for the accountant')))
        body = dw_head('reports') + filters + kpis + '<div class="ews-dw-two"><div>%s%s</div><div>%s%s</div></div>' % (proj, sub, trade, heat)
        return admin_page(T('تقارير العمالة اليومية', 'Daily workers reports'), body, SUBMENU, 'workers', feature_css=CSS)
    return make


def sheet_payout():
    def make():
        rows = ''
        tot = [0, 0, 0]
        for i, (n, d, a, b) in enumerate(PAY, 1):
            w = WORKERS[i - 1]
            rows += '<tr><td>%d</td><td class="dw-name">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td class="dw-sign"></td></tr>' % (
                i, esc(T(*n)), esc(T(*w[1])), d, format(w[2], ','), EXTRA_WEEK[i - 1] or '—', format(a, ','), format(b, ',') if b else '—', format(a - b, ','))
            tot[0] += a; tot[1] += b; tot[2] += a - b
        head = [T('م', '#'), T('الاسم', 'Name'), T('المهنة', 'Trade'), T('الأيام', 'Days'), T('اليومية', 'Rate'), T('س إضافي', 'Extra h'), T('المبلغ', 'Amount'), T('سلفة', 'Advance'),
                T('الصافي', 'Net'), T('التوقيع / البصمة', 'Signature / thumbprint')]
        meta = [(T('الموقع', 'Site'), T(*SITE)), (T('الفترة', 'Period'), T('السبت 3 – الخميس 8 أكتوبر 2026', 'Sat 3 – Thu 8 Oct 2026')), (T('المشرف', 'Foreman'), T(*FOREMAN)),
                (T('رقم الكشف', 'Sheet no.'), 'DW-2026-041')]
        body = ('<div class="dw-sheet"><div class="dw-sheet-head"><div><h1>%s</h1><p>%s</p></div><div class="dw-sheet-logo">NC</div></div>'
                '<div class="dw-sheet-meta">%s</div><table><thead><tr>%s</tr></thead><tbody>%s</tbody><tfoot><tr><td colspan="6">%s</td><td>%s</td><td>%s</td><td>%s</td><td></td></tr></tfoot></table>'
                '<p class="dw-sheet-note">%s</p><div class="dw-sheet-foot"><div>%s</div><div>%s</div><div>%s</div></div></div>') % (
            esc(T('كشف اليومية', 'Daily wages sheet')), esc(T('شركة النيل للمقاولات · المبالغ بالجنيه المصري', 'Nile Contracting · amounts in Egyptian pounds')),
            ''.join('<div><span>%s</span><strong>%s</strong></div>' % (esc(k), esc(v)) for k, v in meta), ''.join('<th>%s</th>' % esc(h) for h in head), rows,
            esc(T('الإجمالي', 'Total')), format(tot[0], ','), format(tot[1], ','), format(tot[2], ','),
            esc(T('أقرّ أنا الموقّع أمام اسمي باستلام المبلغ الصافي الموضّح نظير عملي في الموقع خلال الفترة المذكورة. السلف المخصومة سبق صرفها.',
                  'By signing next to my name I confirm I received the net amount shown for my work at this site in this period. Deducted advances were paid before.')),
            esc(T('المشرف', 'Foreman')), esc(T('أمين الصندوق', 'Cashier')), esc(T('مدير الموقع', 'Site manager')))
        head_html = '<link rel="stylesheet" href="%s">' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS)
        return page_doc(T('كشف اليومية', 'Daily wages sheet'), head_html, '<body style="margin:0;background:#e9eaef">%s</body>' % body)
    return make


def build():
    screens = [
        ('fm-sites', fm_sites()), ('fm-sites-empty', fm_sites('empty')),
        ('fm-day', fm_day()), ('fm-day-outside', fm_day('outside')), ('fm-day-nophoto', fm_day('nophoto')), ('fm-day-saved', fm_day('saved')),
        ('fm-add', fm_add()), ('fm-add-error', fm_add('error')), ('fm-move', fm_move()),
        ('pay', pay()), ('pay-error', pay('error')), ('pay-paid', pay('paid')),
        ('adm-workers', adm_workers()), ('adm-workers-empty', adm_workers('empty')), ('adm-profile', adm_profile()), ('adm-profile-revealed', adm_profile('revealed')),
        ('adm-settings', adm_settings()), ('adm-settings-saved', adm_settings('saved')), ('adm-reports', adm_reports()),
        ('sheet-payout', sheet_payout()),
    ]
    for slug, make in screens:
        write(F, slug, make)
    P, D, A = 'phone', 'desk', 'a4'
    gallery(F, 'Daily workers — mockups (Step 1)', (
        '<p>Static mockups for <code>docs/tasks/daily-workers.md</code>. Each screen in Arabic (RTL) and English on the plugin\'s real stylesheets, fonts and icons; '
        'foreman screens at 390&nbsp;px, wp-admin at 1280&nbsp;px (shrunk here; open a screen for full size), the payout sheet at A4. Sample company: Nile Contracting; '
        'foreman Sayed Ragab; "today" is Thursday 8 October 2026; amounts in EGP.</p>'), [
        ('Foreman — app', 'A foreman sees only his sites. Saving the day needs his GPS inside the site and a group photo from the camera.', [
            ('fm-sites', 'My sites', P), ('fm-sites-empty', 'Empty: no sites assigned', P), ('fm-day', 'Today at the site', P),
            ('fm-day-outside', 'Error: outside the site', P), ('fm-day-nophoto', 'Error: no group photo', P), ('fm-day-saved', 'Saved and locked', P),
            ('fm-add', 'Quick-add a worker', P), ('fm-add-error', 'Error: invalid national ID', P), ('fm-move', 'Move to another site', P)]),
        ('Foreman or cashier — payout', '', [('pay', 'The week\'s payout', P), ('pay-error', 'Error: no signed sheet photo', P), ('pay-paid', 'Paid and locked', P)]),
        ('HR / site manager — wp-admin', '', [
            ('adm-workers', 'Workers list', D), ('adm-workers-empty', 'Empty', D), ('adm-profile', 'Worker profile (national ID masked)', D),
            ('adm-profile-revealed', 'National ID revealed (permission + Audit Log)', D), ('adm-settings', 'Settings', D), ('adm-settings-saved', 'Settings saved', D),
            ('adm-reports', 'Reports: labour cost, attendance per site', D)]),
        ('The printed payout sheet', 'A4, printed from the payout screen; workers sign or thumbprint the last column.', [('sheet-payout', 'كشف اليومية', A)]),
    ])
