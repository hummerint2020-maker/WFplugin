"""Schedule change request (week lock release 2, docs/tasks/schedule-change-request.md, Step 1 mockups).

While the week lock is on and this feature is switched on, a manager's change on a day of a final week is no longer refused: it
becomes a request to HR with a reason. HR decides it in My approvals (app) or on the Requests page (wp-admin), level by level as
configured in Approval Workflows (HR by default). On the last approval every day still as it was is applied and the employee is
told; a day that changed meanwhile is skipped (setting). Off by default; off means release 1 exactly.

The Attendance page screens start from the real page (snap/attendance.<lang>.html, the week of 11 – 15 Oct) like the week-lock
mockups; the rest uses the kit's real frames and the plugin's own classes (app-approvals.css, the Requests Hub table).
"""
import os, re
from build import T, Lang, esc, icon, app_page, admin_page, page_doc, sheet, ux_modal, write, gallery, Build, KIT
import screens_weeklock as WL
import build as _build

_build.ICONS.setdefault('send', '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>')

F = 'schedule-change'
CSS = 'schedule-change.css'
WEEK = WL.WEEK
CUTOFF = WL.CUTOFF

# The three changes the manager makes in the mockups (person, day, old, new) and the day they cannot ask for.
CHANGES = [(('عادل حمدي', 'Adel Hamdy'), ('الأحد 11 أكتوبر', 'Sun 11 Oct'), ('من البيت', 'WFH'), ('مكتب', 'Office')),
           (('عادل حمدي', 'Adel Hamdy'), ('الثلاثاء 13 أكتوبر', 'Tue 13 Oct'), ('من البيت', 'WFH'), ('مكتب', 'Office')),
           (('سارة علي', 'Sara Ali'), ('الأربعاء 14 أكتوبر', 'Wed 14 Oct'), ('من البيت', 'WFH'), ('مكتب', 'Office'))]
REFUSED = (('عادل حمدي', 'Adel Hamdy'), ('الاثنين 12 أكتوبر', 'Mon 12 Oct'),
           ('اليوم ده إجازة: غيّره من الإجازة نفسها عشان الرصيد يتحسب صح.', 'This day is a leave: change it through the leave, so the balance is right.'))
REASON = ('عندنا زيارة عميل في المكتب الأحد والثلاثاء، ومحتاجين سارة في الاجتماع الأربعاء.',
          'A client visits the office on Sunday and Tuesday, and Sara is needed at Wednesday\'s meeting.')


def banner():
    title = T('الأسبوع ده نهائي: التغييرات بتروح لـ HR كطلب', 'This week is final: changes go to HR as a request')
    text = T('اتقفل %s. تقدر تغيّر الأيام وتكتب السبب، و HR يوافق أو يرفض. لحد ما يتوافق، الجدول بيفضل زي ما هو. الإجازات والتبديلات بتتطبّق عادي.' % T(*CUTOFF),
             'Locked since %s. Change the days and write why; HR approves or rejects. Until then the schedule stays as it is. Leave and swaps are still applied as usual.' % T(*CUTOFF))
    side = '<span class="wfo-wl-final">%s%s</span>' % (icon('lock', 14, 2.2), esc(T('نهائي', 'Final')))
    return ('<section class="wfo-wl-banner is-locked wfo-sc-banner" role="status"><span class="wfo-wl-ico">%s</span><div class="wfo-wl-text"><h3>%s</h3><p>%s</p></div>%s</section>') % (
        icon('send', 20, 2.2), esc(title), esc(text), side)


