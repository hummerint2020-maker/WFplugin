"""Attendance corrections: the screens of docs/tasks/attendance-corrections.md (Step 1)."""
from build import T, Lang, esc, icon, app_page, admin_page, sheet, ux_modal, write, gallery

F = 'attendance-corrections'
CSS = 'corrections.css'
APP_CSS = ['app-requests.css', 'app-time.css']
MGR = ('أحمد سمير', 'Ahmed Samir')

# Sample data: a sales employee in Cairo; "today" is Thursday 8 October 2026, 7 pm.
DAYS = [
    # day, weekday, in, out, kind
    ('8', ('الخميس', 'Thu'), '08:47', None, 'today'),
    ('7', ('الأربعاء', 'Wed'), '08:52', None, 'missing'),
    ('6', ('الثلاثاء', 'Tue'), '09:31', '17:05', 'waiting'),
    ('5', ('الاثنين', 'Mon'), '08:55', '17:02', 'ok'),
    ('4', ('الأحد', 'Sun'), None, None, 'fixed-day'),
    ('1', ('الخميس', 'Thu'), '08:58', '17:10', 'ok'),
]


def ARW():
    """The arrow of a change (from → to), pointing the way the language reads."""
    return T('←', '→')


def chip(text, kind=''):
    return '<span class="wfo-cx-chip %s">%s</span>' % (kind, esc(text))


def day_rows(after=False):
    out = []
    for d, wd, tin, tout, kind in DAYS:
        if after and kind == 'missing':
            kind = 'fixed-out'
        missing = '<span class="is-missing">%s</span>' % esc(T('لا يوجد', 'none'))
        times = '%s %s %s' % (tin or missing, ARW(), tout or missing)
        note, side, cls = '', '', ''
        if kind == 'today':
            note = T('اليوم · لم تسجّل الانصراف بعد', 'Today · not signed out yet')
            side = chip(T('في العمل', 'At work'), 'is-pri')
        elif kind == 'missing':
            cls = ' is-problem'
            note = T('لم يُسجَّل الانصراف', 'No Sign Out recorded')
            side = '<button type="button" class="wfo-cx-fix">%s%s</button>' % (icon('edit', 15, 2.2), esc(T('طلب تصحيح', 'Request correction')))
        elif kind == 'fixed-out':
            times = '%s %s <span class="is-fixed">17:30</span>' % (tin, ARW())
            note = T('صُحّح الانصراف · وافق أحمد سمير', 'Sign Out corrected · approved by Ahmed Samir')
            side = chip(T('مُصحَّح', 'Corrected'), 'is-ok')
        elif kind == 'waiting':
            note = T('متأخر 31 دقيقة · طلبت تصحيح الحضور إلى 08:55', 'Late 31 min · you asked to correct Sign In to 08:55')
            side = chip(T('التصحيح في الانتظار', 'Correction waiting'), 'is-warn')
        elif kind == 'fixed-day':
            times = '<span class="is-fixed">09:00 – 17:00</span>'
            note = T('مأمورية عند عميل · وافق أحمد سمير', 'Mission at a customer · approved by Ahmed Samir')
            side = chip(T('مُصحَّح', 'Corrected'), 'is-ok')
        else:
            note = T('مكتب · في الموعد', 'Office · on time')
        out.append('<div class="wfo-cx-day%s"><div class="wfo-cx-date"><b>%s</b><small>%s</small></div><div class="wfo-cx-day-main"><p class="wfo-cx-times">%s</p>'
                   '<p class="wfo-cx-note">%s</p></div>%s</div>' % (cls, d, esc(T(*wd)), times if Lang.code == 'en' else times, esc(note), side))
    return ''.join(out)


def time_hero():
    return ('<div class="ews-time-card wfo-clock"><section class="wfo-clock-hero is-in" aria-label="%s"><div class="wfo-clock-top"><span class="ews-time-date">%s</span>'
            '<span class="wfo-clock-shift" dir="ltr">9:00 AM – 5:00 PM</span></div><div class="wfo-clock-face"><div class="wfo-clock-avatar"><span class="wfo-clock-photo wfo-clock-initials" aria-hidden="true">%s</span></div>'
            '<div class="wfo-clock-who"><strong class="wfo-clock-name">%s</strong><span class="wfo-clock-meta">mona.adel</span><span class="wfo-clock-chip is-office">%s%s</span></div>'
            '<div class="wfo-clock-time"><span>%s</span><strong dir="ltr">07:02 PM</strong></div></div>'
            '<div class="ews-time-actions"><button class="ews-time-btn out"><span>%s</span></button></div></section></div>') % (
                esc(T('اليوم', 'Today')), esc(T('الخميس، 8 أكتوبر 2026', 'Thursday, 08 October 2026')), esc(T('مع', 'MA')), esc(T('منى عادل', 'Mona Adel')),
                icon('office', 14, 2), esc(T('مكتب', 'Office')), esc(T('الآن', 'Now')), esc(T('تسجيل الانصراف', 'Sign Out')))


def days_panel(after=False, empty=False):
    head = '<div class="wfo-cx-days-head"><h3>%s</h3><a href="#">%s</a></div>' % (esc(T('آخر أيامي', 'My recent days')), esc(T('طلبات التصحيح', 'My corrections')))
    if empty:
        body = ('<div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true">%s</span><strong>%s</strong><p>%s</p></div>') % (
            icon('calendar', 24, 2), esc(T('لا توجد أيام مسجّلة بعد', 'No days recorded yet')),
            esc(T('بعد أول تسجيل حضور ستظهر أيامك هنا، ومعها زر طلب التصحيح لو نسيت تسجّل.', 'Your days appear here after your first Sign In, with a button to request a correction if you forget one.')))
        return '<section class="wfo-panel wfo-cx-days">%s%s</section>' % (head, body)
    foot = '<div class="wfo-cx-days-foot">%s</div>' % esc(T('يمكنك طلب تصحيح لأي يوم خلال 7 أيام · استخدمت 2 من 3 طلبات هذا الشهر',
                                                            'You can ask to correct a day within 7 days · 2 of 3 requests used this month'))
    return '<section class="wfo-panel wfo-cx-days">%s%s%s</section>' % (head, day_rows(after), foot)


def emp_days(state='default'):
    def make():
        body = time_hero() + days_panel(after=(state == 'after'), empty=(state == 'empty'))
        return app_page(T('الحضور / الانصراف', 'Time'), body, active='time', css_files=APP_CSS, feature_css=CSS)
    return make


TYPES = [('out', 'logout', ('نسيت الانصراف', 'Forgot Sign Out')), ('in', 'login', ('نسيت الحضور', 'Forgot Sign In')),
         ('time', 'clock', ('وقت غلط', 'Wrong time')), ('day', 'calendar', ('يوم كامل ناقص', 'Whole day missing'))]


