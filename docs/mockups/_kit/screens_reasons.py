"""My approvals: why a level is with administrators (QA 8) and requests decided outside an approval workflow (QA 6).

Owner decisions 2026-11-06, designs docs/tasks/admin-fallback-reason.md and docs/tasks/legacy-approval-cards.md (Step 1
mockups). An administrator's card says why the request came to them, with a link to the setting that would send the next
ones to the right person (only for those who can change it). Requests that never entered an approval workflow (overtime with
the workflow "Not set yet", a leave cancellation with leave approvals off) are cards too, decided as today on their own path.
"""
from build import T, esc, icon, app_page, write, gallery
import build as _build

_build.ICONS.setdefault('info', '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>')

F = 'approval-reasons'
CSS = 'approval-reasons.css'


def path_html(steps):
    out = ''
    for i, (st, b, s, why) in enumerate(steps):
        out += '<li class="is-%s"><span class="wfo-apv-dot" aria-hidden="true">%s</span><div><b>%s</b><small>%s</small>%s</div></li>' % (
            st, '✓' if st == 'done' else str(i + 1), esc(b), esc(s), ('<small class="wfo-apv-pwhy">%s</small>' % esc(why)) if why else '')
    return '<ol class="wfo-apv-path">%s</ol>' % out


def card(tone, ini, name, kind, what, pills, why=None, link=None, steps=(), note=True, open_=False, facts=()):
    pills_html = ''.join('<span class="wfo-apv-pill%s">%s</span>' % ((' ' + c) if c else '', esc(t)) for c, t in pills)
    why_html = ''
    if why:
        why_html = '<p class="wfo-apv-why">%s<span>%s%s</span></p>' % (icon('info', 15, 2.2), esc(why), ('<a href="#">%s</a>' % esc(link)) if link else '')
    facts_html = ('<dl class="wfo-apv-facts">%s</dl>' % ''.join('<dt>%s</dt><dd>%s</dd>' % (esc(k), esc(v)) for k, v in facts)) if facts else ''
    note_html = ('<label class="wfo-apv-note"><span>%s</span><textarea rows="2"></textarea></label>' % esc(T('ملاحظة (اختياري)', 'Note (optional)'))) if note else ''
    return ('<article class="wfo-apv-item tone-%s"><form onsubmit="return false"><div class="wfo-apv-who"><span class="wfo-apv-av" aria-hidden="true">%s</span>'
            '<div class="wfo-apv-t"><b>%s</b><small>%s</small></div></div><p class="wfo-apv-what">%s</p><div class="wfo-apv-meta">%s</div>%s'
            '<details class="wfo-apv-more"%s><summary>%s</summary>%s<h3>%s</h3>%s%s</details>'
            '<div class="wfo-apv-acts"><button type="button" class="wfo-apv-btn is-ok">%s</button><button type="button" class="wfo-apv-btn is-bad">%s</button></div></form></article>') % (
                tone, esc(ini), esc(name), esc(kind), esc(what), pills_html, why_html, ' open' if open_ else '', esc(T('التفاصيل', 'Details')), facts_html,
                esc(T('المسار', 'The path')), path_html(steps), note_html, esc(T('موافقة', 'Approve')), esc(T('رفض', 'Reject')))


ADMINS = ('المسؤولين', 'Administrators')