def grid(variant):
    """variant: request | send | sent | csv | draft"""
    def make():
        h = open(os.path.join(KIT, 'snap', 'attendance.%s.html' % Lang.code)).read()
        h = h.replace('{{ASSETS}}', Build.assets).replace('BA Team', T('شركة النيل للمقاولات', 'Nile Contracting'))
        feat = ''.join('<link rel="stylesheet" href="%s">' % (c.replace('.css', '-rtl.css') if Lang.rtl() else c) for c in (Build.assets + 'css/app-requests.css', '../week-lock/' + WL.CSS, CSS))
        h = h.replace('</head>', feat + '<link rel="stylesheet" href="%smock.css"></head>' % Build.kit, 1)
        h = re.sub(r'(<strong dir="ltr">[^<]*</strong>)', lambda m: m.group(1) + '<span class="wfo-wl-final is-small">%s%s</span>' % (icon('lock', 12, 2.4), esc(T('نهائي', 'Final'))), h, count=1)
        if variant == 'draft':
            # release 1's refusal, with the new button
            modal = ux_modal('error', T('3 تغييرات ما اتحفظتش', '3 changes were not saved'), T(
                'الأسبوع %s بقى نهائي %s وانت بتعدّل. باقي التغييرات اتحفظت. تقدر تبعت التغييرات دي لـ HR كطلب.' % (T(*WEEK), T(*CUTOFF)),
                'The week of %s became final at %s while you were editing. Your other changes were saved. You can send these to HR as a request.' % (T(*WEEK), T(*CUTOFF))))
            modal = modal.replace('<div class="ews-ux-actions">', '<div class="ews-ux-actions"><button type="button" class="ews-ux-ok wfo-sc-primary">%s%s</button>' % (
                icon('send', 16, 2.2), esc(T('ابعتها لـ HR كطلب', 'Send them to HR as a request'))), 1).replace('>%s</button></div></div></div>' % esc(T('حسنًا', 'OK')),
                ' style="background:#fff;color:#13235B;border:1px solid #CFCBEF">%s</button></div></div></div>' % esc(T('لا، شكرًا', 'No, thanks')), 1)
            h = h.replace('<section class="ews-att-controls', WL.banner('locked') + '<section class="ews-att-controls', 1)
            return h.replace('</body>', modal + '</body>', 1)
        h = h.replace('<section class="ews-att-controls', banner() + '<section class="ews-att-controls', 1)
        h = h.replace('class="ews-attendance-page wfo-att"', 'class="ews-attendance-page wfo-att wfo-sc-mode"', 1)
        # three changed days (WFH → Office), one day already requested by someone else
        n = {'i': 0}

        def change(m):
            n['i'] += 1
            if n['i'] in (1, 3, 9):
                cell = m.group(0).replace('class="ews-att-day ews-plan-wfh"', 'class="ews-att-day ews-plan-office is-changed wfo-sc-asked"', 1)
                return cell.replace('<b>WFH</b>', '<b>Office</b>', 1)   # the page shows the status names as configured (English here)
            if n['i'] == 6:
                cell = m.group(0).replace('class="ews-att-day ews-plan-wfh"', 'class="ews-att-day ews-plan-wfh wfo-sc-pending"', 1)
                cell = cell.replace('<button type="button" class="wfo-attg-chip" data-ews-cell', '<button type="button" class="wfo-attg-chip" disabled aria-disabled="true" title="%s"' % esc(T(
                    'متطلب: مكتب (من كريم فتحي، مستني HR)', 'Change requested: Office (by Karim Fathy, waiting for HR)')), 1)
                return cell.replace('</b></button>', '</b><span class="wfo-sc-badge">%s%s</span></button>' % (icon('clock', 11, 2.4), esc(T('متطلب', 'Requested'))), 1)
            return m.group(0)
        h = re.sub(r'<td class="ews-att-day ews-plan-wfh".*?</td>', change, h, flags=re.S)
        # the save bar sends instead of saving
        h = re.sub(r'<div class="ews-att-savebar">.*?</form>', lambda m: (
            '<div class="ews-att-savebar wfo-sc-savebar"><span class="wfo-attg-count">%s</span><button type="button" class="ews-btn wfo-attg-undo">%s%s</button>'
            '<button type="button" class="ews-btn ews-save-all">%s%s</button></div>\n    </form>') % (
                esc(T('3 تغييرات هتتبعت لـ HR', '3 changes to send to HR')), icon('close', 15, 2.2), esc(T('تراجع', 'Undo')), icon('send', 17, 2.4), esc(T('ابعت لـ HR…', 'Send to HR…'))), h, count=1, flags=re.S)
        if variant == 'csv':
            h = h.replace('<section class="ews-att-controls', csv_preview() + '<section class="ews-att-controls', 1)
        if variant == 'send':
            h = h.replace('</body>', send_sheet() + '</body>', 1)
        if variant == 'sent':
            h = h.replace('</body>', ux_modal('success', T('اتبعت لـ HR: 3 أيام', 'Sent to HR: 3 days'), T(
                'HR هيراجع الطلب، وهيوصلك إشعار لما يتقرّر. يوم واحد ما اتبعتش: عادل حمدي · الاثنين 12 أكتوبر — اليوم ده إجازة، غيّره من الإجازة نفسها.',
                'HR will review it and you will be told when it is decided. 1 day not sent: Adel Hamdy · Mon 12 Oct — this day is a leave; change it through the leave.')) + '</body>', 1)
        return h
    return make


def send_sheet():
    rows = ''.join('<li><b>%s</b><span>%s</span><span class="wfo-sc-change">%s<em>%s</em>%s</span></li>' % (
        esc(T(*p)), esc(T(*d)), esc(T(*o)), icon('arrow', 14, 2.2), esc(T(*nw))) for p, d, o, nw in CHANGES)
    body = ('<div class="wfo-rq-field"><span class="wfo-rq-label">%s</span><ul class="wfo-sc-list">%s</ul></div>'
            '<p class="wfo-sc-refused">%s<span><b>%s</b> %s</span></p>'
            '<div class="wfo-rq-field"><label for="sc-reason">%s</label><textarea id="sc-reason" rows="3">%s</textarea><small class="wfo-sc-hint">%s</small></div>'
            '<p class="wfo-sc-om">%s<span>%s</span></p>') % (
                esc(T('التغييرات', 'The changes')), rows,
                icon('alert', 16, 2.2), esc(T('مش هيتبعت:', 'Not sent:')), esc('%s · %s — %s' % (T(*REFUSED[0]), T(*REFUSED[1]), T(*REFUSED[2]))),
                esc(T('السبب (مطلوب)', 'Reason (required)')), esc(T(*REASON)), esc(T('10 حروف على الأقل. HR والموظفين المعنيين بيشوفوه.', 'At least 10 characters. HR sees it.')),
                icon('office', 16, 2.2), esc(T('لو اتوافق: الأحد يبقى 9 من 8 في المكتب (الحد الأدنى 8). ده تنبيه بس.', 'If approved: Sunday has 9 of 8 in the office (minimum 8). This is only a warning.')))
    return sheet(T('ابعت التغييرات لـ HR', 'Send the changes to HR'), body, T('ابعت لـ HR', 'Send to HR'),
                 note=T('الجدول بيفضل زي ما هو لحد ما HR يوافق. تقدر تسحب الطلب من «طلباتي» طول ما هو مستني.', 'The schedule stays as it is until HR approves. You can withdraw it from My requests while it waits.'), icon_name='send')