def emp_form(state='default'):
    """state: default | push (opened from the end-of-day push, today) | error | success | photo"""
    def make():
        today = state == 'push'
        day_label = T('الخميس 8 أكتوبر (اليوم)', 'Thu 8 Oct (today)') if today else T('الأربعاء 7 أكتوبر', 'Wed 7 Oct')
        if state == 'error':
            day_label = T('الاثنين 28 سبتمبر', 'Mon 28 Sep')
        types = ''.join('<button type="button" aria-pressed="%s">%s<span>%s</span></button>' % ('true' if k == 'out' else 'false', icon(i, 18, 2), esc(T(*l))) for k, i, l in TYPES)
        err = ''
        time_cls, time_msg, time_val = '', '', '17:30'
        if state == 'error':
            err = '<p class="wfo-cx-error" role="alert">%s<span>%s</span></p>' % (icon('alert', 18, 2.2), esc(T(
                'هذا اليوم مرّ عليه أكثر من 7 أيام، فلا يمكن طلب تصحيحه من التطبيق. كلّم الموارد البشرية لتصحيحه.',
                'This day is more than 7 days ago, so it cannot be corrected from the app. Ask HR to correct it.')))
            time_cls, time_val = ' is-error', '08:30'
            time_msg = '<p class="wfo-cx-field-msg">%s</p>' % esc(T('وقت الانصراف لازم يكون بعد الحضور (08:52).', 'Sign Out must be after your Sign In (08:52).'))
        if today:
            time_val = ''
        photo = ('<div class="wfo-cx-photo">%s<div><strong>%s</strong>%s</div></div>') % (
            icon('camera', 22, 2), esc(T('صورة (اختياري)', 'Photo (optional)')), esc(T('مثلًا صورة من عند العميل', 'e.g. a photo from the customer\'s site')))
        if state == 'photo':
            photo = ('<div class="wfo-cx-photo is-set"><span class="wfo-cx-thumb"></span><div><strong>IMG_2041.jpg</strong>%s</div></div>') % esc(T('تم إرفاق الصورة · إزالة', 'Photo attached · remove'))
        limit = '<p class="wfo-cx-limit">%s%s</p>' % (icon('check', 16, 2.4), esc(T('ده طلبك الـ 3 من 3 المسموحة هذا الشهر', 'This is request 3 of the 3 allowed this month')))
        body = (err +
                '<div class="wfo-rq-field"><span class="wfo-rq-label">%s</span><div class="wfo-cx-record"><div><span>%s</span><strong>%s</strong></div><div><span>%s</span><strong dir="ltr">08:52</strong></div>'
                '<div><span>%s</span><strong class="is-missing">%s</strong></div></div></div>') % (
                    esc(T('اليوم', 'Day')), esc(T('التاريخ', 'Date')), esc(day_label), esc(T('الحضور', 'Sign In')), esc(T('الانصراف', 'Sign Out')), esc(T('لا يوجد', 'None')))
        if today:
            body = body.replace('>08:52<', '>08:47<')
        body += '<div class="wfo-rq-field"><span class="wfo-rq-label">%s</span><div class="wfo-cx-types">%s</div></div>' % (esc(T('نوع التصحيح', 'What happened?')), types)
        body += ('<div class="wfo-rq-field%s"><label for="cx-time">%s</label><input id="cx-time" type="time" value="%s"%s>%s</div>') % (
            time_cls, esc(T('وقت الانصراف الصحيح', 'Correct Sign Out time')), time_val, ' autofocus' if today else '', time_msg)
        body += ('<div class="wfo-rq-field"><label for="cx-reason">%s</label><textarea id="cx-reason" rows="3" placeholder="%s">%s</textarea></div>') % (
            esc(T('السبب', 'Reason')), esc(T('إيه اللي حصل؟', 'What happened?')),
            '' if today else esc(T('نسيت أسجّل الانصراف، خرجت 5:30 بعد اجتماع العميل.', 'I forgot to sign out; I left at 5:30 after the customer meeting.')))
        body += photo + limit
        note = T('مديرك أحمد سمير هيراجع الطلب. السجل الأصلي لا يتغيّر.', 'Your manager Ahmed Samir reviews it. The original record never changes.')
        page = time_hero() + days_panel() + sheet(T('طلب تصحيح حضور', 'Request a correction'), body, T('إرسال الطلب', 'Send request'), note, open_=(state != 'success'))
        if state == 'success':
            page += ux_modal('success', T('تم إرسال الطلب', 'Request sent'), T(
                'طلب تصحيح انصراف الأربعاء 7 أكتوبر (17:30) وصل لمديرك أحمد سمير. هيوصلك إشعار لما يقرر.',
                'Your request to set Wed 7 Oct Sign Out to 17:30 went to your manager Ahmed Samir. You will get a notification when he decides.'))
        return app_page(T('الحضور / الانصراف', 'Time'), page, active='time', css_files=APP_CSS, feature_css=CSS)
    return make


