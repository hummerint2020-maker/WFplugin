"""Shift swaps in My approvals (3.31.124, owner decision 2026-10-24, Step 1 mockups).

My approvals lists everything that waits for the person's decision, and so also a swap a colleague asked them for (accept or
decline), not only the swaps waiting for an approver. The colleague's notification opens it there. The Schedule page no longer
lists requests: one line with the count and a link to My approvals, and the person's own requests are in My requests. With My
approvals switched off the Schedule page keeps today's swap panel. No new setting, no database change.
"""
from build import T, esc, icon, app_page, page_doc, write, gallery, Build
import build as _build

_build.ICONS.setdefault('info', '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>')

F = 'swap-inbox'
CSS = 'swap-inbox.css'


def path_html(steps):
    return '<ol class="wfo-apv-path">%s</ol>' % ''.join('<li class="is-%s"><span class="wfo-apv-dot" aria-hidden="true">%s</span><div><b>%s</b><small>%s</small></div></li>' % (
        st, '✓' if st == 'done' else str(i + 1), esc(b), esc(s)) for i, (st, b, s) in enumerate(steps))


def peer_card(who, ini, day, mine, theirs, since, note=''):
    """A swap a colleague asked the person for: accept or decline."""
    trade = ('<div class="wfo-si-trade"><div><small>%s</small><b>%s</b></div><span class="wfo-si-arrow" aria-hidden="true">%s</span><div><small>%s</small><b>%s</b></div></div>') % (
        esc(T('انت هتشتغل', 'You will work')), esc(T(*theirs)), icon('swap', 18, 2), esc(T('%s هيشتغل' % T(*who).split()[0], '%s will work' % T(*who).split()[0])), esc(T(*mine)))
    return ('<article class="wfo-apv-item tone-swap"><form onsubmit="return false"><div class="wfo-apv-who"><span class="wfo-apv-av" aria-hidden="true">%s</span>'
            '<div class="wfo-apv-t"><b>%s</b><small>%s</small></div></div><p class="wfo-apv-what">%s</p>%s'
            '<div class="wfo-apv-meta"><span class="wfo-apv-pill is-brand">%s</span><span class="wfo-apv-pill">%s</span></div>%s'
            '<div class="wfo-apv-acts"><button type="button" class="wfo-apv-btn is-ok">%s</button><button type="button" class="wfo-apv-btn is-bad">%s</button></div></form></article>') % (
                esc(ini), esc(T(*who)), esc(T('تبديل يوم · طلب منك انت', 'Shift swap · asked of you')), esc(T(*day)), trade,
                esc(T('ردّك انت', 'Your answer')), esc(since), ('<p class="wfo-si-note">%s%s</p>' % (icon('info', 14, 2.2), esc(note))) if note else '',
                esc(T('قبول', 'Accept')), esc(T('رفض', 'Decline')))


def approver_card():
    return ('<article class="wfo-apv-item tone-swap"><form onsubmit="return false"><div class="wfo-apv-who"><span class="wfo-apv-av" aria-hidden="true">%s</span>'
            '<div class="wfo-apv-t"><b>%s</b><small>%s</small></div></div><p class="wfo-apv-what">%s</p>'
            '<div class="wfo-apv-meta"><span class="wfo-apv-pill">%s</span><span class="wfo-apv-pill">%s</span></div>'
            '<details class="wfo-apv-more"><summary>%s</summary>%s</details>'
            '<div class="wfo-apv-acts"><button type="button" class="wfo-apv-btn is-ok">%s</button><button type="button" class="wfo-apv-btn is-bad">%s</button></div></form></article>') % (
                esc(T('سح', 'SH')), esc(T('سارة حسن', 'Sara Hassan')), esc(T('تبديل يوم · مستني موافقتك كمسؤول', 'Shift swap · waits for you as approver')),
                esc(T('الثلاثاء 27 أكتوبر مع كريم ناجي (وافق)', 'Tue 27 Oct with Karim Nagi (they accepted)')),
                esc(T('المستوى 1 من 1', 'Level 1 of 1')), esc(T('مستني ساعتين عمل', 'Waiting 2 working h')),
                esc(T('التفاصيل', 'Details')), path_html([('done', T('كريم ناجي (الزميل)', 'Karim Nagi (colleague)'), T('قبِل الاثنين 9:40 ص', 'Accepted Mon 9:40 AM')),
                                                          ('now', T('انت', 'You'), T('مستني', 'Waiting'))]),
                esc(T('موافقة', 'Approve')), esc(T('رفض', 'Reject')))