def inbox(open_first=False):
    def make():
        items = [
            card('leave', T('بف', 'BF'), T('بهاء فؤاد', 'Bahaa Fouad'), T('إجازة · سنوية', 'Leave · Annual'), T('3 أيام · الأحد 15 – الثلاثاء 17 نوفمبر', '3 days · Sun 15 – Tue 17 Nov'),
                 [('', T('المستوى 1 من 1', 'Level 1 of 1')), ('', T('مستني 3 ساعات عمل', 'Waiting 3 working h')), ('is-brand', T('للمسؤولين', 'For administrators'))],
                 why=T('وصل للمسؤولين لأن بهاء مالوش مدير مباشر.', 'It came to administrators because Bahaa has no direct manager.'),
                 link=T('حدّد مديره علشان طلباته الجاية تروح له', 'Set their manager so their next requests go to them'),
                 steps=[('now', T('المدير المباشر · المسؤولين', 'Direct manager · Administrators'), T('بيقرّر دلوقتي · مستني 3 ساعات عمل', 'Deciding now · waiting 3 working h'),
                         T('المسؤولين بيقرّروا: مفيش مدير مباشر محدّد', 'Administrators decide it: no direct manager is set'))], open_=open_first),
            card('overtime', T('سع', 'SA'), T('سلمى عاطف', 'Salma Atef'), T('وقت إضافي', 'Overtime'), T('الخميس 12 نوفمبر · 6:00 م – 9:00 م (3 س)', 'Thu 12 Nov · 6:00 PM – 9:00 PM (3 h)'),
                 [('', T('مستني 5 ساعات عمل', 'Waiting 5 working h')), ('is-legacy', T('من غير مسار موافقات', 'No approval workflow'))],
                 steps=[('now', T('اللي يقدر يدير الحضور والانصراف', 'Anyone who can manage Sign In / Out'), T('بيقرّر دلوقتي · مستني 5 ساعات عمل', 'Deciding now · waiting 5 working h'),
                         T('مسار الموافقات للوقت الإضافي لسه مش متحدّد، فبيتقرّر زي النهارده', 'The overtime approval workflow is not set yet, so it is decided as today'))], note=False),
            card('leave', T('عم', 'AM'), T('علي منصور', 'Ali Mansour'), T('إلغاء إجازة', 'Leave cancellation'), T('يلغي إجازة الأحد 22 – الاثنين 23 نوفمبر (يومين)', 'Cancel leave Sun 22 – Mon 23 Nov (2 days)'),
                 [('is-legacy', T('من غير مسار موافقات', 'No approval workflow'))],
                 steps=[('now', T('اللي يقدر يدير الإعدادات', 'Anyone who can manage settings'), T('بيقرّر دلوقتي', 'Deciding now'),
                         T('موافقات الإجازة مقفولة، فالإلغاء بيتقرّر زي النهارده', 'Leave approvals are off, so the cancellation is decided as today'))], note=False),
            card('correction', T('نر', 'NR'), T('نادين رفعت', 'Nadine Refaat'), T('تصحيح حضور', 'Attendance correction'), T('الاثنين 2 نوفمبر · انصراف 5:30 م', 'Mon 2 Nov · sign out 5:30 PM'),
                 [('', T('المستوى 2 من 2', 'Level 2 of 2')), ('is-late', T('متأخر · مستني 26 ساعة عمل', 'Overdue · Waiting 26 working h')), ('is-brand', T('للمسؤولين', 'For administrators'))],
                 why=T('وصل للمسؤولين لأن الموارد البشرية مش متحدّدة في مسارات الموافقات.', 'It came to administrators because HR is not set in Approval Workflows.'),
                 link=T('حدّد الموارد البشرية', 'Set HR'),
                 steps=[('done', T('المدير المباشر · منى عادل', 'Direct manager · Mona Adel'), T('وافقت الأربعاء 9:02 ص', 'Approved Wed 9:02 AM'), ''),
                        ('now', T('الموارد البشرية · المسؤولين', 'HR · Administrators'), T('بيقرّر دلوقتي · مستني 26 ساعة عمل', 'Deciding now · waiting 26 working h'),
                         T('المسؤولين بيقرّروا: الموارد البشرية مش متحدّدة', 'Administrators decide it: HR is not set'))]),
            card('overtime', T('كن', 'KN'), T('كريم ناجي', 'Karim Nagi'), T('وقت إضافي', 'Overtime'), T('السبت 7 نوفمبر · 4 س', 'Sat 7 Nov · 4 h'),
                 [('', T('المستوى 1 من 1', 'Level 1 of 1')), ('', T('مستني ساعتين عمل', 'Waiting 2 working h')), ('is-brand', T('للمسؤولين', 'For administrators'))],
                 why=T('وصل للمسؤولين لأن المسؤول عن الموافقة هو نفسه اللي طلب.', 'It came to administrators because the approver made the request.'),
                 steps=[('now', T('المدير المباشر · المسؤولين', 'Direct manager · Administrators'), T('بيقرّر دلوقتي · مستني ساعتين عمل', 'Deciding now · waiting 2 working h'),
                         T('المسؤولين بيقرّروا: المسؤول عن الموافقة هو اللي طلب', 'Administrators decide it: the approver made the request'))]),
        ]
        body = ('<div class="wfo-apv"><div class="wfo-apv-bar"><nav class="wfo-apv-filters"><a href="#" aria-current="true">%s</a><a href="#">%s</a><a href="#">%s</a><a href="#">%s</a></nav></div>'
                '<div class="wfo-apv-list">%s</div></div>') % (esc(T('الكل · 5', 'All · 5')), esc(T('إجازات · 2', 'Leave · 2')), esc(T('وقت إضافي · 2', 'Overtime · 2')),
                                                               esc(T('تصحيحات · 1', 'Corrections · 1')), ''.join(items))
        return app_page(T('موافقاتي', 'My approvals'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=('هبة فاروق', 'Heba Farouk'))
    return make


def mine():
    def make():
        steps = [('now', T('المدير المباشر · المسؤولين', 'Direct manager · Administrators'), T('بيقرّر دلوقتي · مستني 3 ساعات عمل', 'Deciding now · waiting 3 working h'),
                  T('المسؤولين بيقرّروا: مفيش مدير مباشر محدّد', 'Administrators decide it: no direct manager is set'))]
        item = ('<article class="wfo-apv-item tone-leave"><div class="wfo-apv-who"><div class="wfo-apv-t"><b>%s</b><small>%s</small></div><span class="wfo-apv-state is-waiting">%s</span></div>'
                '<details class="wfo-apv-more" open><summary>%s</summary>%s</details>'
                '<details class="wfo-apv-withdraw"><summary class="wfo-apv-btn is-bad is-sm">%s</summary></details></article>') % (
                    esc(T('إجازة سنوية · 15 – 17 نوفمبر', 'Annual leave · 15 – 17 Nov')), esc(T('3 أيام · طلبتها الأحد 9:10 ص', '3 days · asked Sun 9:10 AM')),
                    esc(T('مستني · المستوى 1 من 1', 'Waiting · level 1 of 1')), esc(T('مين بيقرّر', 'Who decides it')), path_html(steps), esc(T('سحب', 'Withdraw')))
        body = '<div class="wfo-apv is-mine"><nav class="wfo-apv-filters"><a href="#" aria-current="true">%s</a><a href="#">%s</a></nav><div class="wfo-apv-list">%s</div></div>' % (
            esc(T('الكل', 'All')), esc(T('مستني · 1', 'Waiting · 1')), item)
        return app_page(T('طلباتي', 'My requests'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=('بهاء فؤاد', 'Bahaa Fouad'))
    return make


def build():
    for slug, make in [('inbox', inbox()), ('inbox-open', inbox(open_first=True)), ('mine', mine())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Why it is with administrators, and requests outside a workflow — mockups', (
        '<p><b>Why a request is with administrators</b> (QA 8): the card says why it came to them, one text per reason (no direct manager, '
        'HR or Finance not set, no team or department manager, the approver is no longer active or has no account, the approver made the request, '
        'not decided in time with nobody above). A link opens the setting that sends the next requests to the right person, only for those who can change it. '
        'Old requests saved before this show a neutral "Administrators decide it". The person who asked sees the reason in My requests, without the link.</p>'
        '<p><b>Requests outside an approval workflow</b> (QA 6): overtime while its workflow is "Not set yet" and a leave cancellation while leave approvals '
        'are off are cards in My approvals for the people who can decide them today, with "No approval workflow", no level and no note (those paths keep none). '
        'They count in the menu and on Home. Nothing changes in who decides.</p>'), [
        ('My approvals (an administrator)', '', [('inbox', 'The reason on administrator cards, and cards without an approval workflow', P), ('inbox-open', 'Details open: the reason in the path too', P),
                                                 ('inbox', 'The same on a computer', D)]),
        ('My requests (the person who asked)', '', [('mine', 'The reason in "Who decides it", without the link', P)]),
    ])