def emp_requests(state='default'):
    """state: default | empty | limit"""
    def make():
        used = '3' if state == 'limit' else ('0' if state == 'empty' else '2')
        chips = ''
        if state == 'default':
            chips += '<span class="wfo-rq-hero-chip is-wait">%s%s</span>' % (icon('clock', 14, 2.2), esc(T('1 في الانتظار', '1 waiting')))
        if state == 'limit':
            chips += '<span class="wfo-rq-hero-chip is-wait">%s%s</span>' % (icon('alert', 14, 2.2), esc(T('وصلت للحد · الطلبات الجديدة تروح للموارد البشرية', 'Limit reached · new requests go to HR')))
        chips += '<span class="wfo-rq-hero-chip">%s</span>' % esc(T('يتجدد الحد يوم 1 نوفمبر', 'Resets on 1 November'))
        hero = ('<section class="wfo-rq-hero"><div class="wfo-rq-hero-main"><p class="wfo-rq-hero-label">%s</p><p class="wfo-rq-hero-value">%s</p><div class="wfo-rq-hero-chips">%s</div></div>'
                '<button type="button" class="wfo-rq-new">%s%s</button></section>') % (
                    esc(T('أكتوبر · طلبات التصحيح', 'October · corrections')), esc(T('%s من 3' % used, '%s of 3' % used)), chips, icon('plus', 18, 2.4), esc(T('طلب تصحيح', 'Request correction')))

        def row(ico, title, meta, st, cls, quote=''):
            return ('<div class="wfo-rq-row"><span class="wfo-rq-ico" aria-hidden="true">%s</span><div class="wfo-rq-main"><p class="wfo-rq-title">%s</p><p class="wfo-rq-meta">%s</p>%s</div>'
                    '<span class="wfo-rq-state %s">%s</span></div>') % (icon(ico, 18, 2), esc(title), esc(meta), ('<p class="wfo-rq-quote">%s</p>' % esc(quote)) if quote else '', cls, esc(st))
        if state == 'empty':
            body = ('<section class="wfo-rq-card"><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true">%s</span><strong>%s</strong><p>%s</p></div></section>') % (
                icon('history', 24, 2), esc(T('مفيش طلبات تصحيح', 'No correction requests')),
                esc(T('لو نسيت تسجّل حضور أو انصراف، أو الوقت اتسجّل غلط، اطلب تصحيح خلال 7 أيام ومديرك يراجعه.',
                      'If you forget to sign in or out, or a time is wrong, ask for a correction within 7 days and your manager reviews it.')))
        else:
            waiting = row('logout', T('الأربعاء 7 أكتوبر · نسيت الانصراف', 'Wed 7 Oct · Forgot Sign Out'), T('الانصراف ← 17:30 · عند أحمد سمير', 'Sign Out → 17:30 · with Ahmed Samir'),
                          T('في الانتظار', 'Waiting'), 'is-pending')
            if state == 'limit':
                waiting += row('login', T('الخميس 8 أكتوبر · نسيت الحضور', 'Thu 8 Oct · Forgot Sign In'), T('الحضور ← 08:40 · عند الموارد البشرية (فوق الحد)', 'Sign In → 08:40 · with HR (above the limit)'),
                               T('في الانتظار', 'Waiting'), 'is-pending')
            decided = row('calendar', T('الأحد 4 أكتوبر · يوم كامل ناقص', 'Sun 4 Oct · Whole day missing'), T('09:00 – 17:00 · مأمورية عند عميل', '09:00 – 17:00 · mission at a customer'),
                          T('تمت الموافقة', 'Approved'), 'is-approved', T('أحمد سمير: تمام، الزيارة مسجّلة عندي.', 'Ahmed Samir: OK, I have the visit on record.'))
            decided += row('clock', T('الثلاثاء 29 سبتمبر · وقت غلط', 'Tue 29 Sep · Wrong time'), T('الحضور 09:40 ← 09:00', 'Sign In 09:40 → 09:00'),
                           T('مرفوض', 'Rejected'), 'is-rejected', T('أحمد سمير: الموقع اتسجّل خارج المكتب الساعة 9:15.', 'Ahmed Samir: your location was outside the office at 9:15.'))
            body = ('<h3 class="wfo-rq-group">%s</h3><div class="wfo-rq-card">%s</div><h3 class="wfo-rq-group">%s</h3><div class="wfo-rq-card">%s</div>') % (
                esc(T('في انتظار القرار', 'Waiting for a decision')), waiting, esc(T('تم القرار', 'Decided')), decided)
        page = '<div class="ews-page wfo-rq is-narrow">%s<div class="wfo-rq-solo wfo-rq-col">%s</div></div>' % (hero, body)
        return app_page(T('طلبات التصحيح', 'My Corrections'), page, active='time', css_files=APP_CSS, feature_css=CSS)
    return make


def emp_push():
    def make():
        body = ('<div class="mock-lock"><div class="mock-lock-time" dir="ltr">7:00</div><div class="mock-lock-date">%s</div>'
                '<div class="mock-push"><span class="mock-push-icon">WO</span><div class="mock-push-body"><div class="mock-push-top"><span>WORKFORCE ONE</span><span>%s</span></div>'
                '<strong>%s</strong>%s<div class="mock-push-actions"><span>%s</span><span>%s</span></div></div></div>'
                '<p class="mock-caption">%s</p></div>') % (
                    esc(T('الخميس 8 أكتوبر', 'Thursday 8 October')), esc(T('الآن', 'now')),
                    esc(T('لم تسجّل الانصراف اليوم', 'You did not sign out today')),
                    esc(T('سجّلت الحضور 08:47. لو خرجت خلاص، اطلب تصحيح بوقت خروجك.', 'You signed in at 08:47. If you have left, request a correction with the time you left.')),
                    esc(T('طلب تصحيح', 'Request correction')), esc(T('سجّل الانصراف الآن', 'Sign out now')),
                    esc(T('يوصل الساعة 7 مساءً (من الإعدادات) لمن سجّل حضور ولم يسجّل انصراف. «طلب تصحيح» يفتح نموذج التصحيح على اليوم ونوع «نسيت الانصراف». «سجّل الانصراف الآن» يفتح صفحة الحضور / الانصراف.',
                          'Sent at 7 pm (a setting) to anyone signed in without a Sign Out. "Request correction" opens the form on today with "Forgot Sign Out"; "Sign out now" opens Sign In / Out.')))
        from build import page_doc, Build
        return page_doc(T('إشعار نهاية اليوم', 'End-of-day push'), '<link rel="stylesheet" href="%smock.css">' % Build.kit, '<body style="margin:0">%s</body>' % body)
    return make