def csv_preview():
    rows = [(4, T('عادل حمدي', 'Adel Hamdy'), '2026-10-13', T('مكتب', 'Office'), 'final'),
            (5, T('سارة علي', 'Sara Ali'), '2026-10-14', T('مكتب', 'Office'), 'final'),
            (6, T('منى عادل', 'Mona Adel'), '2026-10-19', T('من البيت', 'WFH'), 'ok'),
            (7, T('عادل حمدي', 'Adel Hamdy'), '2026-10-12', T('مكتب', 'Office'), 'leave')]
    res = {'ok': '<span class="wfo-att-ok">%sOK</span>' % icon('check', 14, 2.4),
           'final': '<span class="wfo-sc-tohr">%s%s</span>' % (icon('send', 14, 2.2), esc(T('أسبوع نهائي: هيتبعت لـ HR', 'Final week: will be sent to HR'))),
           'leave': '<span class="ews-error wfo-att-bad">%s%s</span>' % (icon('alert', 14, 2.2), esc(T('اليوم ده إجازة', 'This day is a leave')))}
    trs = ''.join('<tr class="%s"><td>%d</td><td>%s</td><td dir="ltr">%s</td><td>%s</td><td></td><td>%s</td></tr>' % (
        'ews-invalid' if k == 'leave' else 'ews-valid', ln, esc(e), d, esc(s), res[k]) for ln, e, d, s, k in rows)
    return ('<section class="ews-csv-preview wfo-att-card"><h3>%s</h3><p>%s</p><div class="wfo-att-preview-scroll"><table class="ews-table ews-preview"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table></div>'
            '<div class="wfo-rq-field wfo-sc-csv-reason"><label>%s</label><textarea rows="2">%s</textarea></div>'
            '<div class="wfo-att-preview-actions"><button class="ews-btn wfo-att-btn">%s%s</button><a class="ews-btn secondary wfo-att-btn is-ghost" href="#">%s</a></div></section>') % (
                esc(T('معاينة الملف', 'CSV Preview')), esc(T('صف واحد هيتحفظ، 2 هيتبعتوا لـ HR في طلب واحد، و1 هيتشال.', '1 row will be saved, 2 will be sent to HR in one request, 1 will be skipped.')),
                esc(T('السطر', 'Line')), esc(T('الموظف', 'Employee')), esc(T('التاريخ', 'Date')), esc(T('الحالة', 'Status')), esc(T('ملاحظة', 'Note')), esc(T('النتيجة', 'Result')), trs,
                esc(T('سبب الصفوف اللي رايحة لـ HR (مطلوب)', 'Reason for the rows sent to HR (required)')), esc(T(*REASON)),
                icon('check', 16, 2.4), esc(T('استورد صف واحد وابعت 2 لـ HR', 'Import 1 row and send 2 to HR')), esc(T('إلغاء', 'Cancel')))


# ---------------------------------------------------------------- HR: My approvals (app)

def path_html(steps):
    return '<ol class="wfo-apv-path">%s</ol>' % ''.join('<li class="is-%s"><span class="wfo-apv-dot" aria-hidden="true">%s</span><div><b>%s</b><small>%s</small></div></li>' % (
        st, '✓' if st == 'done' else ('✕' if st == 'rejected' else str(i + 1)), esc(b), esc(s)) for i, (st, b, s) in enumerate(steps))