def inbox():
    def make():
        items = [peer_card(('أحمد سمير', 'Ahmed Samir'), T('أس', 'AS'), ('الأحد 25 أكتوبر (الأسبوع الجاي)', 'Sun 25 Oct (next week)'), ('مكتب', 'Office'), ('من البيت', 'WFH'), T('من ساعة', '1 h ago')),
                 peer_card(('منى عادل', 'Mona Adel'), T('مع', 'MA'), ('الأربعاء 4 نوفمبر', 'Wed 4 Nov'), ('من البيت', 'WFH'), ('مكتب', 'Office'), T('امبارح', 'yesterday'),
                           note=T('بعد ما تقبل، الطلب بيروح للمسؤول يوافق عليه.', 'After you accept, it goes to the approver.')),
                 approver_card()]
        body = ('<div class="wfo-apv"><div class="wfo-apv-bar"><nav class="wfo-apv-filters"><a href="#" aria-current="true">%s</a><a href="#">%s</a><a href="#">%s</a></nav></div>'
                '<div class="wfo-apv-list">%s</div></div>') % (esc(T('الكل', 'All')), esc(T('تبديل · 3', 'Shift swap · 3')), esc(T('إجازات', 'Leave')), ''.join(items))
        return app_page(T('موافقاتي', 'My approvals'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=('كريم ناجي', 'Karim Nagi'))
    return make


def schedule():
    def make():
        days = ''.join('<li class="is-%s"><span class="wfo-myweek-day">%s</span><span class="wfo-myweek-num">%s</span><span class="wfo-myweek-icon">%s</span><span class="wfo-myweek-word">%s</span></li>' % (
            tone, esc(T(a, e)), n, icon({'office': 'office', 'wfh': 'wfh'}[tone], 18, 2), esc(T(*w))) for a, e, n, tone, w in [
                ('أحد', 'Sun', '18', 'office', ('مكتب', 'Office')), ('اثنين', 'Mon', '19', 'wfh', ('من البيت', 'WFH')), ('ثلاثاء', 'Tue', '20', 'office', ('مكتب', 'Office')),
                ('أربعاء', 'Wed', '21', 'wfh', ('من البيت', 'WFH')), ('خميس', 'Thu', '22', 'office', ('مكتب', 'Office'))])
        line = ('<a class="wfo-si-line" href="#"><span class="wfo-si-line-ico">%s</span><span><b>%s</b><small>%s</small></span><span aria-hidden="true">›</span></a>') % (
            icon('swap', 18, 2), esc(T('طلبين تبديل مستنيين ردّك', '2 swap requests wait for your answer')), esc(T('افتحهم في موافقاتي', 'Open them in My approvals')))
        mine = ('<a class="wfo-si-line is-quiet" href="#"><span class="wfo-si-line-ico">%s</span><span><b>%s</b><small>%s</small></span><span aria-hidden="true">›</span></a>') % (
            icon('clock', 18, 2), esc(T('طلب تبديل منك مستني', '1 swap you asked for is waiting')), esc(T('تابعه في طلباتي', 'Follow it in My requests')))
        card = ('<section class="wfo-sched-card wfo-myweek"><div class="wfo-myweek-head"><span class="ews-att-avatar wfo-att-avatar tone-2" aria-hidden="true">%s</span>'
                '<div class="wfo-myweek-who"><h3>%s</h3><strong>%s</strong></div></div><ol class="wfo-myweek-days">%s</ol>'
                '<button type="button" class="ews-btn wfo-btn-solid wfo-myweek-swap">%s%s</button></section>') % (
                    esc(T('كن', 'KN')), esc(T('أسبوعي', 'My week')), esc(T('كريم ناجي', 'Karim Nagi')), days, icon('swap', 16), esc(T('طلب تبديل', 'Request a swap')))
        nav = ('<section class="wfo-sched-top"><div class="ews-week-nav wfo-weeknav"><a href="#" class="wfo-weeknav-prev">%s</a><div class="wfo-weeknav-text"><span>%s</span><small dir="ltr">18 – 22 Oct 2026</small></div>'
               '<a href="#" class="wfo-weeknav-next">%s</a></div></section>') % (icon('chevron', 20, 2.2), esc(T('الأسبوع ده', 'This week')), icon('chevron', 20, 2.2))
        return app_page(T('الجدول', 'Schedule'), '<div class="ews-schedule-page wfo-sched">%s%s%s%s</div>' % (nav, line, mine, card), active='schedule',
                        css_files=['app-schedule.css', 'app-attendance.css'], feature_css=CSS, who=('كريم ناجي', 'Karim Nagi'))
    return make


def mine():
    def make():
        def item(state, label, title, sub, steps):
            return ('<article class="wfo-apv-item tone-swap"><div class="wfo-apv-who"><div class="wfo-apv-t"><b>%s</b><small>%s</small></div><span class="wfo-apv-state is-%s">%s</span></div>'
                    '<details class="wfo-apv-more"%s><summary>%s</summary>%s</details>%s</article>') % (
                        esc(title), esc(sub), state, esc(label), ' open' if state == 'waiting' else '', esc(T('مين بيقرّر', 'Who decides it')), path_html(steps),
                        ('<details class="wfo-apv-withdraw"><summary class="wfo-apv-btn is-bad is-sm">%s</summary></details>' % esc(T('سحب', 'Withdraw'))) if state == 'waiting' else '')
        items = [item('waiting', T('مستني', 'Waiting'), T('تبديل · الأحد 25 أكتوبر', 'Shift swap · Sun 25 Oct'), T('مع منى عادل · مستني ردّها', 'With Mona Adel · waiting for her answer'),
                      [('now', T('منى عادل (الزميلة)', 'Mona Adel (colleague)'), T('مستني ردّها', 'Waiting for her answer')), ('wait', T('المسؤول', 'Approver'), T('بعد ما تقبل', 'After she accepts'))]),
                 item('waiting', T('مستني · المستوى 1 من 1', 'Waiting · level 1 of 1'), T('تبديل · الثلاثاء 27 أكتوبر', 'Shift swap · Tue 27 Oct'), T('مع أحمد سمير · وافق', 'With Ahmed Samir · they accepted'),
                      [('done', T('أحمد سمير (الزميل)', 'Ahmed Samir (colleague)'), T('قبِل', 'Accepted')), ('now', T('هبة فاروق', 'Heba Farouk'), T('مستني', 'Waiting'))]),
                 item('approved', T('اتوافق', 'Approved'), T('تبديل · الخميس 15 أكتوبر', 'Shift swap · Thu 15 Oct'), T('مع منى عادل · الأيام اتبدّلت', 'With Mona Adel · the days were exchanged'),
                      [('done', T('منى عادل', 'Mona Adel'), T('قبِلت', 'Accepted')), ('done', T('هبة فاروق', 'Heba Farouk'), T('وافقت', 'Approved'))])]
        body = '<div class="wfo-apv is-mine"><nav class="wfo-apv-filters"><a href="#" aria-current="true">%s</a><a href="#">%s</a></nav><div class="wfo-apv-list">%s</div></div>' % (
            esc(T('الكل', 'All')), esc(T('مستني · 2', 'Waiting · 2')), ''.join(items))
        return app_page(T('طلباتي', 'My requests'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=('كريم ناجي', 'Karim Nagi'))
    return make


def push():
    def make():
        body = ('<div class="mock-lock"><div class="mock-lock-time" dir="ltr">9:14</div><div class="mock-lock-date">%s</div>'
                '<div class="mock-push"><span class="mock-push-icon">WO</span><div class="mock-push-body"><div class="mock-push-top"><span>WORKFORCE ONE</span><span>%s</span></div>'
                '<strong>%s</strong>%s</div></div><p class="mock-caption">%s</p></div>') % (
                    esc(T('السبت 24 أكتوبر', 'Saturday 24 October')), esc(T('الآن', 'now')), esc(T('طلب تبديل', 'Swap request')),
                    esc(T('أحمد سمير عايز يبدّل معاك الأحد 25 أكتوبر: انت من البيت، هو مكتب.', 'Ahmed Samir asks to swap Sun 25 Oct with you: you WFH, him Office.')),
                    esc(T('بيفتح «موافقاتي» على الطلب ده، أيًا كان أسبوعه.', 'It opens My approvals on this request, whatever its week.')))
        return page_doc(T('إشعار التبديل', 'Swap push'), '<link rel="stylesheet" href="%smock.css">' % Build.kit, '<body style="margin:0">%s</body>' % body)
    return make


def build():
    for slug, make in [('inbox', inbox()), ('schedule', schedule()), ('mine', mine()), ('push', push())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Shift swaps in My approvals — mockups (3.31.124)', (
        '<p><b>My approvals lists everything that waits for your decision</b>: also a swap a colleague asked you for (accept or decline), '
        'whatever week its day is in. The colleague\'s notification opens it there. After the colleague accepts, the swap goes to the approver as today.</p>'
        '<p>The <b>Schedule page no longer lists requests</b>: one line with the count and a link to My approvals; your own requests are in My requests. '
        'With My approvals switched off, the Schedule page keeps today\'s swap panel. No new setting, no database change.</p>'), [
        ('The colleague', '', [('push', 'The notification opens My approvals', P), ('inbox', 'My approvals: swaps asked of you (accept / decline), and one waiting for you as approver', P),
                               ('inbox', 'The same on a computer', D)]),
        ('The Schedule page', '', [('schedule', 'One line per kind, no lists', P)]),
        ('The one who asked', '', [('mine', 'My requests: waiting for the colleague, waiting for the approver, approved', P)]),
    ])