def mgr(state='default'):
    """state: default | error (reject without a note) | success | empty"""
    def make():
        if state == 'empty':
            card = ('<section class="wfo-rq-card"><div class="wfo-rq-card-head"><div><h3>%s</h3><p>%s</p></div></div><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true">%s</span>'
                    '<strong>%s</strong><p>%s</p></div></section>') % (
                        esc(T('تصحيحات للموافقة', 'Corrections to approve')), esc(T('طلبات فريقك، وقدام كل طلب اللي النظام يعرفه عن اليوم.', 'Your team\'s requests, each with what the system knows about the day.')),
                        icon('check', 24, 2.2), esc(T('مفيش طلبات تصحيح في الانتظار', 'No corrections waiting')), esc(T('كله تمام.', 'You\'re all caught up.')))
        else:
            tl = [('is-ok', '08:52', T('سجّلت الحضور من التطبيق', 'Signed in from the app'), T('مكتب المعادي · داخل النطاق (35 م) · الجهاز سليم', 'Maadi office · inside the area (35 m) · device check passed')),
                  ('is-ok', '12:10', T('تحقق الحضور: ناجح', 'Presence check: passed'), T('طلبه المدير · رد خلال دقيقتين', 'Asked by the manager · answered in 2 min')),
                  ('is-ok', '17:41', T('آخر موقع للجهاز', 'Last device location'), T('عند المكتب (60 م) · من التطبيق', 'At the office (60 m) · from the app')),
                  ('is-bad', '—', T('لا يوجد تسجيل انصراف', 'No Sign Out'), T('ولا يوجد تسجيل من الكشك أو QR', 'and no kiosk or QR event'))]
            tlh = ''.join('<li class="%s"><time dir="ltr">%s</time><div><b>%s</b>%s</div></li>' % (c, t, esc(a), esc(b)) for c, t, a, b in tl)
            facts = ''.join('<div%s>%s<strong>%s</strong></div>' % (cls, esc(k), esc(v)) for cls, k, v in [
                ('', T('الجدول', 'Schedule'), T('مكتب · 9:00 – 17:00', 'Office · 9:00 – 17:00')),
                ('', T('الوردية', 'Shift'), T('صباحي', 'Morning')),
                (' class="is-warn"', T('تصحيحات هذا الشهر', 'Corrections this month'), T('هذا الثاني من 3', 'This is 2 of 3')),
                ('', T('الكشك / QR', 'Kiosk / QR'), T('لا شيء اليوم', 'Nothing that day'))])
            note_cls = ' is-error' if state == 'error' else ''
            note_msg = ('<p class="wfo-cx-field-msg">%s</p>' % esc(T('اكتب سبب الرفض: الموظف هيشوفه.', 'Write why you reject it: the employee will see it.'))) if state == 'error' else ''
            req1 = ('<div class="wfo-cx-req"><div class="wfo-cx-req-top"><span class="wfo-rq-ico is-person" aria-hidden="true">%s</span><div class="wfo-rq-main"><p class="wfo-rq-title">%s</p>'
                    '<p class="wfo-rq-meta">%s</p></div><span class="wfo-rq-state is-pending">%s</span></div>'
                    '<p class="wfo-cx-ask">%s <b>—</b> <span class="wfo-cx-arrow">%s</span> <b>17:30</b><br>“%s”</p>'
                    '<div class="wfo-cx-evidence"><h4>%s%s<small>%s</small></h4><ul class="wfo-cx-tl">%s</ul><div class="wfo-cx-facts">%s</div></div>'
                    '<div class="wfo-cx-decide%s"><textarea placeholder="%s">%s</textarea>%s<div class="wfo-rq-actions"><button class="wfo-rq-btn is-no" type="button">%s%s</button>'
                    '<button class="wfo-rq-btn is-yes" type="button">%s%s</button></div></div></div>') % (
                        esc(T('مع', 'MA')), esc(T('منى عادل', 'Mona Adel')), esc(T('الأربعاء 7 أكتوبر · نسيت الانصراف · منذ ساعة', 'Wed 7 Oct · Forgot Sign Out · 1 h ago')), esc(T('المستوى 1', 'Level 1')),
                        esc(T('الانصراف:', 'Sign Out:')), ARW(), esc(T('نسيت أسجّل الانصراف، خرجت 5:30 بعد اجتماع العميل.', 'I forgot to sign out; I left at 5:30 after the customer meeting.')),
                        icon('shield', 16, 2), esc(T('اللي النظام يعرفه عن اليوم', 'What the system knows about the day')), esc(T('للقراءة فقط', 'read-only')),
                        tlh, facts, note_cls, esc(T('ملاحظة للموظف (مطلوبة عند الرفض)', 'Note to the employee (required to reject)')), '', note_msg,
                        icon('close', 16, 2.2), esc(T('رفض', 'Reject')), icon('check', 16, 2.2), esc(T('موافقة', 'Approve')))
            req2 = ('<div class="wfo-cx-req"><div class="wfo-cx-req-top"><span class="wfo-rq-ico is-person" aria-hidden="true">%s</span><div class="wfo-rq-main"><p class="wfo-rq-title">%s</p>'
                    '<p class="wfo-rq-meta">%s</p></div><span class="wfo-rq-state is-pending">%s</span></div>'
                    '<p class="wfo-cx-level2">%s%s</p><div class="wfo-rq-actions" style="margin-top:10px;justify-content:flex-end"><button class="wfo-rq-btn is-soft" type="button">%s</button></div></div>') % (
                        esc(T('كي', 'KY')), esc(T('كريم يوسف', 'Karim Youssef')), esc(T('الثلاثاء 6 أكتوبر · وقت غلط · الحضور 09:31 ← 08:55', 'Tue 6 Oct · Wrong time · Sign In 09:31 → 08:55')),
                        esc(T('المستوى 1', 'Level 1')), icon('alert', 16, 2.2),
                        esc(T('يقدّم وقت الحضور (يلغي 31 دقيقة تأخير): بعد موافقتك يروح للمستوى الثاني (الموارد البشرية).', 'Moves Sign In earlier (removes 31 min late): after you approve it goes to the second level (HR).')),
                        esc(T('عرض الأدلة', 'Show the evidence')))
            card = ('<section class="wfo-rq-card"><div class="wfo-rq-card-head"><div><h3>%s</h3><p>%s</p></div><span class="wfo-rq-state is-pending">%s</span></div>%s%s</section>') % (
                esc(T('تصحيحات للموافقة', 'Corrections to approve')), esc(T('طلبات فريقك، وقدام كل طلب اللي النظام يعرفه عن اليوم.', 'Your team\'s requests, each with what the system knows about the day.')),
                esc(T('2 في الانتظار', '2 waiting')), req1, req2)
        page = '<div class="ews-page wfo-rq is-narrow"><div class="wfo-rq-solo wfo-rq-col">%s</div></div>' % card
        if state == 'success':
            page += ux_modal('success', T('تمت الموافقة', 'Approved'), T(
                'انصراف منى عادل يوم الأربعاء 7 أكتوبر بقى 17:30. السجل الأصلي محفوظ، والتصحيح باسمك في سجل التدقيق.',
                'Mona Adel\'s Sign Out on Wed 7 Oct is now 17:30. The original record is kept and the correction is in the Audit Log under your name.'))
        rail = [('dashboard', 'home', ('الرئيسية', 'Dashboard')), ('time', 'clock', ('الحضور / الانصراف', 'Sign In / Out')), ('corrections', 'history', ('التصحيحات', 'Corrections')),
                ('attendance', 'attendance', ('الحضور', 'Attendance')), ('reports', 'reports', ('التقارير', 'Reports'))]
        return app_page(T('التصحيحات', 'Corrections'), page, active='corrections', css_files=APP_CSS, feature_css=CSS, who=MGR, rail=rail)
    return make


def day_after_app():
    def make():
        lines = [
            (T('الحضور', 'Sign In'), '<strong dir="ltr">08:52</strong><span class="wfo-cx-who">%s</span>' % esc(T('مكتب المعادي · داخل النطاق (35 م)', 'Maadi office · inside the area (35 m)'))),
            (T('الانصراف', 'Sign Out'), '<strong dir="ltr">17:30</strong> %s<span class="wfo-cx-who">%s</span><span class="wfo-cx-who">%s</span>' % (
                chip(T('مُصحَّح', 'Corrected'), 'is-ok'), esc(T('طلبتِه يوم 8 أكتوبر 09:02 · وافق أحمد سمير يوم 8 أكتوبر 10:14', 'You asked on 8 Oct 09:02 · approved by Ahmed Samir on 8 Oct 10:14')),
                esc(T('السبب: نسيت أسجّل الانصراف، خرجت 5:30 بعد اجتماع العميل.', 'Reason: I forgot to sign out; I left at 5:30 after the customer meeting.')))),
            (T('الأصل', 'Original'), '<s>%s</s><span class="wfo-cx-who">%s</span>' % (esc(T('لا يوجد انصراف', 'No Sign Out')), esc(T('محفوظ كما سجّله التطبيق', 'Kept as the app recorded it')))),
            (T('الحالة', 'Status'), '<strong>%s</strong>' % esc(T('مكتب · في الموعد · 8 س 38 د', 'Office · on time · 8 h 38 min'))),
        ]
        detail = ''.join('<div class="wfo-cx-line"><span>%s</span><div>%s</div></div>' % (esc(k), v) for k, v in lines)
        body = ('<section class="wfo-panel wfo-cx-dayview"><h3>%s</h3>%s</section>' % (esc(T('الأربعاء 7 أكتوبر', 'Wednesday 7 October')), detail)) + days_panel(after=True)
        return app_page(T('الحضور / الانصراف', 'Time'), body, active='time', css_files=APP_CSS, feature_css=CSS)
    return make