def hr_card():
    def make():
        days = ''.join('<li class="%s"><b>%s</b><span>%s</span><span class="wfo-sc-change">%s<em>%s</em>%s</span>%s</li>' % (
            'is-warn' if i == 1 else '', esc(T(*p)), esc(T(*d)), esc(T(*o)), icon('arrow', 14, 2.2), esc(T(*nw)),
            ('<small class="wfo-sc-since">%s%s</small>' % (icon('alert', 13, 2.2), esc(T('اتغيّر بعد الطلب: بقى إجازة (إجازة #812). اليوم ده هيتساب.', 'Changed since the request: now a leave (leave #812). This day will be skipped.')))) if i == 1 else '')
            for i, (p, d, o, nw) in enumerate(CHANGES))
        facts = ('<dl class="wfo-apv-facts"><dt>%s</dt><dd>%s</dd><dt>%s</dt><dd>%s</dd><dt>%s</dt><dd>%s</dd></dl>') % (
            esc(T('الأسبوع', 'Week')), esc(T(*WEEK)), esc(T('السبب', 'Reason')), esc(T(*REASON)),
            esc(T('القبض', 'Pay')), esc(T('شهر أكتوبر لسه مفتوح في المرتبات. الأيام اللي فاتت بتأثر على الغياب والتأخير.', 'October is still open in Payroll. Past days change absence and lateness.')))
        item = ('<article class="wfo-apv-item tone-schedule"><form onsubmit="return false"><div class="wfo-apv-who"><span class="wfo-apv-av" aria-hidden="true">%s</span>'
                '<div class="wfo-apv-t"><b>%s</b><small>%s</small></div></div><p class="wfo-apv-what">%s</p>'
                '<div class="wfo-apv-meta"><span class="wfo-apv-pill">%s</span><span class="wfo-apv-pill">%s</span><span class="wfo-apv-pill is-warn">%s</span></div>'
                '<ul class="wfo-sc-list is-card">%s</ul><p class="wfo-sc-om">%s<span>%s</span></p>'
                '<details class="wfo-apv-more" open><summary>%s</summary>%s<h3>%s</h3>%s'
                '<label class="wfo-apv-note"><span>%s</span><textarea rows="2"></textarea></label></details>'
                '<div class="wfo-apv-acts"><button type="button" class="wfo-apv-btn is-ok">%s</button><button type="button" class="wfo-apv-btn is-bad">%s</button></div></form></article>') % (
                    esc(T('كف', 'KF')), esc(T('كريم فتحي', 'Karim Fathy')), esc(T('تغيير جدول · مدير الحسابات', 'Schedule change · Accounts manager')),
                    esc(T('3 أيام في أسبوع %s · 2 موظفين' % T(*WEEK), '3 days in the week of %s · 2 people' % T(*WEEK))),
                    esc(T('المستوى 1 من 1', 'Level 1 of 1')), esc(T('مستني 3 ساعات عمل', 'Waiting 3 working h')), esc(T('يوم اتغيّر بعد الطلب', '1 day changed since')),
                    days, icon('office', 16, 2.2), esc(T('الأحد: 9 من 8 في المكتب لو اتوافق. تنبيه بس.', 'Sunday: 9 of 8 in the office if approved. Only a warning.')),
                    esc(T('التفاصيل', 'Details')), facts, esc(T('المسار', 'The path')),
                    path_html([('now', T('HR · هبة فاروق', 'HR · Heba Farouk'), T('مستني من الأحد 9:14 ص', 'Waiting since Sun 9:14 AM'))]),
                    esc(T('ملاحظة (مطلوبة للرفض)', 'Note (needed to reject)')), esc(T('موافقة', 'Approve')), esc(T('رفض', 'Reject')))
        body = ('<div class="wfo-apv"><div class="wfo-apv-bar"><nav class="wfo-apv-filters"><a href="#">%s</a><a href="#" aria-current="true">%s</a></nav></div><div class="wfo-apv-list">%s</div></div>') % (
            esc(T('الكل', 'All')), esc(T('تغيير جدول', 'Schedule change')), item)
        return app_page(T('موافقاتي', 'My approvals'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=('هبة فاروق', 'Heba Farouk'))
    return make


# ---------------------------------------------------------------- the requester: My requests

def mine():
    def make():
        def item(state, label, title, sub, steps, note='', withdraw=False, days=''):
            return ('<article class="wfo-apv-item tone-schedule"><div class="wfo-apv-who"><div class="wfo-apv-t"><b>%s</b><small>%s</small></div><span class="wfo-apv-state is-%s">%s</span></div>%s%s'
                    '<details class="wfo-apv-more"%s><summary>%s</summary>%s</details>%s</article>') % (
                        esc(title), esc(sub), state, esc(label), ('<p class="wfo-apv-why">%s</p>' % esc(note)) if note else '', days,
                        ' open' if state == 'waiting' else '', esc(T('مين بيقرّر', 'Who decides it')), path_html(steps),
                        ('<details class="wfo-apv-withdraw"><summary class="wfo-apv-btn is-bad is-sm">%s</summary></details>' % esc(T('سحب', 'Withdraw'))) if withdraw else '')
        skipped = '<ul class="wfo-sc-list is-mini"><li class="is-warn"><b>%s</b><span>%s</span><small class="wfo-sc-since">%s%s</small></li></ul>' % (
            esc(T('عادل حمدي', 'Adel Hamdy')), esc(T('الثلاثاء 13 أكتوبر', 'Tue 13 Oct')), icon('alert', 13, 2.2), esc(T('ما اتطبّقش: اليوم بقى إجازة قبل القرار.', 'Not applied: the day became a leave before the decision.')))
        items = [
            item('waiting', T('مستني · المستوى 1 من 1', 'Waiting · level 1 of 1'), T('تغيير جدول · 3 أيام', 'Schedule change · 3 days'), T('أسبوع 11 – 15 أكتوبر · الأحد 11 أكتوبر 9:14', 'Week of 11 – 15 Oct · Sun 11 Oct, 9:14'),
                 [('now', T('HR · هبة فاروق', 'HR · Heba Farouk'), T('مستني', 'Waiting'))], withdraw=True),
            item('approved', T('اتطبّق جزئيًا', 'Partly applied'), T('تغيير جدول · 3 أيام', 'Schedule change · 3 days'), T('أسبوع 4 – 8 أكتوبر · 2 اتطبّقوا، 1 لأ', 'Week of 4 – 8 Oct · 2 applied, 1 not'),
                 [('done', T('HR · هبة فاروق', 'HR · Heba Farouk'), T('وافقت الاثنين 5 أكتوبر', 'Approved Mon 5 Oct'))], days=skipped),
            item('rejected', T('اترفض', 'Rejected'), T('تغيير جدول · يوم واحد', 'Schedule change · 1 day'), T('أسبوع 27 سبتمبر – 1 أكتوبر', 'Week of 27 Sep – 1 Oct'),
                 [('rejected', T('HR · هبة فاروق', 'HR · Heba Farouk'), T('رفضت', 'Rejected'))], note=T('الحد الأدنى للمكتب يوم الخميس مش هيكمل.', 'Thursday would fall below the office minimum.')),
            item('expired', T('اتقفل تلقائيًا', 'Closed automatically'), T('تغيير جدول · يومين', 'Schedule change · 2 days'), T('أسبوع 13 – 17 سبتمبر', 'Week of 13 – 17 Sep'),
                 [('cancelled', T('HR', 'HR'), T('ما اتقرّرش في 14 يوم', 'Not decided in 14 days'))], note=T('اتقفل تلقائيًا: عدّى 14 يوم من غير قرار.', 'Closed automatically: 14 days without a decision.')),
        ]
        body = ('<div class="wfo-apv is-mine"><nav class="wfo-apv-filters"><a href="#" aria-current="true">%s</a><a href="#">%s</a><a href="#">%s</a></nav><div class="wfo-apv-list">%s</div></div>') % (
            esc(T('الكل', 'All')), esc(T('مستني · 1', 'Waiting · 1')), esc(T('اتوافق · 1', 'Approved · 1')), ''.join(items))
        return app_page(T('طلباتي', 'My requests'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=('كريم فتحي', 'Karim Fathy'))
    return make


def emp_push():
    def make():
        body = ('<div class="mock-lock"><div class="mock-lock-time" dir="ltr">11:02</div><div class="mock-lock-date">%s</div>'
                '<div class="mock-push"><span class="mock-push-icon">WO</span><div class="mock-push-body"><div class="mock-push-top"><span>WORKFORCE ONE</span><span>%s</span></div>'
                '<strong>%s</strong>%s</div></div><p class="mock-caption">%s</p></div>') % (
                    esc(T('الأحد 11 أكتوبر', 'Sunday 11 October')), esc(T('الآن', 'now')), esc(T('جدولك اتغيّر', 'Your schedule changed')),
                    esc(T('الأحد 11 أكتوبر بقى مكتب · الثلاثاء 13 أكتوبر بقى مكتب', 'Sun 11 Oct is now Office · Tue 13 Oct is now Office')),
                    esc(T('بيوصل للموظف لما الطلب يتوافق ويتطبّق (رسالة واحدة بأيامه)، زي أي تغيير جدول النهارده. وقت الطلب نفسه مفيش إشعار، إلا لو الإعداد اتفعّل.',
                          'Sent to the employee when the request is approved and applied (one message with their days), like any schedule change today. Nothing is sent when it is requested, unless the setting is on.')))
        return page_doc(T('إشعار الموظف', 'Employee push'), '<link rel="stylesheet" href="%smock.css">' % Build.kit, '<body style="margin:0">%s</body>' % body)
    return make


# ---------------------------------------------------------------- wp-admin

SUBMENU = [('requests', ('الطلبات', 'Requests')), ('approvals', ('مسارات الموافقة', 'Approval Workflows')), ('features', ('إعدادات الميزات', 'Feature Configuration')), ('audit', ('سجل التدقيق', 'Audit Log'))]


def hub(notice):
    def make():
        msgs = {'advanced': ('success', T('الموافقة اتسجّلت والطلب راح للمستوى اللي بعده.', 'Approval recorded and moved to the next level.')),
                'partial': ('success', T('اتوافق: 2 من 3 أيام اتطبّقوا. يوم واحد اتساب لأنه اتغيّر بعد الطلب (عادل حمدي · الثلاثاء 13 أكتوبر بقى إجازة).',
                                         'Approved: 2 of 3 days applied. 1 day was skipped because it changed since the request (Adel Hamdy · Tue 13 Oct is now a leave).')),
                'changed': ('error', T('ما اتغيّرش حاجة: يوم من أيام الطلب اتغيّر بعد الطلب، والإعداد بيرفض الطلب كله في الحالة دي. تقدر ترفضه.',
                                       'Nothing was changed: a day of this request changed since it was made, and the setting refuses the whole request then. You can reject it.')),
                'moved': ('error', T('الطلب ده راح لمستوى تاني من وقت ما فتحت الصفحة؛ ما اتغيّرش حاجة.', 'This request moved to another level since you opened the page; nothing was changed.'))}
        kind, text = msgs[notice]
        rows = [(T('تغيير جدول', 'Schedule Change'), T('كريم فتحي', 'Karim Fathy'), T('3 أيام · أسبوع 11 – 15 أكتوبر · عادل حمدي الأحد من البيت ← مكتب، الثلاثاء من البيت ← مكتب · سارة علي الأربعاء من البيت ← مكتب · «%s»' % T(*REASON),
                                                                                   '3 days · week of 11 – 15 Oct · Adel Hamdy Sun WFH → Office, Tue WFH → Office · Sara Ali Wed WFH → Office · "%s"' % T(*REASON)),
                 T('موافقة #418 · المستوى 1', 'Approval #418 · Level 1'), '2026-10-11 09:14'),
                (T('إجازة / سنوية', 'Vacation / Annual Leave'), T('منى عادل', 'Mona Adel'), '2026-10-25 → 2026-10-26 · 2 day(s)', T('موافقة #417 · المستوى 1', 'Approval #417 · Level 1'), '2026-10-10 16:40')]
        trs = ''.join('<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td dir="ltr">%s</td><td><div style="display:flex;gap:7px;flex-wrap:wrap"><button class="button button-primary">%s</button><button class="button">%s</button></div></td></tr>' % (
            esc(a), esc(b), esc(c), esc(dd), e, esc(T('موافقة', 'Approve')), esc(T('رفض', 'Reject'))) for a, b, c, dd, e in rows)
        body = ('<h1>%s</h1><div class="notice notice-%s is-dismissible"><p>%s</p></div><div style="margin-top:18px;background:#fff;border:1px solid #dcdcde;max-width:1200px"><table class="widefat striped">'
                '<thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table></div>') % (
                    esc(T('مركز الطلبات', 'Requests Hub')), kind, esc(text), esc(T('الطلب', 'Request')), esc(T('الموظف', 'Employee')), esc(T('التفاصيل', 'Details')),
                    esc(T('الموافقة', 'Approval')), esc(T('اتطلب', 'Requested')), esc(T('الإجراء', 'Action')), trs)
        return admin_page(T('مركز الطلبات', 'Requests Hub'), body, SUBMENU, 'requests', feature_css=CSS)
    return make


def admin_settings():
    def make():
        on = '<label class="wfo-feature-status"><input type="checkbox" checked> %s</label>' % esc(T('مفعّل', 'Enabled'))
        statuses = ''.join('<label class="wfo-sc-opt"><input type="checkbox"%s> %s</label>' % (' checked' if c else '', esc(T(*l))) for l, c in [
            (('مكتب', 'Office'), 1), (('من البيت', 'WFH'), 1), (('مأمورية', 'Business trip'), 1), (('غياب', 'Absent'), 1), (('إجازة (أنواع الإجازات)', 'Leave types'), 0)])
        rows = [
            (T('طلبات التغيير لـ HR', 'Change requests to HR'), ('<label><input type="checkbox" checked> %s</label><p class="description">%s</p><p class="wfo-sc-dep">%s%s</p>') % (
                esc(T('تغيير المدير على يوم في أسبوع نهائي يبقى طلب لـ HR بسبب', 'A manager\'s change on a day of a final week becomes a request to HR, with a reason')),
                esc(T('بيشتغل بس وقفل الأسبوع شغّال. لو اتقفل (هو أو قفل الأسبوع)، الطلبات اللي مستنية بتتقفل تلقائيًا والمديرين بيوصلهم إشعار.',
                      'Works only while the week lock is on. Switching it off (or the week lock) closes the waiting requests automatically and tells the managers.')),
                icon('check', 14, 2.4), esc(T('«موافقاتي» شغّالة: HR بيقرّر من التطبيق ومن صفحة الطلبات.', 'My approvals is on: HR decides in the app and on the Requests page.')))),
            (T('الأنواع اللي تتطلب', 'Statuses that can be requested'), '<div class="wfo-sc-opts">%s</div><p class="description">%s</p>' % (
                statuses, esc(T('الإجازات بتتطلب من صفحة الإجازات عشان الرصيد يتحسب.', 'Leave goes through a leave request, so the balance is counted.')))),
            (T('السبب', 'Reason'), '<input type="number" value="10" min="1" max="200" class="small-text"> %s' % esc(T('حرف على الأقل (السبب مطلوب دايمًا)', 'characters at least (a reason is always required)'))),
            (T('أقصى عدد أيام في طلب', 'Most days in one request'), '<input type="number" value="25" min="1" max="200" class="small-text">'),
            (T('الأيام اللي فاتت', 'Past days'), '<input type="number" value="14" min="0" max="90" class="small-text"> %s<p class="description">%s</p>' % (
                esc(T('يوم لورا (0 = النهارده وبعده بس)', 'days back (0 = today and later only)')), esc(T('أبدًا في شهر اتقفل في المرتبات.', 'Never in a month already closed in Payroll.')))),
            (T('يوم اتغيّر قبل القرار', 'A day changed before the decision'), '<label><input type="radio" name="sc_changed" checked> %s</label><br><label><input type="radio" name="sc_changed"> %s</label>' % (
                esc(T('يتساب اليوم ده وباقي الأيام تتطبّق', 'Skip that day and apply the others')), esc(T('ما يتطبّقش حاجة (HR يرفضه)', 'Apply nothing (HR rejects it)')))),
            (T('انتهاء الطلب', 'Expiry'), '<input type="number" value="14" min="0" max="90" class="small-text"> %s<p class="description">%s</p>' % (
                esc(T('يوم من غير قرار (0 = أبدًا)', 'days without a decision (0 = never)')), esc(T('وبيتقفل كمان لو شهره اتقفل في المرتبات.', 'It also closes when its month is closed in Payroll.')))),
            (T('ملف الإكسيل', 'CSV import'), '<label><input type="checkbox" checked> %s</label>' % esc(T('صفوف الأسابيع النهائية تتبعت لـ HR كطلب واحد بسبب واحد', 'Rows in a final week are sent to HR as one request with one reason'))),
            (T('الموظف', 'The employee'), '<label><input type="checkbox"> %s</label><p class="description">%s</p>' % (
                esc(T('بلّغه كمان وقت الطلب', 'Also tell them when it is requested')), esc(T('لما يتطبّق بيتبلّغ دايمًا، زي أي تغيير جدول.', 'They are always told when it is applied, like any schedule change.')))),
            (T('كلام البانر', 'Banner text'), ('<p class="description">%s</p><div class="wfo-sc-texts">'
                '<label>%s<input type="text" class="regular-text" placeholder="%s"></label><label>%s<textarea rows="2" class="large-text" placeholder="%s"></textarea></label>'
                '<label>%s<input type="text" dir="ltr" class="regular-text" placeholder="This week is final"></label><label>%s<textarea rows="2" dir="ltr" class="large-text" placeholder="Locked since {since}. Approved leave and swaps are still applied. To change a day, ask HR."></textarea></label></div>'
                '<p class="description">%s</p>') % (
                esc(T('اللي بيظهر للمديرين فوق صفحة الحضور لما الأسبوع يبقى نهائي. سيبه فاضي عشان يفضل الكلام الافتراضي.', 'What managers see above the Attendance page when the week is final. Leave it empty to keep the default text.')),
                esc(T('العنوان (عربي)', 'Title (Arabic)')), esc('الأسبوع ده نهائي'), esc(T('الشرح (عربي)', 'Text (Arabic)')), esc('مقفول منذ {since}. الإجازات والتبديلات الموافق عليها تُطبَّق كالمعتاد. لتغيير يوم، تواصل مع الموارد البشرية.'),
                esc(T('العنوان (إنجليزي)', 'Title (English)')), esc(T('الشرح (إنجليزي)', 'Text (English)')),
                esc(T('{since} بيتحط مكانها ميعاد القفل. كل مستخدم بيشوف النص بلغة الموقع عنده.', '{since} is replaced by the time the week was locked. Each person sees the text in their own language.')))),
            (T('مين بيوافق', 'Who approves'), '<p>%s <a href="#">%s</a></p>' % (esc(T('HR، مستوى واحد. تقدر تغيّره من', 'HR, one level. Change it on')), esc(T('مسارات الموافقة ← تغيير جدول', 'Approval Workflows → Schedule Change')))),
        ]
        form = '<table class="form-table" role="presentation">%s</table>' % ''.join('<tr><th scope="row">%s</th><td>%s</td></tr>' % (esc(k), v) for k, v in rows)
        body = ('<h1>%s</h1><div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div>%s</div>'
                '<div class="wfo-wl-admin"><p class="description">%s</p></div><div class="wfo-sc-block"><h3>%s</h3>%s<p class="wfo-sc-warn">%s%s</p></div></div><p class="submit"><button class="button button-primary">%s</button></p>') % (
                    esc(T('إعدادات الميزات', 'Feature Configuration')), esc(T('قفل الأسبوع', 'Week lock')),
                    esc(T('من ميعاد ثابت كل أسبوع، جدول الأسبوع الجاي بيبقى نهائي.', 'From a fixed time every week, next week\'s schedule is final.')), on,
                    esc(T('ميعاد القفل: الخميس 5:00 م · إشعار الموظفين: أيوه (زي ما هو في الإصدار 1).', 'Cutoff: Thursday 5:00 PM · notify employees: yes (as in release 1).')),
                    esc(T('طلبات التغيير لـ HR', 'Change requests to HR')), form,
                    icon('alert', 14, 2.2), esc(T('لو قفلت الميزة دي دلوقتي: 2 طلب مستنيين هيتقفلوا تلقائيًا والمديرين هيتبلّغوا.', 'If you switch this off now: 2 waiting requests will be closed automatically and their managers told.')),
                    esc(T('حفظ التغييرات', 'Save Changes')))
        return admin_page(T('إعدادات الميزات', 'Feature Configuration'), body, SUBMENU, 'features', css_files=['admin-features.css'], feature_css=CSS)
    return make


def admin_workflow():
    def make():
        rows = [(T('المستويات', 'Levels'), '<select><option selected>%s</option><option>%s</option></select>' % (esc(T('مستوى واحد', 'One level')), esc(T('مستويين', 'Two levels')))),
                (T('المستوى 1', 'Level 1'), '<select><option selected>HR</option><option>%s</option><option>%s</option></select> <span class="description">%s</span>' % (
                    esc(T('المدير المباشر', 'Direct manager')), esc(T('مدير المدير', "Manager's manager")), esc(T('HR: هبة فاروق (من إعدادات الموافقات)', 'HR: Heba Farouk (from the approval settings)')))),
                (T('لو اللي طالب هو HR', 'When the requester is the approver'), '<select><option selected>%s</option><option>%s</option><option>%s</option></select>' % (
                    esc(T('يروح للمديرين (الأدمن)', 'Administrators decide')), esc(T('المستوى اللي بعده', 'The next level')), esc(T('يوافق بنفسه', 'They decide')))),
                (T('سبب الرفض', 'Reason to reject'), '<label><input type="checkbox" checked> %s</label>' % esc(T('مطلوب', 'Required'))),
                (T('تذكير / تصعيد', 'Remind / move up'), '<input type="number" value="0" class="small-text"> / <input type="number" value="0" class="small-text"> %s' % esc(T('ساعة عمل (0 = أبدًا)', 'working hours (0 = never)')))]
        form = '<table class="form-table" role="presentation">%s</table>' % ''.join('<tr><th scope="row">%s</th><td>%s</td></tr>' % (esc(k), v) for k, v in rows)
        body = ('<h1>%s</h1><div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div></div>%s'
                '<p class="wfo-sc-path"><b>%s</b> %s</p></div><p class="submit"><button class="button button-primary">%s</button></p>') % (
                    esc(T('مسارات الموافقة', 'Approval Workflows')), esc(T('تغيير جدول', 'Schedule Change')),
                    esc(T('طلبات المديرين لتغيير يوم في أسبوع نهائي (قفل الأسبوع). أول مرة الميزة تشتغل بيتعمل مستوى واحد: HR.', 'Managers\' requests to change a day of a final week (week lock). Set up with one level, HR, the first time the feature is on.')),
                    form, esc(T('المسار:', 'Path:')), esc(T('المدير يطلب ← HR يوافق ← الأيام تتطبّق والموظف يتبلّغ', 'The manager asks → HR approves → the days are applied and the employee is told')),
                    esc(T('حفظ', 'Save')))
        return admin_page(T('مسارات الموافقة', 'Approval Workflows'), body, SUBMENU, 'approvals', css_files=['admin-features.css'], feature_css=CSS)
    return make


def admin_audit():
    def make():
        rows = [('2026-10-11 11:02', T('هبة فاروق', 'Heba Farouk'), 'schedule_change_applied', T('طلب #31 · عادل حمدي الأحد 11 أكتوبر: من البيت ← مكتب', 'Request #31 · Adel Hamdy Sun 11 Oct: WFH → Office')),
                ('2026-10-11 11:02', T('هبة فاروق', 'Heba Farouk'), 'schedule_change_skipped', T('طلب #31 · عادل حمدي الثلاثاء 13 أكتوبر: اتغيّر بعد الطلب (إجازة)', 'Request #31 · Adel Hamdy Tue 13 Oct: changed since the request (leave)')),
                ('2026-10-11 11:02', T('هبة فاروق', 'Heba Farouk'), 'schedule_change_approved', T('طلب #31 · 3 أيام · 2 اتطبّقوا', 'Request #31 · 3 days · 2 applied')),
                ('2026-10-11 09:14', T('كريم فتحي', 'Karim Fathy'), 'schedule_change_request', T('طلب #31 · 3 أيام · أسبوع 11 – 15 أكتوبر · «%s»' % T(*REASON), 'Request #31 · 3 days · week of 11 – 15 Oct · "%s"' % T(*REASON))),
                ('2026-09-30 05:00', T('النظام', 'System'), 'schedule_change_expired', T('طلب #22 · ما اتقرّرش في 14 يوم', 'Request #22 · not decided in 14 days'))]
        trs = ''.join('<tr><td dir="ltr">%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>' % (d, esc(u), a, esc(m)) for d, u, a, m in rows)
        body = ('<h1>%s</h1><p>%s</p><table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>') % (
            esc(T('سجل التدقيق', 'Audit Log')), esc(T('الفلتر: تغيير جدول', 'Filter: Schedule change')),
            esc(T('الوقت', 'Time')), esc(T('مين', 'Who')), esc(T('العملية', 'Action')), esc(T('التفاصيل', 'Details')), trs)
        return admin_page(T('سجل التدقيق', 'Audit Log'), body, SUBMENU, 'audit', feature_css=CSS)
    return make


def build():
    for slug, make in [('request', grid('request')), ('send', grid('send')), ('sent', grid('sent')), ('draft', grid('draft')), ('csv', grid('csv')),
                       ('hr-card', hr_card()), ('hub-advanced', hub('advanced')), ('hub-partial', hub('partial')), ('hub-changed', hub('changed')), ('hub-moved', hub('moved')),
                       ('mine', mine()), ('emp-push', emp_push()), ('settings', admin_settings()), ('workflow', admin_workflow()), ('audit', admin_audit())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Schedule change request — mockups (week lock, release 2)', (
        '<p>While the <b>week lock</b> is on and this feature is switched on, a manager\'s change on a day of a <b>final week</b> becomes a <b>request to HR with a reason</b> '
        'instead of being refused. HR decides it in <b>My approvals</b> (app) or on the <b>Requests page</b> (wp-admin); the levels are set in Approval Workflows (HR by default). '
        'On approval every day that is still as it was is applied and the employee is told; a day that changed meanwhile (a leave, a swap) is skipped. '
        'Leave days go through a leave request. Past days up to 14 days back, never in a month closed in Payroll. A waiting request closes after 14 days or when its month closes.</p>'
        '<p>Off by default; off means release 1 exactly. Nothing new in the phone app this release.</p>'), [
        ('The manager — app → Attendance (a final week)', '', [
            ('request', 'Request mode: changed days, a day already requested, "3 changes to send to HR"', D),
            ('request', 'The same on a phone', P),
            ('send', 'Send to HR: the changes, the reason, a day that cannot be asked for, the office minimum', D),
            ('sent', 'After sending: sent 3, 1 not sent with why', D),
            ('draft', 'A page opened before the cutoff, saved after it: send the refused changes to HR', D),
            ('csv', 'CSV import: final-week rows go to HR with one reason', D)]),
        ('HR', '', [('hr-card', 'My approvals: the request, each day old → new, a day changed since, pay note, approve / reject', P),
                    ('hub-advanced', 'Requests page: the row (current level only); moved to the next level', D),
                    ('hub-partial', 'Requests page: approved, one day skipped', D),
                    ('hub-changed', 'Requests page: setting "apply nothing", a day changed', D),
                    ('hub-moved', 'Requests page: the level moved since the page was opened (3.31.122)', D)]),
        ('The manager — My requests', '', [('mine', 'Waiting (withdraw), partly applied, rejected with the reason, closed automatically', P)]),
        ('The employee', '', [('emp-push', 'Told when the change is applied', P)]),
        ('wp-admin', '', [('settings', 'Feature Configuration → Week lock → Change requests to HR', D),
                          ('workflow', 'Approval Workflows → Schedule Change (HR, one level)', D),
                          ('audit', 'Audit Log', D)]),
    ])