# ---------------------------------------------------------------- wp-admin

SUBMENU = [('emp', ('الموظفون', 'Employees')), ('report', ('تقرير الحضور والانصراف', 'Sign In / Out Report')), ('hub', ('مركز الطلبات', 'Requests Hub')),
           ('corr', ('تصحيحات الحضور', 'Attendance Corrections')), ('leaves', ('الإجازات', 'Leaves')), ('payroll', ('الرواتب', 'Payroll')),
           ('features', ('إعدادات الميزات', 'Feature Configuration')), ('overview', ('نظرة على الإعدادات', 'Settings Overview'))]


def tabs(current):
    items = [('list', T('الطلبات', 'Requests')), ('report', T('التقرير', 'Report')), ('settings', T('الإعدادات', 'Settings'))]
    return '<nav class="nav-tab-wrapper">%s</nav>' % ''.join('<a href="#" class="nav-tab%s">%s</a>' % (' nav-tab-active' if k == current else '', esc(l)) for k, l in items)


def admin_head(tab):
    return ('<h1 class="wp-heading-inline">%s</h1> <a href="#" class="page-title-action">%s</a><hr class="wp-header-end">%s') % (
        esc(T('تصحيحات الحضور', 'Attendance Corrections')), esc(T('تصحيح مباشر', 'Correct directly')), tabs(tab))


def hr_list(state='default'):
    def make():
        status = [(T('الكل', 'All'), 14), (T('في الانتظار', 'Waiting'), 3), (T('تمت الموافقة', 'Approved'), 9), (T('مرفوض', 'Rejected'), 2)]
        subsub = '<ul class="subsubsub">%s</ul>' % ' |'.join('<li><a href="#"%s>%s <span class="count">(%d)</span></a></li>' % (' class="current"' if i == 0 else '', esc(l), 0 if state == 'empty' else n) for i, (l, n) in enumerate(status))
        sel = lambda opts: '<select>%s</select>' % ''.join('<option>%s</option>' % esc(o) for o in opts)
        filters = '<div class="ews-cx-filters">%s%s%s<input type="search" placeholder="%s" list="cx-emps">%s<button class="button">%s</button></div>' % (
            sel([T('كل الحالات', 'All statuses'), T('في الانتظار', 'Waiting'), T('تمت الموافقة', 'Approved'), T('مرفوض', 'Rejected')]),
            sel([T('كل الأنواع', 'All types'), T('نسيت الانصراف', 'Forgot Sign Out'), T('نسيت الحضور', 'Forgot Sign In'), T('وقت غلط', 'Wrong time'), T('يوم كامل ناقص', 'Whole day missing')]),
            sel([T('كل الإدارات', 'All departments'), T('المبيعات', 'Sales'), T('العمليات', 'Operations'), T('المخازن', 'Warehouse')]),
            esc(T('الموظف', 'Employee')), '<input type="month" value="2026-10">', esc(T('تصفية', 'Filter')))
        head = [T('الموظف', 'Employee'), T('اليوم', 'Day'), T('النوع', 'Type'), T('التغيير', 'Change'), T('السبب', 'Reason'), T('الحالة', 'Status'), T('طُلب', 'Requested')]
        rows = [
            (T('منى عادل', 'Mona Adel'), T('المبيعات', 'Sales'), T('الأربعاء 7 أكتوبر', 'Wed 7 Oct'), T('نسيت الانصراف', 'Forgot Sign Out'), T('الانصراف: لا يوجد ← 17:30', 'Sign Out: none → 17:30'),
             T('خرجت 5:30 بعد اجتماع العميل', 'Left at 5:30 after the customer meeting'), ('is-wait', T('عند أحمد سمير', 'With Ahmed Samir')), T('8 أكتوبر 09:02', '8 Oct 09:02')),
            (T('كريم يوسف', 'Karim Youssef'), T('المبيعات', 'Sales'), T('الثلاثاء 6 أكتوبر', 'Tue 6 Oct'), T('وقت غلط', 'Wrong time'), T('الحضور: 09:31 ← 08:55', 'Sign In: 09:31 → 08:55'),
             T('التطبيق علّق والنت فاصل', 'The app hung, no network'), ('is-hr', T('المستوى 2 · الموارد البشرية', 'Level 2 · HR')), T('7 أكتوبر 18:20', '7 Oct 18:20')),
            (T('ياسمين خالد', 'Yasmin Khaled'), T('العمليات', 'Operations'), T('الاثنين 5 أكتوبر', 'Mon 5 Oct'), T('نسيت الحضور', 'Forgot Sign In'), T('الحضور: لا يوجد ← 08:45', 'Sign In: none → 08:45'),
             T('دخلت مع وفد العميل', 'Came in with the client delegation'), ('is-wait', T('عند هبة فاروق', 'With Heba Farouk')), T('5 أكتوبر 11:40', '5 Oct 11:40')),
            (T('منى عادل', 'Mona Adel'), T('المبيعات', 'Sales'), T('الأحد 4 أكتوبر', 'Sun 4 Oct'), T('يوم كامل ناقص', 'Whole day missing'), '09:00 – 17:00',
             T('مأمورية عند عميل في العاشر', 'Mission at a customer in 10th of Ramadan'), ('is-ok', T('وافق أحمد سمير', 'Approved · Ahmed Samir')), T('4 أكتوبر 20:05', '4 Oct 20:05')),
            (T('محمود حسن', 'Mahmoud Hassan'), T('المخازن', 'Warehouse'), T('الخميس 1 أكتوبر', 'Thu 1 Oct'), T('نسيت الانصراف', 'Forgot Sign Out'), T('الانصراف: لا يوجد ← 18:00', 'Sign Out: none → 18:00'),
             T('جرد آخر الشهر', 'Month-end stock count'), ('is-ok', T('تصحيح مباشر · هبة فاروق', 'Direct · Heba Farouk')), T('2 أكتوبر 09:15', '2 Oct 09:15')),
            (T('منى عادل', 'Mona Adel'), T('المبيعات', 'Sales'), T('الثلاثاء 29 سبتمبر', 'Tue 29 Sep'), T('وقت غلط', 'Wrong time'), T('الحضور: 09:40 ← 09:00', 'Sign In: 09:40 → 09:00'),
             T('الزحمة على الدائري', 'Traffic on the Ring Road'), ('is-bad', T('رفض أحمد سمير', 'Rejected · Ahmed Samir')), T('29 سبتمبر 10:02', '29 Sep 10:02')),
        ]
        if state == 'empty':
            tbody = '<tr class="no-items"><td colspan="7">%s</td></tr>' % esc(T('لا توجد طلبات تصحيح في أكتوبر 2026 بهذه التصفية.', 'No correction requests in October 2026 with these filters.'))
        else:
            tbody = ''.join('<tr><td><strong><a href="#">%s</a></strong><span class="ews-cx-sub">%s</span></td><td>%s</td><td>%s</td><td dir="auto">%s</td><td>%s</td>'
                            '<td><span class="ews-cx-badge %s">%s</span></td><td>%s</td></tr>' % (esc(a), esc(b), esc(c), esc(d), esc(e), esc(f), g[0], esc(g[1]), esc(h)) for a, b, c, d, e, f, g, h in rows)
        table = '<table class="wp-list-table widefat fixed striped"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>' % (''.join('<th>%s</th>' % esc(x) for x in head), tbody)
        nav = '<div class="tablenav top"><div class="tablenav-pages"><span class="displaying-num">%s</span></div><br class="clear"></div>' % esc(T('14 طلب', '14 items') if state != 'empty' else T('0 طلب', '0 items'))
        body = admin_head('list') + subsub + '<br class="clear">' + filters + nav + table
        return admin_page(T('تصحيحات الحضور', 'Attendance Corrections'), body, SUBMENU, 'corr', feature_css=CSS)
    return make


def hr_direct(state='default'):
    """state: default | error | success"""
    def make():
        notice = ''
        if state == 'error':
            notice = '<div class="notice notice-error"><p>%s</p></div>' % esc(T('اكتب السبب: التصحيح المباشر من الموارد البشرية يحتاج سببًا.', 'Write the reason: a direct correction by HR needs one.'))
        if state == 'success':
            notice = '<div class="notice notice-success is-dismissible"><p>%s</p></div>' % esc(T('تم حفظ التصحيح: منى عادل، الأربعاء 7 أكتوبر، الانصراف 17:30. السجل الأصلي محفوظ، والتصحيح في سجل التدقيق.',
                                                                                                 'Correction saved: Mona Adel, Wed 7 Oct, Sign Out 17:30. The original record is kept and the correction is in the Audit Log.'))
        reason_style = ' style="border-color:#d63638;box-shadow:0 0 0 1px #d63638"' if state == 'error' else ''
        rows = [
            (T('الموظف', 'Employee'), '<input type="text" class="regular-text" value="%s" list="ews-pick-employees"><p class="description">%s</p>' % (
                esc(T('منى عادل (mona.adel) · #128', 'Mona Adel (mona.adel) · #128')), esc(T('اكتب حروف من الاسم واختار من القائمة.', 'Type a few letters and pick from the list.')))),
            (T('اليوم', 'Day'), '<input type="date" value="2026-10-07"> <span class="description">%s</span>' % esc(T('المسجّل: حضور 08:52 · لا يوجد انصراف', 'Recorded: Sign In 08:52 · no Sign Out'))),
            (T('النوع', 'Type'), '<select><option>%s</option><option>%s</option><option>%s</option><option>%s</option></select>' % tuple(esc(T(*l)) for k, i, l in TYPES)),
            (T('الوقت الصحيح', 'Correct time'), '<input type="time" value="17:30">'),
            (T('السبب', 'Reason') + ' <span style="color:#d63638">*</span>', '<textarea class="large-text" rows="3"%s>%s</textarea><p class="description">%s</p>' % (
                reason_style, '' if state == 'error' else esc(T('المدير أكد إنها كانت في اجتماع العميل لحد 5:30.', 'Her manager confirmed she was in the customer meeting until 5:30.')),
                esc(T('مطلوب. يظهر للموظف وفي سجل التدقيق.', 'Required. The employee sees it, and it goes to the Audit Log.')))),
        ]
        form = '<table class="form-table" role="presentation">%s</table>' % ''.join('<tr><th scope="row">%s</th><td>%s</td></tr>' % (k if '<' in k else esc(k), v) for k, v in rows)
        body = (('<h1>%s</h1>' % esc(T('تصحيح حضور مباشر', 'Correct attendance directly'))) + notice +
                '<p>%s</p>' % esc(T('للحالات اللي الموظف مايقدرش يطلبها من التطبيق (مثلًا بعد مرور 7 أيام). نفس طريق الطلبات: السجل الأصلي لا يتغيّر.',
                                    'For cases the employee cannot request from the app (e.g. after 7 days). Same path as requests: the original record never changes.')) +
                '<div class="ews-cx-card">%s<p class="submit"><button class="button button-primary">%s</button> <a class="button" href="#">%s</a></p></div>' % (
                    form, esc(T('حفظ التصحيح', 'Save correction')), esc(T('إلغاء', 'Cancel'))))
        return admin_page(T('تصحيح حضور مباشر', 'Correct attendance directly'), body, SUBMENU, 'corr', feature_css=CSS)
    return make


def hr_settings(state='default'):
    def make():
        notice = '<div class="notice notice-success is-dismissible"><p>%s</p></div>' % esc(T('تم حفظ الإعدادات.', 'Settings saved.')) if state == 'success' else ''
        photo = lambda v: '<select>%s</select>' % ''.join('<option%s>%s</option>' % (' selected' if o == v else '', esc(T(*l))) for o, l in [
            ('off', ('بدون', 'Off')), ('opt', ('اختياري', 'Optional')), ('req', ('مطلوبة', 'Required'))])
        types = ''.join('<tr><td><label><input type="checkbox" checked> %s</label></td><td>%s</td></tr>' % (esc(T(*l)), photo('req' if k == 'day' else 'opt')) for k, i, l in TYPES)
        rows = [
            (T('الميزة', 'Feature'), '<label><input type="checkbox" checked> %s</label>' % esc(T('الموظفون يقدروا يطلبوا تصحيح حضور', 'Employees can request attendance corrections'))),
            (T('الأنواع والصورة', 'Types and photo'), '<table class="widefat ews-cx-types-table" style="max-width:520px"><thead><tr><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>' % (
                esc(T('النوع', 'Type')), esc(T('صورة مرفقة', 'Photo')), types)),
            (T('المهلة', 'Deadline'), '<input type="number" class="small-text" value="7"> %s<p class="description">%s</p>' % (
                esc(T('أيام بعد اليوم', 'days after the day')), esc(T('بعدها التصحيح يكون مباشر من الموارد البشرية بس.', 'After that, only HR can correct it directly.')))),
            (T('الحد الشهري للموظف', 'Monthly limit per employee'), '<input type="number" class="small-text" value="3"><fieldset style="margin-top:8px"><label><input type="radio" name="above"> %s</label><br>'
             '<label><input type="radio" name="above" checked> %s</label></fieldset>' % (esc(T('فوق الحد: يُرفض', 'Above it: refused')), esc(T('فوق الحد: يُقبل ويروح للموارد البشرية (مستوى ثاني)', 'Above it: allowed, sent to HR (second level)')))),
            (T('تقديم وقت الحضور', 'Earlier Sign In'), '<label><input type="checkbox" checked> %s</label><p class="description">%s</p>' % (
                esc(T('طلب يقدّم وقت الحضور يحتاج المستوى الثاني', 'A request that moves Sign In earlier needs the second level')), esc(T('لأنه يلغي تأخير وبيأثر على الراتب.', 'It removes lateness, which changes pay.')))),
            (T('تذكير نهاية اليوم', 'End-of-day reminder'), '<label><input type="checkbox" checked> %s</label> <input type="time" value="19:00"><p class="description">%s</p>' % (
                esc(T('إشعار لمن لم يسجّل الانصراف الساعة', 'Notify anyone without a Sign Out at')), esc(T('«لم تسجّل الانصراف اليوم. تطلب تصحيح؟»', '"You did not sign out today. Request a correction?"')))),
            (T('الموافقات', 'Approvals'), '<p>%s <a href="#">%s</a></p>' % (esc(T('مين يوافق ومستوى كام: من', 'Who approves and how many levels:')), esc(T('مسارات الموافقة ← تصحيح الحضور', 'Approval Workflows → Attendance correction')))),
        ]
        form = '<table class="form-table" role="presentation">%s</table>' % ''.join('<tr><th scope="row">%s</th><td>%s</td></tr>' % (esc(k), v) for k, v in rows)
        body = admin_head('settings') + notice + '<div class="ews-cx-card">%s<p class="submit"><button class="button button-primary">%s</button></p></div><p class="description">%s</p>' % (
            form, esc(T('حفظ التغييرات', 'Save Changes')), esc(T('كل الإعدادات دي ظاهرة في «نظرة على الإعدادات».', 'All of these are listed on Settings Overview.')))
        return admin_page(T('تصحيحات الحضور', 'Attendance Corrections'), body, SUBMENU, 'corr', feature_css=CSS)
    return make


def hr_report():
    def make():
        kpis = [('14', T('طلب في أكتوبر', 'requests in October')), ('9', T('موافقة (64%)', 'approved (64%)')), ('2', T('مرفوض', 'rejected')), ('3', T('في الانتظار', 'waiting'))]
        k = '<div class="ews-cx-kpis">%s</div>' % ''.join('<div class="ews-cx-kpi"><b>%s</b><span>%s</span></div>' % (a, esc(b)) for a, b in kpis)
        head = [T('الموظف', 'Employee'), T('الإدارة', 'Department'), T('نسيت الانصراف', 'Forgot Sign Out'), T('نسيت الحضور', 'Forgot Sign In'), T('وقت غلط', 'Wrong time'),
                T('يوم كامل', 'Whole day'), T('موافقة', 'Approved'), T('رفض', 'Rejected'), T('الإجمالي', 'Total')]
        rows = [(T('منى عادل', 'Mona Adel'), T('المبيعات', 'Sales'), 1, 0, 1, 1, 2, 1, 3, True), (T('كريم يوسف', 'Karim Youssef'), T('المبيعات', 'Sales'), 1, 0, 1, 0, 1, 0, 2, False),
                (T('ياسمين خالد', 'Yasmin Khaled'), T('العمليات', 'Operations'), 0, 2, 0, 0, 1, 0, 2, False), (T('محمود حسن', 'Mahmoud Hassan'), T('المخازن', 'Warehouse'), 2, 0, 0, 0, 2, 0, 2, False),
                (T('عمر سعيد', 'Omar Saeed'), T('العمليات', 'Operations'), 1, 0, 0, 1, 2, 0, 2, False), (T('نورا إبراهيم', 'Noura Ibrahim'), T('المبيعات', 'Sales'), 1, 1, 0, 0, 1, 1, 3, True)]
        tb = ''.join('<tr><td><strong>%s</strong>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td><span class="ews-cx-bar" style="width:%dpx"></span>%d</td></tr>' % (
            esc(a), (' <span class="ews-cx-flag">%s</span>' % esc(T('· متكرر', '· frequent'))) if fl else '', esc(b), c, d, e, f, g, h, t * 14, t) for a, b, c, d, e, f, g, h, t, fl in rows)
        table = '<table class="wp-list-table widefat striped"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>' % (''.join('<th>%s</th>' % esc(x) for x in head), tb)
        dept = [(T('المبيعات', 'Sales'), 8, 5, 2), (T('العمليات', 'Operations'), 4, 3, 0), (T('المخازن', 'Warehouse'), 2, 2, 0)]
        dtb = ''.join('<tr><td>%s</td><td><span class="ews-cx-bar" style="width:%dpx"></span>%d</td><td>%d</td><td>%d</td></tr>' % (esc(a), b * 14, b, c, d) for a, b, c, d in dept)
        dtable = '<table class="widefat striped" style="max-width:640px"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>' % (
            esc(T('الإدارة', 'Department')), esc(T('الطلبات', 'Requests')), esc(T('موافقة', 'Approved')), esc(T('رفض', 'Rejected')), dtb)
        filters = '<div class="ews-cx-filters"><input type="month" value="2026-10"><select><option>%s</option></select><button class="button">%s</button><span style="flex:1"></span><a class="button" href="#">%s</a> <a class="button" href="#">PDF</a></div>' % (
            esc(T('كل الإدارات', 'All departments')), esc(T('عرض', 'Show')), esc(T('تصدير Excel', 'Export to Excel')))
        body = (admin_head('report') + filters + k + '<h2>%s</h2><p class="description">%s</p>' % (
            esc(T('حسب الموظف', 'By employee')), esc(T('«متكرر» = 3 طلبات أو أكثر في الشهر: نمط يستحق المتابعة.', '"Frequent" = 3 or more requests in a month: a pattern worth a look.'))) + table +
            '<h2 style="margin-top:22px">%s</h2>' % esc(T('حسب الإدارة', 'By department')) + dtable)
        return admin_page(T('تقرير التصحيحات', 'Corrections report'), body, SUBMENU, 'corr', feature_css=CSS)
    return make


def day_after_report():
    def make():
        head = [T('التاريخ', 'Date'), T('الموظف', 'Employee'), T('الحالة المجدولة', 'Scheduled Status'), T('الحدث', 'Event'), T('الوقت', 'Time'), T('الموقع', 'Location'), T('النتيجة', 'Result'), T('المصدر', 'Source')]
        rows = ('<tr><td>2026-10-07</td><td>%s</td><td>%s</td><td>%s</td><td>08:52</td><td>%s</td><td>%s</td><td>%s</td></tr>'
                '<tr><td>2026-10-07</td><td>%s</td><td>%s</td><td>%s</td><td><strong>17:30</strong></td><td>—</td><td>—</td><td><span class="ews-cx-badge is-ok">%s</span></td></tr>'
                '<tr class="ews-cx-detail"><td></td><td colspan="7">%s</td></tr>'
                '<tr><td>2026-10-06</td><td>%s</td><td>%s</td><td>%s</td><td>08:55</td><td>%s</td><td>%s</td><td>%s</td></tr>') % (
            esc(T('منى عادل', 'Mona Adel')), esc(T('مكتب', 'Office')), esc(T('حضور', 'Sign In')), esc(T('مكتب المعادي (35 م)', 'Maadi office (35 m)')), esc(T('داخل النطاق', 'Inside')), esc(T('التطبيق', 'App')),
            esc(T('منى عادل', 'Mona Adel')), esc(T('مكتب', 'Office')), esc(T('انصراف', 'Sign Out')), esc(T('تصحيح', 'Correction')),
            esc(T('الأصل: لا يوجد انصراف (محفوظ). تصحيح رقم 482: الانصراف 17:30 · طلبته منى عادل 8 أكتوبر 09:02 · وافق أحمد سمير 8 أكتوبر 10:14 · السبب: خرجت 5:30 بعد اجتماع العميل.',
                  'Original: no Sign Out (kept). Correction #482: Sign Out 17:30 · asked by Mona Adel on 8 Oct 09:02 · approved by Ahmed Samir on 8 Oct 10:14 · reason: left at 5:30 after the customer meeting.')),
            esc(T('منى عادل', 'Mona Adel')), esc(T('مكتب', 'Office')), esc(T('حضور', 'Sign In')), esc(T('مكتب المعادي (20 م)', 'Maadi office (20 m)')), esc(T('داخل النطاق', 'Inside')), esc(T('التطبيق', 'App')))
        table = '<table class="widefat striped"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>' % (''.join('<th>%s</th>' % esc(x) for x in head), rows)
        body = ('<h1>%s</h1><form class="ews-cx-filters"><label>%s <input type="date" value="2026-10-01"></label> <label>%s <input type="date" value="2026-10-08"></label> '
                '<label>%s <input type="search" value="%s"></label> <button class="button button-primary">%s</button></form>%s<p class="description">%s</p>') % (
            esc(T('تقرير الحضور والانصراف', 'Sign In / Out Report')), esc(T('من', 'From')), esc(T('إلى', 'To')), esc(T('الموظف', 'Employee')), esc(T('منى', 'Mona')), esc(T('عرض التقرير', 'View Report')), table,
            esc(T('عمود «المصدر» جديد: التطبيق / الكشك / QR / تلقائي / استيراد / تصحيح / الإدارة. التصحيح يظهر بجانب الأصل، والأصل لا يُحذف.',
                  'The new "Source" column: App / Kiosk / QR / Auto / Import / Correction / Admin. A correction shows next to the original, which is never deleted.')))
        return admin_page(T('تقرير الحضور والانصراف', 'Sign In / Out Report'), body, SUBMENU, 'report', feature_css=CSS)
    return make


def build():
    screens = [
        ('emp-days', emp_days()), ('emp-days-empty', emp_days('empty')),
        ('emp-form', emp_form()), ('emp-form-photo', emp_form('photo')), ('emp-form-error', emp_form('error')), ('emp-form-success', emp_form('success')),
        ('emp-requests', emp_requests()), ('emp-requests-limit', emp_requests('limit')), ('emp-requests-empty', emp_requests('empty')),
        ('emp-push', emp_push()), ('emp-push-opens', emp_form('push')),
        ('mgr', mgr()), ('mgr-error', mgr('error')), ('mgr-success', mgr('success')), ('mgr-empty', mgr('empty')),
        ('hr-list', hr_list()), ('hr-list-empty', hr_list('empty')),
        ('hr-direct', hr_direct()), ('hr-direct-error', hr_direct('error')), ('hr-direct-success', hr_direct('success')),
        ('hr-settings', hr_settings()), ('hr-settings-saved', hr_settings('success')), ('hr-report', hr_report()),
        ('day-after-app', day_after_app()), ('day-after-report', day_after_report()),
    ]
    for slug, make in screens:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Attendance corrections — mockups (Step 1)', (
        '<p>Static mockups for <code>docs/tasks/attendance-corrections.md</code>. Each screen is shown in Arabic (RTL) and English, built on the plugin\'s real '
        'stylesheets, fonts and icons; app screens at 390&nbsp;px, wp-admin at 1280&nbsp;px (shrunk here; open a screen to see it full size). Sample company: Nile Contracting; '
        '"today" is Thursday 8 October 2026.</p>'), [
        ('Employee — app', 'The Sign In / Out page gains "My recent days"; a day without a Sign Out has the yellow <b>Request correction</b> button.', [
            ('emp-days', 'My recent days (on Sign In / Out)', P), ('emp-days-empty', 'Empty: a new employee', P),
            ('emp-form', 'The request form', P), ('emp-form-photo', 'With a photo attached', P), ('emp-form-error', 'Error: older than 7 days, time before Sign In', P),
            ('emp-form-success', 'Success', P), ('emp-requests', 'My corrections', P), ('emp-requests-limit', 'Monthly limit reached', P), ('emp-requests-empty', 'Empty', P)]),
        ('End-of-day push', 'Drawn by the phone; the second screen is what "Request correction" opens.', [
            ('emp-push', 'The push at 7 pm', P), ('emp-push-opens', 'What it opens: the form on today, "Forgot Sign Out"', P)]),
        ('Manager — app', 'Each request comes with the <b>evidence panel</b>: what the system recorded that day, read-only.', [
            ('mgr', 'Corrections to approve, with evidence', P), ('mgr-error', 'Error: reject without a note', P), ('mgr-success', 'Approved', P), ('mgr-empty', 'Empty', P)]),
        ('HR — wp-admin', '', [
            ('hr-list', 'Requests list with filters', D), ('hr-list-empty', 'Empty', D), ('hr-direct', 'Direct correction by HR', D), ('hr-direct-error', 'Error: no reason', D),
            ('hr-direct-success', 'Saved', D), ('hr-settings', 'Settings', D), ('hr-settings-saved', 'Settings saved', D), ('hr-report', 'Corrections report', D)]),
        ('The day afterwards', 'The original is kept and shown next to the correction, with who approved it.', [
            ('day-after-app', 'Employee app: the day', P), ('day-after-report', 'wp-admin: Sign In / Out Report', D)]),
    ])
